# M2S Customer Portal

A web-based customer/tenant portal for Mangga Dua Square (M2S) Mall. It allows tenants to view and manage their billing, make payments, access documents, submit support tickets, and stay updated with mall news and events. Administrators and finance staff can manage users, billing records, process payments, import VA bank records, and handle support tickets.


## Features

### Customer Portal
- **Authentication**: Secure login/logout with session-based auth
- **Dashboard**: Overview of outstanding balances, recent bills, payments, announcements, and upcoming events
- **Billing Management**: View all invoices, check status (paid/partial/pending), due dates, and payment details
- **Payments**: Track payment history with reference numbers and timestamps
- **News & Events**: View featured announcements, promotions, events, and maintenance notices
- **Documents**: Access public documents and private files shared with specific tenants
- **Support Tickets**: Create support tickets, chat with admin staff, and track ticket status
- **FAQ**: Built-in frequently asked questions

### Admin & Finance Features
- **User Management**: View and manage tenant/customer accounts
- **Billing Administration**: Create, edit, and manage billing records
- **Payment Processing**: Record and track customer payments
- **Virtual Account (VA) Import**: Import bank VA transaction records from Excel/CSV files with preview and review workflows
- **WhatsApp Billing Delivery**: Send billing notifications via WhatsApp with PDF attachments (supports Fonnte/Wablas/Twilio providers)
- **Support Ticket Management**: Respond to tickets, update status (open/in_progress/resolved/closed), and track conversations
- **Audit Logs**: Track user activities for security and compliance
- **Announcements**: Create and manage news/events/announcements


## Tech Stack

- **Backend**: PHP 8+ (vanilla PHP, no framework)
- **Database**: MySQL/MariaDB
- **Frontend**: Vanilla JavaScript (Single Page Application), HTML5, CSS3
- **Libraries**: [PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/) for Excel/CSV import processing
- **UI/Styling**: Custom CSS with Font Awesome 6.5 icons and Inter font


## Prerequisites

- PHP 8.0+ with required extensions:
  - pdo_mysql / mysqli
  - mbstring
  - zip (required for PhpSpreadsheet)
  - gd (optional, for image processing)
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache with mod_rewrite, or Nginx)
- Composer (for dependency management)

## Database Setup

Initialize the database schema and seed sample data:

```bash
php data/init_db.php
```

This will:
- Create all required tables (users, billing, payments, news, documents, support_tickets, ticket_messages, audit_logs, wa_settings)
- Add necessary columns and indexes
- Seed sample data for testing

**Default Test Accounts:**

| Role | Email | Password |
|------|-------|----------|
| Customer | juan@example.com | password123 |
| Customer | maria@example.com | password123 |
| Admin | admin@mall.com | password123 |
| Finance | finance@mall.com | password123 |

> ⚠️ **Important**: Change default passwords in production and never commit `.env` files. The `.env` and database dumps (`*.sql`) are gitignored for security.

## Usage

### Development (Laragon/XAMPP)

1. Start Apache and MySQL services
2. Navigate to `http://localhost/portal` in your browser
3. Login with one of the test accounts

### Production Deployment

1. Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
2. Ensure proper file permissions (uploads/ directory writable)
3. Use HTTPS with `secure` cookies enabled
4. Change all default credentials
5. Configure proper database credentials

## Security Features

- Session cookies with HttpOnly, Secure (when HTTPS), and SameSite=Lax
- CSRF/XSS protection via headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy)
- Password hashing with `password_hash()` (bcrypt)
- SQL prepared statements to prevent SQL injection
- Role-based access control (customer, admin, finance)
- Audit logging for user actions

## Import & Review Workflows

The portal includes specialized import tools for VA bank records:

- `import_va_preview.php` - Preview bank transactions before import
- `import_va_record.php` - Process and record payments from VA files
- Skipped rows during import are written to review CSV files (e.g., `va_skip_review_*.csv`) for admin review

> See the included skills (in `.opencode/skills/va-import-review/`) for detailed import review procedures.

## Project Structure

```text
portal/
├── api/                # API endpoints and backend logic
├── assets/             # CSS, JS, images, fonts
├── data/               # Database schema and initialization
├── lib/                # PHP utility libraries
├── uploads/            # User-uploaded files (gitignored)
├── vendor/             # Composer dependencies (PhpSpreadsheet)
├── .env.example        # Environment variables template
├── env.php             # Environment loader
├── index.php           # Main entry point / SPA router
├── composer.json       # PHP dependencies
└── README.md           # This file
```

## License

This project is proprietary software for Mangga Dua Square (M2S).
