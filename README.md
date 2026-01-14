# SmartDump ⚡

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-777BB4.svg)](https://php.net)
[![Version](https://img.shields.io/badge/version-1.0.0-green.svg)](https://github.com/workflowdone/smartdump/releases)

> **The modern BigDump alternative**. Import large MySQL databases with a beautiful step-by-step wizard interface.

• [**Report Bug**](https://github.com/workflowdone/smartdump/issues) • [**Request Feature**](https://github.com/workflowdone/smartdump/issues)

![SmartDump Interface](https://workflowdone.com/smartdump-interface)

## ✨ Features

### Why SmartDump over BigDump?

| Feature | BigDump | SmartDump |
|---------|---------|-----------|
| Modern UI | ❌ | ✅ Bootstrap 5 |
| Step-by-Step Wizard | ❌ | ✅ 4 simple steps |
| Upload Progress | ❌ | ✅ Real-time |
| Auto-Detection | ❌ | ✅ Charset & Prefix |
| Live Logs | ⚠️ Basic | ✅ Terminal-style |
| Error Handling | ⚠️ Limited | ✅ 3 modes |
| Email Notifications | ❌ | ✅ |
| Database Backup | ❌ | ✅ |
| WordPress Optimized | ⚠️ | ✅ |
| Mobile Responsive | ❌ | ✅ |
| Last Updated | 2023 | 2024 (Active) |

### 🚀 Core Features

- **Chunked Execution** - Import databases up to 500MB+ without timeout errors
- **Smart Auto-Detection** - Automatically detects charset, collation, and table prefixes
- **Real-Time Progress** - Live progress bar, percentage, and terminal-style logs
- **WordPress Ready** - Handles WordPress & WooCommerce serialized data perfectly
- **Table Prefix Replacement** - Easily change table prefixes (e.g., `wp_` → `newwp_`)
- **Resume Support** - Continue from where you left off if connection drops
- **3 Error Modes** - Continue on errors, ignore duplicates only, or stop on first error
- **FTP Upload Support** - For files too large for browser upload
- **Database Backup** - Backup your database before importing
- **Email Notifications** - Get notified when import completes
- **Enterprise Security** - Input validation, SQL injection prevention, path traversal protection

## 📦 Installation

### Requirements

- PHP 7.4 or higher
- MySQL 5.6+ or MariaDB 10.0+
- MySQLi extension enabled
- At least 128MB PHP memory limit

### Quick Start

1. **Download SmartDump**
   ```bash
   wget https://github.com/workflowdone/smartdump/releases/latest/download/smartdump.php
   ```

2. **Upload to your server**
   - Via FTP/SFTP to your web directory
   - Or copy directly if you have shell access

3. **Access via browser**
   ```
   https://yourdomain.com/smartdump.php
   ```

4. **Follow the wizard** (4 simple steps)

5. **Delete the file when done** ⚠️ IMPORTANT for security!

That's it! No configuration needed.

## 🎯 Usage

### Step 1: Upload SQL File

**Option A: Browser Upload**
- Drag & drop your `.sql` or `.gz` file
- Real-time upload progress bar
- Recommended for files under 100MB

**Option B: FTP Upload**
- Upload large files via FTP to `smartdump_uploads/` folder
- Click "Refresh Files" in the FTP tab
- Select your file

### Step 2: Database Configuration

Enter your MySQL credentials:
- Host (usually `localhost`)
- Database name
- Username
- Password

Click "Test Connection" to verify before proceeding.

### Step 3: Import Settings

**Auto-Detection** (Recommended)
- Click magic wand 🪄 buttons to auto-detect:
  - Charset from SQL file
  - Table prefix from existing database

**Manual Configuration**
- Charset: `utf8mb4`, `utf8`, `latin1`, `cp1251`
- Collation: `utf8mb4_unicode_ci`, etc.
- Max queries per step: `300` (default)
- Max time per step: `30` seconds (default)

**Optional Settings**
- **Old Prefix** → **New Prefix**: Change table prefixes
- **Email Notification**: Get notified when done
- **Error Handling**:
  - Continue on errors (recommended for WordPress)
  - Ignore duplicates only
  - Stop on first error (strict mode)
- **DROP DATABASE**: ⚠️ Delete all existing data

### Step 4: Import

- Watch real-time progress
- Monitor live logs
- Get statistics (queries executed, failed, success rate)
- Optionally backup database when complete

## 🔧 Configuration

### Optional: Enable IP Whitelist

Edit line 23 in `smartdump.php`:

```php
define('ENABLE_IP_WHITELIST', true);
define('ALLOWED_IPS', ['123.45.67.89', '98.76.54.32']);
```

### Optional: Adjust Upload Limits

Create/edit `php.ini` or `.htaccess`:

```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
memory_limit = 512M
```

For Nginx, edit your config:

```nginx
client_max_body_size 500M;
```



## 📖 Use Cases

### WordPress Migration

Perfect for moving WordPress sites between hosts:

1. Export database from old host (via phpMyAdmin or plugin)
2. Upload SQL file to SmartDump
3. Auto-detect charset and prefix
4. Import with "Continue on errors" mode
5. Update `wp-config.php` with new credentials

### WooCommerce Store Transfer

Handles large WooCommerce databases with ease:

- Automatically handles serialized product data
- Skips duplicate entry errors (common with WooCommerce)
- Live logs show exactly what's happening
- 99%+ success rate even with complex stores

### Database Backup & Restore

1. Use "Backup Database" feature before risky operations
2. Download the backup SQL file
3. If something goes wrong, restore using SmartDump

### Localhost to Production

Import development databases to production:

- Change table prefix if needed (`local_` → `prod_`)
- Set error handling to "stop on first error" for strict imports
- Get email notification when complete

## 🛡️ Security

### Built-in Security Features

- ✅ Input validation on all user inputs
- ✅ SQL injection prevention (prepared statements)
- ✅ Path traversal protection (basename, realpath checks)
- ✅ XSS prevention (htmlspecialchars on output)
- ✅ File type validation (whitelist)
- ✅ CSRF protection via session validation
- ✅ Optional IP whitelist
- ✅ `.htaccess` protection for upload directories

### Security Best Practices

1. **Delete SmartDump after use** - Most important!
2. **Use strong database passwords**
3. **Enable IP whitelist in production**
4. **Only use on trusted networks**
5. **Review SQL files before importing**
6. **Keep backups before importing**
7. **Don't leave uploaded files on server**

## 🐛 Troubleshooting

### Upload Failed: 413 Error

**Problem**: File too large for server

**Solution**: 
- Use FTP Upload tab instead
- Or increase Nginx `client_max_body_size`
- Or increase PHP `upload_max_filesize`

### Connection Failed

**Problem**: Cannot connect to database

**Solutions**:
- Verify credentials are correct
- Check database exists
- Ensure MySQL is running
- Check if host allows external connections
- Try `localhost` instead of `127.0.0.1` or vice versa

### Import Errors: Serialized Data

**Problem**: WordPress queries failing

**Solution**: 
- Use "Continue on errors" mode (recommended)
- Or "Ignore duplicates only" mode
- This is normal for WordPress - failed queries are usually cache/transients

### Timeout Errors

**Problem**: Script times out

**Solutions**:
- Reduce "Max queries per step" (try 200 or 100)
- Reduce "Max time per step" (try 20 seconds)
- Check server timeout limits
- For very large files, consider using command line import

### Files Not Showing

**Problem**: Uploaded files don't appear

**Solutions**:
- Check `smartdump_uploads/` directory exists
- Verify directory permissions (755 or 777)
- Check PHP `upload_tmp_dir` is writable
- Look at browser console (F12) for errors

## 🤝 Contributing

Contributions are welcome! Here's how:

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

### Development Setup

```bash
# Clone the repo
git clone https://github.com/workflowdone/smartdump.git

# No dependencies to install!
# Just edit smartdump.php and test
```

## 📝 Changelog

### Version 1.0.0 (2024-12-08)

**Initial Release**
- Step-by-step wizard interface
- Auto-detection for charset and table prefixes
- Real-time upload progress
- Live import logs
- 3 error handling modes
- WordPress/WooCommerce optimization
- Database backup feature
- Email notifications
- FTP upload support
- Mobile responsive design

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 💖 Support

If SmartDump saved you time and money, consider supporting development:

**[Donate via PayPal](https://www.paypal.com/paypalme/workflowdone)** 💙

Your support helps keep SmartDump free and actively maintained!

## 🙏 Credits

Created with ❤️ by [WorkflowDone.com](https://workflowdone.com)

Inspired by BigDump (2003-2023) - Thanks for years of service!

Built with:
- [Bootstrap 5](https://getbootstrap.com/)
- [Bootstrap Icons](https://icons.getbootstrap.com/)
- Modern PHP & vanilla JavaScript

## 📞 Contact & Links

- **Website**: [WorkflowDone.com](https://workflowdone.com)
- **GitHub**: [https://github.com/workflowdone/smartdump](https://github.com/workflowdone/smartdump)
- **Issues**: [Report a bug](https://github.com/workflowdone/smartdump/issues)
- **Email**: [temo@workflowdone.com](mailto:support@workflowdone.com)
- **Donate**: [PayPal.me/workflowdone](https://www.paypal.com/paypalme/workflowdone)

- **Support & Knowledge Base**: [workflowdone.com/support]([https://workflowdone.co](https://workflowdone.com/support/kb/smartdump))
 

---

<p align="center">
  Made with ❤️ by <a href="https://workflowdone.com">WorkflowDone.com</a>
</p>

<p align="center">
  <sub>⭐ Star us on GitHub — it helps!</sub>
</p>
