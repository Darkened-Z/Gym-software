# Testing Guide - Attendance & Excel Import

## Attendance Tracking Testing

### Test Scenario 1: Member Login Auto Check-in

**Steps**:
1. Open browser and navigate to `http://localhost/gym-management/`
2. Click on "Member Login" tab
3. Enter a valid member code (e.g., M001)
4. Click "Login"

**Expected Result**:
- ✅ Member is redirected to their profile page
- ✅ Attendance is automatically recorded
- ✅ Check-in time is displayed on profile
- ✅ Database shows new attendance record

**Verification**:
```sql
-- Check attendance record
SELECT * FROM attendance_men 
WHERE member_id = (SELECT id FROM members_men WHERE member_code = 'M001')
AND DATE(check_in) = CURDATE()
ORDER BY check_in DESC LIMIT 1;
```

---

### Test Scenario 2: Manual Check-in (Admin)

**Steps**:
1. Login as admin
2. Navigate to "Attendance" section
3. Click "Manual Check-in"
4. Enter member code or select from list
5. Click "Check In"

**Expected Result**:
- ✅ Success message displayed
- ✅ Attendance record created
- ✅ Member status updated to "checked in"

**Verification**:
```sql
SELECT m.member_code, m.name, m.is_checked_in, a.check_in
FROM members_men m
LEFT JOIN attendance_men a ON m.id = a.member_id AND DATE(a.check_in) = CURDATE()
WHERE m.member_code = 'M001';
```

---

### Test Scenario 3: Check-out

**Steps**:
1. Login as admin
2. Navigate to "Attendance" section
3. Find a checked-in member
4. Click "Check Out"

**Expected Result**:
- ✅ Check-out time recorded
- ✅ Duration calculated
- ✅ Member status updated to "checked out"

**Verification**:
```sql
SELECT 
    member_id,
    check_in,
    check_out,
    duration_minutes,
    TIMESTAMPDIFF(MINUTE, check_in, check_out) as calculated_duration
FROM attendance_men
WHERE DATE(check_in) = CURDATE()
AND check_out IS NOT NULL
ORDER BY check_in DESC LIMIT 5;
```

---

### Test Scenario 4: Attendance Calendar (Member Profile)

**Steps**:
1. Login as member
2. View attendance calendar on profile page
3. Navigate through months

**Expected Result**:
- ✅ Green dots on days with attendance
- ✅ Red/gray dots on days without attendance
- ✅ Calendar navigation works smoothly
- ✅ Clicking a day shows attendance details

---

### Test Scenario 5: Attendance Report (Admin)

**Steps**:
1. Login as admin
2. Navigate to "Reports" section
3. Select "Attendance Report"
4. Choose date range
5. Select gender (Men/Women/All)
6. Click "Generate Report"

**Expected Result**:
- ✅ Report displays all attendance records
- ✅ Filtering works correctly
- ✅ Pagination works (if > 20 records)
- ✅ Export options available (CSV, PDF)

---

## Excel Import Testing

### Test Scenario 1: Download Template

**Steps**:
1. Login as admin
2. Navigate to "Import" section
3. Click "Download Sample Template"

**Expected Result**:
- ✅ Excel file downloads
- ✅ File name: `gym_members_template.xlsx`
- ✅ Contains correct column headers

**Template Columns**:
| Ac_No | Ac_Name | Mobile | Address | Admission_Date | Monthly_fee | Locker_Fee | Photo |
|-------|---------|--------|---------|----------------|-------------|------------|-------|

---

### Test Scenario 2: Import Valid Data

**Sample Data** (create Excel file with this data):

| Ac_No | Ac_Name | Mobile | Address | Admission_Date | Monthly_fee | Locker_Fee |
|-------|---------|--------|---------|----------------|-------------|------------|
| M100 | Test User 1 | 03001234567 | Test Address 1 | 2025-01-15 | 2000 | 500 |
| M101 | Test User 2 | 03009876543 | Test Address 2 | 2025-01-15 | 2500 | 500 |
| M102 | Test User 3 | 03001112233 | Test Address 3 | 2025-01-15 | 2000 | 0 |

**Steps**:
1. Login as admin
2. Navigate to "Import" section
3. Select "Men" or "Women"
4. Click "Choose File" and select the Excel file
5. Click "Import Members"

**Expected Result**:
- ✅ "Importing..." message displayed
- ✅ Progress indicator shown
- ✅ Success message: "3 members imported successfully"
- ✅ Import summary displayed
- ✅ Members appear in members list

**Verification**:
```sql
-- Check imported members
SELECT member_code, name, phone, join_date, monthly_fee
FROM members_men
WHERE member_code IN ('M100', 'M101', 'M102');
```

---

### Test Scenario 3: Import with Duplicates

**Sample Data** (using existing member code):

| Ac_No | Ac_Name | Mobile | Address | Admission_Date | Monthly_fee |
|-------|---------|--------|---------|----------------|-------------|
| M001 | Duplicate User | 03001234567 | Address | 2025-01-15 | 2000 |
| M200 | New User | 03009876543 | Address | 2025-01-15 | 2500 |

**Steps**:
1. Import the file as before

**Expected Result**:
- ✅ Import completes
- ✅ Summary shows: "1 imported, 1 duplicate skipped"
- ✅ Duplicate member code not overwritten
- ✅ New member (M200) imported successfully

---

### Test Scenario 4: Import with Invalid Data

**Sample Data** (with errors):

| Ac_No | Ac_Name | Mobile | Address | Admission_Date | Monthly_fee |
|-------|---------|--------|---------|----------------|-------------|
| M300 | Valid User | 03001234567 | Address | 2025-01-15 | 2000 |
|  | Missing Code | 03009876543 | Address | 2025-01-15 | 2500 |
| M301 |  | 03001112233 | Address | 2025-01-15 | 2000 |
| M302 | Invalid Phone | 12345 | Address | 2025-01-15 | 2000 |

**Steps**:
1. Import the file

**Expected Result**:
- ✅ Import completes
- ✅ Summary shows: "1 imported, 3 failed"
- ✅ Error details displayed:
  - "Row 2: Member code required"
  - "Row 3: Name required"
  - "Row 4: Invalid phone number format"
- ✅ Valid record (M300) imported successfully

---

### Test Scenario 5: Large Import (Performance Test)

**Sample Data**: Create Excel with 100 members

**Steps**:
1. Create Excel with 100 rows of data
2. Import the file

**Expected Result**:
- ✅ Import completes in < 30 seconds
- ✅ Progress indicator updates
- ✅ All 100 members imported (if no duplicates)
- ✅ No timeout errors
- ✅ Database remains responsive

**Verification**:
```sql
-- Count imported members
SELECT COUNT(*) as total_members FROM members_men;
```

---

## Common Issues & Troubleshooting

### Issue 1: Attendance Not Created
**Cause**: Member login failed or database error
**Solution**:
1. Check browser console for errors
2. Verify member code exists
3. Check database connection
4. Review error logs

### Issue 2: Excel Import Fails
**Cause**: File format, permissions, or data validation
**Solution**:
1. Verify file is .xlsx format
2. Check column headers match exactly
3. Ensure upload folder has write permissions
4. Review import error messages

### Issue 3: Duplicate Members
**Cause**: Same member code exists
**Solution**:
1. Review import summary
2. Check existing members before import
3. Update member code in Excel if needed

### Issue 4: Phone Number Validation Fails
**Cause**: Invalid Pakistani phone format
**Solution**:
- Use format: 03001234567 (11 digits)
- Or: 3001234567 (10 digits without leading 0)

---

## Expected Performance Metrics

### Attendance
- **Check-in time**: < 1 second
- **Check-out time**: < 1 second
- **Calendar load**: < 2 seconds
- **Report generation**: < 3 seconds (for 1000 records)

### Excel Import
- **Small file (< 50 members)**: < 5 seconds
- **Medium file (50-100 members)**: < 15 seconds
- **Large file (100-500 members)**: < 60 seconds

---

## Test Checklist

### Attendance Testing
- [ ] Member login auto check-in works
- [ ] Manual check-in creates attendance
- [ ] Check-out updates attendance
- [ ] Duration calculated correctly
- [ ] Attendance calendar displays correctly
- [ ] Reports generate successfully
- [ ] Date filtering works
- [ ] No duplicate check-ins on same day

### Excel Import Testing
- [ ] Template downloads correctly
- [ ] Valid data imports successfully
- [ ] Duplicates are detected and skipped
- [ ] Invalid data shows error messages
- [ ] Import summary is accurate
- [ ] Large files import without timeout
- [ ] Phone number validation works
- [ ] Date format validation works
- [ ] Fee amounts imported correctly

---

**Test Date**: _____________  
**Tested By**: _____________  
**Environment**: Local / Staging / Production  
**Status**: Pass / Fail / Partial

---

**Notes**:
