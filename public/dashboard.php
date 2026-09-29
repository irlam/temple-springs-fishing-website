<?php
require dirname(__DIR__).'/app/web.php';
$u=staff(); $isAdmin=$u['role']==='admin'; $error='';
if(post()) {
    checkCsrf(); staff(true);
    try {
        $id=(int)field('booking_id'); $b=$s->one('SELECT * FROM bookings WHERE id=?',[$id]);
        if(!$b) throw new RuntimeException('Booking not found.');
        if(field('action')==='retry_email') { $s->run("UPDATE outbox SET status='pending',attempts=0,next_attempt=0 WHERE booking_id=?",[$id]); $s->audit($u['id'],'retry_email',$id); }
        elseif(field('action')==='cancel') {
            if($b['status']==='complimentary') $s->run("UPDATE bookings SET status='cancelled' WHERE id=? AND status='complimentary'",[$id]);
            elseif(in_array($b['status'],['creating','pending'],true)) (new Temple\Payments($s,$config))->expire($b,'cancelled');
            else throw new RuntimeException('Refund paid bookings in Stripe. Their status updates through the signed refund webhook.');
            $s->audit($u['id'],'cancellation_requested',$id);
        }
        redirect('/dashboard.php?q='.urlencode($b['reference']));
    } catch(RuntimeException $e) { $error=$e->getMessage(); } catch(Throwable $e) { $error='The operation could not be confirmed. Check payment status before retrying.'; }
}
$q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
$date=is_string($_GET['date']??null)?$_GET['date']:'';
$args=['%'.$q.'%','%'.$q.'%','%'.$q.'%']; $where='(b.reference LIKE ? OR b.name LIKE ? OR b.email LIKE ?)';
if($date) { $where.=' AND b.date=?'; $args[]=$date; }
if(isset($_GET['export'])) {
    staff(true); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="temple-bookings.csv"');
    $f=fopen('php://output','w'); fputcsv($f,['Reference','Date','Type','Quantity','Name','Email','Status','Total GBP','Refund GBP','Stripe session','Payment intent'],',','"','');
    foreach($s->all('SELECT b.* FROM bookings b WHERE '.$where.' ORDER BY b.id DESC',$args) as $b) {
        $row=[$b['reference'],$b['date'],$b['type_name'],$b['quantity'],$b['name'],$b['email'],$b['status'],number_format($b['total']/100,2,'.',''),number_format($b['refund_amount']/100,2,'.',''),$b['session_id'],$b['payment_intent']];
        fputcsv($f,array_map(fn($v)=>preg_match('/^[\s]*[=+@\-]/u',(string)$v)?"'".$v:$v,$row),',','"','');
    }
    $s->audit($u['id'],'csv_export'); exit;
}
head('Fishery dashboard'); if($error) notice($error);
echo '<div class="toolbar"><a class="button" href="/scan.php">Bankside spot check</a><a class="button secondary" href="/spot-checks.php">Spot-check log</a>'.($isAdmin?'<a class="button secondary" href="/settings.php">Manage fishery</a><a class="button secondary" href="/bailiffs.php">Manage bailiffs</a>':'').'<form method="post" action="/staff.php">'.csrf().'<input type="hidden" name="action" value="logout"><button class="button secondary">Sign out</button></form></div>';
notice('Public bookings: '.($s->setting('bookings_enabled')==='1'?'ON':'OFF').' · Signed in as '.$u['name'].' ('.$u['role'].')');
$a=$booking->availability(date('Y-m-d')); $checkins=$s->one("SELECT COUNT(*) n FROM audit WHERE action='spot_check' AND created_at>=?",[strtotime('today')])['n'];
$payments=$s->one("SELECT COALESCE(SUM(total-refund_amount),0) n FROM bookings WHERE status IN ('paid','partially_refunded','refunded','refund_required')")['n'];
$alerts=$s->one("SELECT COUNT(*) n FROM bookings WHERE status='refund_required'")['n'];
echo '<div class="metrics"><article><strong>'.$a['used'].' / '.$a['capacity'].'</strong><span>Places reserved today'.($a['closed']?' · Closed':'').'</span></article><article><strong>'.$checkins.'</strong><span>Spot checks today</span></article><article><strong>'.money($payments).'</strong><span>Payments less refunds · All dates</span></article><article><strong>'.$alerts.'</strong><span>Payments needing refund review</span></article></div><form method="get" class="panel form-row"><label>Search bookings<input name="q" value="'.h($q).'" placeholder="Reference, name or email"></label><label>Fishing date<input type="date" name="date" value="'.h($date).'"></label><button class="button">Search</button></form>';
if($isAdmin) echo '<p><a class="text-link" href="/dashboard.php?export=1&q='.urlencode($q).'&date='.urlencode($date).'">Export matching bookings as CSV</a></p>';
$page=max(1,min(100000,(int)($_GET['page']??1))); $offset=($page-1)*30;
$rows=$s->all('SELECT b.*,o.status email_status,o.last_error FROM bookings b LEFT JOIN outbox o ON o.booking_id=b.id WHERE '.$where.' ORDER BY b.id DESC LIMIT 31 OFFSET '.$offset,$args);
foreach(array_slice($rows,0,30) as $b) {
    echo '<article class="panel"><div class="booking-title"><h3>'.h($b['reference']).'</h3><span class="pill">'.h($b['mode'].' · '.$b['status']).'</span></div><p>'.h($b['date']).' · '.h($b['type_name']).' × '.$b['quantity'].' · '.money($b['total']).'<br>'.h($b['name']).' · '.h($b['email']).'</p>';
    if($b['note']) echo '<p>Record: '.h($b['note']).'</p>';
    if($isAdmin) {
        echo '<p class="quiet">Email: '.h($b['email_status']??'not issued').' '.h($b['last_error']).'<br>Stripe session: '.h($b['session_id']??'—').'<br>Payment intent: '.h($b['payment_intent']??'—').' · Paid: '.money($b['amount_paid']).' · Refunded: '.money($b['refund_amount']).' · Method: '.h($b['payment_method']??'—').'</p><form class="toolbar" method="post">'.csrf().'<input type="hidden" name="booking_id" value="'.$b['id'].'">';
        if($b['email_status']) echo '<button name="action" value="retry_email" class="button secondary">Resend ticket email</button>';
        if(in_array($b['status'],['creating','pending','complimentary'],true)) echo '<button name="action" value="cancel" class="button secondary">Cancel booking</button>';
        echo '</form>';
        $remaining=max(0,(int)$b['amount_paid']-(int)$b['refund_amount']);
        if($remaining>0 && in_array($b['status'],['paid','partially_refunded','refund_required'],true)) {
            echo '<form class="panel" method="post">'.csrf().'<input type="hidden" name="booking_id" value="'.$b['id'].'"><h4>Stripe refund</h4><div class="form-row"><label>Amount (£)<input name="refund_amount" inputmode="decimal" value="'.number_format($remaining/100,2,'.','').'" required></label><label>Reason<input name="refund_reason" maxlength="500" placeholder="Customer cancellation" required></label></div><button name="action" value="refund" class="button secondary">Issue refund</button></form>';
        }
        $refunds=$s->all('SELECT * FROM refunds WHERE booking_id=? ORDER BY id DESC',[$b['id']]);
        if($refunds) {
            echo '<p class="quiet"><strong>Refund history</strong><br>';
            foreach($refunds as $r) echo h($r['stripe_refund_id']).' · '.money($r['amount']).' · '.h($r['status']).'<br>';
            echo '</p>';
        }
    }
    foreach($s->all('SELECT * FROM tickets WHERE booking_id=?',[$b['id']]) as $i=>$t) echo '<a class="ticket-link" href="/scan.php?token='.h($t['token']).'">Ticket '.($i+1).' · '.'Inspect ticket'.' ↗</a> ';
    echo '</article>';
}
if(!$rows) notice('No matching bookings.');
if($page>1) echo '<a class="button secondary" href="?q='.urlencode($q).'&date='.urlencode($date).'&page='.($page-1).'">Previous</a> ';
if(count($rows)>30) echo '<a class="button" href="?q='.urlencode($q).'&date='.urlencode($date).'&page='.($page+1).'">Next</a>';
foot();
