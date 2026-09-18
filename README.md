# Temple Springs booking platform

PHP 8.3+ and SQLite application for the **proposed community-led Temple Springs fishing restoration project, Bolton**. A redesigned mobile-first fishery homepage retains the existing branding, original landscape illustration and provisional project information. **Fishing rights and access remain unconfirmed. New installations default to bookings OFF and Stripe test mode.** No credentials are included.

## Deploy

Follow **[docs/PLESK.md](docs/PLESK.md)** for the exact Plesk, Cloudflare, Stripe, SMTP, scheduled-task and backup setup. Only `public/` is the document root. Run Composer and the database migration before using the PHP pages.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
cp config.example.php config.php
# Fill configuration outside public/ and generate app_key, then:
php bin/console.php migrate
php bin/console.php user your-admin-email@example.com admin "Your name"
php bin/console.php health
```

The `user` command prompts for a password privately; there is no web installer, default password or public account registration. Run it from an interactive terminal. Staff sign-in: `/staff.php`.

## Try booking without a payment account

Open `/demo.php` after installing Composer dependencies. The separate practice system needs **no Stripe keys, SMTP account or initial admin**. Choose a date, tickets and a made-up name, simulate a payment, then view, print and scan individual QR tickets. You can also simulate failure, cancellation and refund. No money is collected, no email is sent, and demo tickets never grant access. See [docs/DEMO.md](docs/DEMO.md).

Real bookings remain OFF. When rights and access are confirmed, the existing Stripe Checkout integration can be configured first in test mode, then live mode.

## What is included

- Mobile public site, proposed rules, project information, booking date/availability, ticket selection, quantity and customer details.
- Server-priced Stripe Checkout, GBP, card payments, 35-minute reservations, signed webhook confirmation and idempotent ticket/email issue.
- Transactional SQLite capacity limits, date overrides/closures, an audited bookings ON/OFF switch, and recorded complimentary tickets.
- One cryptographically random QR per angler, separate secure booking link, SVG QR generation on the server, printable/mobile tickets and SMTP outbox/retries.
- Admin/bailiff roles, session security, CSRF, rate limits, booking search, pagination, protected CSV export, audit trail and explicit check-in with duplicate-use protection.
- Camera scanner using the native detector where available and locally bundled jsQR fallback. No QR data is sent to a third-party scanner service. Manual token/reference/customer lookup remains available.
- Private configuration, database, sessions and backups; asset-only PWA caching; no private offline pages.

See **[docs/OPERATIONS.md](docs/OPERATIONS.md)** for staff workflows, payment states, retries and recovery. See **[docs/TESTING.md](docs/TESTING.md)** for verification and the remaining host/credential-dependent acceptance checks.

## Development

```sh
composer install
php tests/run.php
node tests/http.mjs
node tests/cli.mjs
php tests/demo.php
node tests/demo-browser.mjs
node tests/migration.mjs
# Optional browser checks after installing Playwright and Chromium locally:
TEMPLE_BROWSER=1 node tests/http.mjs
```

`tests/run.php` uses isolated temporary SQLite databases and simulated Stripe transport, including real multi-process capacity contention. HTTP tests launch a temporary PHP server with fixture accounts and signed fixture webhooks. They neither touch your production database nor send real payments/emails. `PHP_BIN` can point the Node test suite to your PHP executable. Use Node 22+ for the HTTP suite.

For local development only, copy the example config, set `secure_cookies=false`, use a localhost `base_url`, migrate and run `php -S 127.0.0.1:8080 -t public`. Keep production cookies secure. Never serve the repository root.

## Architecture and scope

No frontend build is required. Composer installs the official Stripe PHP SDK, PHPMailer and BaconQrCode; the lockfile pins tested versions. jsQR 1.4.0 is checked in under `public/vendor/` with its Apache-2.0 licence. This is a single-server SQLite deployment on local disk, using WAL and `BEGIN IMMEDIATE` for capacity and check-in transactions. Use MySQL if moving to multiple application servers or a network filesystem, or if measured write contention requires it; that migration is not necessary for the initial Plesk setup.

Default ticket prices (£10 adult / £5 junior) and capacity (20 anglers) are **editable sample settings**, not confirmed fishery arrangements. The organiser must decide real prices, capacity, operating hours, terms and privacy information before any opening. No sample bookings or staff accounts are installed by migrations.
