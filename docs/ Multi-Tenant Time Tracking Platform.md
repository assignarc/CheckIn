
# TimeFlow - Multi-Tenant Time Tracking Platform

## Product Requirement Document (PRD)

---

## 1. Executive Summary & TL;DR

TimeFlow is a secure, web-first, multi-tenant check-in/checkout and time-tracking platform designed for organizations ranging from small local businesses to large enterprises. The system allows organizations (tenants) to manage employee attendance, track categorized work hours, configure automated attendance policies (session limits, overtime, check-in windows), and generate detailed exportable reports.

Built with **PHP 8.5** and **Symfony 8.1**, the platform focuses on high performance, tight data separation, and scalability. It features an interactive Kiosk interface for physical workspace tablet check-ins, a spreadsheet-driven user onboarding wizard, a self-service Stripe billing simulator, and a comprehensive platform-wide administrator control console.

---

## 2. Platform Vision & Objectives

### 2.1 Business Goals

* **Self-Service Onboarding**: Enable managers to sign up, configure their organization, and upload their employee roster in under 5 minutes without technical assistance.
* **Subscription Monetization**: Drive revenue through plan-gated tiers (Basic, Silver, Gold) with clear value propositions around data retention, user limits, and reporting.
* **Low Support Overhead**: Keep support queries related to core operations (Kiosk logins, PIN issues, spreadsheet uploads) below 2% of total active tenants.

### 2.2 User Goals

* **Frictionless Clock-in**: Employees should be able to identify themselves, select an activity category, enter credentials (if secure), and check in within 10 seconds.
* **Operational Integrity**: Prevent "buddy clocking" and password fatigue using standardized, easy-to-remember unique PINs.
* **Auditability**: Managers must be able to view, search, and verify time logs for compliance, payroll, or invoicing.

### 2.3 Out of Scope (Non-Goals)

* **Native Apps**: iOS/Android native applications are out of scope for the current web phase (though REST APIs are constructed to support them in the future).
* **Hardware Integration**: Integration with physical card readers, turnstiles, or biometric sensors.
* **Payroll Processing**: Direct calculation of tax deductions, banking payouts, or integration with external payroll processors (e.g., ADP, Gusto) is left for future iterations.

---

## 3. User Personas & Journeys (Detailed Step-by-Step)

### 3.1 Organization Employee / User

* **Attributes**: Non-technical or semi-technical; clocks in/out daily using a shared workspace tablet (Kiosk).
* **Clock-in Journey Steps**:
  1. **Locate Name**: The employee approaches the Kiosk screen. They type their name into the autocomplete search input, filtering the active roster.
  2. **Select Profile**: They click their name from the filtered dropdown.
  3. **Enter Credentials**: If the organization operates in **Secure PIN Mode**, a numeric keyboard overlay is displayed. The employee enters their 4-character PIN (e.g. `B045`). Keypad input is visually masked.
  4. **Select Category**: The Kiosk displays categories (e.g. "General Shift", "Overtime Task") configured for their tenant. The employee selects one.
  5. **Submission and State Verification**:
     - The request is dispatched. If the PIN is verified and the user does not have an open session, a new `WorkSession` is created.
     - The Kiosk displays a green "Success" banner, showing the check-in time.
     - After 3 seconds, the screen transitions back to the idle search state.

### 3.2 Organization Manager

* **Attributes**: Business owner, HR coordinator, or department head.
* **Onboarding & Setup Journey Steps**:
  1. **Registration**: The manager visits the signup page, enters organization details, selects a subscription tier, and inputs billing details (via Stripe simulator).
  2. **Roster Upload**: The manager navigates to the **Users** section and clicks **Import Roster**. They upload a CSV file of employees.
  3. **Mapping Configurations**:
     - Previews the first 5 rows of data.
     - Maps CSV columns to database fields (First Name, Last Name, Email, Phone) and custom fields.
     - Defines which column functions as the unique identifier.
  4. **Resolving Matches**:
     - The system reviews the database. It highlights rows that match existing records as "Updates" and non-matching records as "New Inserts".
     - The manager confirms or adjusts these mapping outcomes.
  5. **Activation**: Clicking **Commit** imports the records and generates secure, unique A/B/C PINs for all active members.
  6. **Configuration**: The manager goes to settings and sets session thresholds, grace windows, and creates category tags.

### 3.3 Platform Administrator

* **Attributes**: Platform owner or support representative.
* **System Maintenance Journey Steps**:
  1. **Global Dashboard Access**: The admin signs in to `/admin` using their superuser credentials.
  2. **Audit Logs Review**: Reviews system-wide actions to monitor configuration changes, exports, and support operations.
  3. **Support Intervention**:
     - Search for a specific organization (e.g., golden-demo).
     - Resolves tenant issues by overriding limits, resetting manager passwords, or manually adjusting expired work sessions.

---

## 4. Plan Gating & Feature Matrix

The platform gates features and resource limits dynamically based on the organization's subscription plan.

| Feature / Constraint           | Basic Plan         | Silver Plan            | Gold Plan                  |
| :----------------------------- | :----------------- | :--------------------- | :------------------------- |
| **Employee Limit**       | Max 10             | Max 100                | Max 1,000                  |
| **Manager Limit**        | Max 1              | Max 1                  | Unlimited                  |
| **Max Session Duration** | 2 Hours (Hard Cap) | 8 Hours (Customizable) | 24 Hours (Customizable)    |
| **Access Mode**          | Open Mode Only     | Open or Secure PIN     | Open or Secure PIN         |
| **Data Retention**       | 45 Days            | 90 Days                | 365 Days                   |
| **Report Exports**       | Disabled           | Excel/CSV Exports      | Excel/CSV Exports          |
| **Custom Fields**        | Max 3              | Max 10                 | Unlimited                  |
| **Category Limit**       | Max 1              | Max 5                  | Unlimited                  |
| **Communications**       | None               | System Email Logs      | Real/Simulated Email & SMS |

---

## 5. Security & Technical Architecture Rules

### 5.1 Standardized PIN Security

- **PIN format**: Must be exactly 4 characters. The first character must be **A**, **B**, or **C**, followed by exactly 3 numeric digits (e.g. `A001`, `B999`). This provides exactly 3,000 unique possible permutations per organization.
- **Uniqueness constraint**: Enforced via a unique constraint in MySQL `uq_org_pin` on `[organization_id, pin]`.
- **Brute-Force Protection**: A maximum of 3 consecutive failed PIN attempts within the session blocks Kiosk check-in. The lockout status is cleared when the manager resets the PIN.

### 5.2 Performance & Hash Caching

- **Hashing Overhead**: BCrypt password hashing takes ~100ms per call. To prevent timeouts during bulk employee uploads or demo data generation, the system pre-computes and caches BCrypt hashes for all 3,000 valid PINs into `var/cache/pin_hashes.php`.
- **Lookups**: `PinService` uses an $O(1)$ memory lookup on the precomputed cache array to instantly retrieve hashes during writes and comparisons.

### 5.3 Automated Session Sweeper

- To maintain data integrity, active sessions that exceed the allowed duration cap (based on organization settings or category limits) must be automatically cleaned up.
- The `SessionSweeperService` scans open sessions on every Kiosk request and terminates them, setting `endedAt` to the maximum permitted session limit.

---

## 6. Recent Platform Updates & Feature Specs

### 6.1 Unified Configuration-Driven Architecture
The application now uses [config/seed_data.json](file:///Users/vishalkhapre/Documents/Code/CheckIn/config/seed_data.json) as the source of truth for platform entities and system limits. Roster lists, plans, default settings, and categories are parsed dynamically, removing dependency on hardcoded PHP structures and raw CSV imports.

### 6.2 Nondestructive Demo Database Resets
The demo data refresh command processes updates without altering organization primary keys (`id`). Managers and users are purged using selective raw SQL queries on child rows, preserving organization URLs, tokens, and active sessions in-place.

### 6.3 Progressive Logging & Audits
Reset actions are executed asynchronously via Messenger. Two distinct audit logs (`SYSTEM_DEMO_DATA_RESET_START` and `SYSTEM_DEMO_DATA_RESET_COMPLETE`) trace the lifecycle of background worker tasks. Direct **Refresh** buttons on manager and admin log pages allow real-time audit polling.

### 6.4 Responsive Navigation Sidebar
The platform admin panel sidebar supports desktop collapsing to maximize data space and collapses into a responsive header drawer on mobile devices. Collapsed sidebar elements display floating label tooltips on hover to ensure clear navigation.

### 6.5 Tabbed & Compact Mobile Settings
Settings panels are organized under tabbed panels that stretch evenly (`flex: 1`) on mobile viewports. The active tab state is persisted in `sessionStorage` to maintain tab context across form submissions and page reloads.

### 6.6 Global Application Configuration Options
Exposed three new settings parameters in the administrative console:
- `new_signups_enabled` (boolean): Blocks signup routing and replaces landing page links with disabled indicators.
- `session_sweep_throttle_seconds` (integer): Regulates execution frequency of background database sweeps.
- `demo_reset_history_days` (integer): Determines length of generated employee history.
- `audit_log_archive_dir` (string): Specifies file storage paths for zipped database logs.
