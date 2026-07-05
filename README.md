# TimeFlow - Check-In/Checkout Multi-Tenant Time Tracking Platform

TimeFlow is a robust, web-first check-in/checkout and time-tracking platform built with **PHP 8.5**, **Symfony 8.1**, and **MySQL**. It enables organizations of any size to track, categorize, and report work hours across multiple tenants.

## Features

- **Multi-Tenant Logical Separation**: Complete separation of database scopes.
- **Three Subscription Tiers**:
  - **Basic**: 10 employee limit, 1 manager, 2-hour max sessions, open kiosk mode (name selection from dropdown), no reports exports, 45-day retention.
  - **Silver**: 100 employee limit, 1 manager, 8-hour max sessions, secure PIN kiosk mode, Excel exports, 90-day retention, overtime rules configuration.
  - **Gold**: 1,000 employee limit, unlimited managers, 24-hour max sessions, secure PIN kiosk mode, Excel exports, 365-day retention, email/SMS notification alerts log simulation.
- **Interactive Check-In Kiosk Screen**: Displays local digital clock, search-to-select autocomplete, interactive 4-digit PIN pad with brute-force lockout (locked after 3 failures), and category selections.
- **Spreadsheet User Onboarding Wizard**: Multi-step manager onboarding flow to upload a CSV sheet, select unique index key, map custom column metrics, execute fuzzy matches, choose resolution outcomes (Add, Update, Skip), and commit with logs.
- **Auditing Trail**: Complete audit logging of all structural operations (PIN resets, limit overrides, imports, configurations changes).
- **Billing Sandbox Console**: Stripe billing simulator allowing managers to trigger invoice payments, upgrades/downgrades, and payment failure modes.
- **Platform Admin Dashboard**: Platform-wide metrics, tenant registry management, manager password resets, and overrides for user/manager limits.

---

## Pre-Seeded Credentials

Run the seeder command to automatically initialize the platform with these records:

### Consoles Login Credentials
- **System Administrator (Platform Console)**:
  - **Email**: `admin@platform.com`
  - **Password**: `admin123`
- **Acme Corporation Manager (Gold Plan)**:
  - **Email**: `manager@acme.com`
  - **Password**: `manager123`
- **Basic Demo Manager (Basic Plan)**:
  - **Email**: `basic@demo.com`
  - **Password**: `demo123`

### Kiosk Check-In PINs (Acme Corp)
- **John Doe**: `1234`
- **Jane Smith**: `5678`
- **Bob Johnson**: `4321`

---

## Installation & Setup

### Prerequisites
- PHP 8.5 or higher
- Composer
- Local MySQL Server
- Symfony CLI (Optional, but highly recommended)

### 1. Configure Environment Variables
Open the `.env` file in the root directory and configure your MySQL database connection:
```env
DATABASE_URL="mysql://checkin_user:checkin_password@127.0.0.1:3306/checkin_db?serverVersion=8.4.0&charset=utf8mb4"
```

### 2. Create MySQL Databases
Log into your local MySQL server and create the development and testing databases:
```sql
CREATE DATABASE IF NOT EXISTS checkin_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS checkin_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'checkin_user'@'localhost' IDENTIFIED BY 'checkin_password';
GRANT ALL PRIVILEGES ON checkin_db.* TO 'checkin_user'@'localhost';
GRANT ALL PRIVILEGES ON checkin_db_test.* TO 'checkin_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Bootstrap Doctrine Database Schemas
Build the tables in your databases by running Doctrine updates:
```bash
# Update development database
php bin/console doctrine:schema:update --force

# Update testing database
php bin/console --env=test doctrine:schema:update --force
```

### 4. Seed Initial Records
Populate the databases with pre-seeded organizations, managers, and employees:
```bash
# Seed development database
php bin/console app:seed-data

# Seed testing database
php bin/console --env=test app:seed-data
```

### 5. Start Local Development Server
Use Symfony CLI to start your server:
```bash
symfony local:server:start
```
Or start the standard PHP built-in server:
```bash
php -S localhost:8000 -t public
```

Access the home screen at [http://localhost:8000](http://localhost:8000).

---

## Running Automated Tests

Run the PHPUnit test suite to verify functional routing, kiosk access rules, and logins:
```bash
./vendor/bin/phpunit
```
