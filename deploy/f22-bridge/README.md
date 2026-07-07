# ZKTeco F22 ↔ Gym software bridge

Connects a ZKTeco **F22** (fingerprint + RFID door terminal) at the gym to the
cloud gym software so that **unpaid members are blocked at the door** and every
scan is recorded as a check-in.

## How it works (the short version)

- The gym software is the **brain**. It already decides paid/unpaid (active
  membership + no fee due) — the same rule as the RFID gate.
- The F22 opens the door on its **own** local fingerprint/card verify — it never
  asks the cloud per scan. So to block an unpaid member we make the **device**
  refuse them.
- This little bridge (runs on a Raspberry Pi / any always-on Linux box on the
  gym wifi) does two things every ~45s and in real time:
  - **Sync:** pulls the allow/block list from the cloud and, for each **unpaid**
    member, removes their fingerprint from the F22 (door stays shut, F22 shows
    the red/"invalid" prompt). When they **pay**, it puts the fingerprint back —
    **no re-enrolment**, because it keeps a backup of each print.
  - **Report:** streams every scan to the cloud so it lands in attendance +
    the front-desk monitor.
- The bridge talks **out** to the cloud (HTTPS) and **in** to the F22 (LAN). No
  ports need opening anywhere.

> Trade-off to know: enforcement is **near-real-time (~45s)**, not per-scan —
> standard for any standalone terminal. And the first fingerprint capture for a
> member must happen **physically at the F22**.

## Device identity (PINs)

The software assigns each member a stable device **PIN** (user id) so scans map
back cleanly:

| Member | PIN on the F22 |
|--------|----------------|
| Men, member id N | `10000000 + N` |
| Women, member id N | `20000000 + N` |

The cloud `sync_list` gives you each member's PIN and name. **Enrol each member's
finger on the F22 under that PIN.**

## Setup (Raspberry Pi / Linux box)

```bash
sudo mkdir -p /opt/f22-bridge && cd /opt/f22-bridge
# copy f22_bridge.py, config.example.ini, requirements.txt here
sudo pip3 install -r requirements.txt
cp config.example.ini config.ini
nano config.ini    # set the F22 ip, base_url, device_key
```

Get the **device_key** from the server: it's `F22_DEVICE_KEY` in the gym app's
`.env` (already generated — `grep F22_DEVICE_KEY /var/www/<gym>/.env`).

Run it once in the foreground to watch the logs:

```bash
python3 f22_bridge.py config.ini
```

Then install as a service so it auto-starts and auto-restarts:

```bash
sudo cp f22-bridge.service /etc/systemd/system/
sudo systemctl enable --now f22-bridge
journalctl -u f22-bridge -f     # live logs
```

## On the F22 (one-time)

1. **Network:** give the F22 a fixed IP on the router; make sure it's on the same
   LAN as the Pi. Note the IP → `config.ini [device] ip`.
2. **(Optional) Comm password:** if set on the device, put it in
   `config.ini [device] password`.
3. **Enrolment:** for each member, add a device user with **user_id = their PIN**
   (from `sync_list`) and enrol their fingerprint. The bridge caches the print on
   its next sync, and from then on handles block/unblock automatically.
4. **Door lock:** already wired to open on a successful verify — nothing to
   change for the delete/re-add method.

## Optional (cleaner) block method — per-user validity date

If your F22's menu has **Access Control → user "Valid Time" / expiry date** and
the **Expiration Rule** is set to *keep the user* (not delete), you can block by
setting each member's valid-until date instead of deleting the fingerprint. The
cloud `sync_list` returns `valid_until` (= the member's next fee due date) for
this. It avoids delete/re-add entirely. pyzk itself can't set that field, so this
path uses the device menu / ADMS — ask us to wire it if your unit supports it.

## Troubleshooting

- `Can't reach the device` → check the IP, that the Pi and F22 are on the same
  LAN, and try `force_udp = true` / `ommit_ping = true`.
- `Unauthorized device` (401) from the cloud → the `device_key` doesn't match the
  server's `F22_DEVICE_KEY`.
- Scans not appearing → check `journalctl -u f22-bridge -f`; confirm the member
  was enrolled under the correct PIN.
