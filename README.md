# Research Data Management & Academic Supervision System (FUD RDM System)

An enterprise-grade, web-based Research Data Management (RDM), Academic Supervision, and Institutional Repository Platform designed for Higher Education Institutions.

---

## Technical Architecture & Stack

- **Backend**: Native PHP (Version 8.0+)
- **Database**: MariaDB / MySQL (Version 10.4+) with PDO & Prepared Statements
- **Frontend**: Responsive Vanilla CSS & Modern ES6 JavaScript (No bloated external dependencies)
- **Security**: Server-Side Role-Based Access Control (RBAC), Super Admin Governance Read-Only Guard, CSRF token validation, XSS escaping, strict IDOR object-level authorization, and parameterized SQL queries.

---

## Key Modules & Role Hierarchy

### 1. Super Admin (Institutional Governance)
- Read-only institutional governance and compliance monitoring across all departments, research projects, supervisor workloads, repository deposits, and system audit logs.
- Exclusive privilege to designate and revoke System Administrator roles.
- Server-side read-only guard preventing operational mutations.

### 2. System Administrator
- Operational system management, user provisioning, academic structure configuration (faculties, departments, programmes, academic sessions), and student-supervisor assignments.

### 3. Academic Supervisor
- Consolidated student supervision workspace, proposal & chapter reviews, milestone tracking, direct student messaging, correction management, and viva/defense authorization.

### 4. Student / Researcher
- Research project lifecycle management, Data Management Plan (DMP) creation, chapter uploads, progress tracking, milestone submission, supervisor feedback reviews, and final repository submission.

### 5. Librarian & Repository Manager
- Repository deposit review, metadata curation (DOI / Handles), publication approval, digital preservation records, and a dedicated **Report Generation & Export Engine** (PDF / CSV export).

---

## Installation & Setup Instructions

### Prerequisites
- PHP >= 8.0 with `pdo_mysql`, `mbstring`, and `gd` extensions enabled.
- MySQL / MariaDB Server (v10.4+).
- Web Server: Apache (XAMPP / WAMP) or Nginx.

### Step 1: Clone Project Repository
Clone or place the project files into your web root directory (e.g. `C:/xampp/htdocs/rdm_system` or `/var/www/html/rdm_system`).

### Step 2: Database Setup
1. Open MySQL / phpMyAdmin or MySQL CLI.
2. Import the complete 35-table database schema and seed data from `database/rdm_system.sql`:
   ```bash
   mysql -u root -p < database/rdm_system.sql
   ```

### Step 3: Database Configuration
Configure database connection settings in `config/database.php`. For custom local credentials without modifying tracked files, create `config/database.local.php`:
```php
<?php
$host = "localhost";
$dbname = "rdm_system";
$username = "your_username";
$password = "your_password";
```

### Step 4: Storage Directory Permissions
Ensure the web server has write permissions for file upload and backup directories:
- `storage/uploads/`
- `storage/backups/`
- `uploads/`

---

## System Security & Audit Guidelines

- **Authentication & RBAC**: Enforced on every endpoint via `requireAuth()` and role-based checks.
- **Audit Logging**: All critical actions (logins, role changes, supervisor assignments, project approvals, repository publication) generate immutable audit records in `audit_logs`.
- **Pre-GitHub Review**: Sensitive credentials, scratch scripts, local dumps, and private upload files are excluded via `.gitignore`.
