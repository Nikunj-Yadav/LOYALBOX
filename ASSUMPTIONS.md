# Assumptions

The brief leaves many details open. Each decision below is one I made, with the reason.

| # | Unspecified point | Assumption | Reason |
|---|---|---|---|
| 1 | Code format | 16 characters from a 32-character alphabet (no I, L, O, U), shown as `XXXX-XXXX-XXXX-XXXX`. That is 80 bits of randomness from `random_bytes`/`random_int`. | Unpredictable, short enough to print, and easy to read from a box. |
| 2 | How codes are stored | HMAC-SHA256 with a secret `CODE_PEPPER` from `.env`. Only the last 4 characters (`code_hint`) are kept as a plain hint. | "Store hashes, not plaintext". A plain SHA-256 of an 80-bit code is already hard to reverse, but the pepper means a stolen database alone cannot be used to test guesses. The hint lets staff find a code on a damaged label. |
| 3 | Exporting codes | Plaintext codes are shown and downloadable **once**, right after generation. Later exports contain only hints, status and claim info. | Follows directly from not storing plaintext. The factory must send the first export to the printer. |
| 4 | QR codes | The export includes a claim URL (`.../claim.php?code=...`) per code. QR images are produced by the printing process from that column. The app does not draw QR images. | The stack rule forbids extra libraries. Opening the URL pre-fills the field; the customer still has to press the claim button. |
| 5 | Product-to-points | Points are set per product; every code of that product awards the product's current points. | Simplest model that matches "manage products and reward points". |
| 6 | Reward fulfilment | Redeeming deducts points at once and creates a `pending` redemption. An admin marks it `fulfilled` or `cancelled`. Cancelling refunds the points and restores stock through the ledger. | Gives a real workflow without shipping integration. |
| 7 | Rate limits | Customer claims: 5 failed attempts per 15 min per account, 15 per 15 min per IP. Logins: 5 failures per 15 min per e-mail, 20 per IP. Registrations: 5 per hour per IP. | Counted from the `security_events` table so they survive restarts and need no extra service. |
| 8 | "Suspicious" activity | An account with 5+ failed claims in 24 h, an IP with 10+ failures in 24 h, or an e-mail with 5+ failed logins in 24 h. | Simple, explainable thresholds. |
| 9 | Client IP | `REMOTE_ADDR` only. `X-Forwarded-For` is ignored. | Trusting that header lets attackers dodge rate limits. If you deploy behind a proxy, configure it to set REMOTE_ADDR. |
| 10 | Admin creation | Admins are created only by `scripts/setup.php` (CLI). There is no public admin registration. | Avoids a privileged sign-up page. |
| 11 | Customer e-mail | The e-mail is the login and cannot be edited in the profile. | Avoids an e-mail verification flow that was not requested. No e-mail verification or password reset is included. |
| 12 | Password rules | Minimum 10 characters, at most 72 bytes (bcrypt limit). Hashed with `password_hash()` (bcrypt). | NIST-style length over complexity rules. |
| 13 | Sessions | Separate session cookies for customers and admins, `HttpOnly`, `SameSite=Lax`, `Secure` when on HTTPS, ID regenerated at login. Admin sessions expire after 30 min idle, customers after 2 h. | Prevents one login from granting the other role. |
| 14 | Large tables | Lists load the newest 500 rows and search/sort/filter in the browser. | Keeps the JavaScript small. The limit is stated on screen. Codes can be filtered by status/batch on the server. |
| 15 | Batch size | 1 to 1,000 codes per batch. | Keeps one request fast; run several batches for more. |
| 16 | Deleting | Nothing is deleted. Products, rewards and codes are deactivated; customers are blocked. | Preserves the audit trail and foreign keys. |
| 17 | Fonts | Manrope is loaded from Google Fonts with a system fallback. | The brief asks for Manrope/Inter/Poppins. If offline, the system font is used and nothing breaks. |
| 18 | Charts | One small chart (claims per day) drawn with inline SVG, not Chart.js. | Chart.js is optional in the brief; avoiding a CDN dependency keeps the app working offline. |
| 19 | Retail purchase | A code proves **possession of a valid code**, not that the customer bought the product at retail. | Stated in the brief; also shown to customers on the claim page. |
| 20 | Time zone | `APP_TIMEZONE` (default UTC) is used by both PHP and the MySQL session. | Avoids mismatched timestamps. |
