<?php
// No session, CSRF or browser redirect on Stripe's server-to-server endpoint.
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit; }
define('TEMPLE_WEBHOOK', true);
try {
    require dirname(__DIR__).'/app/bootstrap.php';
    if(empty($config['stripe_webhook_secret'])) { http_response_code(503); exit; }
    if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576) { http_response_code(413); exit; }
    $raw=file_get_contents('php://input',false,null,0,1048577);
    if(strlen($raw)>1048576) { http_response_code(413); exit; }
    $event=Stripe\Webhook::constructEvent($raw,$_SERVER['HTTP_STRIPE_SIGNATURE']??'',$config['stripe_webhook_secret'],300);
    $booking->event($event->toArray(),$config['stripe_mode']==='live');
    http_response_code(200); echo 'OK';
} catch(Stripe\Exception\SignatureVerificationException|UnexpectedValueException $e) { http_response_code(400); echo 'Invalid signature or payload'; }
catch(Throwable $e) { error_log('Temple webhook processing failed: '.get_class($e)); http_response_code(500); echo 'Retry later'; }
