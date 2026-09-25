<?php
declare(strict_types=1);
set_exception_handler(function(Throwable $e) { http_response_code(503); header('Cache-Control: no-store'); error_log('Temple application error: '.get_class($e)); echo 'This service is temporarily unavailable. Please try again later.'; });
require __DIR__.'/bootstrap.php';
function h(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function field(string $k): string { return is_string($_POST[$k]??null)?trim($_POST[$k]):''; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function post(): bool { return $_SERVER['REQUEST_METHOD']==='POST'; }
function checkCsrf(): void { if(!hash_equals($_SESSION['csrf'],field('csrf'))) { http_response_code(403); exit('Please reload the page and try again.'); } }
function redirect(string $url): never { header('Location: '.$url,true,303); exit; }
function staff(bool $admin=false): array {
    global $s;
    $u=$s->one('SELECT * FROM users WHERE id=? AND active=1',[$_SESSION['user']??0]);
    if(!$u || !hash_equals((new Temple\StaffAccounts($s))->stamp($u,$GLOBALS['config']['app_key']),$_SESSION['auth_stamp']??'') || time()-($_SESSION['seen']??0)>1800 || time()-($_SESSION['login_at']??0)>43200) { unset($_SESSION['user']); if(basename($_SERVER['SCRIPT_NAME'])==='scan.php' && is_string($_GET['token']??null) && preg_match('/^[a-f0-9]{64}$/D',$_GET['token'])) $_SESSION['scan_after_login']=$_GET['token']; redirect('/staff.php'); }
    $_SESSION['seen']=time();
    if($admin && $u['role']!=='admin') { http_response_code(403); exit('Administrator access required.'); }
    return $u;
}
function head(string $title): void { echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'.h($title).' · Temple Springs</title><link rel="icon" href="/assets/mark.svg"><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/booking.css"><script src="/vendor/jsQR.js" defer></script><script src="/booking.js" defer></script></head><body><a class="skip" href="#main">Skip to content</a><div class="status-bar">Proposed community project · Rights and access not yet confirmed</div><header class="header wrap"><a class="brand" href="/"><img src="/assets/mark.svg" width="44" height="44" alt=""><span>TEMPLE SPRINGS<small>FISHERIES · BOLTON</small></span></a><nav><a href="/demo.php">Booking demo</a><a href="/book.php">Tickets</a><a href="/rules.php">Rules</a><a href="/staff.php">Staff</a></nav></header><main class="wrap app-main" id="main"><h1>'.h($title).'</h1>'; global $config; if(($config['stripe_mode']??'test')==='test') notice('TEST MODE — payments and tickets are for testing only. Not valid for fishing or site access.'); }
function foot(): void { echo '</main><footer class="wrap footer-main"><p>Temple Springs · Proposed community-led restoration.<br>This website does not grant permission to enter or fish.</p><a href="/privacy.php">Privacy</a></footer></body></html>'; }
function notice(string $text): void { echo '<p class="notice" role="status">'.h($text).'</p>'; }
function money(int $p): string { return '£'.number_format($p/100,2); }
