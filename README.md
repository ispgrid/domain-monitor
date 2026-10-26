# 🌐 Domain Monitor

> A powerful, self-hosted domain expiration monitoring system with multi-channel notifications

> **Fork notice:** this is the [ispgrid](https://github.com/ispgrid) fork of [Hosteroid/domain-monitor](https://github.com/Hosteroid/domain-monitor). See [About This Fork](#-about-this-fork). Running the original? See [Switching from Hosteroid/domain-monitor](#-switching-from-hosteroiddomain-monitor).

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

A modern PHP MVC application for monitoring domain expiration dates and sending notifications through multiple channels (Email, Telegram, Discord, Slack, Mattermost, Pushover, Webhook). Never lose a domain again with automated monitoring and timely alerts.

## ✨ Features

### Core Features
- 📋 **Domain Management** - Add, edit, and monitor unlimited domains
- 🔍 **Smart WHOIS/RDAP Lookup** - Automatically fetches expiration dates and registrar information
- 🗂️ **TLD Registry System** - Built-in support for 1,400+ TLDs with IANA integration
- 🔔 **Multi-Channel Notifications** - Email, Telegram, Discord, Slack, Mattermost, Pushover, and Webhook support
- 👥 **Notification Groups** - Organize channels and assign domains flexibly
- ⚡ **Real-time Dashboard** - Overview of all domains and their status
- 📊 **Notification Logs** - Complete history of all sent notifications
- 🤖 **Automated Monitoring** - Cron-based checks with configurable intervals
- 🎨 **Modern UI** - Clean, responsive design with intuitive interface

### Advanced Features
- 🔐 **Secure by Default** - Random passwords, database-backed sessions, prepared statements
- 🔔 **User Notifications System** - In-app notification center with real-time updates
- 📬 **Smart Notifications** - Welcome messages, upgrade alerts, domain warnings
- 🌐 **Advanced Session Management** - View all active sessions with geolocation and device tracking
- 🚨 **Remote Session Termination** - Logout any device immediately from anywhere
- 📈 **Bulk Operations** - Import, refresh, and manage multiple domains at once
- 🎯 **Flexible Alerts** - Customizable notification thresholds (60, 30, 21, 14, 7, 5, 3, 2, 1 days)
- 🔄 **Auto WHOIS Refresh** - Keep domain data up-to-date automatically
- 📱 **Monitoring Controls** - Enable/disable notifications per domain with alerts
- 🌍 **RDAP Support** - Modern protocol for faster, structured domain data
- 🏴 **Geolocation Tracking** - See location, ISP, and device info for all sessions

## 📋 Requirements

- PHP 8.2 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Apache/Nginx with mod_rewrite enabled
- Cron support for automated checks
- SMTP server for email notifications (optional)

## 🔐 Security

The application includes built-in authentication with secure practices:

- 🔑 **Random Password Generation** - Unique secure password created on installation
- 🛡️ **Database-Backed Sessions** - True session management with immediate remote logout
- 🌍 **Session Tracking** - Monitor all active sessions with location and device info
- 🚨 **Remote Session Control** - Terminate suspicious sessions from any device
- 💉 **SQL Injection Protection** - All queries use prepared statements
- 🔒 **One-time Credentials** - Admin password shown only once during setup
- 🍪 **Secure Remember Me** - Cryptographically secure 30-day tokens linked to sessions

⚠️ **Important:** Save your admin password during installation - it won't be shown again!

## 🚀 Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/ispgrid/domain-monitor.git
cd domain-monitor
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Copy the example environment file:

```bash
# Linux/Mac
cp env.example.txt .env

# Windows
copy env.example.txt .env
```

Edit `.env` and configure your settings:

```ini
# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=domain_monitor
DB_USERNAME=root
DB_PASSWORD=your_password
```

**Note:** 
- The encryption key (APP_ENCRYPTION_KEY) will be automatically generated during web installation
- Application name, URL, timezone, email settings, and monitoring schedules are configured through the web interface in **Settings** (not .env)

### 4. Create Database

Create a MySQL database:

```sql
CREATE DATABASE domain_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Run Web Installer

#### Option A: Apache/Nginx (Recommended)

Configure your web server (see step 7 below), then visit your domain in a browser:

```
http://your-domain.com
```

You'll be automatically redirected to the installer.

#### Option B: PHP Built-in Server

```bash
php -S localhost:8000 -t public
```

Then visit: `http://localhost:8000`

The web installer will:
1. ✅ Create all database tables
2. ✅ Generate encryption key and save to `.env`
3. ✅ Let you set admin email and password
4. ✅ Show credentials on completion (save them!)

**⚠️ IMPORTANT:** The installer will display your admin credentials **only once**. Save them to a secure password manager!

### 6. Import TLD Registry Data (Optional but Recommended)

After logging in, go to **TLD Registry** page and click **"Import TLDs"** to download RDAP and WHOIS server data for 1,400+ TLDs from IANA.

Alternatively, use the CLI:

```bash
php cron/import_tld_registry.php
```

### 7. Configure Web Server

#### Apache

Make sure `.htaccess` is enabled. Your virtual host should point to the `public` directory.

Example configuration:

```apache
<VirtualHost *:80>
    ServerName domainmonitor.local
    DocumentRoot "/path/to/domain-monitor/public"
    
    <Directory "/path/to/domain-monitor/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### PHP Built-in Server (Development)

```bash
php -S localhost:8000 -t public
```

Then visit: `http://localhost:8000`

## 🔁 Switching from Hosteroid/domain-monitor

If you run the original [Hosteroid/domain-monitor](https://github.com/Hosteroid/domain-monitor) (1.1.x), its built-in updater only checks Hosteroid's repository, so **Settings → Updates will never offer this fork's releases**. Switch once by hand with the steps below. After that, updates come from this fork through **Settings → Updates**.

Your database, settings, domains, users, 2FA and uploaded avatars are kept. Only the program files are replaced, then the database is brought up to date.

**Before you start**

- **Back up your database.** Every method below starts with it; keep the backup until the switch is done and checked.
- Make sure the site runs **PHP 8.2 or newer** (`php -v`, or your hosting control panel). PHP 8.1 is not supported.
- Find the folder your site is installed in: the one that contains `.env`, `app` and `public`. The steps call it `domain-monitor` and its parent folder `/var/www` or `C:\laragon\www`; use your own names.
- Have your admin login ready for the last step.

Then follow the steps for your setup:

- [Installed with git clone](#installed-with-git-clone) (most installs)
- [Linux server (SSH)](#linux-server-ssh)
- [Windows server](#windows-server)
- [Shared hosting (cPanel and similar)](#shared-hosting-cpanel-and-similar)

### Installed with git clone

Most installs were made with `git clone`. If yours was, and you haven't changed the code, the switch is a few commands. They work the same in a Linux shell and in Windows PowerShell.

1. Back up the database (database name and user are in `.env`):
   ```bash
   mysqldump -u DB_USERNAME -p DB_DATABASE > domain-monitor-backup.sql
   ```
   In Windows PowerShell use `--result-file=domain-monitor-backup.sql` instead of `> domain-monitor-backup.sql`. On shared hosting, export the database from phpMyAdmin.
2. Point the install at this fork and pull the latest code:
   ```bash
   cd domain-monitor
   git rev-parse --short HEAD
   git remote set-url origin https://github.com/ispgrid/domain-monitor.git
   git pull --ff-only origin main
   composer install --no-dev
   ```
   Note the commit printed by `git rev-parse`; you need it to undo the switch. The pull replaces your local `composer.lock` with the one from this fork.
3. [Finish in the browser](#finish-in-the-browser).

If `git pull` refuses because of local changes, use the steps for your platform below instead.

### Linux server (SSH)

1. Go to the parent folder and back up the database (database name and user are in `.env`):
   ```bash
   cd /var/www
   mysqldump -u DB_USERNAME -p DB_DATABASE > domain-monitor-backup.sql
   ```
2. Rename the old install. It stays as your file backup:
   ```bash
   mv domain-monitor domain-monitor-old
   ```
3. Download and unpack the latest release:
   ```bash
   TAG=$(curl -s https://api.github.com/repos/ispgrid/domain-monitor/releases/latest | grep -m1 '"tag_name"' | cut -d'"' -f4)
   curl -L -o domain-monitor.zip "https://github.com/ispgrid/domain-monitor/archive/refs/tags/$TAG.zip"
   unzip -q domain-monitor.zip
   mv "domain-monitor-${TAG#v}" domain-monitor
   ```
4. Copy your settings and uploaded avatars into the new folder:
   ```bash
   cp domain-monitor-old/.env domain-monitor-old/.installed domain-monitor/
   [ -d domain-monitor-old/public/assets/uploads/avatars ] && cp -a domain-monitor-old/public/assets/uploads/avatars domain-monitor/public/assets/uploads/
   ```
5. Install the dependencies and give the files the same owner as the old install:
   ```bash
   cd domain-monitor
   composer install --no-dev
   sudo chown -R --reference=../domain-monitor-old .
   ```
6. If you use PHP-FPM, reload it so it drops cached files, e.g. `sudo systemctl reload php8.3-fpm`.
7. [Finish in the browser](#finish-in-the-browser).

### Windows server

Stop the web server first (Laragon: **Stop All**, XAMPP: stop Apache, IIS: `iisreset /stop`), then open **PowerShell**.

1. Go to the parent folder and back up the database (database name and user are in `.env`; `mysqldump` is in your MySQL `bin` folder):
   ```powershell
   cd C:\laragon\www
   mysqldump -u DB_USERNAME -p DB_DATABASE --result-file=domain-monitor-backup.sql
   ```
2. Rename the old install. It stays as your file backup:
   ```powershell
   Rename-Item domain-monitor domain-monitor-old
   ```
3. Download and unpack the latest release:
   ```powershell
   $tag = (Invoke-RestMethod https://api.github.com/repos/ispgrid/domain-monitor/releases/latest).tag_name
   Invoke-WebRequest "https://github.com/ispgrid/domain-monitor/archive/refs/tags/$tag.zip" -OutFile domain-monitor.zip
   Expand-Archive domain-monitor.zip -DestinationPath .
   Rename-Item "domain-monitor-$($tag.TrimStart('v'))" domain-monitor
   ```
4. Copy your settings and uploaded avatars into the new folder:
   ```powershell
   Copy-Item domain-monitor-old\.env, domain-monitor-old\.installed domain-monitor\
   if (Test-Path domain-monitor-old\public\assets\uploads\avatars) { Copy-Item domain-monitor-old\public\assets\uploads\avatars domain-monitor\public\assets\uploads\ -Recurse }
   ```
5. Install the dependencies:
   ```powershell
   cd domain-monitor
   composer install --no-dev
   ```
6. Start the web server again.
7. [Finish in the browser](#finish-in-the-browser).

### Shared hosting (cPanel and similar)

These steps use cPanel names. Plesk and DirectAdmin have the same tools (database export, File Manager, PHP version) under similar names.

1. **Back up the database:** open **phpMyAdmin**, select your Domain Monitor database, click **Export**, then **Go**, and keep the downloaded `.sql` file.
2. **Check PHP:** in **Select PHP Version** or **MultiPHP Manager**, make sure the site uses PHP 8.2 or newer.
3. **Show hidden files:** open **File Manager**, click **Settings** (top right), tick **Show Hidden Files (dotfiles)** and save. You need this to see `.env` and `.installed`.
4. **Rename the old install:** right-click your `domain-monitor` folder, choose **Rename** and call it `domain-monitor-old`. It stays as your file backup. The site is offline until the last step.
5. **Upload the new release:** on your computer, download **Source code (zip)** from the [latest release](https://github.com/ispgrid/domain-monitor/releases/latest). In File Manager, open the folder that contained `domain-monitor`, click **Upload** and upload the zip. Right-click the zip, choose **Extract**, then rename the extracted folder (for example `domain-monitor-1.2.2`) to `domain-monitor`.
6. **Copy your settings and avatars:** in `domain-monitor-old`, select `.env` and `.installed`, click **Copy** and enter the path of the new `domain-monitor` folder. If `domain-monitor-old/public/assets/uploads/avatars` exists, copy that folder to `domain-monitor/public/assets/uploads/`.
7. **Install the dependencies**, in one of these ways:
   - **With Terminal or SSH** (cPanel: **Advanced → Terminal**):
     ```bash
     cd ~/domain-monitor
     composer install --no-dev
     ```
     Use the path of your folder. If Composer complains about the PHP version, the terminal uses a different PHP than your site; ask your host for the right PHP binary.
   - **Without Terminal:** on your computer, unpack the same zip and, in that folder, run the command below. The dependencies are locked to versions that work on PHP 8.2 and newer, so they run on the host even if your computer has a newer PHP.
     ```bash
     composer install --no-dev
     ```
     Zip the `vendor` folder, upload the zip into `domain-monitor` on the host and **Extract** it there.
8. [Finish in the browser](#finish-in-the-browser).

### Finish in the browser

1. Log in to your site as an admin.
2. Open `https://your-site/install/update` and click **Run Update Now**. It runs every database migration between your old version and the new one.
3. Check your domains, then go to **Settings → Updates** and click **Check for Updates**. It should show the latest release from this fork.

Cron jobs keep working because the folder path has not changed. When everything works, delete `domain-monitor-old` and the downloaded zip. Keep the database backup for a while.

If something goes wrong, restore the database backup, then put the old files back:

- **git clone:** `git remote set-url origin https://github.com/Hosteroid/domain-monitor.git`, `git reset --hard <commit you noted>`, then `composer update --no-dev`.
- **Other methods:** delete the new `domain-monitor` folder and rename `domain-monitor-old` back to `domain-monitor`.

To restore the backup on the command line (Linux or Windows): `mysql -u DB_USERNAME -p DB_DATABASE -e "source domain-monitor-backup.sql"`, or import the file in phpMyAdmin.

## 🔧 Configuration

### Application & Email Settings

All application and email settings are now managed through the **Settings** page in the web interface:

1. Navigate to **Settings** → **Application** tab
   - Set application name, URL, and timezone

2. Navigate to **Settings** → **Email** tab
   - Configure SMTP server (host, port, encryption)
   - Set authentication credentials
   - Configure from address and name

**Example Email Settings:**
- SMTP Host: `smtp.gmail.com`
- SMTP Port: `587`
- Encryption: `TLS`
- Username: `your-email@gmail.com`
- Password: `your-app-password`

### Notification Channels

#### ✈️ Telegram

1. Create a bot using [@BotFather](https://t.me/BotFather)
2. Get your Chat ID using [@userinfobot](https://t.me/userinfobot)
3. Add the channel in the notification group settings

#### 💬 Discord

1. Go to Server Settings → Integrations → Webhooks
2. Create a new webhook
3. Copy the webhook URL
4. Add it in the notification group settings

#### 💼 Slack

1. Go to Slack App Settings
2. Enable Incoming Webhooks
3. Create a new webhook
4. Copy the webhook URL
5. Add it in the notification group settings

#### 🌐 Webhook (Custom)

Send JSON payloads to any HTTP endpoint (e.g., n8n, Zapier, Make, your own API):

1. Go to Notification Groups → Edit → Add Channel
2. Choose "Webhook (Custom)"
3. Paste your endpoint URL (HTTPS recommended)
4. Click "Test Channel" to verify

Payload example sent on domain alerts:

```json
{
  "event": "domain_expiration_alert",
  "message": "⚠️ WARNING: Domain 'example.com' expires in 7 days (January 30, 2026)!\n\nRegistrar: Example Registrar\nPlease renew soon.",
  "data": {
    "domain": "example.com",
    "domain_id": 123,
    "days_left": 7,
    "expiration_date": "2026-01-30",
    "registrar": "Example Registrar"
  },
  "sent_at": "2025-10-17T12:34:56Z"
}
```

Use this with n8n's "Webhook" trigger to start flows.

## 📅 Setting Up Cron Jobs

The application requires a cron job to check domains periodically.

**💡 Pro Tip:** The cron path is automatically detected! Go to **Settings → System** to copy the exact command for your installation.

### Linux/Mac

```bash
crontab -e
```

Add this line (or copy from Settings → System):

```cron
0 9 * * * /usr/bin/php /your/actual/path/cron/check_domains.php
```

### Windows

Use Task Scheduler:

1. Open Task Scheduler
2. Create Basic Task
3. Set trigger (e.g., Daily at 9:00 AM)
4. Action: Start a program
5. Program: `C:\php\php.exe`
6. Arguments: Copy from **Settings → System** tab (auto-detected path)

## 🧪 Testing Notifications

Before setting up the cron job, test your notification channels through the web interface:

1. Go to **Settings → Email** tab
2. Enter a test email address
3. Click **"Send Test Email"** to verify SMTP configuration
4. For Telegram/Discord/Slack, send a test from the notification group settings

## 📖 Usage Guide

### Adding Domains

1. Navigate to **Domains** → **Add Domain**
2. Enter the domain name (e.g., `example.com`)
3. Optionally assign to a notification group
4. Click **Add Domain**

The system will automatically fetch WHOIS information.

### Creating Notification Groups

1. Navigate to **Notification Groups** → **Create Group**
2. Enter a name and description
3. Click **Create Group**
4. Add notification channels (Email, Telegram, Discord, Slack)
5. Assign domains to the group

### Monitoring

The **Dashboard** shows:
- Total domains and their status
- Domains expiring soon
- Recent notifications sent

### Notification Schedule

By default, notifications are sent at these intervals before expiration:
- 60 days (2 months)
- 30 days (1 month)
- 21 days (3 weeks)
- 14 days (2 weeks)
- 7 days (1 week)
- 5 days
- 3 days
- 2 days
- 1 day (tomorrow!)
- When expired (immediate alert)

### Configure Settings

All system settings are managed through the **Settings** page (`/settings`) in your browser:

#### Application Settings
- **Application Name**: Customize the display name
- **Application URL**: Base URL for links in emails
- **Timezone**: Set your preferred timezone
- **User Registration**: Enable/disable new user signups
- **Email Verification**: Require email verification for new users

#### Email Settings
- **SMTP Configuration**: Host, port, encryption
- **Authentication**: Username and password
- **From Address**: Email sender details

#### Monitoring Settings
- **Notification Schedule**: Choose from presets or create custom
  - **Minimal**: 30, 7, 1 days
  - **Standard**: 60, 30, 21, 14, 7, 5, 3, 2, 1 days
  - **Frequent**: 90, 60, 45, 30, 21, 14, 10, 7, 5, 3, 2, 1 days
  - **Business Focused**: 60, 30, 14, 7, 3, 1 days
  - **Custom**: Enter your own comma-separated days

- **Check Interval**: How often to check domains
  - Every 6 hours
  - Every 12 hours  
  - Daily (24 hours)
  - Every 2 days
  - Weekly

#### System Settings
- **Auto-Detected Cron Path**: Copy-paste ready cron commands with your actual installation path
- **Log File Locations**: Find logs for troubleshooting

### User Notifications

Stay informed with the in-app notification system:

#### Notification Center
- **Bell Icon**: Top navigation shows unread count with animated indicator
- **Dropdown Preview**: Quick view of 5 most recent unread notifications
- **Full Page**: `/notifications` with complete history and management

#### Notification Types
- 📬 **Welcome** - Sent when you create an account or system is installed
- ⬆️ **System Upgrade** - Admins notified when system is updated (includes version & migration count)
- 🔴 **Domain Expiring** - Alerts based on your configured thresholds
- ⚠️ **Domain Expired** - Critical alerts for expired domains
- 🔄 **Domain Updated** - WHOIS data changes detected
- 🔐 **New Login** - Security alerts for new device logins
- ❌ **WHOIS Failed** - Lookup errors and issues

#### Notification Features
- **Filter by Status**: Unread, Read, or All
- **Filter by Type**: Domain, System, or Security notifications
- **Date Ranges**: Today, This Week, This Month, All Time
- **Pagination**: View 10, 25, 50, or 100 per page
- **Quick Actions**: Mark as read, Delete, Mark all read, Clear all

### Profile Management

Access your profile settings via the top-right user menu:

#### My Profile
- Update full name and email
- View account creation date
- Check last login timestamp
- Email verification status

#### Security
- Change password securely
- Password strength requirements
- Security best practices

#### Active Sessions
- **View All Sessions**: See every device where you're logged in
- **Session Details**: Location (country, city), ISP, device type, browser
- **Country Flags**: Visual indicators for each session location
- **Session Age**: See when each session was created
- **Last Activity**: Monitor recent activity per session
- **Remember Me Indicator**: See which sessions have "remember me" enabled
- **Remote Logout**: Terminate individual sessions or all other sessions
- **Instant Termination**: Deleted sessions are logged out immediately

All settings are stored in the database and can be updated at any time through the web interface.

## 📁 Project Structure

```
Domain Monitor/
├── app/
│   ├── Controllers/        # Application controllers
│   ├── Models/            # Database models (User, Domain, SessionManager, etc.)
│   ├── Services/          # Business logic & services
│   │   ├── Channels/      # Notification channel implementations
│   │   └── NotificationService.php  # Notification creation & management
│   ├── Helpers/           # Helper classes for formatting & display logic
│   │   ├── LayoutHelper.php     # Global layout data (notifications, stats)
│   │   ├── DomainHelper.php     # Domain formatting & calculations
│   │   └── SessionHelper.php    # Session display formatting
│   └── Views/             # HTML views (pure display, no business logic)
├── core/                  # Core MVC framework
│   ├── DatabaseSessionHandler.php  # Database session storage
│   ├── SessionValidator.php        # Session validation middleware
│   ├── Auth.php           # Authentication helpers
│   └── ...
├── cron/                  # Cron job scripts
├── database/
│   └── migrations/        # Database migrations
├── public/                # Web root (index.php, assets)
├── routes/                # Route definitions
├── vendor/                # Composer dependencies
└── .env                   # Environment configuration
```

## 🔐 Security Considerations

1. **Never commit `.env`** - Contains sensitive credentials
2. **Secure your web server** - Point only the `public` directory to the web
3. **Use strong database passwords**
4. **Enable HTTPS** in production
5. **Protect cron endpoints** - Ensure cron scripts aren't web-accessible
6. **Regular updates** - Keep dependencies updated

## 🐛 Troubleshooting

### WHOIS Lookup Fails

- Some domain TLDs may not be supported
- Check if the domain is valid and registered
- Verify your server can make outbound connections

### Notifications Not Sending

1. Check logs: `logs/cron.log`
2. Verify notification channel configuration in **Settings → Email**
3. Test email using the built-in test function in Settings
4. Check SMTP/API credentials in Settings

### Database Connection Error

- Verify database credentials in `.env`
- Ensure MySQL service is running
- Check if database exists

### Cron Job Not Running

- Verify cron syntax and paths
- Check server logs
- Test manually: `php cron/check_domains.php`

## 🐛 Bug Reports & Feature Requests

We welcome bug reports and feature requests! Please use GitHub Issues:

### 🐞 Report a Bug
Found a bug? [Open an issue](https://github.com/ispgrid/domain-monitor/issues/new?template=bug_report.md) with:
- Clear description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Environment details (PHP version, OS, etc.)

### 💡 Request a Feature
Have an idea? [Submit a feature request](https://github.com/ispgrid/domain-monitor/issues/new?template=feature_request.md) with:
- Clear description of the feature
- Use case and benefits
- Any implementation ideas

## 🤝 Contributing

Contributions are welcome and appreciated! Here's how you can help:

### How to Contribute

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/AmazingFeature`)
3. **Make your changes**
4. **Test thoroughly**
5. **Commit your changes** (`git commit -m 'Add some AmazingFeature'`)
6. **Push to the branch** (`git push origin feature/AmazingFeature`)
7. **Open a Pull Request**

### Development Guidelines

- Follow PSR-12 coding standards
- Write clear commit messages
- Add comments for complex logic
- Test your changes before submitting
- Update documentation as needed

### Areas for Contribution

- 🐛 Bug fixes
- ✨ New features
- 📝 Documentation improvements
- 🌍 Translations
- 🎨 UI/UX enhancements
- ⚡ Performance optimizations

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

**TL;DR:** Free to use for personal and commercial projects. Attribution appreciated but not required.

## 📧 Support & Community

- 🐛 **Issues:** [Bug Tracker](https://github.com/ispgrid/domain-monitor/issues)
- 📖 **Documentation:** this README and [CHANGELOG.md](CHANGELOG.md)
- ⭐ **Star the project** if you find it useful!

## 🍴 About This Fork

This is an actively maintained fork of [Hosteroid/domain-monitor](https://github.com/Hosteroid/domain-monitor), maintained by [ispgrid](https://github.com/ispgrid). Please report issues with this fork here rather than to the original project. To move an existing Hosteroid install to this fork, see [Switching from Hosteroid/domain-monitor](#-switching-from-hosteroiddomain-monitor).

## 🙏 Acknowledgments

- Originally created by [Hosteroid](https://www.hosteroid.uk) ([Hosteroid/domain-monitor](https://github.com/Hosteroid/domain-monitor))
- WHOIS/RDAP data from [IANA](https://www.iana.org/)
- Built with modern PHP and love ❤️

## 📊 Project Stats

![GitHub stars](https://img.shields.io/github/stars/ispgrid/domain-monitor?style=social)
![GitHub forks](https://img.shields.io/github/forks/ispgrid/domain-monitor?style=social)
![GitHub issues](https://img.shields.io/github/issues/ispgrid/domain-monitor)
![GitHub pull requests](https://img.shields.io/github/issues-pr/ispgrid/domain-monitor)

---

<div align="center">

**Maintained by [ispgrid](https://github.com/ispgrid) • Originally created by [Hosteroid](https://www.hosteroid.uk)**

[Report Bug](https://github.com/ispgrid/domain-monitor/issues/new?template=bug_report.md) • [Request Feature](https://github.com/ispgrid/domain-monitor/issues/new?template=feature_request.md)

</div>

