#!/usr/bin/env python3
"""
Gym <-> ZKTeco F22 bridge.

Runs on a small always-on box on the gym LAN (e.g. a Raspberry Pi). It talks to
the F22 over the local network (pyzk, TCP 4370) and to the gym cloud API over
OUTBOUND HTTPS only -- nothing on the VPS or the gym network is exposed.

Three jobs:
  1. SYNC   (every N sec): pull the allow/block roster from the cloud and enforce
            it on the device. Because the F22 opens the door on its OWN local
            verify, "block an unpaid member" = remove their fingerprint from the
            device; "unblock on payment" = restore it. We cache each enrolled
            member's template first so restore needs NO physical re-enrolment.
  2. LIVE   (thread): stream scan events from the device and report each to the
            cloud (records the check-in + logs paid/unpaid for the monitor).
  3. BEAT   (every N sec): tell the cloud the bridge is alive.

IMPORTANT — test against the real F22 before relying on it:
  * Fingerprint FIRST-capture is physical: enrol each member at the device under
    user_id = their assigned PIN (the cloud sync_list gives each member's PIN).
    This bridge only toggles members it has already seen + cached a template for.
  * delete/re-add is the block mechanism; the template CACHE is safety-critical.
    If your F22 firmware exposes a per-user "valid until" date instead, that is
    cleaner (set it = valid_until from sync_list) -- see README.
"""

import sys, os, time, json, pickle, threading, configparser, logging
import requests

try:
    from zk import ZK, const
except ImportError:
    print("Missing dependency: pip install pyzk requests", file=sys.stderr)
    raise

log = logging.getLogger("f22bridge")


class Bridge:
    def __init__(self, cfg_path):
        cfg = configparser.ConfigParser()
        cfg.read(cfg_path)
        self.ip = cfg.get("device", "ip")
        self.port = cfg.getint("device", "port", fallback=4370)
        self.password = cfg.getint("device", "password", fallback=0)
        self.force_udp = cfg.getboolean("device", "force_udp", fallback=False)
        self.ommit_ping = cfg.getboolean("device", "ommit_ping", fallback=False)

        self.base_url = cfg.get("cloud", "base_url").rstrip("/")
        self.device_key = cfg.get("cloud", "device_key")
        self.device_sn = cfg.get("cloud", "device_sn", fallback="F22")

        self.sync_interval = cfg.getint("bridge", "sync_interval", fallback=45)
        self.heartbeat_interval = cfg.getint("bridge", "heartbeat_interval", fallback=30)
        self.cache_dir = cfg.get("bridge", "template_cache_dir", fallback="./cache")
        self.state_file = cfg.get("bridge", "state_file", fallback="./state.json")

        os.makedirs(self.cache_dir, exist_ok=True)
        self.zk = ZK(self.ip, port=self.port, timeout=5, password=self.password,
                     force_udp=self.force_udp, ommit_ping=self.ommit_ping)
        self.conn = None
        self._stop = threading.Event()
        self.state = self._load_json(self.state_file, {})  # pin -> "allowed"/"blocked"

    # ---- helpers ----------------------------------------------------------
    def _load_json(self, path, default):
        try:
            with open(path) as f:
                return json.load(f)
        except Exception:
            return default

    def _save_state(self):
        try:
            with open(self.state_file, "w") as f:
                json.dump(self.state, f)
        except Exception as e:
            log.warning("state save failed: %s", e)

    def _cache_path(self, pin):
        return os.path.join(self.cache_dir, f"{pin}.pkl")

    def _api(self, method, action, **kw):
        url = f"{self.base_url}/api/f22.php?action={action}"
        headers = {"X-Device-Key": self.device_key}
        return requests.request(method, url, headers=headers, timeout=15, **kw)

    def connect(self):
        self.conn = self.zk.connect()
        log.info("connected to F22 %s:%s", self.ip, self.port)

    # ---- 1. SYNC ----------------------------------------------------------
    def cache_template(self, user):
        """Cache a device user's fingerprint templates + record so we can restore."""
        path = self._cache_path(user.user_id)
        if os.path.exists(path):
            return
        templates = []
        for fid in range(10):
            try:
                t = self.conn.get_user_template(uid=user.uid, temp_id=fid)
                if t:
                    templates.append(t)
            except Exception:
                pass
        if not templates:
            return  # no finger enrolled yet -> nothing to preserve
        with open(path, "wb") as f:
            pickle.dump({"user": user, "templates": templates}, f)
        log.info("cached %d template(s) for PIN %s (%s)", len(templates), user.user_id, user.name)

    def restore_user(self, pin):
        path = self._cache_path(pin)
        if not os.path.exists(path):
            return False
        with open(path, "rb") as f:
            blob = pickle.load(f)
        self.conn.save_user_template(blob["user"], blob["templates"])
        log.info("restored PIN %s", pin)
        return True

    def sync(self):
        r = self._api("GET", "sync_list")
        r.raise_for_status()
        roster = {str(m["pin"]): m for m in r.json().get("members", [])}

        users = {str(u.user_id): u for u in self.conn.get_users()}
        # First, make sure everything currently enrolled has a cached template.
        for pin, user in users.items():
            if pin in roster:
                self.cache_template(user)

        self.conn.disable_device()  # freeze the device while we mutate its DB
        try:
            for pin, m in roster.items():
                allowed = bool(m.get("allowed"))
                on_device = pin in users
                has_cache = os.path.exists(self._cache_path(pin))
                if not has_cache and not on_device:
                    continue  # never enrolled here -> ignore until enrolled physically
                if allowed and not on_device:
                    if self.restore_user(pin):
                        self.state[pin] = "allowed"
                elif not allowed and on_device:
                    self.cache_template(users[pin])  # ensure we can restore later
                    try:
                        self.conn.delete_user(user_id=pin)
                        self.state[pin] = "blocked"
                        log.info("BLOCKED unpaid PIN %s (%s)", pin, m.get("name"))
                    except Exception as e:
                        log.warning("block PIN %s failed: %s", pin, e)
                else:
                    self.state[pin] = "allowed" if allowed else "blocked"
        finally:
            self.conn.enable_device()
            self._save_state()

    # ---- 2. LIVE ----------------------------------------------------------
    def live_loop(self):
        while not self._stop.is_set():
            try:
                for att in self.conn.live_capture():
                    if self._stop.is_set():
                        break
                    if att is None:
                        continue  # timeout tick / heartbeat
                    try:
                        self._api("POST", "report_scan", json={
                            "pin": att.user_id,
                            "time": str(att.timestamp),
                            "status": att.status,
                            "punch": att.punch,
                        })
                    except Exception as e:
                        log.warning("report_scan failed: %s", e)
            except Exception as e:
                log.warning("live_capture dropped (%s) — reconnecting", e)
                time.sleep(5)
                try:
                    self.connect()
                except Exception:
                    pass

    # ---- 3. BEAT ----------------------------------------------------------
    def beat_loop(self):
        while not self._stop.is_set():
            try:
                self._api("POST", "heartbeat", json={"device_sn": self.device_sn})
            except Exception as e:
                log.debug("heartbeat failed: %s", e)
            self._stop.wait(self.heartbeat_interval)

    # ---- run --------------------------------------------------------------
    def run(self):
        self.connect()
        threading.Thread(target=self.live_loop, daemon=True).start()
        threading.Thread(target=self.beat_loop, daemon=True).start()
        while not self._stop.is_set():
            try:
                self.sync()
            except Exception as e:
                log.warning("sync failed: %s", e)
            self._stop.wait(self.sync_interval)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    cfg_path = sys.argv[1] if len(sys.argv) > 1 else "config.ini"
    bridge = Bridge(cfg_path)
    while True:
        try:
            bridge.run()
        except KeyboardInterrupt:
            break
        except Exception as e:
            log.error("bridge crashed: %s — restarting in 10s", e)
            time.sleep(10)


if __name__ == "__main__":
    main()
