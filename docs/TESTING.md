# Verification record and launch checks

Verified locally on 18 September 2026 using PHP 8.3.6, SQLite, Node 24 and headless Chromium 153. All databases, customer details and credentials used by automated tests were temporary fixtures. **No real Stripe payment or email was sent. Public bookings remain OFF in the shipped defaults.**

## Completed checks

**145 automated checks passed:**

- **53 service/payment/security checks** (`php tests/run.php`): disabled reservation and payment initiation; date/quantity/email validation; admin-only complimentary issue; capacity holds; six separate processes racing for one remaining place; closures; unpaid sessions; amount/currency/mode checks; duplicate and out-of-order events; signed/tampered/stale webhooks; individual random QR tokens; wrong-date, cancelled, refunded, wrong-mode and already-used validation; explicit check-in and staff identity; four simultaneous bailiffs checking one ticket; cancellation and expiry; late payment refund-review handling; partial/full refunds; SMTP retry; rate limits; SQLite backup integrity.
- **45 HTTP/browser checks** (`TEMPLE_BROWSER=1 node tests/http.mjs`): forged booking requests while OFF, CSRF, authentication, admin/bailiff permissions, non-guessable customer pages, private-file 404s, real HTTP signed webhook processing and retries, ticket generation only after confirmation, SVG QR output, check-in endpoints, CSV formula safety, opening rights confirmation, admin pricing/closure/complimentary flows, asset-only service-worker policy, 390px mobile layouts, staff browser sign-in, generated QR image decoding using the local decoder, and no PHP/JavaScript errors. Without Playwright, the same suite runs **35 HTTP checks**.
- **5 command-line checks** (`node tests/cli.mjs`): initial migration, default OFF, repeat migration preserving settings, private backup creation and maintenance without credentials.

- **19 demo service checks** (`php tests/demo.php`): separate database, capacity, expiry, confirmation idempotency, individual tokens, failed/cancelled/refunded states, wrong-date and repeat check-in, and retention.
- **20 demo HTTP/browser checks** (`TEMPLE_BROWSER=1 node tests/demo-browser.mjs`): no payment credentials or production database, CSRF, server prices, phone-sized navigation, complete simulated checkout, two individual QR tickets, actual QR decoding, anonymous scanner without customer names, explicit check-in and refund rejection. Without Playwright this runs **7 HTTP checks**.
- **3 migration checks** (`node tests/migration.mjs`): upgrading the restrictive legacy schema preserves existing bookings, tickets, check-ins, audit and outbox records; foreign keys remain valid; new reservations accept the application state sequence.

PHP lint passed for application, endpoints, commands and test fixtures. JavaScript syntax checks passed. Composer audit reported **no known security vulnerability advisories** for the installed locked dependencies at verification time. The redesigned desktop/mobile homepage and mobile demo checkout/ticket screenshots were inspected locally. The earlier dependency audit is recorded above; this update does not change dependencies.

The repository includes a GitHub Actions workflow for PHP/HTTP/CLI checks on pushes and pull requests. Local results above are independent of whether the remote workflow has run. Browser tests require Playwright plus Chromium; optionally set `CHROMIUM_EXECUTABLE` to an existing Chromium binary and `PHP_BIN` to a specific PHP executable. `TEMPLE_SCREENSHOTS` can point to a local output directory. Production needs neither Node nor Playwright.

## Before accepting actual payments

These require your account, server and physical devices and were **not** claimed as completed:

1. Correct Plesk document root, FPM PHP extensions, private owner/permissions and no PHP source exposure.
2. Real HTTPS certificate and Cloudflare Full (strict), caching bypass and webhook WAF behaviour.
3. A real **Stripe test-mode Checkout** in an isolated private staging installation: successful card, declined card, abandon/expire, explicit cancel, duplicate webhook resend, full and partial refund, and success page arriving before webhook confirmation. Confirm amounts match and each booked angler receives one QR.
4. Interrupt webhook delivery and verify it can be resent from Stripe safely; interrupt outbound Stripe access and confirm reservations remain safely held.
5. Real SMTP authentication, TLS, sender verification, inbox/spam receipt, failed delivery/retry and resend. Verify secure links point to the intended hostname.
6. Plesk scheduled tasks execute as the subscription user every minute/daily; failure notifications reach the operator. Restore a backup into a private copy and inspect integrity and records.
7. Physical Android/iPhone camera scanning over HTTPS, camera denial, manual lookup, already-used and wrong-date tickets, and a second staff phone attempting the same check-in. No offline check-in is offered.
8. Confirm the organiser's rights, access, operating arrangements, prices/capacity, privacy contact and final rules. Configure separate live keys/webhook and a clean live database only when ready. Leave bookings OFF until deliberately authorised to open.

## Known operational boundaries

SQLite is for a single Plesk host on local storage. Refunds are performed in the Stripe dashboard; the application follows signed refund events. Partial refunds do not revoke individual tickets. Email delivery is at-least-once rather than exactly-once. Camera access depends on browser/device permission; manual lookup is always available. PWA basics do not include offline private pages, offline check-in, push notifications or background payment submission.
