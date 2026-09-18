<?php
require dirname(__DIR__).'/app/web.php';
$error=''; $date=is_string($_GET['date']??null)?$_GET['date']:date('Y-m-d');
if(post()) {
    checkCsrf();
    try {
        $s->rate('book:'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR'],$config['app_key']),10,3600);
        if(field('rules')!=='yes') throw new RuntimeException('Please accept the rules and privacy information.');
        $payments=new Temple\Payments($s,$config);
        $b=$booking->reserve(field('date'),(int)field('type'),(int)field('quantity'),field('name'),field('email'));
        // Retain the secure status link even if the Stripe request outcome is uncertain.
        $_SESSION['last_booking']=$b['token'];
        try { redirect($payments->start($b)); } catch(Throwable $e) { redirect('/booking.php?token='.$b['token']); }
    } catch(RuntimeException $e) { $error=$e->getMessage(); }
}
head('Plan a day by the water');
if($error) notice($error);
if($s->setting('bookings_enabled')!=='1') {
    notice('Bookings are not yet open. Temple Springs is a proposed community-led fishing restoration project. Angling rights and fishing access are not confirmed. Do not enter the site or fish on the basis of this website.');
    echo '<a class="button" href="/demo.php">Try a booking — no payment needed</a> <a class="text-link" href="/#opening">Opening update</a>';
} else {
    echo '<p>Choose a date, select your tickets and pay securely. Each angler receives their own QR ticket.</p><ol class="steps"><li>Choose date</li><li>Your tickets</li><li>Secure payment</li></ol><form method="get" class="panel"><label>Fishing date<input type="date" name="date" min="'.date('Y-m-d').'" max="'.date('Y-m-d',strtotime('+365 days')).'" value="'.h($date).'" required></label><button class="button">Check availability</button></form>';
    try {
        Temple\Booking::date($date); $a=$booking->availability($date);
        notice($a['closed']?'Closed on this date.':$a['remaining'].' of '.$a['capacity'].' places available on '.$date.'.');
        if(!$a['closed'] && $a['remaining']>0) {
            echo '<form method="post" class="panel">'.csrf().'<input type="hidden" name="date" value="'.h($date).'"><div class="form-row"><label>Ticket type<select name="type" required>';
            foreach($s->all('SELECT * FROM ticket_types WHERE active=1 AND price>=30') as $t) echo '<option value="'.$t['id'].'">'.h($t['name']).' — '.money($t['price']).' each</option>';
            echo '</select></label><label>Quantity<input type="number" name="quantity" min="1" max="'.min(10,$a['remaining']).'" value="1" required></label></div><div class="form-row"><label>Your name<input name="name" maxlength="100" autocomplete="name" required></label><label>Email for your tickets<input type="email" name="email" maxlength="254" autocomplete="email" required></label></div><label class="consent"><input type="checkbox" name="rules" value="yes" required><span>I accept the <a href="/rules.php">rules</a> and have read the <a href="/privacy.php">privacy information</a>.</span></label><p>Places are held during checkout for approximately 35 minutes. The final total is shown before payment on Stripe.</p><button class="button">Continue to secure payment ↗</button></form>';
        }
    } catch(RuntimeException $e) { notice($e->getMessage()); }
}
foot();
