<?php
require dirname(__DIR__).'/app/web.php';
$enabled=$s->setting('bookings_enabled')==='1';
$error='';
$date=is_string($_GET['date']??null)?$_GET['date']:date('Y-m-d');
$quantity=max(1,min(10,(int)($_GET['quantity']??1)));
$types=$s->all('SELECT * FROM ticket_types WHERE active=1 AND price>=30 ORDER BY id');

if(post()) {
    $date=field('date');
    $quantity=max(1,min(10,(int)field('quantity')));
    checkCsrf();
    try {
        $s->rate('book:'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'local',$config['app_key']),10,3600);
        if(!$enabled) throw new RuntimeException('Bookings are not yet open. No fishing or site access is granted.');
        if(field('rules')!=='yes') throw new RuntimeException('Please accept the rules and privacy information.');
        // Constructing the gateway first prevents creating a hold when Stripe is not configured.
        $payments=new Temple\Payments($s,$config);
        $b=$booking->reserve($date,(int)field('type'),$quantity,field('name'),field('email'));
        $_SESSION['last_booking']=$b['token'];
        try { redirect($payments->start($b)); }
        catch(Throwable $e) { redirect('/booking.php?token='.$b['token']); }
    } catch(RuntimeException $e) { $error=$e->getMessage(); }
}

$availability=null;
if($enabled) {
    try { Temple\Booking::date($date); $availability=$booking->availability($date); }
    catch(RuntimeException $e) { if(isset($_GET['date']) || post()) $error=$error?:$e->getMessage(); }
}
$selectedType=(int)(post()?field('type'):($types[0]['id']??0));
$summaryTimestamp=strtotime($date)?:time();
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><meta name="theme-color" content="#153f36">
<title>Day tickets · Temple Springs Fisheries</title>
<link rel="icon" href="/assets/mark.svg"><link rel="stylesheet" href="/fishery.css?v=6"><link rel="stylesheet" href="/homepage-water.css?v=3"><link rel="stylesheet" href="/tickets.css?v=1">
<script src="/homepage-water.js?v=4" defer></script><script src="/tickets.js?v=1" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="opening-strip"><span class="dot"></span> <?= $enabled?'SECURE ONLINE BOOKING':'BOOKINGS CURRENTLY CLOSED' ?> <span class="strip-extra">· Fishing rights and access are not yet confirmed</span></div>
<header class="site-header shell"><a class="logo" href="/"><img src="/assets/mark.svg" width="44" height="44" alt=""><span>TEMPLE SPRINGS<small>F I S H E R I E S · B O L T O N</small></span></a><a class="underlink" href="/">← Back to the fishery</a></header>
<main id="main" class="shell tickets-main">
 <div class="tickets-heading"><p class="kicker">YOUR DAY BY THE WATER</p><h1>Let’s make a day of it.</h1><p><?= $enabled?'Choose your day tickets, enter your details and pay securely through Stripe.':'The booking system is ready, but public bookings will stay closed until the project has confirmed fishing rights, site access and opening arrangements.' ?></p></div>
 <div class="tickets-layout">
  <section class="tickets-work">
   <ol class="booking-progress" aria-label="Booking steps"><li class="current">01 <span>Your day</span></li><li>02 <span>Your tickets</span></li><li>03 <span>Secure payment</span></li><li>04 <span>Digital ticket</span></li></ol>
   <?php if($error): ?><p class="booking-notice error" role="alert"><?=h($error)?></p><?php endif; ?>
   <?php if(!$enabled): ?>
    <div class="closed-card"><span class="tag">PRE-OPENING</span><h2>Bookings are not yet open.</h2><p>Temple Springs is a proposed community-led fishing restoration project. No angling rights or public access are confirmed. Please do not enter the site or fish on the basis of this website.</p><a class="btn btn-dark" href="/#opening">Read the project update <span>↗</span></a></div>
   <?php else: ?>
    <?php if(($config['stripe_mode']??'test')==='test'): ?><p class="booking-notice">TEST MODE — no real payment or fishing ticket will be created. Do not use test tickets for site access.</p><?php endif; ?>
    <form method="get" class="date-panel">
     <div><label for="fishing-date">Fishing date</label><p>Choose a date within the next year.</p></div>
     <input id="fishing-date" type="date" name="date" min="<?=date('Y-m-d')?>" max="<?=date('Y-m-d',strtotime('+365 days'))?>" value="<?=h($date)?>" required>
     <button class="btn btn-outline">Check availability</button>
    </form>
    <?php if($availability): ?>
     <p class="availability <?=($availability['closed']||$availability['remaining']<1)?'unavailable':''?>"><span class="dot"></span> <?=$availability['closed']?'Closed on this date':h((string)$availability['remaining']).' of '.h((string)$availability['capacity']).' places available for '.h(date('j F Y',strtotime($date)))?></p>
     <?php if(!$availability['closed'] && $availability['remaining']>0): ?>
      <form method="post" id="ticket-form" class="checkout-form">
       <?=csrf()?><input type="hidden" name="date" value="<?=h($date)?>">
       <div class="step-heading"><span>01</span><div><h2>Choose your tickets.</h2><p>Each angler receives an individual dated QR ticket after payment is confirmed.</p></div></div>
       <fieldset class="ticket-choices"><legend class="visually-hidden">Ticket type</legend>
        <?php foreach($types as $i=>$t): ?>
         <label class="ticket-option"><input type="radio" name="type" value="<?=$t['id']?>" data-name="<?=h($t['name'])?>" data-price="<?=$t['price']?>" <?=($selectedType===(int)$t['id']||(!$selectedType&&$i===0))?'checked':''?> required><span><strong><?=h($t['name'])?></strong><small>One dated digital ticket per angler</small></span><b><?=money((int)$t['price'])?></b></label>
        <?php endforeach; ?>
       </fieldset>
       <div class="form-grid"><label>Number of anglers<input id="ticket-quantity" type="number" name="quantity" min="1" max="<?=min(10,$availability['remaining'])?>" value="<?=$quantity?>" required></label><label>Your name<input name="name" maxlength="100" autocomplete="name" value="<?=h(post()?field('name'):'')?>" required></label><label class="wide">Email for your tickets<input type="email" name="email" maxlength="254" autocomplete="email" value="<?=h(post()?field('email'):'')?>" required></label></div>
       <label class="consent"><input type="checkbox" name="rules" value="yes" required><span>I accept the <a href="/rules.php">rules</a> and have read the <a href="/privacy.php">privacy information</a>.</span></label>
       <p class="small-print">Places are held during checkout for approximately 35 minutes. Stripe shows the final total before payment. Tickets are issued only after signed payment confirmation reaches Temple Springs.</p>
       <button class="btn btn-dark btn-wide">Continue to secure payment <span>↗</span></button>
      </form>
     <?php endif; ?>
    <?php endif; ?>
   <?php endif; ?>
  </section>
  <aside class="tickets-aside">
   <div class="aside-art" data-fish-scene="card"><img src="/assets/landscape.svg" alt="Illustrated lake and woodland" width="800" height="900"><div class="aside-water" aria-hidden="true"><canvas class="jumping-fish-canvas"></canvas></div><span>TEMPLE SPRINGS<br>FISHERIES</span></div>
   <div class="aside-body"><span class="tag"><?=$enabled?'SECURE DAY TICKETS':'PRE-OPENING'?></span><h2>Your day.<br>Kept simple.</h2><div id="booking-summary"><dl><div><dt>Date</dt><dd><?=h(date('j M Y',$summaryTimestamp))?></dd></div><div><dt>Ticket</dt><dd id="summary-ticket"><?=$enabled?'Choose below':'Not available yet'?></dd></div><div><dt>Anglers</dt><dd id="summary-quantity"><?=$enabled?$quantity:'—'?></dd></div><div class="summary-total"><dt>Estimated total</dt><dd id="summary-total">—</dd></div></dl></div><hr><p class="small-print">A paid ticket will be private, dated and unique to one angler. Keep it available for occasional bankside spot checks. A bailiff does not need to be present before fishing begins once the fishery is officially open.</p></div>
  </aside>
 </div>
</main>
<footer class="tickets-footer shell"><p>Temple Springs · Proposed community-led restoration.<br>This website does not grant permission to enter or fish.</p><div><a href="/privacy.php">Privacy</a> · <a href="/staff.php">Staff sign in</a></div></footer>
</body></html>
