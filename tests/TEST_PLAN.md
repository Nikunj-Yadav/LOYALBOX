# Test plan and actual results

Results below come from the runs described in `AI_LOG.md`, on PHP 8.3.6 + MariaDB 10.11 + headless Chromium, in a Linux sandbox. The `RESULTS*.md` files list every individual check.

## Automated suites

| Suite | Command | What it covers | Result |
|---|---|---|---|
| Business rules | `php tests/run_tests.php` | code format/normalisation, no plaintext storage, claim success, double claim, other user, inactive code/product, blocked user, batch deactivate, rate limiting, redeem/cancel/refund, DB-level constraints, **12-way and 8-way parallel-process races**, ledger vs balance | **61 / 61 passed** (`RESULTS.md`) |
| HTTP end-to-end | `php tests/http_test.php` | auth gating of every page, CSRF (missing/forged), security headers, cookie flags, registration validation, XSS escaping, password hashing, claim flow, SQL-injection-style input, rate-limited sign-in, admin CRUD, one-time code display + replay rejection, CSV export has no plaintext, blocking, activity log | **80 / 80 passed** (`RESULTS_HTTP.md`) |
| Browser UI | `cd tests/ui && npm i && node ui_test.mjs` | all 20 customer/admin screens × 3 viewports (desktop-light, tablet-light, mobile-dark): renders, no console errors, no horizontal overflow; theme toggle + persistence + exact brand colours; mobile menu; table search, filter, sort, counter, empty state; `/` shortcut; custom confirm dialog; modal pre-fill; inline validation; toasts; chart; count-up; CSV download; reduced-motion; exact primary-button counts | **117 / 117 passed** (`ui/RESULTS_UI.md`) |
| Design-token audit | `php tests/css_audit.php` | no colour/shadow/radius/font-weight outside tokens | **passed** |

Mutation check: removing the row locks and balance guard from `redeem_reward()` made 4 race checks fail, so the race tests do detect broken locking.

## Manual checks performed

| Check | Result |
|---|---|
| Looked at screenshots of admin dashboard, admin codes (desktop), customer rewards and admin customers (mobile, dark) | Consistent neumorphic look; issues found were fixed (see AI_LOG #9) |
| Stored `<script>alert(1)</script>` as a customer name | Rendered as plain text in tables and header |

## Not tested (be honest in the demo)

* Browsers other than Chromium; Safari and Firefox rendering (`color-mix`, `<dialog>`, `:focus-visible` need reasonably current versions).
* The real Manrope web font (sandbox had no internet; the system fallback was rendered).
* HTTPS and the `Secure` cookie flag (tests ran over plain HTTP).
* MySQL 8 (only MariaDB 10.11).
* Screen-reader use and automated accessibility tools such as axe; only keyboard behaviour, labels, focus rings and reduced-motion were checked by script.
* Load or performance testing; very large code batches in the browser tables (lists show the newest 500 rows).
* Camera/QR scanning: the app reads a typed code or a printed link; it does not scan QR images itself.
* Behind a reverse proxy: the app deliberately trusts only `REMOTE_ADDR`.

## Known limitations

* Lockout can be triggered against a victim's e-mail (ASSUMPTIONS #21); the guess budget is slightly soft under truly simultaneous attempts (#22).
* No e-mail verification and no password reset.
* A code proves possession, not retail purchase.
