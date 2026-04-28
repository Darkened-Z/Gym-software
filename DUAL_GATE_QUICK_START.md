# Dual-Gate RFID System - Quick Start Guide

## 📦 What You Have

### Files Created
1. **Database Migration**: `database/migrate_to_dual_gate_rfid.sql`
2. **Updated API**: `api/gate.php` (complete rewrite)
3. **Entry Gate Code**: `esp32/entry_gate/entry_gate.ino`
4. **Exit Gate Code**: `esp32/exit_gate/exit_gate.ino`
5. **Documentation**: Implementation plan & walkthrough

---

## 🚀 Quick Deployment (5 Steps)

### Step 1: Database Migration (5 minutes)
```bash
# Navigate to project
cd c:\xampp\htdocs\gym-management

# Run migration
mysql -u root -p gym_management < database\migrate_to_dual_gate_rfid.sql
```

**What it does**:
- Adds `rfid_uid` column to members tables
- Adds gate tracking to attendance tables
- Creates `gate_activity_log` for security
- Creates `gate_configuration` for gate settings

### Step 2: API is Already Updated ✅
The `api/gate.php` file has been completely rewritten with new endpoints:
- **Entry**: `/api/gate.php?type=entry&rfid_uid=XXX&gate_id=ENTRY_01`
- **Exit**: `/api/gate.php?type=exit&rfid_uid=XXX&gate_id=EXIT_01`

**No action needed** - file is ready!

### Step 3: Upload ESP32 Code (15 minutes per gate)

**Required Arduino Libraries**:
- MFRC522 (by GithubCommunity)
- WiFi (built-in)
- HTTPClient (built-in)

**For Entry Gate**:
1. Open `esp32/entry_gate/entry_gate.ino` in Arduino IDE
2. Update configuration (lines 24-27):
   ```cpp
   const char* WIFI_SSID = "Your_WiFi_SSID";
   const char* WIFI_PASSWORD = "Your_WiFi_Password";
   const char* SERVER_URL = "http://YOUR_SERVER_IP/gym-management/api/gate.php";
   const char* GATE_ID = "ENTRY_01";
   ```
3. Select board: "ESP32 Dev Module"
4. Upload to ESP32

**For Exit Gate**:
1. Open `esp32/exit_gate/exit_gate.ino`
2. Update same configuration (change GATE_ID to "EXIT_01")
3. Upload to second ESP32

### Step 4: Hardware Wiring (30 minutes per gate)

**RC522 to ESP32**:
```
RC522 Pin → ESP32 Pin
SDA (SS)  → GPIO 5
SCK       → GPIO 18
MOSI      → GPIO 23
MISO      → GPIO 19
RST       → GPIO 22
3.3V      → 3.3V
GND       → GND
```

**Relay to ESP32**:
```
Relay Pin → ESP32 Pin
IN        → GPIO 4
VCC       → 5V
GND       → GND
```

**Relay to Gate Motor**:
```
Relay COM → Motor positive
Relay NO  → Power supply positive
Motor negative → Power supply negative
```

### Step 5: Assign RFID Cards (Ongoing)

**Manual Method** (temporary):
```sql
-- Update member with RFID card UID
UPDATE members_men 
SET rfid_uid = 'CARD_UID_HERE', rfid_assigned_date = NOW() 
WHERE member_code = 'M001';
```

**Reading RFID UID**:
- Scan card at entry gate
- Check ESP32 Serial Monitor (115200 baud)
- Copy the UID displayed (e.g., "04A1B2C3")

---

## 🧪 Testing (15 minutes)

### Test 1: Entry Gate - Valid Member
1. Scan a member's RFID card at entry gate
2. **Expected**: Gate opens for 3 seconds
3. **Serial Monitor**: "✓ ACCESS GRANTED"
4. **Check database**: `SELECT * FROM attendance_men ORDER BY id DESC LIMIT 1;`

### Test 2: Entry Gate - Fee Defaulter
1. Set a member's `total_due_amount > 0`
2. Scan their card
3. **Expected**: Gate does NOT open
4. **Serial Monitor**: "✗ ACCESS DENIED - Fee defaulter"

### Test 3: Exit Gate - Checked-in Member
1. Check in a member at entry gate
2. Scan same card at exit gate
3. **Expected**: Gate opens, duration calculated
4. **Serial Monitor**: "✓ ACCESS GRANTED - Goodbye, [Name]!"

### Test 4: Exit Gate - Not Checked In
1. Scan a card that hasn't checked in
2. **Expected**: Gate denied
3. **Serial Monitor**: "✗ ACCESS DENIED - Not checked in"

---

## 🔧 Configuration Options

### Change Gate Open Duration
In ESP32 code (both entry and exit):
```cpp
const int GATE_OPEN_DURATION = 3000;  // Change to 5000 for 5 seconds
```

### Change Server URL
If your server IP changes, update in ESP32 code:
```cpp
const char* SERVER_URL = "http://NEW_IP/gym-management/api/gate.php";
```

### Change Gate IDs
If you want different gate identifiers:
```cpp
const char* GATE_ID = "MAIN_ENTRY";  // Or "SIDE_ENTRY", "VIP_EXIT", etc.
```

---

## 📊 Monitoring

### View Recent Gate Activity
```sql
SELECT 
    created_at,
    gate_type,
    member_name,
    action,
    status,
    reason
FROM gate_activity_log 
ORDER BY created_at DESC 
LIMIT 20;
```

### View Today's Attendance
```sql
SELECT 
    a.*,
    m.member_code,
    m.name
FROM attendance_men a
JOIN members_men m ON a.member_id = m.id
WHERE DATE(a.check_in) = CURDATE()
ORDER BY a.check_in DESC;
```

### Check Members Currently Inside
```sql
SELECT member_code, name, is_checked_in 
FROM members_men 
WHERE is_checked_in = 1
UNION ALL
SELECT member_code, name, is_checked_in 
FROM members_women 
WHERE is_checked_in = 1;
```

---

## 🐛 Troubleshooting

### Problem: ESP32 won't connect to WiFi
**Solution**:
- Verify WiFi credentials
- Check if WiFi is 2.4GHz (ESP32 doesn't support 5GHz)
- Try moving ESP32 closer to router

### Problem: RFID reader not detecting cards
**Solution**:
- Check wiring (especially 3.3V and GND)
- Verify SPI pins are correct
- Try different RFID cards
- Check Serial Monitor for RFID version info

### Problem: Gate opens for fee defaulter
**Solution**:
- Check `total_due_amount` in database
- Verify API response in Serial Monitor
- Check `gate_activity_log` table for details

### Problem: Database connection error
**Solution**:
```sql
-- Verify migration ran successfully
SHOW COLUMNS FROM members_men LIKE 'rfid_uid';
SHOW TABLES LIKE 'gate_activity_log';
```

---

## 📚 Documentation Files

1. **Implementation Plan**: `dual_gate_implementation_plan.md` (50+ pages)
   - Complete technical specification
   - Business logic flows
   - Hardware wiring diagrams
   - API endpoint details

2. **Walkthrough**: `walkthrough.md`
   - What was implemented
   - Testing results
   - Deployment guide
   - Performance metrics

3. **System Logic Documentation**: `system_logic_documentation.md`
   - All models and functions
   - API endpoints
   - JavaScript functions
   - Business rules

---

## ✅ Deployment Checklist

- [ ] Run database migration SQL
- [ ] Test API endpoints with Postman
- [ ] Wire entry gate hardware
- [ ] Wire exit gate hardware
- [ ] Upload entry ESP32 code
- [ ] Upload exit ESP32 code
- [ ] Test with valid member
- [ ] Test with fee defaulter
- [ ] Test exit without check-in
- [ ] Assign RFID cards to all members
- [ ] Train staff on new system
- [ ] Monitor for 24 hours

---

## 🎯 Key Features

✅ **Separate Entry/Exit Gates** - No confusion  
✅ **Fee Defaulter Blocking** - Automatic at entry  
✅ **First Entry Detection** - Tracks daily first visits  
✅ **Duration Tracking** - Shows workout time  
✅ **Activity Logging** - Complete audit trail  
✅ **Re-entry Support** - Members can re-enter easily  

---

## 💡 Tips

1. **RFID Card Assignment**: Scan card at entry gate and note UID from Serial Monitor
2. **Serial Monitor**: Keep it open during testing (115200 baud rate)
3. **Gate Activity Log**: Check regularly for security/debugging
4. **Backup**: Always backup database before migration
5. **Testing**: Test with dummy cards before deploying to members

---

## 📞 Support

For issues or questions:
1. Check Serial Monitor for error messages
2. Review `gate_activity_log` table
3. Check ESP32 WiFi connection
4. Verify database migration completed

---

**Version**: 2.0.0 (Dual-Gate RFID System)  
**Last Updated**: 2025-12-16  
**Status**: Production Ready ✅
