# Calling CRM — Admin Shell

Clean AdminLTE base for the Calling CRM project.

## Login
- URL: `/CRM_google_data/`
- Auth table: `users` (username + password)
- No default user is seeded — create one after importing the schema.

## What's included
| Piece | Purpose |
|-------|---------|
| Login | Session auth against `users` table |
| Dashboard | Empty welcome shell |
| Layout | Sidebar, header, footer (AdminLTE) |
| Theme | Bootstrap 4 + AdminLTE + Font Awesome |

## Database
Import structure only (no seed data):

```bash
C:\xampp\mysql\bin\mysql.exe -u root < CRM_google_data/database/schema.sql
```

Database: `calling_crm`

### Tables
`users`, `leads`, `lead_imports`, `lead_assignments`, `call_logs`, `followups`, `lead_status_history`, `activities`, `settings`

### Excel Import
- Page: `import_excel.php` (sidebar → Excel Import)
- Files saved to `uploads/excel/`
- History in `lead_imports`; leads stored in `leads`
- Auto-detects columns; unknown columns → `extra_data` JSON
- Duplicates skipped by phone or business name
- Allowed: `.xls`, `.xlsx` · Max size: 10 MB
- Requires: `composer install` (PhpSpreadsheet)

### Lead Management
- Page: `leads.php` — DataTables AJAX list with filters & search
- View: `lead_view.php` · Edit/Add: `lead_edit.php`
- Bulk: delete, change status, assign executive
- Soft delete (`is_deleted`)

### Calling CRM
- Green **Call** button on lead list → opens lead with call modal
- Log Call: executive, date, duration, result, lead status, notes, remarks, next follow-up
- Call history + follow-ups stored per lead (`call_logs`, `followups`)

### Dashboard
- KPI cards: Total Leads, Today's Calls, Pending Follow-ups, Converted, New, Imported Files
- Charts: Leads by Status, Daily Calls, Monthly Imports, Executive Performance
- Recent activities + pending follow-up list

### Reports
- Page: `reports.php`
- Types: Calls, Leads, Follow-ups, Executive Performance, Conversion Rate, Import Summary
- Export: Excel (.xlsx), CSV, PDF


