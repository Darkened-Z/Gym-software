#!/usr/bin/env python3
"""
F22 bridge — CONNECTION TEST (100% read-only, deletes nothing).

Run this FIRST on the reception laptop, before the real bridge. It answers the
two questions that decide whether the software-only per-member lock is possible
on this exact F22:

  1. Can we reach the device on port 4370 (the legacy protocol channel)?
     -> if this fails, the vendor stripped the legacy protocol too, and the
        bridge cannot work. Fall back to the real-time intercept.

  2. What user-id scheme are the fingerprints enrolled under on the device?
     -> the cloud roster must use the SAME ids or blocking won't match anyone.

It ONLY reads. It never disables, deletes, or writes anything to the device.

Usage:
    python test_connection.py 192.168.18.16
    python test_connection.py 192.168.18.16 4370 0     (ip port commpassword)
"""

import sys

try:
    from zk import ZK
except ImportError:
    print("Missing dependency. Run:  pip install pyzk requests")
    sys.exit(1)


def main():
    ip = sys.argv[1] if len(sys.argv) > 1 else "192.168.18.16"
    port = int(sys.argv[2]) if len(sys.argv) > 2 else 4370
    password = int(sys.argv[3]) if len(sys.argv) > 3 else 0

    print(f"\n=== F22 connection test → {ip}:{port} (read-only) ===\n")

    zk = ZK(ip, port=port, timeout=8, password=password, ommit_ping=False)
    conn = None

    # --- try normal TCP first, then UDP fallback ---
    for label, kwargs in [("TCP", {}), ("UDP", {"force_udp": True}),
                          ("TCP+no-ping", {"ommit_ping": True}),
                          ("UDP+no-ping", {"force_udp": True, "ommit_ping": True})]:
        try:
            zk = ZK(ip, port=port, timeout=8, password=password, **kwargs)
            conn = zk.connect()
            print(f"[OK] Connected via {label}.")
            break
        except Exception as e:
            print(f"[..] {label} failed: {e}")

    if conn is None:
        print("\n[FAIL] Could not reach the F22 on the legacy protocol (port 4370).")
        print("       Possible causes:")
        print("        - laptop is not on the same WiFi/LAN as the F22")
        print("        - the F22 IP is different (check Menu > Comm > Ethernet on the device)")
        print("        - a 'Comm Password' is set on the device (pass it as 3rd argument)")
        print("        - the firmware also disabled the legacy protocol (then the bridge can't work)")
        print("\n       Re-run with the right IP, or tell the setup guide it failed.")
        sys.exit(2)

    try:
        # Device info
        try:
            print(f"\n  Firmware : {conn.get_firmware_version()}")
            print(f"  Serial   : {conn.get_serialnumber()}")
            print(f"  Platform : {conn.get_platform()}")
        except Exception:
            pass

        users = conn.get_users()
        print(f"\n[OK] Read {len(users)} enrolled users from the device.\n")
        print("  First 15 users (user_id · name · has-fingerprint):")
        print("  " + "-" * 50)
        for u in users[:15]:
            # does this user have a fingerprint template?
            has_fp = False
            try:
                for fid in range(10):
                    if conn.get_user_template(uid=u.uid, temp_id=fid):
                        has_fp = True
                        break
            except Exception:
                pass
            print(f"    user_id={str(u.user_id):<10} name={str(u.name)[:20]:<20} fp={'yes' if has_fp else 'no'}")

        # PIN scheme detection
        ids = [str(u.user_id) for u in users]
        small = sum(1 for i in ids if i.isdigit() and int(i) < 1_000_000)
        big = sum(1 for i in ids if i.isdigit() and int(i) >= 10_000_000)
        print("\n  PIN scheme on device:")
        if small > big:
            print(f"    -> SMALL ids ({small} users) — device uses member_code / small numbers.")
            print("       The cloud sync_list must return pin = member_code (needs a 1-line server tweak).")
        elif big > small:
            print(f"    -> OFFSET ids ({big} users) — device uses the 10,000,000+id scheme.")
            print("       Matches sync_list as-is. No server change needed.")
        else:
            print(f"    -> Mixed/other. Share this output so we align the mapping.")

        print("\n[SUCCESS] The bridge CAN control this device. Per-member lock is possible.")
        print("          Next: fill config.ini and run the real bridge.\n")

    finally:
        try:
            conn.disconnect()
        except Exception:
            pass


if __name__ == "__main__":
    main()
