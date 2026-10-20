# Heartfelt Pharmacy + Pharmacy Expiry Prototype

This project now includes a PHP + MySQL pharmacy expiry monitoring prototype alongside the existing billing POS system.

## Features
- **Secure login** with bcrypt hashed passwords and admin/staff roles
- **POS-style menu and order builder** for billing
- **Pharmacy dashboard** for medicine inventory and expiry monitoring
- **Add, edit, delete, search, filter, and report** medicine records
- **Automatic expiry status calculation** with Safe / Near-Expiry / Expired classification
- **Color-coded visual indicators** (green/yellow/red) with pulse animation on expired items
- **Admin-configurable threshold** (e.g., 30/60/90 days) via Settings or Alert Center
- **SMTP-based email alerts** for near-expiry and expired medicines
- **Role-based access control**: Staff can view inventory; only Admin can add/edit/delete
- **Interactive JS** — real-time table filtering, auto-dismiss alerts, hover effects

## Public vs staff routing

This app intentionally separates public browsing from protected staff access:

- Public landing page: `http://localhost/BILLING/` or `http://localhost/BILLING/index.php`
  - Shows the read-only medicine catalog with no visible staff login button.
  - Uses the medicines table as the source of truth for public stock visibility.
- Staff login route: `http://localhost/BILLING/staff/`
  - Hidden, non-obvious login path for staff/admin access.
  - Validates a server-side session before allowing access to protected pages.
- Staff-only pages: inventory, reports, settings, order flow, dashboard, etc.
  - Require a valid staff session via `requireLogin()` / `requireAdmin()`.

## Project structure
```
BILLING/
├── config/
│   ├── db.php           # Database connection, session guards, protected URL helpers
│   ├── mail.php         # SMTP mailer with PHPMailer-like raw socket implementation
│   └── mail.local.php   # SMTP credentials (gitignored)
├── includes/
│   ├── auth.php         # Legacy auth redirect helper
│   ├── header.php       # Sidebar + app shell header
│   ├── footer.php       # Footer + JS includes
│   └── medicine_helpers.php  # Expiry computation, alerts, threshold helpers
├── assets/
│   ├── css/style.css    # Complete stylesheet
│   ├── js/app.js        # Frontend interactivity (POS + pharmacy)
│   └── img/             # Menu item images
├── browse.php           # Public read-only medicine catalog (root landing page)
├── database.sql         # Full schema + seed data
├── index.php            # Root route that serves the public catalog
├── login.php            # Legacy redirect layer; actual staff login lives under /staff/
├── staff/
│   └── index.php        # Hidden staff login screen
├── pharmacy_dashboard.php  # Dashboard with status summary
├── pharmacy_inventory.php  # Inventory list with search/filter
├── sales.php               # Sales analytics (Daily/Monthly/Yearly, charts, categories)
├── api/sales.php           # Sales aggregates as JSON (page-permission guarded)
├── includes/permissions.php # Page registry, grants, guards (the ONE access source)
├── pharmacy_form.php       # Add/edit medicine (category dropdown)
├── pharmacy_alerts.php     # Alert center (admin only)
├── pharmacy_report.php     # Printable report
├── settings.php            # POS + pharmacy settings (admin only)
└── README.md               # This file
```

## Setup on XAMPP
1. Place this project in `C:/xampp/htdocs/BILLING`.
2. Start Apache and MySQL in XAMPP.
3. Import the database schema and seed data:
   - Open phpMyAdmin
   - Create a database named `billing_system`
   - Import [database.sql](database.sql)
4. Seed the admin account. Credentials are read from the [.env](.env) file in the project
   root (`ADMIN_USERNAME`, `ADMIN_PASSWORD`) — never from the frontend or main source code:
   ```
   php scripts/seed_admin.php
   ```
   A real environment variable overrides the file, e.g.
   `ADMIN_PASSWORD=your-chosen-password php scripts/seed_admin.php`.
   Re-run the script after editing `.env` whenever you want to rotate the admin password.
5. Update the database credentials in [config/db.php](config/db.php) if needed.
6. Open `http://localhost/BILLING/` for the public catalog, or `http://localhost/BILLING/staff/` for staff login.

## Logins
| Role  | Username  | Password |
|-------|-----------|----------|
| Admin | `admin`   | `ADMIN_PASSWORD` from [.env](.env), applied by [scripts/seed_admin.php](scripts/seed_admin.php) |
| Staff | self-registered via `staff-signup.php`, or created on the admin **Users** page | chosen at sign-up |

> Passwords are stored only as **bcrypt hashes** (`password_hash()` / `password_verify()`); there is no
> plain-text fallback. **Change the seeded admin password before going live.**

## Pharmacy module usage
1. Click **Pharmacy** in the sidebar to view the dashboard with summary counts.
2. Use **Manage Inventory** to view, search, and filter medicines.
3. Click **Add Medicine** (admin only) to add new stock, or **Edit** to update.
4. Use **Alert Center** (admin only) to adjust the warning threshold and send email alerts.
5. Use **Settings → Pharmacy Expiry Alert Settings** to configure threshold and notification email.
6. Open the **Printable Report** to print or review the full inventory.

## Email setup
The pharmacy alerts send mail through SMTP, which works with Gmail when you provide app credentials.

Edit [config/mail.local.php](config/mail.local.php) with your Gmail app credentials:

```php
<?php
return [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'your-email@gmail.com',
    'password' => 'your-gmail-app-password',
    'from_email' => 'your-email@gmail.com',
    'from_name' => 'Your Pharmacy',
];
```

> Gmail requires an **app password** instead of your regular password. Generate one at: Google Account → Security → App Passwords.

## Expired stock in the Menu (selling screen)

The Menu never shows or sells expired stock; Inventory and the expiry alerts still do, so staff
can find and shelve the expired batches.

- A batch is expired when `expiry_date < today`, compared with the **server's** date in one
  app timezone (`Asia/Manila` by default — override with `APP_TIMEZONE`; the MySQL session is
  pinned to the same offset with `APP_DB_TZ_OFFSET`, default `+08:00`). A batch that expires
  **today is still sellable** — recommended, because the medicine is usable that day and
  "expired" stays strictly before the date. (Hiding same-day stock is a one-line change if the
  shop prefers it.)
- The Menu shows each medicine once with the SUM of its **non-expired** batches; if every
  batch is expired the medicine disappears from the Menu entirely. It stays in the database,
  keeps its red "Expired" badge on the Inventory page, and keeps alerting in the pop-up.
- Filtering is server-side in `getSellableMedicineGroups()` — never CSS/JS. The server
  re-validates at cart-add (`ajax/add_item.php`) and at charge (`charge.php`), rejects expired
  items with `"… (Batch 1) has expired and was removed from your order."`, never deducts stock
  from an expired batch, and sells **FEFO** (earliest non-expiring batch first).
- The POS re-polls `api/menu_data.php` every 5 minutes, so an item that crosses midnight leaves
  the grid and the cart without a reload (checkout still re-validates).

## Sales page (daily / monthly / yearly)

**Sales** sits in the sidebar directly below **History** (admins always have it; staff only when an
admin grants it — see below). It reads the SAME completed orders History lists (`orders` with
`status = 'paid'` joined to `order_items`); there is no second copy of sales data, and cancelled or
voided orders are never counted because only `paid` is selected.

- **Daily** (date picker, defaults to today): total sales, orders, items sold, average order value,
  a bar chart by hour of the day and the change versus yesterday.
- **Monthly** (month picker): the same totals with a chart by day of the month, versus last month.
- **Yearly** (year picker): the same totals with a chart by month, versus last year.
- **Categories bought**: a donut of medicine categories with its own legend (percentage, units,
  money), switchable between **quantity** and **revenue**.
- **Top selling medicines**: name, category, quantity, revenue for the selected period.
- All money is formatted in Philippine pesos and every boundary uses **Asia/Manila** (one timezone
  app-wide: `APP_TIMEZONE`, with the MySQL session pinned to the same offset by `APP_DB_TZ_OFFSET`).
- Totals are aggregates computed in SQL (`salesReport()` in `config/db.php`), never by shipping rows
  to the browser. `sales.php` renders the first paint server-side; `api/sales.php` serves the same
  payload when a tab or picker changes. Charts use Chart.js (cdnjs, the same CDN as Font Awesome);
  if it cannot load, the numbers, legend and table still render.

### Medicine categories

Every medicine carries a `category` (blank counts as **Uncategorized**). Set it in
**Pharmacy → Add/Edit Medicine** with the Category dropdown — it lists the shared categories used by
the Menu and Settings, always offers the built-in suggestions (Pain Relief, Vitamins, Antibiotics,
Cough & Cold, Uncategorized) and a **+ Add new category…** option. The category name is **snapshotted
on each sale** (`order_items.category`), so re-categorising a medicine later never rewrites past
sales. Categories can also be created/reassigned in bulk from **Setting → Current Categories**.

## Page access (who sees what)

`includes/permissions.php` is the ONE source of truth for page access; the sidebar, the Menu header
buttons, the route guards and every JSON endpoint all read from it.

| Page key | Sidebar item | Staff default | Grantable to staff |
|---|---|---|---|
| `menu` | Menu | yes | — |
| `history` | History | yes | — |
| `sales` | Sales | no | yes |
| `users` | Users | no | **never** (admin only) |
| `settings` | Setting | no | **never** (admin only) |
| `pharmacy` | Pharmacy | no | yes |
| `inventory_dashboard` | (Menu header button) | no | yes |
| `inventory_alerts` | (alert bell + pop-up) | no | yes |
| `help` | Help Center | yes | — |

- A new staff account can open **Menu, History and Help Center** only. Hidden pages are removed from
  the sidebar *and* blocked server-side: typing the URL redirects to the Menu with
  *"You don't have access to this page."*, and the page's endpoints answer **403** JSON.
- Admins always have every page; `Users` and `Setting` cannot be granted to anyone else.
- **Users → Manage access** (on each staff row) opens a modal with the locked-on default pages plus a
  checkbox per grantable page. Grants are stored in `user_pages`, re-read from the database on every
  request, so a grant or revocation applies on the staff member's very next click — no re-signup and
  no waiting for a session to expire. Each change is written to `admin_logs`
  (`page_access_changed`) as an audit trail of who changed what, and when.

The table on the Users page shows each account's access as small badges (defaults muted, grants
highlighted, "All pages" for admins).

## Database migrations

Migrations are idempotent and run automatically on every request (`config/db.php`), so simply
loading a page applies them. To apply and verify them deliberately:

```sh
php scripts/migrate.php
```

Added by the Sales + page-access work:

| Change | Why |
|---|---|
| `order_items.category` (VARCHAR 100, default `Uncategorized`) | Category **snapshot** stored with each sold line so past sales keep the category they were sold under. Existing rows are backfilled once from the medicine's current category. |
| `user_pages` (PRIMARY KEY `user_id, page`) | One row per extra page grant behind **Users → Manage access**. Staff defaults live in code, not in this table. Deleting a user removes their rows. |

## Testing the sales aggregates and page access

```sh
php scripts/test_sales_permissions.php              # CLI: totals, buckets, categories, snapshot, grants
php scripts/test_sales_permissions_http.php         # end-to-end: sidebar, 403s, grant/revoke, charts
BASE_URL=http://localhost/BILLING php scripts/test_sales_permissions_http.php
php scripts/test_sales_permissions_http.php --cleanup   # remove this suite's users/orders/audit rows
php scripts/test_sales_permissions.php --cleanup        # remove leftover fixtures after a crash
```

## Testing the expiry rules

```sh
php scripts/seed_menu_test_data.php                    # seed the documented scenarios
php scripts/test_menu_expiry.php                       # PHP-layer assertions (exit != 0 on failure)
BASE_URL=http://localhost/BILLING php scripts/test_menu_expiry_http.php   # end-to-end HTTP suite
php scripts/test_menu_expiry_http.php --cleanup        # remove test orders/user/customer
php scripts/seed_menu_test_data.php --cleanup          # remove the seeded rows
php scripts/add_expiry_index.php --status              # medicines.expiration_date index check
```

Splitting `medicines` into a medicine catalog + batches table is **optional** — these rules do
not need it. See the safe, additive migration in `scripts/migrate_to_batches.php`.

## Recent improvements
- ✅ Removed plain-text password fallback — bcrypt only
- ✅ Added `requireAdmin()` to pharmacy add/edit/delete operations
- ✅ Added pharmacy settings (threshold + email) to Settings page
- ✅ Removed empty `pages/` directory and simplified asset path logic
- ✅ Added JS enhancements: pulse animation, auto-dismiss, real-time filter, hover effects
- ✅ Added CSS: print improvements, responsive layout fixes, status pill animations
- ✅ Added try/catch error handling to pharmacy pages
- ✅ Added **stock inventory tracking** for all menu products
- ✅ Added **expiration date** field when adding new products in Settings
- ✅ **Stock out detection**: Menu items show OUT and are disabled when stock = 0
- ✅ **Auto-decrement stock** when items are ordered via POS
- ✅ **Inline stock & expiry editing** directly in Settings product list
- ✅ **Auto-migration** for existing databases — adds `stock_quantity` and `expiration_date` columns
