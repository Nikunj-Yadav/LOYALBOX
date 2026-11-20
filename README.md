# LoyalBox — customer loyalty rewards for boxed products

A small PHP 8 + MySQL web app for a factory that ships products in boxes. Each box has a unique code inside. Customers register, claim the code and earn points, then redeem points for rewards. Factory staff manage products, codes, rewards and customers, and watch for abuse.

**Live demo / deployment link:** none. This project has not been deployed anywhere.

## Features

**Customer** — register, sign in, edit profile and password · claim a code (typed, or opened from a printed link) · points balance and ledger history · browse and redeem rewards · redemption history.

**Factory admin** — separate secure sign-in · dashboard with live database numbers and a 14-day claims chart · products and points per box · generate batches of codes, download them once as CSV, export a status list, activate/deactivate single codes or whole batches · customers (view, block/unblock, per-customer investigation page) · rewards and stock · claims, redemptions (fulfil, or cancel with automatic refund), full points ledger · security page with flagged accounts/IPs/e-mails · activity log.

## Stack

PHP 8.1+ (PDO), MySQL 8 or MariaDB 10.5+, plain HTML/CSS, vanilla JavaScript, inline SVG icons. No framework, no Composer, no Node needed to run the app. See `ARCHITECTURE.md` for why.

## Installation

Requirements: PHP 8.1+ with `pdo_mysql` and `mbstring`; MySQL 8 or MariaDB 10.5+.

```bash
# 1. Database and a least-privilege user (run as a MySQL admin)
mysql -u root -p -e "CREATE DATABASE loyalbox CHARACTER SET utf8mb4;
  CREATE USER 'loyal'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
  GRANT SELECT, INSERT, UPDATE, DELETE ON loyalbox.* TO 'loyal'@'localhost';"

# 2. Configuration
cp .env.example .env
#    edit .env: DB_* values, and a secret for CODE_PEPPER:
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The app user above cannot create tables, so create the schema with an admin account:

```bash
# 3. Create tables (DESTROYS existing LoyalBox tables) and the first admin
mysql -u root -p loyalbox < database/schema.sql
php scripts/setup.php --admin=you@example.com        # prompts for a password (min 10 chars)
#   optional sample data, clearly labelled "Demo":  php scripts/setup.php --demo
```

(`php scripts/setup.php --schema` does step 3's schema part too, but needs a `.env` user with CREATE/DROP rights.)

## Run

```bash
php -S localhost:8000 -t public
```

* Customers: <http://localhost:8000/customer/>
* Admin: <http://localhost:8000/admin/>

For production, point the web server's document root at `public/` (never at the project root, so `src/` and `.env` are not reachable), serve over HTTPS and set `APP_URL` in `.env` so printed claim links use your real address.

**Back up `CODE_PEPPER`.** Codes are stored as keyed hashes; if the secret is lost or changed, every existing code stops working.

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
public/            web root: customer/, admin/, assets/ (css, js)
src/               config, db, helpers, security, services (business rules), layout
database/          schema.sql
scripts/setup.php  CLI: schema, first admin, optional demo data
tests/             automated tests and results
```
