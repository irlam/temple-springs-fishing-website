<?php
// Copy to config.php OUTSIDE public/. Never commit credentials.
return [
    'base_url' => 'https://fishing.defecttracker.uk',
    'data_dir' => __DIR__ . '/var', // SQLite, private sessions, backups; never inside public.
    'app_key' => '', // php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'secure_cookies' => true, // false ONLY for local http development
    'stripe_mode' => 'test',
    'stripe_secret' => '', // sk_test_... initially
    'stripe_webhook_secret' => '', // whsec_... for this exact endpoint/mode
    'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from' => '', 'from_name' => 'Temple Springs'],
    'recipient' => '', // enquiry recipient
    'privacy_contact' => '', // published organiser/contact details, configure before opening
];
