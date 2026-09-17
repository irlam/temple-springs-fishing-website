<?php
require dirname(__DIR__).'/app/web.php';
$token=is_string($_GET['token']??null)?$_GET['token']:'';
$b=preg_match('/^[a-f0-9]{64}$/D',$token)?$s->one('SELECT * FROM bookings WHERE token=?',[$token]):null;
if(!$b) { http_response_code(404); head('Booking not found'); foot(); exit; }
$error='';
if(post()) { checkCsrf(); if(field('action')==='cancel' && in_array($b['status'],['creating','pending'],true)) {
    try { (new Temple\Payments($s,$config))->expire($b,'cancelled'); redirect('/booking.php?token='.$token); } catch(Throwable $e) { $error='Cancellation could not be confirmed. Your places remain held until payment status is confirmed. Please check again.'; }
} }
head('Your booking'); if($b['mode']==='test' && $config['stripe_mode']!=='test') notice('TEST BOOKING — not valid for fishing or access.'); if($error) notice($error);
echo '<section class="panel"><p class="eyebrow">'.h($b['reference']).'</p><h2>'.h($b['date']).'</h2><p>'.h($b['name']).' · '.h($b['type_name']).' × '.$b['quantity'].'</p><p>Total: '.money($b['total']).' · Status: <strong>'.h(str_replace('_',' ',$b['status'])).'</strong></p>';
if(in_array($b['status'],['creating','pending'],true)) {
    notice('Payment has not yet been confirmed. Returning from checkout does not issue a ticket. Refresh this page shortly to check for confirmation.');
    echo '<a class="button" href="/booking.php?token='.h($token).'">Refresh status</a><form method="post">'.csrf().'<input type="hidden" name="action" value="cancel"><button class="button secondary">Cancel unpaid checkout</button></form>';
} elseif($b['status']==='refund_required') notice('Payment was received but tickets could not be issued. The organiser must review and refund this payment. Please contact the organiser quoting your reference.');
echo '</section>';
if(in_array($b['status'],['paid','complimentary','partially_refunded'],true)) {
    $mail=$s->one('SELECT status FROM outbox WHERE booking_id=?',[$b['id']]);
    notice('Keep this link private. Email delivery: '.($mail['status']??'pending').'. Your tickets remain available here even if email is delayed.');
    foreach($s->all('SELECT * FROM tickets WHERE booking_id=?',[$b['id']]) as $i=>$t) {
        echo '<article class="ticket panel"><div><p class="eyebrow">'.($b['mode']==='test'?'TEST — NOT VALID FOR ACCESS · ':'').'DIGITAL DAY TICKET · '.($i+1).' / '.$b['quantity'].'</p><h2>'.h($b['date']).'</h2><p>'.h($b['type_name']).'<br>'.h($b['name']).'<br>'.h($b['reference']).'</p><strong>'.($t['checked_at']?'Already checked in':'Present this QR to a bailiff').'</strong><p class="quiet">One person · One check-in · Booked date only</p></div><img class="qr" src="/qr.php?token='.h($t['token']).'" alt="QR code for ticket '.($i+1).'" width="260" height="260"></article>';
    }
    echo '<button class="button" id="print-tickets">Print tickets</button>';
}
foot();
