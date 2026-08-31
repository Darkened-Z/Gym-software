# F22 Bridge — Reception Laptop Setup (Windows)

This runs on the gym's **reception laptop** (or any always-on Windows PC on the
same WiFi as the F22). It gives you the **per-member lock** the F22's cloud
connection can't do: unpaid member's thumb stops opening the door, paying
members are untouched, and when someone pays their fingerprint is restored
automatically — no re-enrolment.

**KL values (already filled for you):**
- F22 IP: `192.168.18.16`
- Device key: `5dc74f8e311f94edbaa5aa36d8cafdc33ec88ae07fcc18fe`
- Cloud URL: `https://kl.gym.goxx.app`

---

## Step 1 — Install Python (one time, ~3 min)

1. Go to **python.org/downloads** → download Python 3.11 or newer.
2. Run the installer. **CHECK the box "Add Python to PATH"** at the bottom — this is important.
3. Click Install.

Verify: open **Command Prompt** (press `Win`, type `cmd`, Enter) and run:
```
python --version
```
You should see `Python 3.11.x` or similar.

---

## Step 2 — Get the bridge files onto the laptop

Copy this whole `f22-bridge` folder to the laptop, e.g. to `C:\f22-bridge\`.
(USB stick, email, or WhatsApp the zip to yourself.)

Then install the two libraries it needs — in Command Prompt:
```
pip install pyzk requests
```

---

## Step 3 — CONNECTION TEST (do this before anything else)

This is read-only — it deletes nothing. It proves the laptop can actually
control the F22, and shows how members are enrolled.

In Command Prompt:
```
cd C:\f22-bridge
python test_connection.py 192.168.18.16
```

**Read the result:**

- ✅ **`[SUCCESS] The bridge CAN control this device`** → per-member lock works. Continue to Step 4.
- ❌ **`[FAIL] Could not reach the F22`** → the laptop can't talk to the device on port 4370. Check:
  - Is the laptop on the **same WiFi** as the F22?
  - Is the F22 IP still `192.168.18.16`? (On the device: Menu → Comm → Ethernet)
  - Did someone set a "Comm Password" on the F22? If so run: `python test_connection.py 192.168.18.16 4370 <password>`
  - If it still fails, the firmware blocks the legacy protocol too — **stop here**, tell me, we use the real-time intercept instead.

**Also note what the test says about the PIN scheme** — if it says "SMALL ids /
member_code", tell me, I make a 1-line server change so blocking matches the
right people. If it says "OFFSET ids", no change needed.

---

## Step 4 — Configure the bridge

Open `config.ini` (copy `config.example.ini` to `config.ini` first) in Notepad
and set:

```ini
[device]
ip = 192.168.18.16
port = 4370
password = 0

[cloud]
base_url = https://kl.gym.goxx.app
device_key = 5dc74f8e311f94edbaa5aa36d8cafdc33ec88ae07fcc18fe
device_sn = KL-F22

[bridge]
sync_interval = 45
heartbeat_interval = 30
template_cache_dir = ./cache
state_file = ./state.json
```

---

## Step 5 — First run (watch it work)

```
cd C:\f22-bridge
python f22_bridge.py config.ini
```

Leave it running. You'll see log lines like:
```
connected to F22 192.168.18.16:4370
cached 1 template(s) for PIN 44 (Kinza Shahid)
BLOCKED unpaid PIN 44 (Kinza Shahid)
```

The first sync **caches every enrolled member's fingerprint** (safety — so
restore works later), then blocks the unpaid ones.

**Test it live:** have an unpaid member try their thumb → door should refuse.
Have a paid member try → door opens normally. Mark the unpaid member as paid in
the dashboard → within 45 seconds their fingerprint is restored and their thumb
works again.

Stop it any time with `Ctrl + C`.

---

## Step 6 — Make it auto-start (so it survives reboots)

So you don't have to open Command Prompt every morning:

1. Create a file `start-bridge.bat` in `C:\f22-bridge\` with this content:
   ```
   @echo off
   cd C:\f22-bridge
   python f22_bridge.py config.ini
   ```
2. Press `Win + R`, type `shell:startup`, Enter — this opens the Startup folder.
3. Right-click `start-bridge.bat` → **Create shortcut** → move the shortcut into
   that Startup folder.

Now the bridge starts automatically whenever the laptop turns on. It only needs
to run while the gym is open (enforcement only matters when the door is in use).

---

## How it behaves

- **Unpaid member** → fingerprint removed from device → thumb does nothing → door stays shut.
- **They pay** (you renew them in the dashboard) → within 45s their cached fingerprint is pushed back → thumb works again. **No re-enrolment.**
- **Paying members** → never touched.
- **Laptop off / bridge stopped** → the F22 keeps working on its own (opens for whoever is currently enrolled). Enforcement just pauses until the bridge is back — nothing breaks.
- **The fingerprint cache** lives in `C:\f22-bridge\cache\`. Don't delete it — it's what lets paid members get restored without re-scanning. Back it up if you reinstall.

---

## Safety notes

- The bridge talks **out** to the cloud over HTTPS and **in** to the F22 over the
  LAN. Nothing on the gym network or the server is exposed to the internet.
- It **caches before it deletes** — a member's template is always saved before
  their fingerprint is removed, so payment always restores cleanly.
- If the cache is ever lost AND a blocked member pays, that one member re-enrolls
  their thumb once. That's the worst case, and only if the cache folder is deleted.
