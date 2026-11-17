# GYM MANAGEMENT SYSTEM — COMPLETE PROJECT HANDOVER MASTER GUIDE
**Confidential & Comprehensive Handover Documentation for Client, Developers & System Administrators**  
*Document Version: 3.0.0 (Production Handover Edition) | Date: November 2026*

---

## 📑 TABLE OF CONTENTS
1. [Executive Summary & System Architecture](#1-executive-summary--system-architecture)
2. [Live Production Environments & Cloud Hosting](#2-live-production-environments--cloud-hosting)
3. [VPS Server & Infrastructure Architecture](#3-vps-server--infrastructure-architecture)
4. [Database Architecture & Working Deep Dive](#4-database-architecture--working-deep-dive)
5. [Core Application Modules & Operational Workflows](#5-core-application-modules--operational-workflows)
   - [Workflow 1: Member Registration & Card Assignment](#workflow-1-member-registration--card-assignment)
   - [Workflow 2: Daily Attendance & Turnstile Gate Control](#workflow-2-daily-attendance--turnstile-gate-control)
   - [Workflow 3: Fee Billing, Dues & Payment Invoicing](#workflow-3-fee-billing-dues--payment-invoicing)
   - [Workflow 4: Apex Coach — Trainer & Roster Management](#workflow-4-apex-coach--trainer--roster-management)
   - [Workflow 5: Apex Fit — AI Nutrition & Workout Coaching](#workflow-5-apex-fit--ai-nutrition--workout-coaching)
   - [Workflow 6: Financial Accounting & Expense Tracking](#workflow-6-financial-accounting--expense-tracking)
   - [Workflow 7: WhatsApp Automated Messaging Engine](#workflow-7-whatsapp-automated-messaging-engine)
6. [Hardware & Gate Integration Workflows](#6-hardware--gate-integration-workflows)
   - [ZKTeco F22 Biometric Terminal Integration](#zkteco-f22-biometric-terminal-integration)
   - [ESP32 NFC/RFID Turnstile Gate Controller](#esp32-nfcrfid-turnstile-gate-controller)
7. [Deployment, Operations & Multi-Tenant Management](#7-deployment-operations--multi-tenant-management)
   - [Shipping Code Updates Across All Gyms](#shipping-code-updates-across-all-gyms)
   - [Provisioning a New Gym Tenant](#provisioning-a-new-gym-tenant)
   - [Deprovisioning / Archiving a Tenant](#deprovisioning--archiving-a-tenant)
   - [Automated Daily Backups & Disaster Recovery](#automated-daily-backups--disaster-recovery)
8. [Standalone / Offline Client Deployment Guide](#8-standalone--offline-client-deployment-guide)
9. [Master Credentials, Secrets & Port Matrix](#9-master-credentials-secrets--port-matrix)
10. [Troubleshooting & Emergency Maintenance Playbook](#10-troubleshooting--emergency-maintenance-playbook)

---

## 1. Executive Summary & System Architecture

### 1.1 What the System Is
The **Gym Management System** is an enterprise-grade, high-performance gym management platform tailored for gyms, fitness clubs, and multi-branch gym networks. It bridges modern web-based management, offline progressive web app (PWA) reliability, biometrics/RFID hardware automation, AI-driven coaching, and automated WhatsApp communication into a unified platform.

### 1.2 Technology Stack
* **Backend:** PHP 8.2+ (FastCGI / PHP-FPM, PSR-4 structure, native PDO MySQL prepared statements, zero external framework overhead for ultra-low latency).
* **Database:** Percona Server for MySQL 8.4 LTS / MySQL 8.0+ (InnoDB, UTF-8 MB4, indexed relations, foreign key constraints, JSON columns).
* **Frontend:** High-performance responsive Single Page UI (HTML5, Vanilla ES6+ JavaScript, Tailwind CSS utility classes, Feather Icons, Chart.js, HTML5 Canvas QR/Barcode engines).
* **Web Server:** Nginx (Reverse proxy, HTTP/2, gzip compression, micro-caching for static assets, strict security headers).
* **Biometrics & Hardware:** ZKTeco F22 (ADMS push protocol via `iclock.php` + Python Bridge daemon) & ESP32 Microcontrollers (PN532/RC522 NFC/RFID readers with relay triggers).
* **WhatsApp Automation:** Node.js Baileys service daemon with database message queue polling and delivery logging.
* **Server Hosting:** Ubuntu 24.04 LTS VPS with CloudPanel & systemd daemon managers.

### 1.3 High-Level System Architecture

```
                                  [ INTERNET / LAN ]
                                           │
                ┌──────────────────────────┼──────────────────────────┐
                ▼                          ▼                          ▼
      [ Admin & Staff Portal ]    [ Member Search Kiosk ]    [ Member AI Profiles ]
        (admin-dashboard)              (index.html)         (member-profile-men/women)
                │                          │                          │
                └──────────────────────────┼──────────────────────────┘
                                           │ HTTPS (Nginx Proxy)
                                           ▼
                                ┌──────────────────────┐
                                │   PHP 8.2 API Core   │
                                │   (/api/*.php)       │
                                └──────────┬───────────┘
                                           │
     ┌──────────────────────┬──────────────┴─────────────┬─────────────────────┐
     ▼                      ▼                            ▼                     ▼
[ Auth & Staff ]     [ Members & Dues ]          [ Apex Coach & Fit ]    [ Attendance Core ]
  users table         members_men/women            trainers table          attendance_men/women
  system_license      payments_men/women           member_fitness          gate_configuration
                            │                            │                     ▲
                            │                            │                     │
                            └──────────────┬─────────────┘                     │
                                           ▼                                   │
                                 [ MySQL Database Engine ]                     │
                                (gym_bhatti / gym_kl / gym_02)                 │
                                           │                                   │
                ┌──────────────────────────┴──────────────────────────┐        │
                ▼                                                     ▼        │
   [ ZKTeco F22 Biometrics ]                                 [ ESP32 Gate Controller ]
   Push protocol / iclock.php                                Relay / NFC RFID Reader
```

---

## 2. Live Production Environments & Cloud Hosting

The platform is deployed in a **Multi-Install Architecture** where each physical gym has its own isolated subdomain, independent codebase instance, and completely separate MySQL database. This guarantees zero data leakage between gym owners while sharing one high-performance server.

### Active Production Sites
| Gym / Branch Name | Subdomain URL | Server Path | Database Name | Active Features |
|---|---|---|---|---|
| **Bhatti Gym (Main)** | `https://bhatti.gym.goxx.app` | `/var/www/bhatti.gym.goxx.app` | `gym_bhatti` | Full Suite, Apex Fit, Trainers, F22 Push |
| **KL Gym** | `https://kl.gym.goxx.app` | `/var/www/kl.gym.goxx.app` | `gym_kl` | Full Suite, Apex Fit, Trainers, F22 Push |
| **02 Gym** | `https://02.gym.goxx.app` | `/var/www/02.gym.goxx.app` | `gym_02` | Full Suite, Apex Fit, Trainers, Dual Section |
| **CloudPanel Admin** | `https://76.13.213.251:8443` | `/home/clp/htdocs/app` | `db.sq3` | Server Management, Nginx, PHP, SSL |

### Default Production Credentials
* **Admin Dashboard URL:** `https://<slug>.gym.goxx.app/admin-dashboard.html`
* **Default Admin Username:** `admin`
* **Default Admin Password:** `admin123` *(Must be changed after handover in Settings)*
* **Member Public Kiosk:** `https://<slug>.gym.goxx.app/index.html`

---

## 3. VPS Server & Infrastructure Architecture

### 3.1 Server Specifications
* **Public IPv4:** `76.13.213.251`
* **Operating System:** Ubuntu 24.04 LTS (x86_64)
* **Hosting Platform:** Oracle Cloud Infrastructure / Custom VPS
* **Web Server:** Nginx 1.24+ (managed with CloudPanel + custom vhosts)
* **Database Engine:** Percona Server for MySQL 8.4 LTS
* **PHP Runtime:** PHP 8.2-FPM (unix socket: `/var/run/php/php8.2-fpm.sock`)

### 3.2 SSH Remote Access
```bash
# Connect using the configured SSH host alias:
ssh gym-vps

# Or connect directly via IP using the SSH key:
ssh -i ~/.ssh/id_rsa_vps root@76.13.213.251
```
* **SSH Port:** `22` (Standard)
* **Authorized Keys:** `C:/Users/7eesh/.ssh/id_rsa_vps` and `gym-saas.key`
* **Root Login:** Enabled via public key authentication.

### 3.3 Directory Layout on VPS
```
/var/www/
├── bhatti.gym.goxx.app/        # Bhatti Gym production web root
│   ├── .env                    # Bhatti environment config
│   ├── api/                    # API endpoints
│   ├── app/                    # Models and business logic
│   ├── assets/                 # CSS, JS, branding images
│   ├── database/               # SQL migrations
│   └── uploads/                # Member profile photos
├── kl.gym.goxx.app/            # KL Gym production web root
├── 02.gym.goxx.app/            # 02 Gym production web root
└── ...

/etc/gym-deploy/
├── nginx-vhost.conf.template   # Standard vhost template for new gyms
└── credentials/                # Stored provisioning records

/usr/local/bin/
├── provision-gym.sh            # One-click provisioning of new gyms
├── deprovision-gym.sh          # Safe deletion & backup script
├── update-all-gyms.sh          # Global git pull updater
└── backup-all-gyms.sh          # Daily MySQL backup runner
```

---

## 4. Database Architecture & Working Deep Dive

The database architecture is designed with **dual-section segregation** (Men and Women sections), strict index optimizations on all foreign keys and search fields (phone, member_code, NFC/RFID UID), and atomic transactions for fee collections.

### 4.1 Database Credentials
* **MySQL Root User:** `root`
* **MySQL Root Password:** `e1vXvT9UnnYUZ2ok`
* **Port:** `3306` (Bound to `127.0.0.1` for security)

#### Per-Tenant Database User Matrix
| Database Name | MySQL User | MySQL Password | Host |
|---|---|---|---|
| `gym_bhatti` | `gym_bhatti` | `ozKPLSW4q1nWVwgJGnxNOWf1Qh0SlnO` | `localhost` |
| `gym_kl` | `gym_kl` | `7r9WP9aRjDGnug8wqYv7ZO51eW0iUA6` | `localhost` |
| `gym_02` | `gym_02` | `41ccedee790fe00d94942ad7e1ac0f5416ad` | `localhost` |

### 4.2 Comprehensive Database Schema Dictionary

#### 1. `users` (Staff & Admin Accounts)
Stores administrative staff and receptionist accounts.
* `id` (INT PK): Unique identifier.
* `username` (VARCHAR 50 UNIQUE): Login handle.
* `password` (VARCHAR 255): Blowfish/BCrypt password hash (`$2y$10$...`).
* `role` (VARCHAR 50): Access level (`admin`, `staff`, `manager`).
* `staff_section` (ENUM: `men`, `women`, `both`): Controls which section staff can view/edit.

#### 2. `members_men` & `members_women` (Member Records)
Split by gender for strict physical and cultural segregation.
* `id` (INT PK): Internal numeric identity.
* `member_code` (VARCHAR 50 UNIQUE): Display code (e.g., `M1`, `M8810`, `W102`).
* `name` (VARCHAR 200): Full name of the gym member.
* `phone` (VARCHAR 20 UNIQUE): Mobile number (used for WhatsApp & search).
* `cnic` / `email` / `address`: Personal demographics.
* `membership_type` (VARCHAR 50): Active tier (`Basic`, `Standard`, `VIP`, `Trainer Package`).
* `join_date` (DATE): Enrollment date.
* `admission_fee` / `monthly_fee` / `locker_fee` (DECIMAL 10,2): Billing parameters.
* `next_fee_due_date` (DATE): Expiry / renewal billing cycle threshold.
* `total_due_amount` (DECIMAL 10,2): Outstanding debt balance.
* `nfc_uid` (VARCHAR 20 UNIQUE): RFID/NFC card Hex serial number (e.g. `1A2B3C4D`).
* `assigned_trainer_id` (INT NULL): Foreign key linking to `trainers.id`.
* `status` (ENUM: `active`, `inactive`): Account status.
* `is_checked_in` (TINYINT 1): 1 if member is currently inside gym, 0 if checked out.

#### 3. `attendance_men` & `attendance_women` (Access Logs)
* `id` (INT PK): Log entry sequence.
* `member_id` (INT FK): Links to member table.
* `check_in` (DATETIME): Check-in timestamp.
* `check_out` (DATETIME NULL): Check-out timestamp.
* `duration_minutes` (INT NULL): Total workout duration in minutes.
* `entry_gate_id` / `exit_gate_id`: Turnstile identifier (`ENTRY_01`, `F22_MAIN`).
* `write_signature` (VARCHAR 128): Anti-passback verification hash.

#### 4. `payments_men` & `payments_women` (Financial Invoices)
* `id` (INT PK): Receipt sequence number.
* `member_id` (INT FK): Links to member.
* `amount_paid` (DECIMAL 10,2): Amount collected.
* `discount` (DECIMAL 10,2): Concession granted.
* `payment_method` (ENUM: `cash`, `bank_transfer`, `online`, `card`).
* `payment_date` (DATE): Transaction date.
* `receipt_number` (VARCHAR 50 UNIQUE): Official audit reference.
* `remaining_due` (DECIMAL 10,2): Debt remaining after this receipt.

#### 5. `trainers` (Personal Coaches & Instructors)
* `id` (INT PK): Trainer identity.
* `trainer_code` (VARCHAR 50 UNIQUE): Display code (e.g. `TR-01`).
* `name` (VARCHAR 200): Trainer's full name.
* `phone` (VARCHAR 20 UNIQUE): Mobile number.
* `section` (ENUM: `men`, `women`, `both`): Working department.
* `monthly_salary` (DECIMAL 10,2): Fixed monthly payroll base.
* `commission_pct` (DECIMAL 5,2): Commission percentage earned on client fees.
* `status` (ENUM: `active`, `inactive`): Employment status.

#### 6. `member_fitness` (Apex Fit AI Coaching Data)
* `id` (INT PK): Fitness record id.
* `gender` (ENUM: `men`, `women`) + `member_id` (INT) -> UNIQUE KEY.
* `height` (DECIMAL 5,2): Height in centimeters.
* `target_weight` (DECIMAL 5,2): Target goal weight in kg.
* `goal` (ENUM: `lose`, `gain`, `maintain`): Fitness objective.
* `vegetarian` (TINYINT 1): Dietary restriction.
* `allergies` / `injuries` (TEXT): Safety constraints.
* `experience` (ENUM: `novice`, `intermediate`, `advanced`): Training level.
* `progress_json` (LONGTEXT): Historical body measurements array.
* `logs_json` (LONGTEXT): Session workout performance logs.
* `meals_json` (LONGTEXT): Dietary adherence logs.

#### 7. Other Supporting Tables
* `packages`: Preconfigured fee structures (e.g., 1 Month, 3 Months, 1 Year).
* `expenses`: Overhead expenses (Rent, Electricity, Equipment repairs, Staff salaries).
* `gate_configuration` & `gate_cooldown`: Gate relay trigger timeouts and anti-passback windows (default 300 seconds).
* `system_license`: Hardware-bound license and subscription tracking.
* `message_queue` & `message_logs`: WhatsApp outgoing queue and delivery records.

---

## 5. Core Application Modules & Operational Workflows

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          OPERATIONAL WORKFLOW MAP                           │
└─────────────────────────────────────────────────────────────────────────────┘

 [1. Member Enrolls] ────────► [2. Package & Trainer Assigned] ────────► [3. Initial Fee Paid]
           │                                                                    │
           ▼                                                                    ▼
 [4. RFID / Fingerprint Issued]                                        [Receipt Printed]
           │
           ▼
 [5. Daily Workout] ─────────► [Scans at Turnstile Gate] ────────────► [Anti-Passback OK]
                                        │                                       │
                                        ▼                                       ▼
                               [If Dues Overdue: Alert]               [Gate Unlocks / Logs]
                                                                                │
                                                                                ▼
 [6. Member Profile] ◄──────── [7. AI Coach Generates Routine] ◄────── [Check-out Logged]
```

### Workflow 1: Member Registration & Card Assignment
1. Staff opens **Admin Dashboard** -> **Members** -> **Add New Member**.
2. Selects Gender Section (**Men** or **Women**).
3. Inputs Member Name, Mobile Number, CNIC, and Join Date.
4. Selects **Package** (e.g., *Basic Monthly - PKR 3,000*). Admission fee and monthly fee auto-populate.
5. *(Optional)* Selects **Assigned Trainer** from the trainer roster.
6. **Card Assignment:**
   - Tap an RFID card onto the desktop USB reader or ESP32 scanner.
   - The hexadecimal UID appears in the **RFID UID** field.
7. Click **Save Member**.
   - System automatically generates a sequential `member_code` (`M101`).
   - Generates an initial pending due for the monthly fee.

### Workflow 2: Daily Attendance & Turnstile Gate Control
1. **Automated Walk-In (RFID / Biometrics):**
   - Member scans their RFID card or places finger on ZKTeco F22.
   - The device sends the UID/ID to `/api/attendance.php?action=check_gate_access`.
   - **Verification Checks:**
     1. Does member exist and status == `active`?
     2. Does member belong to this gate's section (Men/Women)?
     3. Anti-passback check: Did member scan within the last 5 minutes? (Prevents handing card back).
     4. Fee due check: Is the member overdue past the configured grace period?
   - If valid: Gate sends relay pulse (3000ms), member walks through, `attendance_men` or `attendance_women` records `check_in` time.
2. **Manual Staff Walk-In (Receptionist Screen):**
   - Receptionist opens `index.html` on desk tablet/PC.
   - Types member name, code, or phone number.
   - Instant search displays member photo, fee due status (Green/Red), and one-click **"Check In"** or **"Check Out"** button.

### Workflow 3: Fee Billing, Dues & Payment Invoicing
1. Member approaches reception to pay fees.
2. Staff clicks **Payments** -> **Collect Fee** (or clicks "Pay Now" on Member's Profile).
3. System shows current due balance (e.g., `PKR 3,500`).
4. Staff enters:
   - **Amount Paid** (e.g., `PKR 3,500`).
   - **Payment Method** (Cash, Bank Transfer, EasyPaisa, JazzCash).
   - Any approved discount.
5. Click **Submit Payment**:
   - `payments_men` / `payments_women` receives a new row with a unique receipt number.
   - `next_fee_due_date` automatically rolls forward by 30 days (or package duration).
   - `total_due_amount` updates in real time.
   - Thermal invoice popup renders ready for 80mm POS receipt printer.
   - An automated WhatsApp confirmation message is queued.

### Workflow 4: Apex Coach — Trainer & Roster Management
1. Inside **Admin Dashboard** -> click **Trainers** in sidebar.
2. **Add Trainer:** Set Trainer Code (`TR-1`), Name, Phone, Monthly Base Salary (`PKR 25,000`), and Commission Percentage (`15%`).
3. **Roster Assignment:**
   - Click **"Assigned Members"** on any trainer row.
   - Search and attach members directly without navigating away.
4. **Payroll Calculation:**
   - Total Monthly Pay = `Base Salary + Sum(Member Fees Collected * Commission %)`.

### Workflow 5: Apex Fit — AI Nutrition & Workout Coaching
1. Open any member profile (`member-profile-men.html?code=M1`).
2. Navigate to the **"Apex Fit AI Coach"** section.
3. Input member's biometric stats:
   - **Height** (cm), **Current Weight** (kg), **Target Weight** (kg).
   - **Primary Goal:** Fat Loss, Hypertrophy (Muscle Gain), or Athletic Maintenance.
   - **Dietary Preference:** Non-Vegetarian / Vegetarian.
   - **Medical Constraints:** Any logged joint injuries or food allergies.
4. Click **"Generate AI Plan"**:
   - The built-in rule engine (`assets/js/apex-ai.js`) computes:
     - **BMR (Basal Metabolic Rate)** using Mifflin-St Jeor formula.
     - **TDEE (Total Daily Energy Expenditure)**.
     - **Target Daily Calories & Hydration (Liters)**.
     - **Daily Macronutrient Split:** Protein (g), Carbohydrates (g), Fats (g).
     - **Custom 4-Day Workout Split:** Day 1: Chest & Triceps, Day 2: Back & Biceps, Day 3: Legs & Core, Day 4: Shoulders & Abs.
5. All routines, logs, and weigh-ins save into `member_fitness` as structured JSON.

### Workflow 6: Financial Accounting & Expense Tracking
1. Inside **Admin Dashboard** -> **Expenses**.
2. Staff logs routine expenses:
   - Category: Rent, Electricity, Janitorial, Equipment Maintenance, Staff Salaries, Supplies.
   - Amount, Date, and Description.
3. **Financial Reports:**
   - Navigating to **Reports** displays:
     - **Gross Revenue:** Total fees collected in period.
     - **Total Expenses:** Sum of overhead.
     - **Net Profit:** `Gross Revenue - Total Expenses`.
     - Daily breakdown graphs rendered with Chart.js.

### Workflow 7: WhatsApp Automated Messaging Engine
1. **Fee Due Reminders:** Runs automatically 3 days before member's `next_fee_due_date`.
2. **Fee Overdue Notices:** Triggers if fee is unpaid 1 day after due date.
3. **Payment Receipt:** Sent immediately upon recording a payment.
4. **Queue & Dispatch:**
   - Outgoing messages write to `message_queue`.
   - Node.js bot daemon (`whatsapp-bot/src/index.js`) monitors the queue and dispatches messages via connected WhatsApp session.
   - Delivery statuses (`queued`, `sent`, `delivered`, `failed`) update in `message_logs`.

---

## 6. Hardware & Gate Integration Workflows

### ZKTeco F22 Biometric Terminal Integration
The ZKTeco F22 device communicates in real-time with the web application using ZKTeco's native **ADMS (Automatic Data Master Server)** HTTP Push protocol.

```
┌─────────────────────┐       HTTP POST Check-in       ┌──────────────────────────┐
│  ZKTeco F22 Device  │ ─────────────────────────────► │ https://<gym>/iclock.php │
│  (Finger / RFID)    │ ◄───────────────────────────── │ (Validates Member & Dues)│
└─────────────────────┘          Return OK / Relay     └──────────────────────────┘
```

#### F22 Device Menu Configuration
1. Press **[ M/OK ]** on F22 keypad -> Enter Admin Menu.
2. Go to **Comm.** -> **Ethernet**:
   - Assign static IP on gym LAN (e.g., `192.168.1.201`, Subnet: `255.255.255.0`, Gateway: `192.168.1.1`).
3. Go to **Comm.** -> **Cloud Server Server (ADMS / Webserver)**:
   - **Server Address:** `76.13.213.251` (or your domain `bhatti.gym.goxx.app`)
   - **Server Port:** `80` (or `443` if using HTTPS)
   - **Push Server URL:** `/iclock.php`
   - **Enable Domain Name:** `ON` (if using hostname)
4. Restart F22. When connected, the globe icon on the top bar turns green.

#### Alternative: Python F22 Local Bridge Daemon
If the gym runs on a local PC without direct internet port-forwarding:
1. Located in `deploy/f22-bridge/`.
2. Edit `config.kl.ini` with local device IP (`192.168.1.201`) and cloud endpoint.
3. Run `3-run-bridge.bat` (or enable `f22-bridge.service` on Windows startup).
4. The daemon connects via pyzk, intercepts fingerprint hits, and forwards them to cloud APIs.

---

### ESP32 NFC/RFID Turnstile Gate Controller
For gyms using motorized turnstiles or magnetic drop-arm barriers:

```
┌─────────────────┐       SPI Bus       ┌───────────────────┐
│ PN532 / RC522   │ ──────────────────► │  ESP32 Micro-     │
│ NFC RFID Reader │                     │  controller       │
└─────────────────┘                     └─────────┬─────────┘
                                                  │ GPIO 4 (Relay Trigger)
                                                  ▼
                                        ┌───────────────────┐
                                        │  5V Relay Module  │ ──► [ Turnstile Barrier ]
                                        └───────────────────┘
```

#### Microcontroller Flashing & Setup
1. Firmware files: `esp32/entry_gate/entry_gate.ino` and `esp32/exit_gate/exit_gate.ino`.
2. Open in Arduino IDE (Install `ESP32` Board definitions + `MFRC522` or `Adafruit_PN532` libraries).
3. Update Wi-Fi SSID, Password, and API target:
   ```cpp
   const char* ssid = "GYM_WIFI";
   const char* password = "GymWifiPassword";
   const char* api_url = "https://bhatti.gym.goxx.app/api/attendance.php?action=gate_scan";
   ```
4. Flash via USB to ESP32 board.
5. On card tap, ESP32 posts UID; if API responds `{"access": true}`, GPIO 4 pulls HIGH for 3000ms, triggering turnstile rotation.

---

## 7. Deployment, Operations & Multi-Tenant Management

### Shipping Code Updates Across All Gyms
When you modify frontend, backend, or style code and push to GitHub, update all live tenants in one step:

```bash
# 1. On your development machine:
git add .
git commit -m "Update feature / bugfix"
git push origin main

# 2. On the VPS (as root):
ssh gym-vps
sudo update-all-gyms.sh
```
*What `update-all-gyms.sh` does automatically:*
- Discovers every tenant folder in `/var/www/*.gym.goxx.app/`.
- Runs `git pull --ff-only` under user `www-data`.
- Re-executes `composer install --no-dev` if dependencies changed.
- Re-applies file ownership permissions (`chown -R www-data:www-data`).

---

### Provisioning a New Gym Tenant
To onboard a new gym client (e.g., "Powerhouse Gym" with slug `powerhouse`):

```bash
ssh gym-vps
sudo provision-gym.sh powerhouse
```
*What `provision-gym.sh` does in under 2 minutes:*
1. Clones the latest application code into `/var/www/powerhouse.gym.goxx.app/`.
2. Creates an isolated MySQL database: `gym_powerhouse`.
3. Creates a unique MySQL user with a secure 24-character random password.
4. Generates the customized `.env` configuration file.
5. Automatically imports all schema tables, default package data, and initial admin account.
6. Generates the Nginx server block with SSL certificate via Let's Encrypt Certbot.
7. Saves all credentials in `/etc/gym-deploy/credentials/powerhouse.gym.goxx.app.txt`.

---

### Deprovisioning / Archiving a Tenant
If a client terminates their subscription:
```bash
ssh gym-vps
sudo deprovision-gym.sh powerhouse
```
*Safeguards:* Creates a complete compressed database dump and file archive inside `/var/backups/gym-deploy/` before safely removing the Nginx vhost and web directory.

---

### Automated Daily Backups & Disaster Recovery

#### Backup Schedule
A daily cron job runs every night at **03:15 AM**:
```bash
# View cron schedule:
sudo crontab -l
# 15 3 * * * /usr/local/bin/backup-all-gyms.sh >> /var/log/gym-backup.log 2>&1
```

#### Backup Storage & Retention
* **Directory:** `/var/backups/gym-deploy/daily/`
* **File Format:** `<database_name>-YYYYMMDD-HHMMSS.sql.gz`
* **Retention Policy:** 7 days rolling retention (backups older than 7 days automatically pruned).

#### Restoring a Database from Backup
To restore a tenant database from a snapshot:
```bash
# Example: Restoring gym_bhatti
gunzip < /var/backups/gym-deploy/daily/gym_bhatti-20261115-031500.sql.gz | mysql -u root -pe1vXvT9UnnYUZ2ok gym_bhatti
```

---

## 8. Standalone / Offline Client Deployment Guide

If a client wants to run the gym software on a single local computer (Windows XAMPP / Laragon) without internet dependency:

### 1. Requirements
* Windows 10/11 or Linux.
* **XAMPP** (PHP 8.0, 8.1, or 8.2 with MySQL).

### 2. Standalone Code Bundle
A clean, standalone ZIP file has already been prepared and packaged on your Desktop:  
`C:\Users\7eesh\Desktop\Gym-Software.zip`

### 3. Step-by-Step Installation
1. Install **XAMPP** and start **Apache** and **MySQL**.
2. Extract `Gym-Software.zip` into `C:\xampp\htdocs\gym\`.
3. Open browser -> Go to `http://localhost/phpmyadmin/`.
4. Create a new database named `gym_management`.
5. Click **Import** -> Select `database/full_setup.sql` from the extracted folder -> Click **Go**.
6. In `C:\xampp\htdocs\gym\`, copy `.env.example` to `.env`:
   ```env
   DB_HOST=localhost
   DB_NAME=gym_management
   DB_USERNAME=root
   DB_PASSWORD=
   APP_ENV=production
   APP_TIMEZONE=Asia/Karachi
   ```
7. Open `http://localhost/gym/admin-dashboard.html` in browser.
8. Login with `admin` / `admin123`.

---

## 9. Master Credentials, Secrets & Port Matrix

| Service / Resource | Host / IP / URL | Username / Identity | Password / Secret Key | Notes |
|---|---|---|---|---|
| **VPS SSH Server** | `76.13.213.251:22` | `root` | Key: `id_rsa_vps` / `gym-saas.key` | Primary server access |
| **CloudPanel Control** | `https://76.13.213.251:8443` | `clp` (Admin) | Saved in CloudPanel vault | Server admin panel |
| **MySQL Root** | `127.0.0.1:3306` | `root` | `e1vXvT9UnnYUZ2ok` | Percona MySQL root |
| **DB: gym_bhatti** | `localhost:3306` | `gym_bhatti` | `ozKPLSW4q1nWVwgJGnxNOWf1Qh0SlnO` | Bhatti Gym database |
| **DB: gym_kl** | `localhost:3306` | `gym_kl` | `7r9WP9aRjDGnug8wqYv7ZO51eW0iUA6` | KL Gym database |
| **DB: gym_02** | `localhost:3306` | `gym_02` | `41ccedee790fe00d94942ad7e1ac0f5416ad` | 02 Gym database |
| **Admin Web Portal** | `https://*.gym.goxx.app` | `admin` | `admin123` | Default app login |
| **F22 Device Key** | N/A | Device Auth | `76f24a245b552f2637e7937fb982296258f331a5fc852d17` | ZKTeco ADMS push token |

---

## 10. Troubleshooting & Emergency Maintenance Playbook

### Issue 1: 502 Bad Gateway on Any Subdomain
* **Cause:** PHP-FPM service crashed or stopped.
* **Fix:**
  ```bash
  ssh gym-vps
  sudo systemctl restart php8.2-fpm
  sudo systemctl restart nginx
  ```

### Issue 2: Database Connection Error (`Access Denied` or `Connection Refused`)
* **Diagnostics:**
  ```bash
  ssh gym-vps
  sudo systemctl status mysql
  ```
* **Fix:** If MySQL stopped, restart it:
  ```bash
  sudo systemctl restart mysql
  ```
* Check `.env` credentials match the tenant database using:
  ```bash
  cat /var/www/<domain>/.env
  ```

### Issue 3: ZKTeco F22 Not Pushing Attendance Records
* **Checklist:**
  1. Ping the F22 IP from the gym network: `ping 192.168.1.201`.
  2. Verify gym router has internet connectivity.
  3. Ensure ADMS Cloud Server setting on F22 points to `76.13.213.251` and port `80`.
  4. Inspect access log on VPS:
     ```bash
     tail -f /var/log/nginx/access.log | grep iclock
     ```

### Issue 4: Resetting Forgotten Admin Password
If the gym manager forgets their admin password:
```bash
# Run the built-in password reset tool on the VPS:
ssh gym-vps
cd /var/www/<gym-subdomain>
php fix-admin-password.php
# This resets user 'admin' back to password: 'admin123'
```

### Issue 5: Changing Brand Logo or Colors
* **Logo image:** Replace `/var/www/<slug>.gym.goxx.app/assets/images/bhatti-logo.png` (or custom logo).
* **Color styles:** Edit `/var/www/<slug>.gym.goxx.app/assets/css/brand.css` (primary accent colors `#d4af37` gold, `#1a1a1a` dark).

---

### Handover Sign-off Checklist
- [x] All 3 live gym installations (`bhatti`, `kl`, `02`) active & verified.
- [x] Apex Fit AI & Apex Coach trainer roster modules active on production databases.
- [x] Automated daily database backup scripts active in crontab.
- [x] All master credentials, MySQL passwords, and SSH keys documented.
- [x] Standalone offline deployment bundle created (`Gym-Software.zip`).

*Master Handover Documentation Complete. All systems operational.*
