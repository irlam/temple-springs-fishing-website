# Temple Springs

A mobile-first website for a **proposed community-led fishing restoration project in Bolton**. Fishing access and angling rights are not confirmed. No claims of surveys, partnerships, secured rights or an opening date are made. The landscape is an original concept illustration, not site photography.

Plain HTML, CSS and JavaScript with a small PHP email endpoint. No framework, database, package install or production build. All assets are local. The site has a manifest, PNG installation icons, SVG favicon, social preview, offline reading, reduced-motion support, keyboard navigation and a progressively enhanced mobile menu.

## Deploy to fishing.defecttracker.uk on Plesk

1. In **Websites & Domains**, select the existing `fishing.defecttracker.uk` subdomain. Verify its DNS A record points to your Plesk server (and any AAAA record points to the correct IPv6 server).
2. Open **Git**, add a remote repository: `https://github.com/irlam/temple-springs-fishing-website.git`, branch `main`. If private, use the SSH URL and add Plesk's generated public key as a read-only GitHub deploy key.
3. Choose **Manual deployment** initially. Set a dedicated deployment directory, for example `temple-springs-site`. Do not deploy over another website.
4. In this subdomain's **Hosting Settings**, set **Document root** to `temple-springs-site/public` (relative to the subscription root). The Git deployment directory is the parent; only `public` should be web-accessible. This is essential to keep configuration private.
5. Enable a supported PHP version, **PHP 8.3 or later**, preferably PHP-FPM. No Node.js hosting or additional deployment command is needed. Pull updates and deploy the repository in Plesk.
6. Issue a Let's Encrypt certificate for `fishing.defecttracker.uk` using Plesk's SSL/TLS tools, and enable permanent HTTP-to-HTTPS redirection. HTTPS is needed for PWA installation and service workers outside localhost.
7. Enable the contact form as below, then perform the launch checks. You can switch to automatic Git deployment once verified.

If Git is unavailable, upload the repository with Plesk File Manager or SFTP into the same dedicated directory and set the document root to its `public` subfolder. Include `.htaccess`. Never place `config.php` in `public`.

Official instructions: [Plesk remote Git hosting](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/git-support/using-remote-git-hosting.75848/) and [hosting settings/document root](https://docs.plesk.com/en-US/obsidian/quick-start-guide/plesk-functionality-explained/managing-web-hosting.74401/).

## Enable enquiries

The form deliberately stays disabled and explains its unavailability until real mail settings exist. It never pretends to store a submission. No recipient address has been invented.

1. Copy `config.example.php` to `config.php` in the deployed repository's top level, outside `public`. This file is ignored by Git and should be preserved during deployments.
2. Set `recipient` to the organiser's mailbox and `sender` to an actual, authorised sending mailbox. Set `rate_limit_secret` to a long random string. Keep these settings server-side.
3. Configure Plesk's local mail delivery and the sending domain's SPF/DKIM as appropriate for your provider. This endpoint uses PHP `mail()`; if your host requires authenticated external SMTP, replace the mail transport before enabling the form.
4. Review the site's inline privacy wording and add the actual organiser/controller identity, contact route and retention period appropriate to your operation before enabling public collection. Delete old enquiries from the mailbox according to that policy. No marketing subscription is included.
5. Send a real test, confirm it arrives in the recipient inbox (including spam checks), and reply to verify Reply-To. A successful PHP response means the mail server accepted the message, **not** guaranteed inbox delivery.

Validation, a session CSRF token, a honeypot and an IP-based 60-second rate limit protect submissions. The rate-limit files contain only timestamps with keyed hash filenames, stored in the server temporary directory. Arrange periodic cleanup of `temple-springs-*.lock` files older than a day using your server maintenance tools. Do not cache `contact.php` in Plesk/nginx/CDN settings. The service worker never caches it or queues submissions offline.

## Launch checks

- Homepage works on desktop and a narrow phone viewport; menu, section links, keyboard focus and form labels work.
- `/config.php` is not reachable (must return 404); no repository/config files are exposed.
- HTTPS has a valid certificate and HTTP redirects correctly.
- `/contact.php` returns JSON, not PHP source. Before configuration it returns `enabled: false`.
- Test invalid fields, missing consent, successful email receipt, rate limiting and unavailable mail transport.
- Install the PWA where supported, open once online, then test offline reading. The form should report unavailability offline. Installation UI varies by browser.
- Verify social preview using `/assets/social.png`, and confirm sitemap/canonical use the intended host.
- Apache headers are in `.htaccess`. With nginx-only hosting, reproduce those headers in Plesk's additional nginx directives, disable directory listing and ensure PHP handling is enabled. Set `Cache-Control: no-cache` on `/sw.js` and `no-store` on `/contact.php` at any proxy layer.

## Editing and local preview

Run `node tests/smoke.mjs` with PHP available to test the endpoint in an isolated temporary directory. This covers disabled/configured states, invalid requests, CSRF, consent, honeypot, config isolation, local assets and section links without sending email. Desktop and 390px mobile layouts and mobile navigation were checked in-browser. Live SMTP delivery, HTTPS, Apache headers and PWA installation must be verified on the target Plesk host.

Edit sections in `public/index.html`, visual styles in `public/styles.css` and interactions in `public/app.js`. Keep access disclaimers until verified rights and permissions justify changing them. Replace the illustration only with imagery you have permission to use; keep captions accurate. The initial update is a planning note, not fabricated news.

With PHP installed, run `php -S localhost:8080 -t public` from the repository and open `http://localhost:8080`. With no config the form remains unavailable. `php -l public/contact.php` checks PHP syntax; `node --check public/app.js` and `node --check public/sw.js` check JavaScript. A static file server can preview the design but cannot send enquiries.

When changing cached files, increment `CACHE` in `public/sw.js`. Network-first caching refreshes content online; installed offline copies may retain older wording until the next connection. The new service worker activates after older tabs close. To roll back, revert the relevant Git commit and redeploy from Plesk.
