# Installation Guide

## Quick Start

1. **Extract/Copy** the project to your web server directory
   - For XAMPP: `C:\xampp\htdocs\gym-management-system\`
   - For WAMP: `C:\wamp\www\gym-management-system\`
   - For Linux: `/var/www/html/gym-management-system/`

2. **Create Database**
   - Open phpMyAdmin or MySQL command line
   - Create a new database: `gym_management`
   - Import the schema: `database/schema.sql`

3. **Configure Database**
   - Edit `config/database.php`
   - Update database credentials if needed:
     ```php
     private $host = 'localhost';
     private $db_name = 'gym_management';
     private $username = 'root';
     private $password = ''; // Your MySQL password
     ```

4. **Install PHP Dependencies**
   ```bash
   composer install
   ```
   If you don't have Composer:
   - Download from https://getcomposer.org/
   - Or manually download PHPSpreadsheet and place in `vendor/` directory

5. **Set Up Admin Password** (Important!)
   - Run `setup.php` via browser: `http://localhost/gym-management-system/setup.php`
   - Or via command line: `php setup.php`
   - This will generate the correct password hash for 'admin123'

6. **Set Permissions** (Linux/Mac only)
   ```bash
   chmod -R 755 uploads/
   chmod -R 755 logs/
   ```

7. **Access the Application**
   - Open: `http://localhost/gym-management-system/`
   - Login with:
     - Username: `admin`
     - Password: `admin123`

## Troubleshooting

### "Composer dependencies not installed"
- Run `composer install` in the project root
- Make sure you have Composer installed

### "Database connection failed"
- Check database credentials in `config/database.php`
- Ensure MySQL service is running
- Verify database `gym_management` exists

### "Cannot login with admin/admin123"
- Run `setup.php` to generate the correct password hash
- Check that the `users` table has an admin record

### "Permission denied" errors (Linux/Mac)
- Set proper permissions: `chmod -R 755 uploads/ logs/`
- Ensure web server user has write access

### Excel import not working
- Ensure PHPSpreadsheet is installed: `composer install`
- Check file upload size limits in PHP configuration
- Verify file format (.xls, .xlsx, or .csv)

## Default Credentials

- **Admin Login:**
  - Username: `admin`
  - Password: `admin123`
  - ⚠️ **Change this password in production!**

- **Member Login:**
  - Uses member code (Ac_No) from the database
  - No default members exist - add them via admin panel or import

## Next Steps

1. Change the admin password after first login
2. Import members using the Excel import feature
3. Configure your gym's membership types and fees
4. Start tracking attendance and payments

## Support

For issues or questions, check the main README.md file.

