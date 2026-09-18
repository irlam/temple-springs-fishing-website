# Plesk deployment: fishing.defecttracker.uk

The application has been built for your existing repository and Plesk hosting. It has **not** been installed on your server. Keep public bookings OFF while access and angling rights are unconfirmed.

## Updating the existing installation for the redesigned site

In Plesk Git, pull `main`, then deploy. Keep your existing private `config.php` and data directory. For the current installation, the confirmed document root is `fishing.defecttracker.uk/httpdocs/public`; run commands from its parent `httpdocs` directory. The alternative paths below are examples for a fresh installation, not a reason to move the working site.

As the subscription user, using the matching PHP CLI version:

```sh
php bin/console.php backup
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/console.php migrate
```

Migration 002 upgrades the booking status constraints while preserving existing bookings, tickets and check-ins. Back up first. Do not delete the database or rerun SQL manually. Keep bookings OFF. Open `/` for the redesigned homepage and `/demo.php` for the complete payment-free practice journey; no Stripe account is needed for the demo. If the old homepage remains, confirm deployment completed, hard-refresh the browser, and purge any existing Cloudflare cached homepage. See [DEMO.md](DEMO.md).

## Initial admin setup without SSH

Use this only before any staff accounts exist. Keep the document root ending in `/public` and allow PHP to read its parent application directory. Composer dependencies must already be installed. This CLI-only script is outside the public root and cannot be run by a website visitor.

1. Pull and deploy the latest `main` through Plesk Git.
2. Go to **Websites & Domains → Scheduled Tasks → Add Task** and choose **Run a PHP script**.
3. Browse to `fishing.defecttracker.uk/httpdocs/bin/plesk-setup.php` (relative to the subscription root). Choose PHP 8.3 or later with the required extensions.
4. In **Arguments**, enter only your admin email address. No password goes into the task.
5. Click **Run Now**. Remove the task after successful setup; it should not run repeatedly.
6. In Plesk File Manager open `fishing.defecttracker.uk/httpdocs/var/initial-admin.txt` (or your configured private data directory). Save the generated password in your password manager, then delete this file.
7. Sign in at `/staff.php` with that email and password.

The script creates missing private configuration or fills an empty app key, preserves other configuration, backs up a pre-existing database, applies migrations and creates one administrator with a random password stored hashed in SQLite. It requires test mode, leaves bookings OFF, never prints credentials in task output, and refuses to reset existing staff accounts. It needs no Stripe or SMTP credentials. Do not put this script, configuration or credentials under `public/`.

## 1. Hosting and document root

1. In Plesk, open **Websites & Domains → fishing.defecttracker.uk → Git**. Use `https://github.com/irlam/temple-springs-fishing-website.git`, branch `main`. For SSH/private access, use a Plesk-generated read-only deploy key. Start with manual deployment.
2. Use a dedicated deployment directory such as `temple-springs-site`, relative to the subscription root. Do not overwrite another site's directory.
3. Under this subdomain's **Hosting Settings**, set **Document root** to `temple-springs-site/public`. The repository itself is one level above the document root. There are deliberately no rewrite rules or pretty-URL dependencies.
4. Enable **PHP 8.3 or later**, PHP-FPM, with **PDO, pdo_sqlite, SQLite3, curl, mbstring, xmlwriter, dom, iconv, openssl, fileinfo and session** available. Composer checks required extensions. Match CLI and FPM PHP versions/extensions. Use a supported, patched PHP release.
5. Turn **display_errors OFF**, **log_errors ON**, and set a modest `post_max_size` such as `2M`. Set `open_basedir` to permit the app directory and private data directory as well as the host's temp directory. Never use SQLite on NFS/network storage.
6. On the usual Plesk Apache+nginx setup, leave proxy mode/PHP handling enabled so `.php` runs through FPM; do not serve PHP as static text. `.htaccess` contains Apache headers. It is not a substitute for the correct document root.

Typical resulting paths (adapt to the actual subscription path shown in Plesk):

```text
/var/www/vhosts/defecttracker.uk/temple-springs-site/
  public/              <- document root; only this is web-accessible
  app/ bin/ migrations/ vendor/ composer.json composer.lock
  config.php           <- private, ignored by Git
  var/                 <- private, ignored by Git
    temple.sqlite      <- SQLite database, plus WAL/SHM files
    sessions/ backups/ maintenance.lock
```

The existing `index.html` remains the public homepage. Do not change DirectoryIndex to bypass it.

## 2. Install dependencies and configuration

Use Plesk's Composer extension, or SSH as the **subscription system user**. Replace the example paths below with your real directory and installed PHP version:

```sh
cd /var/www/vhosts/defecttracker.uk/temple-springs-site
/opt/plesk/php/8.3/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --prefer-dist --optimize-autoloader
cp config.example.php config.php
/opt/plesk/php/8.3/bin/php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

The Composer executable's location can differ: use Plesk's displayed path or `composer` if it runs the same PHP version. Commit neither `vendor/` nor real configuration; deploy from `composer.lock`, not `composer update`.

Edit `config.php` in the **repository root**:

- `base_url`: `https://fishing.defecttracker.uk`, no trailing slash.
- `data_dir`: `__DIR__ . '/var'` is private with the document root above. An external absolute private path also works. Application startup refuses a data directory inside `public/`.
- `app_key`: paste the generated random value. Used for pseudonymous rate-limit keys; do not publish it.
- `secure_cookies`: `true` in Plesk. No dependence on untrusted forwarded headers.
- `stripe_mode`: `test` initially. Leave secrets empty until configuring Stripe; the info site remains available and bookings remain OFF.
- `stripe_secret`, `stripe_webhook_secret`: configure as described below. Never put them in JavaScript or Git.
- `smtp`, `recipient`: real sending service and enquiry recipient.
- `privacy_contact`: actual organiser identity and contact route, which is public. Complete the privacy/terms content before opening.

Permissions: code owned by the subscription user, files normally `0644`, directories `0755`; `config.php` `0600`; private `var/` and its directories `0700`, private files `0600`. FPM and scheduled tasks must run as the same subscription user (or use a deliberately configured private shared group). **Never use `0777`.** Ensure Git deployments preserve ignored `config.php` and `var/`; don't use a deployment script that deletes untracked files.

```sh
chmod 600 config.php
/opt/plesk/php/8.3/bin/php bin/console.php migrate
/opt/plesk/php/8.3/bin/php bin/console.php user YOUR_EMAIL admin "Chris Irlam"
/opt/plesk/php/8.3/bin/php bin/console.php health
```

The account command runs only in an interactive terminal, prompts invisibly for a password of at least 14 characters and stores a password hash. Run it again to reset a password. Create bailiffs using `user EMAIL bailiff "Name"`. No initial password is supplied in the repository.

## 3. Cloudflare DNS, HTTPS and caching

- Confirm Cloudflare's **A record `fishing`** points to the Plesk IPv4 address. Remove or correct an obsolete AAAA record. Keep your existing main-domain records intact.
- In Plesk, issue a **Let's Encrypt certificate for fishing.defecttracker.uk** and enable permanent HTTP→HTTPS redirection. If validation is blocked by proxy/WAF rules, temporarily use DNS-only for this record, issue the certificate, then restore proxying. Ensure certificate renewal validation also works.
- In Cloudflare use **Full (strict)** SSL/TLS once the origin has its valid certificate. Do not use Flexible mode. Camera access and secure cookies require HTTPS.
- Create a Cloudflare Cache Rule: **Hostname equals `fishing.defecttracker.uk` → Bypass cache**. This simple whole-host rule is safest initially. Disable any inherited “Cache Everything” rule and purge the existing homepage/service-worker cache on deployment. Do the same for any Plesk/nginx response cache.
- Do not challenge, redirect to login, or block **POST `/webhook.php`** with bot challenges, Cloudflare Access or a browser verification page. Keep the exemption narrow; the application verifies Stripe's signature. Verify actual test delivery reaches PHP unchanged.
- Preserve the raw POST body and `Stripe-Signature` header. Do not apply transforms to webhook bodies.
- Keep `/sw.js` revalidated (`Cache-Control: no-cache`). The new service worker removes the old page cache and only caches a short allowlist of public assets. Private routes deliberately need a network connection.
- PHP returns `private, no-store` and no-referrer headers. For static HTML, Apache adds matching privacy headers. If using nginx-only hosting, add suitable response headers in the Plesk nginx configuration (or equivalent server policy). Do not add duplicate `location /` blocks to Plesk's generated config. A safe initial server-level policy is `add_header Cache-Control "no-store" always;` plus `X-Content-Type-Options nosniff` and `Referrer-Policy no-referrer`. Verify the resulting effective config with your host.
- Restrict and rotate access logs. Ticket links contain bearer tokens in their query string: configure access logging to record path without query arguments where possible, and do not attach full ticket URLs to analytics or public support posts.

## 4. Stripe test setup

In the Stripe account's **test environment**, create a secret API key and configure `stripe_secret=sk_test_...`. This application creates hosted Checkout sessions server-side; it does **not need a publishable key**.

Create a **snapshot webhook event destination** at:

```text
https://fishing.defecttracker.uk/webhook.php
```

Select these events:

```text
checkout.session.completed
checkout.session.expired
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
payment_intent.payment_failed
charge.refunded
```

Use the API version matching the pinned Stripe SDK (`2025-08-27.basil` for Stripe PHP 17.x). Put this destination's signing secret (`whsec_...`) in `stripe_webhook_secret`. CLI forwarding uses a different secret; don't confuse it with the dashboard destination secret. The endpoint rejects invalid signatures and the wrong test/live mode.

Only confirmed paid Checkout webhook events issue tickets. A success-page visit cannot mark a booking paid. Card declines are logged; they do not free capacity while Checkout is still open and retryable. Expired/terminally failed Checkout frees the reservation. Refunds update from `charge.refunded`; make refunds in the Stripe dashboard. Full refunds invalidate all tickets, partial refunds retain the booking/tickets and record the refunded amount. Use a full refund when cancelling the entire booking.

**Do not enable public bookings just to test while rights are unconfirmed.** Run the automated fixture suites locally. For an actual Stripe test checkout before opening, use an isolated, non-public staging copy with a separate private database, explicitly temporary test controls and no claim of fishing permission; keep the production OFF setting unchanged. An operator can temporarily set that isolated test database's `bookings_enabled` setting to `1` for testing using SQLite, then return it to `0`. Never do that on the public production database. Use Stripe's official test card details and complete the pre-launch checks in TESTING.md.

## 5. SMTP

Use an authorised mailbox/provider. For STARTTLS use port **587**, encryption **`tls`**; for implicit TLS use port **465**, encryption **`ssl`**. Provide host, username, password, sender address and sender display name. Do not disable TLS certificate checks. Allow outbound SMTP through the server firewall and configure the sender's SPF/DKIM/DMARC through the correct DNS provider.

`recipient` is the organiser mailbox for enquiries. Ticket confirmation emails go to the customer's booking address. The outbox is committed with ticket issue, then delivered by maintenance; webhook handling does not wait for SMTP. Failures retry with backoff up to eight attempts, then appear as failed in the dashboard. Admins can request a resend. SMTP acceptance does not guarantee inbox delivery: test receipt and spam placement. Email is at-least-once; a worker crash after SMTP acceptance can cause a duplicate confirmation containing the same ticket links.

## 6. Scheduled tasks (required)

In **Websites & Domains → Scheduled Tasks**, create **Run a command** tasks under the subscription system user. Choose non-chrooted commands/paths as your hosting permits (a chroot may require adjusted PHP/app paths). Use your actual PHP path:

Every minute (`* * * * *`):

```sh
/opt/plesk/php/8.3/bin/php /var/www/vhosts/defecttracker.uk/temple-springs-site/bin/console.php maintenance
```

Daily at 03:10 (`10 3 * * *`):

```sh
/opt/plesk/php/8.3/bin/php /var/www/vhosts/defecttracker.uk/temple-springs-site/bin/console.php backup
```

Enable failure notifications to the operator. The maintenance lock prevents overlapping local workers. It expires unpaid sessions when overdue or when bookings are OFF, sends queued emails, and cleans old rate limits/sessions. If Stripe is unreachable, capacity is retained rather than risking overbooking. Completed sessions wait for signed webhook confirmation; inspect failed webhook deliveries in Stripe and resend them. Scheduled tasks are not public URLs and contain no URL secrets.

## 7. Backups, updates and restore

`backup` uses SQLite `VACUUM INTO` for a consistent snapshot, even with WAL enabled. It retains 30 days under private `var/backups/`. Copy snapshots **encrypted off-server**, and back up `config.php`/credentials separately in secure storage. Do not simply copy the main `.sqlite` while the application is writing; WAL contents may be missed. Restrict backup access; they contain customer information and ticket tokens.

Before updates: run backup, pull/deploy Git, run `composer install --no-dev --prefer-dist --optimize-autoloader`, then `migrate`. Migrations are tracked and transactional; rerunning does not reopen bookings or recreate accounts. Keep `config.php` and `var/` intact. Review dependency updates before changing the lockfile.

Restore: put the app into maintenance at the webserver, stop scheduled workers/FPM access, retain a copy of the damaged database, restore a verified snapshot as `var/temple.sqlite`, remove stale WAL/SHM files **only while all writers are stopped**, correct owner/permissions, run `health`, force `bookings-off`, then resume services. Replay Stripe events since the backup and reconcile check-ins before reopening; restoring an older snapshot also restores older admission records. Test restoration on a private copy first. Code rollback must remain compatible with the database schema.

## 8. Server acceptance checks

Check public homepage and `/book.php` (OFF message), valid HTTPS, staff login and secure cookie flags. `/config.php`, `/var/temple.sqlite`, `/app/Booking.php` and `/composer.json` must be 404/403 and never return file content. Confirm dynamic routes return no-store through Cloudflare, the webhook returns 400 for an unsigned request, and a real signed test event reaches it. Test SMTP and scheduled tasks. Check a phone's camera permission and manual lookup. Keep test/live data separate and complete the real checkout/refund verification before opening.

Before a future live launch: confirm permissions and operational readiness; replace the draft rules/privacy details; choose real prices and capacity; take a backup; configure `stripe_mode=live`, `sk_live_...` and the separate live webhook secret; use a clean live database (archive the private test database), migrate, recreate staff accounts, and verify the live endpoint. Test tickets carry a stored mode and cannot validate in live mode. Only then may the authorised admin intentionally turn bookings ON. This build leaves them OFF.

References: [Plesk remote Git deployment](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/git-support/using-remote-git-hosting.75848/), [Stripe webhook signatures and delivery](https://docs.stripe.com/webhooks), [Checkout session expiry](https://docs.stripe.com/api/checkout/sessions/expire).
