# Payment-free booking practice

Deploy the latest `main` and install Composer dependencies. Open `/demo.php` to choose a date, ticket type, quantity and made-up name. Confirm the simulated payment to receive one QR per angler. Follow “Try demo check-in”, scan the QR using another device, or paste its link into `/demo-scan.php`. Check-in requires an explicit button press; repeat use is rejected. Try failure/cancellation before confirmation, or refund after confirmation.

**No card, payment account, email service or production admin is needed. No money is collected and no email is sent. Every demo ticket is marked invalid for access.** Real fishing rights and access remain unconfirmed. Leave real bookings OFF.

The demo has its own SQLite ledger at `data_dir/demo/demo.sqlite` (defaults to the private repository `var/demo/` directory), separate cookie/session directory, 20-place daily capacity, £10/£5 sample prices, 15-minute holds and 24-hour records. Expired records are cleaned on subsequent demo requests. Prices are samples defined in `app/Demo.php`; production prices remain managed by administrators. PHP must have write permission to the private data directory. Never put it under `public/`.

Anonymous practice scanning shows only demo status and reference, never the name. This is deliberate for fictitious tickets; actual customer tickets still require staff authentication for validation and check-in. Use made-up names. Demo endpoints cannot create or change production bookings, send email, call Stripe or grant access. Private links and QR codes use separate random bearer tokens.

The demo follows `base_url` in the private configuration; without configuration it uses `https://fishing.defecttracker.uk`. Set the correct HTTPS URL before testing QR codes on a hosted copy. For localhost only, use a private config with `base_url`, `data_dir` and `secure_cookies=false`; no app key or gateway configuration is required for demo pages.

## Real payments later

The existing real booking path is `/book.php`, with Stripe Checkout and signed webhook confirmation. It stays disabled until an administrator deliberately enables it. Configure test keys and test webhook secret in the private configuration, SMTP for ticket delivery and the maintenance scheduled task; complete the staging tests in `TESTING.md`. Demo success does not verify Stripe or SMTP. After rights/access and operational arrangements are confirmed, follow `PLESK.md` to switch to live credentials and open real bookings. Never change demo tickets into admission tickets.
