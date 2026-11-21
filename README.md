# LoyalBox — customer loyalty rewards for boxed products

A small PHP 8 + MySQL web app for a factory that ships products in boxes. Each box has a unique code inside. Customers register, claim the code and earn points, then redeem points for rewards. Factory staff manage products, codes, rewards and customers, and watch for abuse.

**Live demo / deployment link:** none. This project has not been deployed anywhere.

## Features

**Customer** — register, sign in, edit profile and password · claim a code (typed, or opened from a printed link) · points balance and ledger history · browse and redeem rewards · redemption history.

**Factory admin** — separate secure sign-in · dashboard with live database numbers and a 14-day claims chart · products and points per box · generate batches of codes, download them once as CSV, export a status list, activate/deactivate single codes or whole batches · customers (view, block/unblock, per-customer investigation page) · rewards and stock · claims, redemptions (fulfil, or cancel with automatic refund), full points ledger · security page with flagged accounts/IPs/e-mails · activity log.

## Stack

PHP 8.1+ (PDO), MySQL 8 or MariaDB 10.5+, plain HTML/CSS, vanilla JavaScript, inline SVG icons. No framework, no Composer, no Node needed to run the app. See `ARCHITECTURE.md` for why.

## Install and run with XAMPP (no terminal needed)

### 1. Start the servers
Install **XAMPP for PHP 8.1 or newer** (apachefriends.org). In the XAMPP Control Panel press **Start** next to **Apache** and **MySQL**.

### 2. Put the project in `htdocs`
Copy the whole `loyalbox` folder to `C:\xampp\htdocs\loyalbox` (macOS: `/Applications/XAMPP/htdocs/loyalbox`, Linux: `/opt/lampp/htdocs/loyalbox`).

### 3. Import the database
1. Open <http://localhost/phpmyadmin>.
2. Click the **Import** tab. **Do not select any database first.**
3. Choose `database.sql` from the project folder and click **Import**.

The file creates the database `loyalbox` with its 11 tables. It is safe to import twice (existing tables and data are kept).

### 4. Open the setup check
Go to <http://localhost/loyalbox/public/check.php>. Every line should say **OK** (the *Admin account* line says "Fix needed" until step 5). If something is wrong, the line tells you how to fix it.

A fresh XAMPP uses MySQL user `root` with an empty password, and LoyalBox is already set up for that, so there is nothing to edit. If you gave MySQL a password (or the page shows "database is not ready" even though MySQL is running), copy `config/config.example.php` to `config/config.local.php` and put the password there.

### 5. Create the admin account
Open <http://localhost/loyalbox/public/admin/>. The first time, it asks you to create the admin (name, e-mail, password of at least 10 characters). This page disappears once an admin exists, so do it before you share the address with anyone.

### 6. Use it
| What | Address |
|---|---|
| Customers | <http://localhost/loyalbox/public/customer/> |
| Admin | <http://localhost/loyalbox/public/admin/> |
| Setup check | <http://localhost/loyalbox/public/check.php> |

On macOS/Linux, if the setup check says the `config` folder is not writable, run `chmod -R 777 config` once.

### Secret key (important)
The first time you generate codes, LoyalBox creates a random secret in `config/secret.key`. Codes are stored only as hashes made with it, so **back this file up and never delete it**: without it no existing code can be verified. (You can instead set `CODE_PEPPER` in `config/config.local.php` or `.env`.)

### Troubleshooting
| Problem | Fix |
|---|---|
| "#1046 No database selected" while importing | You had a database open in phpMyAdmin. Import with **none** selected (or just use the current `database.sql`, which selects it for you). |
| "The database is not ready" page | MySQL is not started, or `database.sql` was not imported. Open `check.php`. |
| Forgot the admin password | In phpMyAdmin open table `admins`, delete the row, then open `public/admin/` to create a new one. |
| `check.php` says private folders are NOT blocked | In XAMPP's `httpd.conf` set `AllowOverride All` for htdocs and restart Apache. |

## Optional: command line / other servers

```bash
mysql -u root -p < database.sql                       # same file; creates the database
php scripts/setup.php --admin=you@example.com         # alternative to the browser setup page
php scripts/setup.php --demo                          # optional sample data, labelled "Demo"
php -S localhost:8000 -t public                       # built-in server: http://localhost:8000/customer/
```

For production, point the web server's document root at `public/` (so `src/`, `config/` and `.env` are not reachable at all), serve over HTTPS and set `APP_URL` so printed claim links use your real address.

## Typical demo script

1. Admin → Products → add a product with points. Admin → Codes → Generate → download the CSV.
2. Customer → register → Claim a product → paste a code from the CSV. Points appear.
3. Try the same code again, or as another customer: refused. Enter wrong codes five times: locked for 15 minutes.
4. Customer → Rewards → redeem. Admin → Redemptions → fulfil or cancel (cancel refunds through the ledger).
5. Admin → Security shows the failed attempts; Activity log shows who did what.

## Tests

See `tests/TEST_PLAN.md` for results. Quick version (needs a MySQL user allowed to create the `loyalbox_test` database; it only ever touches that database):

```bash
export TEST_ADMIN_USER=root_or_admin TEST_ADMIN_PASS=...   # may CREATE DATABASE
export DB_USER=$TEST_ADMIN_USER DB_PASS=$TEST_ADMIN_PASS
php tests/run_tests.php                  # business rules + parallel-process race tests
# HTTP + browser tests need a dev server on the TEST database:
(cd public && DB_NAME=loyalbox_test CODE_PEPPER=ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12 php -S 127.0.0.1:8081) &
php tests/http_test.php
(cd tests/ui && npm install && node ui_test.mjs)   # headless Chromium
php tests/css_audit.php                  # design-token audit
```

## Important notes

* **A code proves possession of a valid code, not a retail purchase.** Someone who finds, buys or steals a box also holds the code. The admin Security page helps spot abuse; it cannot prove where a box was bought.
* Plaintext codes exist only in the browser right after generation. Download them then.
* Known limitations are listed in `ASSUMPTIONS.md` and `tests/TEST_PLAN.md`.

## Project layout

```
public/            web root: customer/, admin/, check.php, assets/ (css, js)
src/               config, db, helpers, security, services (business rules), layout
database.sql       creates the database and tables (import in phpMyAdmin)
config/            optional settings, auto-created secret.key (never published)
scripts/setup.php  optional CLI: tables, first admin, demo data
tests/             automated tests and results
```
