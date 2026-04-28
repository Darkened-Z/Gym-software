# Gym Management System

A comprehensive gym management system with gender-aware member management, built with PHP, MySQL, and vanilla JavaScript.

## Features

- **Authentication System**
  - Admin login
  - Member login using unique member code (Ac_No)
  - Secure session management

- **Admin Dashboard**
  - Responsive sidebar navigation
  - Dashboard with statistics
  - Gender-aware member management (Men/Women)
  - Attendance tracking
  - Payment management
  - Reports section
  - Excel member import

- **Member Portal**
  - Separate portals for men and women members
  - Member lookup bar for quick access
  - Profile display with profile picture
  - Interactive attendance calendar (highlights absent days in red)
  - Fee payment history

- **Excel Import**
  - Import members from Excel files (.xls, .xlsx, .csv)
  - Gender selection for import
  - Automatic data mapping and validation
  - Duplicate detection

- **NFC Gate Automation** 🆕
  - NFC-based automatic check-in and check-out with toggle logic
  - Single PN532 NFC reader integration with ESP32
  - WiFi connection (no USB bridge required)
  - Single gate motor (opens for both check-in and check-out)
  - Automatic toggle: If not checked in → Check-in, If checked in → Check-out
  - Fee defaulter detection (blocks gate access)
  - Admin force-open gate controls
  - Real-time attendance marking
  - NFC card assignment to members

## Technology Stack

- **Backend**: PHP 7.4+ (Object-oriented, PDO)
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Database**: MySQL
- **Dependencies**: PHPSpreadsheet (via Composer)

## Installation

### For Local Development (XAMPP/WAMP)

1. **Clone or extract the project** to your web server directory (e.g., `htdocs` for XAMPP)

2. **Create the database**:
   ```sql
   CREATE DATABASE gym_management;
   ```

3. **Import the database schema**:
   ```bash
   mysql -u root -p gym_management < database/schema.sql
   ```
   Or use phpMyAdmin to import `database/schema.sql`

4. **Configure database connection**:
   Edit `config/database.php` and update:
   - `$host` (default: `localhost`)
   - `$db_name` (default: `gym_management`)
   - `$username` (default: `root`)
   - `$password` (default: empty)

### For Web Hosting

Configure hosting credentials through your `.env` file or server environment variables.
Do not commit real production credentials to the repository.

See `HOSTING_SETUP.md` for detailed hosting instructions.

5. **Install PHP dependencies**:
   ```bash
   composer install
   ```
   If you don't have Composer, download it from [getcomposer.org](https://getcomposer.org/)

6. **Set permissions** (Linux/Mac):
   ```bash
   chmod -R 755 uploads/
   chmod -R 755 logs/
   ```

7. **Access the application**:
   - Open `http://localhost/gym-management-system/` in your browser
   - Create or reset an admin user before production use

## Project Structure

```
gym-management-system/
├── api/                    # API endpoints
│   ├── auth.php           # Authentication API
│   ├── members.php        # Member management API
│   ├── member-profile.php # Member profile API
│   ├── attendance.php     # Attendance API
│   ├── payments.php       # Payments API
│   ├── dashboard.php      # Dashboard API
│   ├── import.php         # Excel import API
│   └── controllers/       # Controller classes
├── app/                    # Application logic
│   └── models/            # Data models
├── assets/                 # Frontend assets
│   ├── css/               # Stylesheets
│   └── js/                # JavaScript files
├── config/                 # Configuration files
├── database/               # Database schema
├── uploads/                # Uploaded files
│   ├── profiles/          # Profile images
│   └── imports/           # Temporary import files
├── logs/                   # Error logs
├── index.html             # Login page
├── admin-dashboard.html   # Admin dashboard
├── member-profile-men.html # Men member portal
├── member-profile-women.html # Women member portal
├── composer.json          # PHP dependencies
└── README.md              # This file
```

## Database Schema

The system uses separate tables for men and women members:

- `users` - Admin users
- `members_men` - Men members (includes `nfc_uid` and `is_checked_in` fields)
- `members_women` - Women members (includes `nfc_uid` and `is_checked_in` fields)
- `attendance_men` - Men attendance records
- `attendance_women` - Women attendance records
- `payments_men` - Men payment records
- `payments_women` - Women payment records
- `reports` - System reports
- `gate_commands` - Force-open gate commands for ESP32 polling
- `system_license` - System activation/license key (prevents unauthorized distribution)

## Excel Import Format

The Excel import expects the following columns:

**Required:**
- `Ac_No` (or `acno`, `member_code`, `code`) - Member code (unique)
- `Ac_Name` (or `acname`, `name`, `member_name`) - Full name (single field)
- `Mobile` (or `phone`, `contact`) - Phone number (unique)

**Optional:**
- `Address` (or `addr`) - Member address
- `Admission_Date` (or `admissiondate`, `join_date`, `joindate`) - Join date (Excel date format)
- `Admission_fee` (or `admissionfee`) - Admission fee amount
- `Monthly_fee` (or `monthlyfee`, `fee`) - Monthly fee amount
- `locker_fee` (or `lockerfee`) - Locker fee amount
- `enable_disable` (or `enabledisable`, `status`) - Status (Enable/Disable or Active/Inactive)

See `EXCEL_IMPORT_GUIDE.md` for detailed import instructions.

## NFC Gate Automation System

### Overview

The system includes NFC-based gate automation for automatic check-in and check-out. Members can use NFC cards to access the gym gates, and the system automatically records attendance and manages access based on fee payment status.

### Hardware Requirements

- **ESP32 Development Board** (e.g., ESP32 DevKit)
- **PN532 NFC Module** (SPI mode)
- **1x Relay Module** (for controlling gate motor)
- **Gate Motor** (single motor for both check-in and check-out)
- **WiFi Network** (ESP32 connects directly via WiFi, no USB bridge needed)

### Setup Instructions

#### 1. Database Migration

Run the NFC migration script to add required columns:

```sql
-- Run in phpMyAdmin or MySQL command line
SOURCE database/add_nfc_columns.sql;
```

Or manually run:
```sql
ALTER TABLE members_men ADD COLUMN nfc_uid VARCHAR(50) UNIQUE NULL AFTER phone;
ALTER TABLE members_men ADD COLUMN is_checked_in TINYINT(1) DEFAULT 0 NOT NULL AFTER status;
ALTER TABLE members_women ADD COLUMN nfc_uid VARCHAR(50) UNIQUE NULL AFTER phone;
ALTER TABLE members_women ADD COLUMN is_checked_in TINYINT(1) DEFAULT 0 NOT NULL AFTER status;
```

#### 2. ESP32 Configuration

1. **Install Arduino IDE** and ESP32 board support
2. **Install Required Libraries**:
   - Adafruit PN532 (via Library Manager)
   - WiFi (built-in)
   - HTTPClient (built-in)

3. **Configure ESP32 Code**:
   - Open `esp32_nfc_gate.ino` in Arduino IDE
   - Update WiFi credentials:
     ```cpp
     const char* ssid = "YOUR_WIFI_SSID";
     const char* password = "YOUR_WIFI_PASSWORD";
     ```
   - Update server URL:
     ```cpp
     const char* serverURL = "https://chocolate-wasp-405221.hostingersite.com";
     ```

4. **Hardware Connections**:
   - **PN532 (SPI Mode)** - Single NFC Reader:
     - VCC → 3.3V
     - GND → GND
     - SDA (MOSI) → GPIO 23
     - SCL (SCK) → GPIO 18
     - MISO → GPIO 19
     - SS (CS) → GPIO 5
   
   - **Relay (Gate Motor)** - Single Relay:
     - IN → GPIO 2
     - VCC → 5V
     - GND → GND

5. **Upload Code** to ESP32

#### 3. Assign NFC Cards to Members

1. Log in to Admin Dashboard
2. Go to **Members** section
3. Click **Edit** on a member
4. Enter the **NFC Card UID** in the "NFC Card UID" field
5. Save the member

**To get NFC UID:**
- Scan the card with the ESP32 (it will display the UID in Serial Monitor)
- Or use an NFC reader app on your phone

### How It Works

#### Toggle Logic (Single Scanner):
The system uses a single NFC scanner with automatic toggle logic:

1. **Member scans NFC card** at the gate
2. **ESP32 reads UID** and sends request to `api/gate.php?uid=XXX&type=toggle`
3. **System checks `is_checked_in` status**:
   - If `is_checked_in = 0` (not checked in):
     - System validates member (exists, active, not a fee defaulter)
     - If valid: **Check-in** → Gate opens → `is_checked_in` set to 1 → Attendance marked
   - If `is_checked_in = 1` (already checked in):
     - **Check-out** → Gate opens → `is_checked_in` set to 0 → Check-out time recorded
4. **Gate opens for 3 seconds** (same motor for both actions)
5. If invalid (member not found, inactive, or fee defaulter):
   - Gate remains closed
   - Error message returned

**Key Benefits:**
- Single scanner handles both check-in and check-out
- No need to remember which gate to use
- Automatic state management
- Simpler hardware setup

#### Admin Force-Open:
1. Admin clicks "Force Open Check-In Gate" or "Force Open Check-Out Gate" in dashboard
2. Command is stored in `gate_commands` table
3. ESP32 polls every 1 second: `api/gate.php?type=poll`
4. When command is received, gate opens immediately
5. Command is marked as executed

### API Endpoints

- `GET /api/gate.php?uid=XXX&type=checkin` - Process check-in
- `GET /api/gate.php?uid=XXX&type=checkout` - Process check-out
- `GET /api/gate.php?type=force_open&gate=checkin` - Admin force-open check-in gate
- `GET /api/gate.php?type=force_open&gate=checkout` - Admin force-open check-out gate
- `GET /api/gate.php?type=poll` - ESP32 polls for force-open commands

### Troubleshooting

- **Gate not opening**: Check ESP32 Serial Monitor for error messages
- **NFC not reading**: Verify PN532 wiring and power supply
- **WiFi connection issues**: Check credentials and signal strength
- **Access denied**: Verify member has NFC card assigned and is not a defaulter

## System Activation (License Protection)

⚠️ **IMPORTANT**: Before using the system, you must run `setup.php` to activate it.

The system includes a license protection mechanism that prevents unauthorized distribution:

1. **Run Setup**: Access `setup.php` via browser or CLI
2. **System Activation**: A unique license key is generated based on your server
3. **Admin Creation**: Only works after system activation
4. **Security**: Without activation, no admin accounts can be created or used

This ensures the software cannot be redistributed without proper authorization.

## Security Notes

- Default admin password should be changed in production
- Implement proper password hashing (bcrypt is used)
- Ensure proper file upload validation
- Use HTTPS in production
- Regularly update dependencies
- **Run `setup.php` once** to activate the system (prevents unauthorized distribution)

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## License

This project is provided as-is for educational and commercial use.

## Support

For issues or questions, please refer to the project documentation or contact the development team.

