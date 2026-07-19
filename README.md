# CenterTime - Multi-Tenant Time Tracking Platform
## Comprehensive Technical System Architecture, Low-Level Design (LLD), & Developer Guide

Welcome to the **CenterTime** technical documentation. This document is designed to guide senior architects and junior developers alike through the entire platform's architecture, design decisions, database models, and critical workflows.

---

## 1. High-Level System Architecture

CenterTime is built on the **Symfony 8.1** framework using a multi-tenant architecture with logical data isolation. All tenants (organizations) share the same application instance and database schema, but their data scopes are strictly isolated at the query level using organization foreign keys.

### 1.1 Conceptual Architecture Diagram
The diagram below illustrates how client devices interact with the frontend, controllers, core services, and the database layer.

```mermaid
graph TD
    %% Clients
    subgraph Clients [Client Layer]
        KioskBrowser[Kiosk Tablet / Public Browser]
        ManagerBrowser[Manager Dashboard / Desktop Browser]
        AdminBrowser[Platform Admin Console]
    end

    %% Web Application Gateway
    subgraph AppServer [Symfony Application Server]
        ControllerLayer[Controller Layer]
        Routing[Symfony Router & Security Firewall]
        
        subgraph CoreServices [Core Business Services]
            PinService[App\Service\PinService]
            SweeperService[App\Service\SessionSweeperService]
            MatchingService[App\Service\UserMatchingService]
        end

        subgraph ORMLayer [Doctrine ORM / Persistence]
            EntityManager[Doctrine EntityManager]
            Repositories[Entity Repositories]
        end
    end

    %% Database & Cache
    subgraph DatabaseLayer [Data Persistence & Cache]
        MySQL[(MySQL 8.4 Database)]
        HashCache[var/cache/pin_hashes.php]
    end

    %% Flow lines
    KioskBrowser -->|Public HTTP Request| Routing
    ManagerBrowser -->|Authenticated HTTP Request| Routing
    AdminBrowser -->|Admin Security Credentials| Routing
    
    Routing --> ControllerLayer
    
    ControllerLayer --> PinService
    ControllerLayer --> SweeperService
    ControllerLayer --> MatchingService
    
    PinService -->|Reads/Writes Cache| HashCache
    CoreServices --> ORMLayer
    ORMLayer --> Repositories
    Repositories --> EntityManager
    EntityManager -->|SQL Queries| MySQL
```

---

## 2. End-to-End User Journeys (Detailed Step-by-Step)

### 2.1 The Employee Clock-In Journey (Secure PIN Mode)
1. **Initial State (Idle Kiosk)**: The kiosk displays a digital clock (synced with browser time), a search input, and a list of active employees for autocomplete.
2. **Search & Name Selection**: The employee begins typing their name. The interface filters the list. The employee clicks their name.
3. **PIN Prompt**: The Kiosk transitions to a PIN entry overlay showing a numeric keypad.
4. **Credential Entry**: The employee types their 4-character PIN. Keypad inputs are masked for privacy.
5. **Form Submission**: The client dispatches a POST request to `/org/{slug}/kiosk/{token}/authenticate` containing:
   - `orgUser_id`: Integer ID of the employee.
   - `pin`: The entered 4-character string.
6. **Backend Authentication & Verification**:
   - `KioskController` checks if the user has >= 3 consecutive failed PIN attempts in the session. If yes, it blocks the check-in immediately.
   - `PinService` retrieves the employee's hashed PIN from the DB and verifies it against the entered PIN using `PasswordHasherInterface`.
   - If incorrect, failed attempts are incremented in the session. A JSON error is returned showing remaining attempts.
   - If correct, attempts are reset to 0.
7. **Category Selection**: The kiosk prompts the employee to select their active work category (e.g., "General Shift" vs. "Design Shift").
8. **Action Execution**:
   - System checks if the employee has an open session. If they do, they are checked out. If they do not, a new `WorkSession` is created with a start timestamp, organization, and category.
   - Visual confirmation (toast notification) is shown, and the kiosk resets back to the Idle state after 3 seconds.

### 2.2 The Manager CSV Roster Onboarding Journey
1. **File Selection**: The manager goes to the **Users** section and clicks **Import Users**. They select a `.csv` file.
2. **Client-Side Preview**: The browser parses the CSV file on-the-fly, showing a table of the first 5 parsed rows.
3. **Unique Key Mapping**: The manager selects which CSV column contains the unique ID (e.g., Employee ID, Email, or Phone).
4. **Column Matching**: The manager maps remaining CSV columns to standard database fields (First Name, Last Name, Email, Phone) and custom fields.
5. **Dry-Run Conflict Report**: The manager submits the mapped configuration. The backend runs `UserMatchingService` to scan for matches in active DB records:
   - Matches by Unique Key -> Flagged as **Update**.
   - Matches by First Name + Last Name -> Flagged as **Update** (warns manager of duplicates).
   - No Match -> Flagged as **Add**.
   - Active users in DB missing from the CSV -> Flagged as **Skip/Deactivate**.
6. **Conflict Resolution**: The manager reviews each conflict on a screen, choosing to proceed, skip, or force insert.
7. **Execution**: The manager clicks **Commit**. The system starts a database transaction, creates/updates user entities, calls `PinService` to assign unique random conforming PINs, hashes them, persists the entities, writes system audit logs, and redirects to the user list.

---

## 3. Database Entity Specifications

The diagram below details the database tables, data types, indexes, and primary/foreign key constraints:

```
+---------------------------------------------------------------------------------+
|                                  ORGANIZATIONS                                  |
+---------------------------------------------------------------------------------+
| id                       | INT (PK, Auto-Increment, Unsigned)                   |
| name                     | VARCHAR(255) (NOT NULL)                              |
| slug                     | VARCHAR(255) (NOT NULL, UNIQUE INDEX)                |
| access_mode              | VARCHAR(50) (NOT NULL, Default: 'open')              |
| check_in_link_token      | VARCHAR(255) (NOT NULL)                              |
| plan_id                  | INT (FK -> plans.id, NOT NULL)                       |
| overtime_grace_minutes   | INT (NOT NULL, Default: 0)                           |
| overtime_multiplier      | DECIMAL(5,2) (NOT NULL, Default: 1.50)               |
| created_at               | DATETIME (NOT NULL)                                  |
| updated_at               | DATETIME (NOT NULL)                                  |
| deleted_at               | DATETIME (NULL, Index)                               |
+---------------------------------------------------------------------------------+
                                      |
                                      | 1
                                      |
                                      | Has Many
                                      |
                                      v 0..*
+---------------------------------------------------------------------------------+
|                                    ORG_USERS                                    |
+---------------------------------------------------------------------------------+
| id                       | INT (PK, Auto-Increment, Unsigned)                   |
| organization_id          | INT (FK -> organizations.id, ON DELETE CASCADE)      |
| first_name               | VARCHAR(100) (NOT NULL)                              |
| last_name                | VARCHAR(100) (NOT NULL)                              |
| email                    | VARCHAR(255) (NULL)                                  |
| phone                    | VARCHAR(50) (NULL)                                   |
| pin                      | VARCHAR(10) (NULL)                                   |
| pin_hash                 | VARCHAR(255) (NOT NULL)                              |
| is_active                | TINYINT/BOOLEAN (NOT NULL, Default: 1)                |
| unique_id_hash           | VARCHAR(255) (NULL)                                  |
| created_at               | DATETIME (NOT NULL)                                  |
| updated_at               | DATETIME (NOT NULL)                                  |
| deleted_at               | DATETIME (NULL, Index)                               |
+---------------------------------------------------------------------------------+
| INDEX uq_org_pin (organization_id, pin) UNIQUE                                  |
| INDEX uq_org_unique_hash (organization_id, unique_id_hash) UNIQUE                |
+---------------------------------------------------------------------------------+
                                      |
                                      | 1
                                      |
                                      | Has Many
                                      |
                                      v 0..*
+---------------------------------------------------------------------------------+
|                                  WORK_SESSIONS                                  |
+---------------------------------------------------------------------------------+
| id                       | INT (PK, Auto-Increment, Unsigned)                   |
| organization_id          | INT (FK -> organizations.id, ON DELETE CASCADE)      |
| org_user_id              | INT (FK -> org_users.id, ON DELETE CASCADE)          |
| category_id              | INT (FK -> categories.id)                            |
| started_at               | DATETIME (NOT NULL)                                  |
| ended_at                 | DATETIME (NULL)                                      |
| duration_seconds         | INT (NULL)                                           |
+---------------------------------------------------------------------------------+
| INDEX idx_active_sessions (org_user_id, ended_at)                               |
+---------------------------------------------------------------------------------+
```

---

## 4. Deep-Dive Design Level Details

### 4.1 centralizing the `PinService`
The `PinService` manages the allocation, security, and hashing parameters for organization PINs:

1. **Permutations Pool**:
   All secure kiosk check-in PINs follow the format `/^[ABC][0-9]{3}$/` (e.g. `A000` through `C999`). This generates a static pool of 3,000 distinct strings:
   $$\text{Pool} = \{\text{Prefix} \in [A,B,C]\} \times \{\text{Digits} \in [000..999]\}$$

2. **Performance Problem**:
   Standard password hashing algorithms (e.g., bcrypt with cost 10) require about 80ms to 100ms of CPU time per hash. Generating PINs and hashes sequentially for 100 seeded employees during database resets or large CSV imports creates a performance bottleneck:
   $$\text{Time} = 100 \times 100\text{ms} = 10\text{ seconds of blocking CPU time}$$
   This can cause PHP execution timeouts and sluggish user interfaces.

3. **Solution - Precomputed Cache**:
   The `PinService` solves this using a file-based memory cache at `var/cache/pin_hashes.php`.
   - On the very first request that needs a PIN hash, the service checks if this cache file exists.
   - If missing, it iterates through all 3,000 combinations, hashes them using Symfony's active password hasher (taking a few seconds), and writes them to the PHP cache file as an array return statement:
     ```php
     <?php return array (
       'A000' => '$2y$10$T8cK2...',
       'A001' => '$2y$10$Y1wH9...',
       // ... 3000 elements
     );
     ```
   - On all subsequent requests, `require 'var/cache/pin_hashes.php'` loads the pre-computed hashes in **<1ms**.
   - When checking or saving a user PIN, the service performs an $O(1)$ array key lookup. If the PIN doesn't follow the A/B/C structure (e.g. legacy/admin PINs), it falls back to hashing it on the fly.

### 4.2 Automated Session Sweeper Logic
The `SessionSweeperService` runs on every Kiosk load and as a scheduled console command (`app:sweep-sessions`). It terminates active sessions that exceed the allowed duration rules:

```mermaid
flowchart TD
    Start[Start Session Sweep] --> FetchOrgs[Fetch Organizations]
    FetchOrgs --> LoopOrgs[Loop through Organizations]
    LoopOrgs --> FetchActiveSessions[Fetch open sessions: endedAt IS NULL]
    FetchActiveSessions --> LoopSessions[Loop through Open Sessions]
    LoopSessions --> CalculateElapsed[Calculate elapsed minutes since startedAt]
    CalculateElapsed --> GetLimit{Get max session duration limit}
    GetLimit --> CheckLimit{Elapsed minutes > limit?}
    
    CheckLimit -- Yes --> AutoEnd[Set endedAt = startedAt + limit]
    AutoEnd --> SaveSession[Update session status and durationSeconds]
    CheckLimit -- No --> NextSession[Check next session]
    
    SaveSession --> NextSession
    NextSession --> LoopSessions
    LoopSessions --> LoopOrgs
    LoopOrgs --> End[End Session Sweep]
```

---

## 5. Developer Installation & Configuration Guide

### 5.1 Environment Configurations
The platform behavior is customized via environment variables in the `.env` file:
```env
# Database Settings
DATABASE_URL="mysql://checkin_user:checkin_password@127.0.0.1:3306/checkin_db?serverVersion=8.4.0&charset=utf8mb4"

# Application Settings
APP_ENV=dev
APP_SECRET=2e3fa87bd87d890bf7c834bcde11219b
```

### 5.2 Automated Setup Scripts

The platform includes two automated setup workflows for first-time installation (after database configurations are added to `.env`):

1. **One-Command Shell Script**:
   ```bash
   ./bin/setup
   ```
   *This executable bash script installs composer dependencies, creates the schema, runs database migrations, and seeds configurations automatically.*

2. **Composer setup script**:
   ```bash
   composer setup
   ```
   *Runs database initialization, migrations, and seeds parameters in one command.*

3. **Composer seed reset script**:
   ```bash
   composer seed-reset
   ```
   *Clears and recreates seed data (plans, platform admin, configurations, and default/demo organizations) while keeping other user-created database entries untouched.*

### 5.3 Manual Setup
If you prefer running commands manually:
```bash
# Install PHP packages
composer install

# Create Database
php bin/console doctrine:database:create --if-not-exists

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Seed data & configurations
php bin/console app:seed-data

# Clean and recreate seed data only
php bin/console app:seed-reset

# Start local server
php -S localhost:8000 -t public_html
```

### 5.4 Running Tests
The test suite utilizes PHPUnit. You can run all test cases with:

```bash
# 1. Prepare and seed the test environment database
php bin/console --env=test app:seed-data

# 2. Run all tests
php bin/phpunit

# Or run a specific test suite
php bin/phpunit tests/Controller/SupportControllerTest.php
```

---

## 6. Recent Platform Features & Architectural Enhancements

### 6.1 Centralized Seeding & Reset Configuration (`config/seed_data.json`)
All seeding, reset rules, plan tier thresholds, and 100 user profiles for the demo organizations have been centralized into a single JSON file. This eliminates external CSV dependencies and hardcoded PHP arrays, making plan parameters and user details simple to update in one place.

### 6.2 Organization ID Preservation during Demo Resets
To prevent breaking active URL links, bookmarks, and sessions, the asynchronous database reset system (`ResetDemoDataHandler`) now preserves organization records in-place:
- Instead of deleting and recreating the `basic-demo` and `golden-demo` organization rows (which increments primary keys), the handler updates them in-place.
- It deletes only dependent rows (`work_sessions`, `audit_logs`, `org_users`, `categories`, and `managers`) matching those specific organization IDs using raw SQL queries prior to seeding new users.

### 6.3 Collapsible Sidebar Layout & Navigation
The administrator dashboard uses a collapsible sidebar design (`#sidebarToggle`) that transitions smoothly:
- **Responsive Collapsing**: Slides down to a slim menu strip on desktop and collapses entirely into a header hamburger drawer on mobile (under `900px`).
- **Interactive Tooltips**: Fully-styled, theme-aware hover popovers (`.sidebar-link::after`) display menu labels when the sidebar is collapsed, preserving clarity and usability.

### 6.4 Step-by-Step Reset Audits & Async Tracking
Because the reset database workflow runs asynchronously via Symfony Messenger, we added step-by-step progress logging:
- Logs `SYSTEM_DEMO_DATA_RESET_START` when the background process begins.
- Logs `SYSTEM_DEMO_DATA_RESET_COMPLETE` once all database tables are refreshed and demo users are seeded.
- Audits are stored with `organization_id = NULL` under the system category so platform admins can easily track background reset performance from the console.
- Manual **Refresh** buttons have been integrated next to the titles in both Manager and Admin audit logs to quickly fetch updates.

### 6.5 Tabbed & Mobile-Compliant Settings Dashboard
The settings panels (both Manager Settings and Admin Global Settings) have been unified under a modern, tabbed panel layout:
- **Responsive Flex Distribution**: On mobile screens (under `768px`), tabs automatically adjust using `flex: 1` to fill the width evenly, rendering a compact, balanced design.
- **Tab State Persistence**: Utilizes `sessionStorage` to track the active tab, ensuring the page returns to the exact same setting tab after form submission or redirects.

### 6.6 New Configurable Global Settings
The following parameters are now stored dynamically in the `app_config` database table and can be edited from the Admin panel Settings page:
- `new_signups_enabled` (boolean): Toggles public registration. When disabled, homepage CTAs show "Registration Closed", pricing selectors are blocked, and direct requests to `/signup` are redirected with a flash warning.
- `session_sweep_throttle_seconds` (integer, default `60`): Configures the frequency lock of request-triggered database sweeps.
- `demo_reset_history_days` (integer, default `150`): Controls how many days of historical employee sessions are seeded during a demo reset.
- `audit_log_archive_dir` (string, default `/var/log/audit_archives`): Sets the directory where zipped database logs are archived.

### 6.7 Automated Dunning & Organization Archiving Flow
- **Dunning Transition**: When Stripe payments fail, the webhook disables the organization's subscription active status. Once the paid month transitions to the past, the background cron sweeper archives the organization.
- **Kiosk & Manager Access Restrictions**: Archived organizations block employee check-in keypad operations and card scanner calls. Managers are barred from editing users or importing rosters and are redirected to the billing tab with an advisory warning alert.

### 6.8 Administrative Data Backup & Cascade Purging
- **Hard Deletes**: Administrators can completely wipe organizations. Before executing the destructive cascade delete on the database, the system compiles a complete schema-wide backup (containing managers, users, categories, payment histories, session logs, and audit logs) into a serialized JSON file packaged inside a compressed ZIP archive under `var/archives/`.

### 6.9 Configurable Log Retention Schedules
- **Retention Dropdown**: Administrators can customize the database retention cleanups via settings, selecting daily, weekly, or monthly boundaries. The background cron matching checks the active settings on every run before calling the archive service.

### 6.10 Noisy Empty Cron Log Pruning
- **Log Noise Pruning**: The background cron execution generates a success log on every run. To prevent database bloating, the cron scheduler automatically prunes all `SYSTEM_CRON_EXECUTION` audit logs older than **2 hours** that didn't sweep any forgotten sessions, rotate credentials, archive tables, or change organization dunning state.
