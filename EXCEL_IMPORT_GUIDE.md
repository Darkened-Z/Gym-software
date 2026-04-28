# Excel Import Format Guide

## Required Fields (Must Have)

Your Excel file **MUST** have these columns in the first row (header):

1. **Ac_No** (or any of these: `acno`, `member_code`, `code`)
   - Member's unique account number/code
   - This is required and must be unique

2. **Ac_Name** (or any of these: `acname`, `name`, `member_name`)
   - Full name of the member (single field - no need to split)
   - This is required

3. **Mobile** (or any of these: `phone`, `contact`)
   - Phone number of the member
   - This is required and must be unique

## Optional Fields (Nice to Have)

These columns are optional but recommended:

4. **Address** (or `addr`)
   - Member's address
   - Optional

5. **Admission_Date** (or any of these: `admissiondate`, `join_date`, `joindate`)
   - Date when member joined
   - Can be Excel date format or text date
   - If not provided, defaults to today's date

6. **Admission_fee** (or `admissionfee`)
   - One-time admission fee amount
   - If not provided, defaults to 0.00

7. **Monthly_fee** (or `monthlyfee`, `fee`)
   - Monthly membership fee amount
   - If not provided, defaults to 0.00

8. **locker_fee** (or `lockerfee`)
   - Locker rental fee amount
   - If not provided, defaults to 0.00

9. **enable_disable** (or `enabledisable`, `status`)
   - Member status
   - Accepts: `enable`, `active`, `1` (for active) or `disable`, `inactive`, `0` (for inactive)
   - If not provided, defaults to `active`

## Excel File Structure

```
Row 1 (Header):  Ac_No | Ac_Name | Mobile | Address | Admission_Date | Admission_fee | Monthly_fee | locker_fee | enable_disable
Row 2 (Data):   1001  | John Doe | 1234567890 | 123 Main St | 2024-01-15 | 500 | 1000 | 200 | enable
Row 3 (Data):   1002  | Jane Smith | 0987654321 | 456 Oak Ave | 2024-02-20 | 500 | 1200 | 250 | active
...
```

## Important Notes

1. **Column names are case-insensitive** - `Ac_No`, `ac_no`, `AC_NO` all work the same
2. **First row must be headers** - The system reads the first row as column names
3. **No empty rows between header and data** - Start data from row 2
4. **Name field is single** - Use `Ac_Name` for full name (no need for separate first/last name columns)
5. **Date format** - Can be Excel date serial number or text date (YYYY-MM-DD, DD/MM/YYYY, etc.)
6. **Phone must be unique** - Each member must have a unique phone number
7. **Member code must be unique** - Each member must have a unique Ac_No

## Fields to REMOVE (if present)

If your Excel file has these columns, you can **remove** them (they're not used):
- `first_name` - Not needed (use `Ac_Name` instead)
- `last_name` - Not needed (use `Ac_Name` instead)
- `email` - Not currently imported (can be added manually later)
- Any other columns not listed above

## Example Perfect Excel File

| Ac_No | Ac_Name | Mobile | Address | Admission_Date | Admission_fee | Monthly_fee | locker_fee | enable_disable |
|-------|---------|--------|---------|----------------|--------------|--------------|------------|----------------|
| 1001 | John Doe | 1234567890 | 123 Main Street | 2024-01-15 | 500 | 1000 | 200 | enable |
| 1002 | Jane Smith | 0987654321 | 456 Oak Avenue | 2024-02-20 | 500 | 1200 | 250 | active |
| 1003 | Bob Johnson | 1122334455 | 789 Pine Road | 2024-03-10 | 500 | 1000 | 0 | enable |

## Quick Checklist

Before uploading, make sure:
- [ ] First row contains column headers
- [ ] `Ac_No` column exists (or alternative: `acno`, `member_code`, `code`)
- [ ] `Ac_Name` column exists (or alternative: `acname`, `name`, `member_name`)
- [ ] `Mobile` column exists (or alternative: `phone`, `contact`)
- [ ] All Ac_No values are unique
- [ ] All Mobile values are unique
- [ ] No empty rows between header and data
- [ ] At least one data row exists (row 2 or below)

## Troubleshooting

**Error: "Missing required fields"**
- Check that Ac_No, Ac_Name, and Mobile columns exist
- Check that all rows have values in these columns

**Error: "Member code already exists"**
- The Ac_No value is already in the database
- Use a different Ac_No or remove the duplicate from Excel

**Error: "Excel file is empty or has no data rows"**
- Make sure you have at least 2 rows (header + at least 1 data row)
- Check that data rows are not completely empty

