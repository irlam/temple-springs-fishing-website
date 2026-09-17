<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
$configPath = dirname(__DIR__) . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
$recipient = $config['recipient'] ?? '';
$sender = $config['sender'] ?? '';
$enabled = filter_var($recipient, FILTER_VALIDATE_EMAIL) && filter_var($sender, FILTER_VALIDATE_EMAIL);
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict']);
session_start();
$_SESSION['token'] ??= bin2hex(random_bytes(32));
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') respond(200, ['enabled' => (bool)$enabled, 'token' => $enabled ? $_SESSION['token'] : null]);
if ($method !== 'POST') { header('Allow: GET, POST'); respond(405, ['message' => 'Method not allowed.']); }
if (!$enabled) respond(503, ['message' => 'Enquiries are not available yet. No message has been sent.']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) respond(413, ['message' => 'Please shorten your message.']);
function field(string $name): string { return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : ''; }
if (!hash_equals($_SESSION['token'], field('token'))) respond(403, ['message' => 'Please reload the page before sending your enquiry. Your text has been kept here.']);
if (field('website') !== '') respond(400, ['message' => 'Unable to accept this enquiry.']);
$name = field('name'); $email = field('email'); $message = field('message'); $interest = field('interest');
$interests = ['General enquiry', 'Local history or observations', 'Future volunteering', 'Angling and the project', 'Conservation or professional advice'];
if ($name === '' || strlen($name) > 400 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email) || strlen($message) < 10 || strlen($message) > 20000 || !in_array($interest, $interests, true) || field('consent') !== 'yes') respond(422, ['message' => 'Please check your name, email, message and consent.']);
// A locked per-IP timestamp prevents rapid submissions across separate browser sessions.
$key = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', (string)($config['rate_limit_secret'] ?? $sender));
$lock = fopen(sys_get_temp_dir() . '/temple-springs-' . $key . '.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX)) respond(503, ['message' => 'Enquiries are temporarily unavailable. Please try later.']);
$last = (int)stream_get_contents($lock);
if (time() - $last < 60) { fclose($lock); respond(429, ['message' => 'Please wait a minute before sending another enquiry.']); }
ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)time()); fflush($lock); flock($lock, LOCK_UN); fclose($lock);
$body = "Temple Springs project enquiry\n\nName: $name\nEmail: $email\nInterest: $interest\n\n$message\n\nConsent: agreed to use details to respond to this enquiry.\n";
$sent = mail($recipient, 'Temple Springs: ' . $interest, $body, ['From' => $sender, 'Reply-To' => $email, 'Content-Type' => 'text/plain; charset=UTF-8']);
if (!$sent) respond(503, ['message' => 'The mail service could not accept your enquiry. Please try again later.']);
respond(200, ['message' => 'Thank you. Your enquiry has been accepted for email delivery to the project organiser.']);
