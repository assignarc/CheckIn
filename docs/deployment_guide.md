# Production Deployment Guide - CenterTime (SiteGround)

This document provides step-by-step instructions for preparing, uploading, configuring, and optimizing the **CenterTime** Symfony application for a SiteGround shared or cloud hosting environment.

---

## 1. Prerequisites

Before beginning the deployment, ensure you have:

1. **SiteGround Account** with access to "Site Tools".
2. **SSH Access** enabled on SiteGround for your website.
3. **Local Terminal** configured with SSH to connect to your SiteGround server.

---

## 2. Step 1: SiteGround Host Preparation

### 2.1 Enable SSH Access

1. Log into your SiteGround account and navigate to **Websites > Site Tools** for your domain.
2. Go to **Devs > SSH Keys Manager**.
3. Generate a new SSH key pair or import your public key.
4. Note your SSH connection details (Port and IP address) listed under "SSH Credentials".

### 2.2 Create the Database

1. In Site Tools, navigate to **Site > MySQL**.
2. Go to the **Databases** tab and click **Create Database**. Note the database name (e.g., `username_checkin`).
3. Go to the **Users** tab and create a new MySQL user (e.g., `username_dbuser`). Keep the password secure.
4. Assign the new user to your new database with "All Privileges".

### 2.3 PHP Version Configuration

1. Navigate to **Devs > PHP Manager**.
2. Ensure the PHP version for your domain is set to **PHP 8.4** (as required by `composer.json`).

---

## 3. Step 2: Uploading the Application Files

On SiteGround, the remote root of the site is `/`, and the public document root directory is `/public_html`.
To ensure production security, all backend Symfony files (`src`, `config`, `templates`, `vendor`, `.env`) are uploaded to the site root `/` (making them inaccessible from the web browser), while the public index file and assets (from your local `public_html` folder) are placed inside `/public_html`.

### Option A: Uploading via Automated Python FTP Script (Recommended for FTP deployments)

The project includes a Python script `deploy/ftp_deploy.py` that recursively uploads files, placing backend files at `/` and public assets inside `/public_html`, while excluding local dev configurations, caches, logs, and Git metadata.

1. **Configure credentials**: Copy the configuration template inside `deploy/`:
   ```bash
   cp deploy/config.json.dist deploy/config.json
   ```
2. **Fill in credentials**: Open `deploy/config.json` and fill in your SiteGround FTP credentials. Ensure `"remote_dir"` is set to `"/"` (the site root directory):
   ```json
   {
     "host": "ftp.yourdomain.com",
     "port": 21,
     "username": "your_ftp_username",
     "password": "your_ftp_password",
     "remote_dir": "/",
     "use_ssl": true
   }
   ```
3. **Run dry-run**: Check what files will be uploaded:
   ```bash
   python3 deploy/ftp_deploy.py --dry-run
   ```
4. **Execute deploy**: Upload the project:
   ```bash
   python3 deploy/ftp_deploy.py
   ```

### Option B: Uploading via SSH and Git (Alternative)

If you prefer SSH-based version control deployment:

1. SSH into your SiteGround server:
   ```bash
   ssh username@server_ip -p 18765
   ```
2. Navigate to your site's root directory (one level above `public_html`):
   ```bash
   cd ~/www/yourdomain.com/
   ```
3. Backup or rename the existing `public_html` directory:
   ```bash
   mv public_html public_html_backup
   ```
4. Clone the project repository (you may need to set up a deployment key in your Git provider):
   ```bash
   git clone git@github.com:your-repo/checkin.git checkin_app
   ```
5. Symlink the project's `public_html` to the server's expected document root:
   ```bash
   ln -s checkin_app/public_html public_html
   ```

---

## 4. Step 3: Application Configuration

Symfony uses environment variables to configure infrastructure. **Never commit production credentials to source control.**

Create a new file named **`.env.local`** directly on the production server (in your application root folder) with your production settings:

```ini
# Production Environment Type
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=GenerateARandomHashHereForSecurity

# Production Database Credentials (SiteGround uses 'localhost' or '127.0.0.1')
DATABASE_URL="mysql://db_user:db_password@127.0.0.1:3306/db_name?serverVersion=8.0.32&charset=utf8mb4"

# Messenger Queue Transport
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# Production SMTP/Mailer Configuration
# Be sure to URL-encode username (@ to %40) and password (# to %23, etc.)
MAILER_DSN="smtp://no-reply%40centertime.us:1_%29Hu12%23o%7Df1@gtxm1343.siteground.biz:465"

# Production Stripe Keys
STRIPE_PUBLIC_KEY="pk_live_your_actual_live_public_key"
STRIPE_SECRET_KEY="sk_live_your_actual_live_secret_key"
STRIPE_WEBHOOK_SECRET="whsec_your_actual_live_webhook_signing_secret"
```

---

## 5. Step 4: Installing Dependencies & Preparing Assets

SSH into the server, navigate to the application directory, and perform the following:

### 5.1 Run Composer Install

SiteGround comes with Composer pre-installed. Execute:

```bash
composer install --no-dev --optimize-autoloader
```

### 5.2 Compile Production Assets (AssetMapper)

Compile assets to prepare them for fast static delivery:

```bash
php bin/console asset-map:compile
```

---

## 6. Step 5: Database Schema Initialization & Seeding

Since the oldest migration in this codebase assumes the initial schema tables (`employees`, `work_sessions`, etc.) are already present, initializing a **completely empty database** requires creating the schema first, syncing the migration tracker, and seeding:

### 6.1 Option A: Initializing a Brand New Database (Recommended)

If you are deploying to a new, empty database:

1. **Create the database tables matching the current entities**:
   ```bash
   php bin/console doctrine:schema:create
   ```
2. **Mark all migrations as completed** (since the schema is now at the latest state):
   ```bash
   php bin/console doctrine:migrations:version --add --all --no-interaction
   ```

If you are updating an existing database that already contains the initial tables, run the migration runner or update the schema directly to add the new archiving fields:

```bash
# Apply schema changes (such as the isArchived column)
php bin/console doctrine:schema:update --force
```

*Note: If you encounter memory limit issues on SiteGround shared hosting, prefix commands with memory limit overrides: `php -d memory_limit=512M bin/console ...`*

### 6.3 Seed Platform Settings & Admin User

Execute the seeding command to prepopulate plans, default categories, global configurations (including the new `audit_log_archive_schedule` defaults), and the system administrator account:

```bash
php bin/console app:seed-data
```

### 6.4 Write Permissions for Data Archival Backups

Ensure the web server has write permissions on the archives folder to successfully export zipped JSON backups during organization deletions:

```bash
mkdir -p var/archives
chmod 775 var/archives
```

---

## 7. Step 6: Finalizing & Optimizing

### 7.1 Clear and Warm Up Cache

Force Symfony to build its DI Container and cache configurations under production settings:

```bash
php bin/console cache:clear
php bin/console cache:warmup
```

### 7.2 Set Permissions

Ensure the `var` directory is writable. (SiteGround usually handles suEXEC permissions automatically, but if you face issues, ensure directories are 755 and files are 644):

```bash
chmod -R 775 var/
```

### 7.3 Verify Apache Routing (`.htaccess`)

Ensure the `.htaccess` file generated by `symfony/apache-pack` inside `public_html/` was correctly uploaded. This file is required to route all incoming HTTP requests to Symfony's front controller `index.php`.

---

## 8. Step 7: Stripe Production Setup & Configuration

To enable live subscription upgrades, invoicing, and webhook synchronization on your production server, follow this detailed Stripe configuration guide.

### 8.1 Stripe API Credentials

1. Log into your **Stripe Dashboard** (or register at [stripe.com](https://stripe.com)).
2. Navigate to **Developers > API keys**.
3. Retrieve your live credentials:
   *   **Publishable key** (starts with `pk_live_`)
   *   **Secret key** (starts with `sk_live_`)
4. Save these keys in your server's `.env.local` file:
   ```ini
   STRIPE_PUBLIC_KEY="pk_live_your_actual_live_public_key"
   STRIPE_SECRET_KEY="sk_live_your_actual_live_secret_key"
   ```

### 8.2 Creating Products & Recurring Prices in Stripe

CenterTime supports three subscription tiers: **Basic** (Free), **Silver**, and **Gold** (and optionally **Platinum**). You must configure the paid tiers as recurring Products in Stripe:

1. In the Stripe Dashboard, go to **Product Catalog** and click **Add Product**.
2. **Create the Silver Plan Product**:
   *   **Name**: `Silver Plan`
   *   **Pricing model**: Standard pricing
   *   **Price**: `$29.00 USD` (or your chosen pricing)
   *   **Billing period**: Monthly (Recurring)
   *   Click **Save product**.
3. **Create the Gold Plan Product**:
   *   **Name**: `Gold Plan`
   *   **Price**: `$99.00 USD`
   *   **Billing period**: Monthly (Recurring)
   *   Click **Save product**.
4. Once created, note the generated **Price IDs** (starts with `price_...`) for both products.

### 8.3 Mapping Stripe Price IDs in the Codebase

Open [StripeController.php](file:///Users/vishalkhapre/Documents/Code/CheckIn/src/Controller/StripeController.php) on your local environment and map the Stripe Price IDs to the corresponding subscription tiers before deploying the controller:

```php
        // Map plans to your product/price IDs in Stripe Dashboard
        $stripePriceId = match($plan->getName()) {
            'silver' => 'price_1PxxYourSilverPriceIDHere',
            'gold' => 'price_1PxxYourGoldPriceIDHere',
            'platinum' => 'price_1PxxYourPlatinumPriceIDHere',
            default => null
        };
```

*Note: If you modify the controller locally, remember to upload the updated file `src/Controller/StripeController.php` to your server.*

### 8.4 Configuring the Stripe Customer Portal (Billing & Invoices)

Stripe's secure Customer Portal is used to allow managers to manage cards, view invoice history, and download receipts.

1. In the Stripe Dashboard, search for **Customer Portal** or navigate to **Settings > Billing > Customer portal**.
2. **Enable Billing Details & Invoices**:
   *   Under **Invoice history**, turn on **Invoices** to allow managers to view and download historical billing statements directly.
   *   Under **Payment methods**, choose which cards are accepted. Allow customers to add and update cards for upcoming renewals.
3. Save the portal configuration changes.

### 8.5 Production Webhook Endpoint Setup

Webhooks tell CenterTime when a payment succeeds or fails, keeping organization access and events logs synchronized.

1. Navigate to **Developers > Webhooks**.
2. Click **Add endpoint**.
3. **Configure the Webhook details**:
   *   **Endpoint URL**: `https://yourdomain.com/webhooks/stripe`
   *   **Select events to listen to**: Add the following three required events:
       *   `checkout.session.completed`
       *   `invoice.payment_succeeded`
       *   `invoice.payment_failed`
4. Click **Add endpoint**.
5. Copy the generated **Signing secret** (starts with `whsec_`).
6. Add the secret to your server's `.env.local` file:
   ```ini
   STRIPE_WEBHOOK_SECRET="whsec_your_actual_live_webhook_signing_secret"
   ```
7. Clear the production cache on your server to load the new environment variables:
   ```bash
   php bin/console cache:clear
   ```

---

## 9. Troubleshooting & Common Issues

- **404 Not Found on Custom Routes (e.g. `/login`):**

  - *Cause:* The `.htaccess` file is missing from the server's `public_html/` folder, meaning Apache is not performing URL rewriting to `index.php`.
  - *Fix:* Ensure the file `public_html/.htaccess` is uploaded to your remote `public_html/` directory.
- **Slow FTP Deployments / Script Hangs:**

  - *Cause:* Uploading the `vendor/` folder via FTP is extremely slow because it contains over 10,000 files.
  - *Fix:* Verify that `"vendor"` is listed under `EXCLUDE_DIRS` in `deploy/ftp_deploy.py`. Run `composer install` directly on the server via SSH instead of uploading packages.
- **Database Access Denied / Connection Fails:**

  - *Cause:* SiteGround DB names and usernames are prefixed (e.g., `db66v5ylqjuq4s_user`), or the user has not been granted database privileges.
  - *Fix:* In SiteTools under **Site > MySQL > Databases**, select your database, click **Add User**, and grant **"All Privileges"** to the username. Update the connection string in `.env.local` to match.
- **500 Internal Server Error:**

  - *Fix:* Check the application logs in `var/log/prod.log` on the server or the SiteGround Error Logs in Site Tools. You can temporarily enable debug mode by setting `APP_DEBUG=1` and `APP_ENV=prod` in `.env.local` (and clearing the cache) to display the error stack trace directly in your browser.
- **Blank Page:**

  - *Fix:* Warm up the cache using `php bin/console cache:clear` and check file permissions for the `var/` folder.
