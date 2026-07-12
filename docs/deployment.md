# Deployment Guide: SiteGround

This document provides step-by-step instructions for deploying the **CheckIn** Symfony application to a SiteGround shared or cloud hosting environment. 

## Prerequisites
Before beginning the deployment, ensure you have the following:
1. **SiteGround Account** with access to "Site Tools".
2. **SSH Access** enabled on SiteGround for your site.
3. **Local Terminal** configured with SSH to connect to your SiteGround server.

---

## Step 1: SiteGround Preparation (Site Tools)

### 1.1 Enable SSH Access
1. Log into your SiteGround account and navigate to **Websites > Site Tools** for your domain.
2. Go to **Devs > SSH Keys Manager**.
3. Generate a new SSH key pair or import your public key.
4. Note your SSH connection details (Port and IP address) listed under "SSH Credentials".

### 1.2 Create the Database
1. In Site Tools, navigate to **Site > MySQL**.
2. Go to the **Databases** tab and click **Create Database**. Note the database name (e.g., `username_checkin`).
3. Go to the **Users** tab and create a new user (e.g., `username_dbuser`). Keep the password secure.
4. Assign the new user to your new database with "All Privileges".

### 1.3 PHP Version
1. Navigate to **Devs > PHP Manager**.
2. Ensure the PHP version for your domain is set to **PHP 8.4** (as required by composer.json).

---

## Step 2: Uploading Files

SiteGround's default web directory is `public_html`. Since Symfony's public assets are also stored in a folder named `public_html` (as configured for this specific project), the directory structure maps perfectly.

### Option A: Uploading via SSH and Git (Recommended)
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

### Option B: Uploading via SFTP/ZIP
1. Compress your entire project folder locally (excluding `var/cache`, `var/log`, and `vendor` directories to save time).
2. Upload the ZIP file to `~/www/yourdomain.com/` using the SiteGround File Manager or an FTP client.
3. Extract the ZIP file via File Manager or SSH. Ensure the extracted folder's `public_html` aligns with the domain's document root.

---

## Step 3: Application Configuration

1. SSH into the server and navigate to your application directory (e.g., `~/www/yourdomain.com/checkin_app`).
2. Copy the example environment file:
   ```bash
   cp .env .env.local
   ```
3. Edit the `.env.local` file using `nano` or `vim`:
   ```bash
   nano .env.local
   ```
4. Configure the critical environment variables:
   - **APP_ENV**: Change to `prod`.
   - **APP_DEBUG**: Change to `0`.
   - **DATABASE_URL**: Update with your SiteGround database credentials. Note that SiteGround uses `localhost` for MySQL.
     ```env
     DATABASE_URL="mysql://db_user:db_password@127.0.0.1:3306/db_name?serverVersion=8.0.32&charset=utf8mb4"
     ```
   - **APP_SECRET**: Generate a secure random string (you can use `openssl rand -hex 16`).

---

## Step 4: Install Dependencies

SiteGround comes with Composer pre-installed. Run the following command in your project root to install the PHP dependencies for production:

```bash
composer install --no-dev --optimize-autoloader
```

---

## Step 5: Database Migrations & Seeding

### 5.1 Run Migrations
Run the Doctrine migrations to build the necessary database tables:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

*Note: If you encounter memory limit issues, prefix the command with `php -d memory_limit=512M bin/console ...`.*

### 5.2 Seed Default Platform Settings
Execute the seeding command to prepopulate plans, platform settings, default categories, and the system administrator account:

```bash
php bin/console app:seed-data
```

---

## Step 6: Finalizing the Production Setup

### 6.1 Clear and Warm Up Cache
Clear the Symfony cache and warm it up for the production environment:

```bash
php bin/console cache:clear
php bin/console cache:warmup
```

### 6.2 Set Proper Permissions
Ensure the `var` directory is writable. (SiteGround usually handles permissions automatically under suEXEC, but if you face issues, ensure directories are 755 and files are 644).

```bash
chmod -R 775 var/
```

### 6.3 Verify .htaccess
Since the project uses `public_html` as its public directory, the `symfony/apache-pack` should have generated an `.htaccess` file inside `public_html`. Ensure this file was correctly uploaded to SiteGround to route all traffic to `index.php`.

---

## Step 7: Testing & Verification

1. Visit your domain in a web browser. The CheckIn application should load successfully.
2. Attempt to log in or create a demo user to verify database connectivity.
3. Verify that CSS, JS, and image assets are loading correctly. If assets are missing, verify that the `public_html` folder structure is correct.

## Troubleshooting

- **500 Internal Server Error:** Check the application logs in `var/log/prod.log` or the SiteGround Error Logs in Site Tools.
- **Access Denied to Database:** Double-check the `DATABASE_URL` in `.env.local`. Ensure the DB user has full privileges.
- **Blank Page:** Ensure `APP_ENV=prod` and `APP_DEBUG=0` are set, and that you have cleared the cache using `php bin/console cache:clear`.
