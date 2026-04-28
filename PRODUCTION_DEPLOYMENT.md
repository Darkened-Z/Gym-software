# Production Deployment Quick Start Guide

## 🚀 Deployment Steps (30 minutes)

### Step 1: Environment Setup (5 min)

```bash
cd /path/to/gym-management

# Copy environment template  
cp .env.example .env

# Edit configuration
nano .env
```

**Required changes in `.env`**:
```env
DB_HOST=localhost
DB_NAME=gym_management
DB_USERNAME=your_username
DB_PASSWORD=your_password

APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true  # If using HTTPS
```

### Step 2: Database Migration (3 min)

```bash
mysql -u root -p gym_management < database/production_hardening.sql
```

**Verify**:
```bash
mysql -u root -p gym_management -e "SHOW TABLES LIKE 'gate_cooldown';"
# Should return: gate_cooldown

mysql -u root -p gym_management -e "SHOW TABLES LIKE 'admin_action_log';"
# Should return: admin_action_log
```

### Step 3: Permissions (2 min)

```bash
chmod 755 cache/ logs/ uploads/
chmod 600 .env
chown www-data:www-data cache/ logs/ uploads/  # Linux/Apache
```

### Step 4: Test Health Check (1 min)

```bash
curl http://localhost/gym-management/api/health.php
```

**Expected**: HTTP 200 with `"status":"ok"`

### Step 5: ESP32 Setup (10 min per gate)

**Entry Gate** (`esp32/entry_gate/entry_gate.ino`):
1. Open in Arduino IDE
2. Update lines 21-23:
   ```cpp
   const char* WIFI_SSID = "YOUR_WIFI_SSID";
   const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
   const char* SERVER_URL = "http://YOUR_SERVER_IP/gym-management/api/gate.php";
   ```
3. Install libraries: `WiFi`, `HTTPClient`, `MFRC522`
4. Upload to ESP32

**Exit Gate**: Same process with `esp32/exit_gate/exit_gate.ino`

### Step 6: Setup Cleanup Cron (2 min)

```bash
crontab -e

# Add this line (runs every 5 minutes):
*/5 * * * * php /full/path/to/gym-management/scripts/cleanup.php >> /full/path/to/gym-management/logs/cleanup.log 2>&1
```

### Step 7: Test Manually (5 min)

#### Test Cleanup Job:
```bash
php scripts/cleanup.php
```

#### Test Admin Override:
```bash
# In browser (after logging in as admin):
http://localhost/gym-management/api/admin-override.php?action=get_log
```

### Step 8: Assign RFID Cards (Variable)

Update member records with RFID UIDs:

```sql
UPDATE members_men 
SET rfid_uid = 'A1B2C3D4', rfid_assigned_date = NOW() 
WHERE id = 1;

UPDATE members_women 
SET rfid_uid = 'E5F6G7H8', rfid_assigned_date = NOW() 
WHERE id = 2;
```

### Step 9: Live Test (Variable)

1. Scan RFID at entry gate
2. Check `gate_activity_log` table
3. Verify `attendance_men/women` record created
4. Scan at exit gate
5. Verify check-out recorded with duration

---

## ✅ Production Readiness Checklist

### Pre-Deployment
- [ ] `.env` file configured with production settings
- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Database migration run successfully
- [ ] Health check endpoint returns 200 OK
- [ ] Permissions set correctly (755 for dirs, 600 for .env)
- [ ] ESP32 firmware uploaded to both gates
- [ ] Cleanup cron job configured
- [ ] At least one RFID card assigned for testing

### Post-Deployment
- [ ] Test RFID entry gate with registered card
- [ ] Test RFID exit gate with checked-in member
- [ ] Verify attendance records in database
- [ ] Test admin override (force check-in)
- [ ] Monitor error logs for 24 hours
- [ ] Verify cleanup job runs automatically
- [ ] Check gate activity logs for errors
- [ ] Performance acceptable (< 2s per scan)

### Security
- [ ] `.env` file protected (chmod 600)
- [ ] Admin password changed from default
- [ ] HTTPS enabled (if in production)
- [ ] `SESSION_SECURE_COOKIE=true` (if HTTPS)
- [ ] Firewall configured (if needed)
- [ ] Database user has minimal privileges

---

## 🆘 Troubleshooting

### ESP32 Not Connecting
```
1. Check WiFi credentials
2. Verify SERVER_URL is correct (use IP, not localhost)
3. Check firewall/network access
4. Monitor Serial output (115200 baud)
```

### Gate Not Opening
```
1. Check gate_activity_log for denial reason
2. Verify RFID UID matches database exactly  
3. Check member status (active? fee defaulter?)
4. Test health check endpoint
5. Check relay wiring
```

### Orphaned Sessions Not Cleaning
```
1. Verify cron job is running: cat /var/log/syslog | grep cleanup
2. Run manually: php scripts/cleanup.php
3. Check logs/cleanup.log for errors
4. Verify CLEANUP_ORPHANED_SESSIONS_ENABLED=true in .env
```

### Database Errors
```
1. Check foreign keys were created: 
   SHOW CREATE TABLE attendance_men;
2. Verify all tables exist:
   SHOW TABLES;
3. Check logs/error.log
```

---

## 📊 Monitoring (First Week)

### Daily Checks
- [ ] Review `logs/error.log` for errors
- [ ] Check `gate_activity_log` for anomalies
- [ ] Verify cleanup job ran (check `system_jobs` table)
- [ ] Monitor WiFi connectivity of ESP32s

### Weekly Checks
- [ ] Review `admin_action_log` for unusual activity
- [ ] Check database size growth
- [ ] Verify all members have RFIDs assigned
- [ ] Performance benchmarking

---

## 🔐 Security Recommendations

1. **Change Default Admin Password** (if applicable)
2. **Enable HTTPS** for production
3. **Restrict database user** permissions
4. **Backup database** daily
5. **Monitor admin_action_log** for suspicious activity
6. **Update ESP32 firmware** if bugs found

---

## 📞 Support

For issues, check:
1. `logs/error.log` - Application errors
2. `logs/cleanup.log` - Cleanup job issues
3. `gate_activity_log` table - Gate access issues
4. ESP32 Serial Monitor - Hardware/network issues

---

**Estimated Total Deployment Time**: 30-45 minutes  
**Recommended Testing Period**: 7 days before full production use
