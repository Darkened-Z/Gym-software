# Complete System Guide - Gym Management with NFC Gate Automation

## Table of Contents
1. [Overview](#overview)
2. [New Features & Updates](#new-features--updates)
3. [System Architecture](#system-architecture)
4. [License/Activation System](#licenseactivation-system)
5. [NFC Gate Automation System](#nfc-gate-automation-system)
6. [How Everything Works Together](#how-everything-works-together)
7. [Database Schema](#database-schema)
8. [API Endpoints](#api-endpoints)
9. [Workflow Diagrams](#workflow-diagrams)
10. [Installation & Setup](#installation--setup)

---

## Overview

This Gym Management System is a comprehensive solution that manages members, attendance, payments, and now includes **NFC-based gate automation** for automatic check-in/check-out. The system also includes a **license protection mechanism** to prevent unauthorized distribution.

### Key Components:
- **Web Application** (PHP/MySQL/JavaScript)
- **NFC Gate Hardware** (ESP32 + PN532 + Relays)
- **Bridge Script** (Python - for USB connection)
- **License System** (Prevents unauthorized use)

---

## New Features & Updates

### 1. NFC Gate Automation System 🆕

**What it does:**
- Automatically opens/closes gym gates when members scan their NFC cards
- Records attendance automatically
- Blocks access for fee defaulters
- Allows admin to manually force-open gates

**Components added:**
- Database columns: `nfc_uid`, `is_checked_in`
- API endpoint: `api/gate.php`
- ESP32 Arduino code
- Python bridge script (for USB connection)
- Admin dashboard force-open buttons
- Member profile NFC display

### 2. License/Activation System 🆕

**What it does:**
- Requires `setup.php` to be run once before system can be used
- Generates unique license key based on server hardware
- Prevents admin account creation without activation
- Prevents unauthorized distribution of software

**Components added:**
- `system_license` database table
- `LicenseHelper.php` class
- Updated `setup.php` with activation
- License checks in `api/auth.php`
- License verification API

### 3. Enhanced Member Management

**New fields:**
- `nfc_uid` - Stores NFC card UID for each member
- `is_checked_in` - Tracks if member is currently inside gym

**New features:**
- Assign NFC cards to members in admin panel
- View NFC card UID in member profile
- Edit NFC card if member loses their card

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    WEB APPLICATION                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Frontend   │  │   Backend    │  │  Database    │      │
│  │  (HTML/JS)   │→ │   (PHP API)  │→ │   (MySQL)    │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                            ↕ HTTP Requests
┌─────────────────────────────────────────────────────────────┐
│              PYTHON BRIDGE SCRIPT (Computer)                │
│  ┌──────────────┐              ┌──────────────┐             │
│  │  Serial Port │←→ USB Cable ←│    ESP32    │             │
│  │  (COM/USB)   │              │  (Hardware)  │             │
│  └──────────────┘              └──────────────┘             │
└─────────────────────────────────────────────────────────────┘
                            ↕
┌─────────────────────────────────────────────────────────────┐
│                    HARDWARE LAYER                            │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   PN532 NFC  │  │   ESP32      │  │  2x Relays   │      │
│  │   Reader     │→ │  Controller  │→ │  (Gates)     │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow:

1. **Member scans NFC card** → PN532 reads UID
2. **ESP32 sends UID** → Via USB Serial to Python bridge
3. **Bridge makes HTTP request** → To `api/gate.php`
4. **Server validates** → Checks member, fee status, check-in status
5. **Server responds** → "allowed" or "blocked"
6. **Bridge sends command** → To ESP32 via Serial
7. **ESP32 activates relay** → Gate opens/closes

---

## License/Activation System

### How It Works

The license system ensures that the software cannot be used without running `setup.php` first, preventing unauthorized distribution.

#### Step 1: System Activation

When you first install the system:

1. **Access `setup.php`** via browser or CLI
2. **System generates:**
   - Unique license key (SHA256 hash)
   - Server fingerprint (based on hostname, IP, document root, PHP version)
3. **License stored** in `system_license` table
4. **Admin account created** with default password

#### Step 2: License Verification

Every time an admin tries to:
- **Login** → System checks if license exists
- **Create admin** → System checks if license exists
- **Access admin features** → System verifies license

#### Step 3: License Components

**Database Table: `system_license`**
```sql
- id (INT)
- license_key (VARCHAR 255) - Unique key
- server_fingerprint (VARCHAR 255) - Server identifier
- activated_at (DATETIME) - When activated
- is_active (TINYINT) - Active status
```

**LicenseHelper Class:**
- `isSystemActivated()` - Checks if system is activated
- `getServerFingerprint()` - Generates unique server ID
- `generateLicenseKey()` - Creates license key
- `activateSystem()` - Activates the system
- `verifyLicense()` - Verifies license matches server

#### Step 4: Security Features

1. **Server Fingerprint:**
   - Based on: hostname, server IP, document root, PHP version
   - Creates unique identifier for each server
   - Prevents license from working on different server

2. **License Key:**
   - SHA256 hash of fingerprint + timestamp + unique ID
   - Formatted as: `XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX`
   - Stored securely in database

3. **Activation Checks:**
   - `api/auth.php` - Checks license before admin login
   - `fix-admin-password.php` - Checks license before creating admin
   - `api/check-license.php` - API endpoint to check activation status

#### Step 5: What Happens Without Activation?

❌ **Cannot create admin accounts**
❌ **Cannot login as admin**
❌ **System displays error: "System not activated. Please run setup.php"**
✅ **Member portal still works** (not affected)
✅ **System can be activated anytime** by running `setup.php`

### License Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    FIRST TIME SETUP                       │
└─────────────────────────────────────────────────────────┘
                          ↓
        User runs setup.php (browser or CLI)
                          ↓
    ┌──────────────────────────────────────┐
    │  Generate Server Fingerprint         │
    │  (hostname + IP + path + PHP ver)    │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  Generate License Key                │
    │  (SHA256 hash of fingerprint)        │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  Store in system_license table       │
    │  Create admin account                │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  System Activated ✓                  │
    │  Can now use admin features          │
    └──────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│              ONGOING OPERATION (Every Request)           │
└─────────────────────────────────────────────────────────┘
                          ↓
        Admin tries to login/create account
                          ↓
    ┌──────────────────────────────────────┐
    │  LicenseHelper::isSystemActivated()  │
    └──────────────────────────────────────┘
                          ↓
              ┌───────────┴───────────┐
              │                       │
         YES (exists)            NO (missing)
              │                       │
              ↓                       ↓
    Allow operation          Block operation
    Continue login          Show error message
                          "Run setup.php first"
```

---

## NFC Gate Automation System

### How It Works - Complete Flow

#### Scenario 1: Member Check-In

```
1. Member arrives at gym
   ↓
2. Member scans NFC card at Check-In gate
   ↓
3. PN532 NFC reader detects card
   ↓
4. ESP32 reads UID (e.g., "A1B2C3D4")
   ↓
5. ESP32 sends via USB Serial: "NFC_DETECTED:A1B2C3D4"
   ↓
6. Python bridge receives UID
   ↓
7. Bridge makes HTTP GET request:
   http://server/api/gate.php?uid=A1B2C3D4&type=checkin
   ↓
8. Server (api/gate.php) processes:
   ├─ Find member by nfc_uid
   ├─ Check if member is active
   ├─ Check if total_due_amount > 0 (defaulter?)
   ├─ Check if is_checked_in = 0 (not already checked in)
   └─ If all OK:
       ├─ Mark attendance (insert into attendance_men/women)
       ├─ Set is_checked_in = 1
       └─ Return: {"allowed": true, "message": "Check-in successful"}
   ↓
9. Bridge receives response
   ↓
10. Bridge sends to ESP32: "ALLOWED:checkin"
    ↓
11. ESP32 activates Relay 1 (Check-In gate)
    ↓
12. Gate opens for 3 seconds
    ↓
13. Gate automatically closes
    ↓
14. Member enters gym ✓
```

#### Scenario 2: Member Check-Out

```
1. Member finishes workout
   ↓
2. Member scans NFC card at Check-Out gate
   ↓
3. Same process as check-in, but:
   - Request: type=checkout
   - Server checks: is_checked_in = 1 (must be checked in)
   ↓
4. If valid:
   ├─ Update attendance record (set check_out time)
   ├─ Set is_checked_in = 0
   └─ Return: {"allowed": true}
   ↓
5. ESP32 activates Relay 2 (Check-Out gate)
   ↓
6. Gate opens, member exits ✓
```

#### Scenario 3: Fee Defaulter Tries to Enter

```
1. Member scans NFC card
   ↓
2. Server checks: total_due_amount > 0
   ↓
3. Server returns: {"allowed": false, "message": "Fee defaulter"}
   ↓
4. Bridge sends to ESP32: "BLOCKED: Fee defaulter"
   ↓
5. ESP32 does NOT activate relay
   ↓
6. Gate remains closed ❌
   ↓
7. Member cannot enter (must pay fees first)
```

#### Scenario 4: Admin Force-Open Gate

```
1. Admin clicks "Force Open Check-In Gate" in dashboard
   ↓
2. JavaScript makes request:
   api/gate.php?type=force_open&gate=checkin
   ↓
3. Server inserts command into gate_commands table:
   INSERT INTO gate_commands (gate_type, command_status) 
   VALUES ('checkin', 'pending')
   ↓
4. Python bridge polls every 1 second:
   api/gate.php?type=poll
   ↓
5. Server finds pending command:
   SELECT * FROM gate_commands 
   WHERE command_status = 'pending' 
   LIMIT 1
   ↓
6. Server returns: {"command": "open", "gate": "checkin"}
   ↓
7. Server marks command as executed
   ↓
8. Bridge sends to ESP32: "OPEN:checkin"
   ↓
9. ESP32 immediately opens gate (bypasses all checks)
   ↓
10. Gate opens for 3 seconds ✓
```

### Database Tables for NFC System

#### 1. `members_men` / `members_women` - New Columns

```sql
nfc_uid VARCHAR(50) UNIQUE NULL
-- Stores the NFC card UID assigned to member
-- NULL if no card assigned
-- UNIQUE ensures one card per member

is_checked_in TINYINT(1) DEFAULT 0 NOT NULL
-- 0 = Not checked in (outside gym)
-- 1 = Checked in (inside gym)
-- Prevents double check-in
-- Required for checkout validation
```

#### 2. `gate_commands` - Force-Open Commands

```sql
CREATE TABLE gate_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gate_type ENUM('checkin', 'checkout') NOT NULL,
    command_status ENUM('pending', 'executed', 'expired') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME NULL
)
```

**How it works:**
- Admin clicks force-open button → Command inserted with status='pending'
- ESP32 polls every 1 second → Finds pending command
- Command executed → Status changed to 'executed'
- Old commands auto-deleted (older than 5 minutes)

---

## How Everything Works Together

### Integration Points

#### 1. Member Registration → NFC Assignment

```
Admin creates/edits member
    ↓
Admin enters NFC UID in form
    ↓
JavaScript sends to api/members.php
    ↓
Member model saves nfc_uid to database
    ↓
Member can now use NFC card for gate access
```

#### 2. Attendance System → NFC Integration

```
NFC check-in triggers:
    ↓
api/gate.php creates attendance record
    ↓
Attendance stored in attendance_men/women table
    ↓
Member profile shows attendance in calendar
    ↓
Admin can see attendance in attendance section
```

#### 3. Payment System → Gate Access Control

```
Member pays fees
    ↓
Payment recorded in payments_men/women
    ↓
total_due_amount updated in members table
    ↓
If total_due_amount = 0:
    ✅ Member can use NFC gate
    ↓
If total_due_amount > 0:
    ❌ Gate blocked (defaulter)
```

#### 4. License System → All Admin Operations

```
Every admin operation:
    ↓
api/auth.php checks license
    ↓
LicenseHelper::isSystemActivated()
    ↓
If activated:
    ✅ Operation proceeds
    ↓
If not activated:
    ❌ Operation blocked
    Error: "Run setup.php first"
```

### Complete System Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    SYSTEM STARTUP                            │
└─────────────────────────────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  1. Run setup.php                    │
    │     - Generates license key          │
    │     - Creates admin account          │
    │     - Activates system               │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  2. Run database migrations          │
    │     - Add nfc_uid columns            │
    │     - Add is_checked_in columns       │
    │     - Create gate_commands table      │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  3. Start Python bridge script       │
    │     - Connects to ESP32 via USB      │
    │     - Ready to receive NFC scans     │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  4. Admin assigns NFC cards          │
    │     - Edit member → Enter NFC UID    │
    │     - Save member                    │
    └──────────────────────────────────────┘
                          ↓
    ┌──────────────────────────────────────┐
    │  5. System ready for use             │
    │     - Members can scan NFC cards      │
    │     - Gates open/close automatically  │
    │     - Attendance recorded             │
    └──────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    DAILY OPERATION                           │
└─────────────────────────────────────────────────────────────┘
                          ↓
    Member scans NFC → Gate opens → Attendance recorded
                          ↓
    Member works out
                          ↓
    Member scans NFC → Gate opens → Check-out recorded
                          ↓
    Admin can:
    - View attendance
    - Manage payments
    - Force-open gates if needed
    - Assign new NFC cards
```

---

## Database Schema

### New Tables

#### `system_license`
```sql
id                  INT PRIMARY KEY
license_key         VARCHAR(255) UNIQUE  -- Unique license key
server_fingerprint  VARCHAR(255)         -- Server identifier
activated_at        DATETIME             -- When activated
is_active           TINYINT(1)           -- Active status
```

#### `gate_commands`
```sql
id              INT PRIMARY KEY
gate_type       ENUM('checkin', 'checkout')
command_status  ENUM('pending', 'executed', 'expired')
created_at      DATETIME
executed_at     DATETIME
```

### Modified Tables

#### `members_men` / `members_women`
```sql
-- New columns:
nfc_uid         VARCHAR(50) UNIQUE NULL      -- NFC card UID
is_checked_in   TINYINT(1) DEFAULT 0         -- Check-in status
```

---

## API Endpoints

### NFC Gate Endpoints

#### 1. Check-In
```
GET /api/gate.php?uid=XXX&type=checkin

Response (Success):
{
    "success": true,
    "allowed": true,
    "message": "Check-in successful",
    "member_name": "John Doe",
    "check_in_time": "2024-01-15 10:30:00"
}

Response (Blocked):
{
    "success": false,
    "allowed": false,
    "message": "Fee defaulter - Cannot check in",
    "is_defaulter": true
}
```

#### 2. Check-Out
```
GET /api/gate.php?uid=XXX&type=checkout

Response (Success):
{
    "success": true,
    "allowed": true,
    "message": "Check-out successful",
    "check_out_time": "2024-01-15 12:30:00"
}

Response (Blocked):
{
    "success": false,
    "allowed": false,
    "message": "Not checked in. Please check in first."
}
```

#### 3. Force-Open Gate
```
GET /api/gate.php?type=force_open&gate=checkin
GET /api/gate.php?type=force_open&gate=checkout

Response:
{
    "success": true,
    "message": "Force open command sent",
    "gate": "checkin"
}
```

#### 4. Poll for Commands (ESP32)
```
GET /api/gate.php?type=poll

Response (Command Available):
{
    "success": true,
    "command": "open",
    "gate": "checkin"
}

Response (No Command):
{
    "success": true,
    "command": "none"
}
```

### License Endpoints

#### Check License Status
```
GET /api/check-license.php

Response (Activated):
{
    "activated": true,
    "verified": true
}

Response (Not Activated):
{
    "activated": false,
    "message": "System not activated. Please run setup.php first.",
    "setup_url": "setup.php"
}
```

---

## Workflow Diagrams

### Member Check-In Workflow

```
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│  Member  │────▶│   ESP32  │────▶│  Bridge  │────▶│   API    │
│  Scans   │     │  Reads   │     │  Script  │     │  Server  │
│   NFC    │     │   UID    │     │          │     │          │
└──────────┘     └──────────┘     └──────────┘     └──────────┘
                                                          │
                                                          ▼
                                                  ┌──────────────┐
                                                  │  Validation │
                                                  │  - Member?   │
                                                  │  - Active?   │
                                                  │  - Defaulter?│
                                                  │  - Checked in?│
                                                  └──────────────┘
                                                          │
                                                          ▼
                                                  ┌──────────────┐
                                                  │  If Valid:   │
                                                  │  - Mark      │
                                                  │    attendance│
                                                  │  - Set flag  │
                                                  │  - Return OK │
                                                  └──────────────┘
                                                          │
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│  Gate    │◀────│   ESP32  │◀────│  Bridge  │◀────│ Response │
│  Opens   │     │ Activates│     │  Script  │     │ "allowed"│
│          │     │  Relay   │     │          │     │          │
└──────────┘     └──────────┘     └──────────┘     └──────────┘
```

### License Activation Workflow

```
┌──────────────┐
│  User runs   │
│  setup.php   │
└──────┬───────┘
       │
       ▼
┌─────────────────────┐
│ Generate Server     │
│ Fingerprint         │
│ (hostname+IP+path)  │
└──────┬──────────────┘
       │
       ▼
┌─────────────────────┐
│ Generate License    │
│ Key (SHA256 hash)   │
└──────┬──────────────┘
       │
       ▼
┌─────────────────────┐
│ Store in Database   │
│ system_license      │
└──────┬──────────────┘
       │
       ▼
┌─────────────────────┐
│ Create Admin        │
│ Account             │
└──────┬──────────────┘
       │
       ▼
┌─────────────────────┐
│ System Activated ✓  │
│ Ready to use        │
└─────────────────────┘
```

---

## Installation & Setup

### Step 1: Database Setup

```sql
-- Run in phpMyAdmin or MySQL CLI
SOURCE database/add_nfc_columns.sql;
SOURCE database/add_system_license.sql;
```

### Step 2: System Activation

1. Access `setup.php` in browser: `http://localhost/gym-management/setup.php`
2. System will:
   - Generate license key
   - Create admin account
   - Activate system
3. Save the license key (displayed on screen)

### Step 3: Hardware Setup

1. **Wire ESP32:**
   - Connect PN532 (SPI mode)
   - Connect 2 relays (GPIO 2 and 4)
   - Connect to computer via USB

2. **Upload ESP32 Code:**
   - Open `esp32_nfc_gate_usb.ino` in Arduino IDE
   - Select ESP32 board and COM port
   - Upload code

### Step 4: Python Bridge Setup

1. **Install Python 3**
2. **Install packages:**
   ```bash
   pip install pyserial requests
   ```
3. **Configure `gate_bridge.py`:**
   - Set `SERVER_URL` to your server URL
   - Set `SERIAL_PORT` if auto-detection fails
4. **Run bridge:**
   - Windows: `gate_bridge.bat`
   - Linux/Mac: `./gate_bridge.sh`

### Step 5: Assign NFC Cards

1. **Get NFC UID:**
   - Scan card with ESP32 (check Serial Monitor)
   - Or use NFC reader app on phone

2. **Assign to Member:**
   - Admin Dashboard → Members → Edit
   - Enter NFC UID in "NFC Card UID" field
   - Save

### Step 6: Test System

1. **Test Check-In:**
   - Scan assigned NFC card
   - Gate should open
   - Check attendance is recorded

2. **Test Check-Out:**
   - Scan same card again
   - Gate should open
   - Check check-out is recorded

3. **Test Force-Open:**
   - Click "Force Open" button in dashboard
   - Gate should open within 1 second

---

## Troubleshooting

### License Issues

**Problem:** "System not activated" error
**Solution:** Run `setup.php` to activate system

**Problem:** License key not working
**Solution:** License is tied to server. If you moved servers, run `setup.php` again on new server

### NFC Gate Issues

**Problem:** Gate not opening
- Check ESP32 Serial Monitor for errors
- Verify Python bridge is running
- Check relay wiring and power
- Verify member has NFC card assigned

**Problem:** "Access denied" for valid member
- Check if member is active (status = 'active')
- Check if `total_due_amount` = 0 (not a defaulter)
- Verify NFC UID matches database

**Problem:** Bridge script not connecting
- Check COM port (Windows: Device Manager, Linux: `ls /dev/tty*`)
- Install USB drivers (CH340, CP2102, etc.)
- Close other programs using the port

---

## Security Features

### 1. License Protection
- Prevents unauthorized distribution
- Tied to server hardware
- Required for admin operations

### 2. Fee Defaulter Blocking
- Automatic gate blocking for defaulters
- Admin can still force-open if needed
- Prevents access until fees paid

### 3. Check-In State Management
- Prevents double check-in
- Requires check-in before check-out
- Tracks member location (inside/outside gym)

### 4. Admin Force-Open
- Only admins can force-open gates
- Commands expire after 5 minutes
- Logged in database

---

## Summary

This system integrates:
- **Web Application** - Manages members, payments, attendance
- **NFC Hardware** - ESP32 + PN532 for card reading
- **Bridge Script** - Connects hardware to web via USB
- **License System** - Protects software from unauthorized use

All components work together to provide:
- Automatic gate control
- Attendance tracking
- Fee management
- Access control
- Admin oversight

The system is designed to be:
- **Secure** - License protection, defaulter blocking
- **Reliable** - USB connection, error handling
- **User-friendly** - Simple admin interface
- **Maintainable** - Well-documented, modular code

---

## Additional Resources

- `README.md` - General project documentation
- `NFC_GATE_USB_SETUP.md` - Detailed hardware setup
- `database/add_nfc_columns.sql` - Database migration
- `database/add_system_license.sql` - License table creation
- `esp32_nfc_gate_usb.ino` - ESP32 Arduino code
- `gate_bridge.py` - Python bridge script

---

**Last Updated:** 2024
**Version:** 2.0 (with NFC Gate Automation & License System)

