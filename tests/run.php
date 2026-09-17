<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('Europe/London');
use Temple\{Store,Booking,Payments,Mail};
$dir=sys_get_temp_dir().'/temple-tests-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
$s=new Store($dir.'/test.sqlite'); $s->db->exec(file_get_contents(dirname(__DIR__).'/migrations/001.sql')); $b=new Booking($s);
$count=0;
function ok(bool $condition,string $name): void { global $count; if(!$condition) throw new RuntimeException('FAIL: '.$name); echo 'PASS: '.$name."\n"; $count++; }
function fails(callable $f,string $name): void { $thrown=false; try{$f();}catch(Throwable $e){$thrown=true;} ok($thrown,$name); }
function event(array $r,string $kind='checkout.session.completed',string $status='paid'): array {
    return ['id'=>'evt_'.bin2hex(random_bytes(8)),'type'=>$kind,'livemode'=>false,'data'=>['object'=>['id'=>$r['session_id']?:'cs_'.$r['id'],'client_reference_id'=>$r['reference'],'metadata'=>['booking_id'=>(string)$r['id']],'payment_status'=>$status,'currency'=>'gbp','amount_total'=>$r['total'],'payment_intent'=>'pi_'.$r['id']]]];
}
function row(int $id): array {global $s;return $s->one('SELECT * FROM bookings WHERE id=?',[$id]);}
function ticket(int $id): ?array {global $s;return $s->one('SELECT * FROM tickets WHERE booking_id=?',[$id]);}
class FakeStripe implements Stripe\HttpClient\ClientInterface {
    public array $calls=[]; public string $state='open'; public bool $error=false;
    public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null) {
        $this->calls[]=[$method,$url,$headers,$params];
        if($this->error) throw new RuntimeException('Simulated unavailable network');
        $state=str_ends_with($url,'/expire')?'expired':$this->state;
        return [json_encode(['id'=>'cs_mock','object'=>'checkout.session','url'=>'https://checkout.stripe.com/c/pay/mock','status'=>$state]),200,[]];
    }
}
try {
    $today=date('Y-m-d'); $tomorrow=date('Y-m-d',strtotime('+1 day'));
    $s->run("INSERT INTO users(email,name,password,role) VALUES('admin@example.test','Admin','unused','admin'),('bailiff@example.test','Bailiff','unused','bailiff')");
    fails(fn()=>$b->reserve($today,1,1,'Test','test@example.test'),'bookings OFF rejects direct reservation');
    fails(fn()=>$b->reserve($today,1,1,'Test','test@example.test',1,'Test'),'OFF rejects complimentary tickets');
    $s->run("UPDATE settings SET value='1' WHERE key='bookings_enabled'");
    fails(fn()=>$b->reserve('2026-02-30',1,1,'Test','test@example.test'),'invalid date rejected');
    fails(fn()=>$b->reserve($today,1,0,'Test','test@example.test'),'invalid quantity rejected');
    fails(fn()=>$b->reserve($today,1,1,'Test','bad'),'invalid email rejected');
    fails(fn()=>$b->reserve($today,1,1,'Test','test@example.test',2,'Test'),'bailiff cannot issue complimentary tickets');
    $s->run('INSERT INTO days(date,capacity,closed) VALUES(?,2,0)',[$today]);
    $r=$b->reserve($today,1,2,'Test Angler','test@example.test');
    ok($b->availability($today)['remaining']===0,'pending checkout holds daily capacity');
    fails(fn()=>$b->reserve($today,1,1,'Other','other@example.test'),'overbooking blocked');
    ok(!ticket($r['id']),'unpaid checkout has no tickets');
    $unpaid=event($r,status:'unpaid'); $b->event($unpaid,false); ok(!ticket($r['id']),'unpaid completed session never issues tickets');
    $bad=event($r); $bad['data']['object']['amount_total']=1; fails(fn()=>$b->event($bad,false),'incorrect payment amount rejected');
    $bad=event($r); $bad['data']['object']['currency']='usd'; fails(fn()=>$b->event($bad,false),'incorrect payment currency rejected');
    $bad=event($r); $bad['livemode']=true; fails(fn()=>$b->event($bad,false),'wrong Stripe mode rejected');
    $paid=event($r); $b->event($paid,false); $b->event($paid,false); $b->event(event($r),false);
    ok((int)$s->one('SELECT COUNT(*) n FROM tickets WHERE booking_id=?',[$r['id']])['n']===2,'duplicate events and separate repeated completion issue tickets once');
    ok((int)$s->one('SELECT COUNT(*) n FROM outbox WHERE booking_id=?',[$r['id']])['n']===1,'email outbox deduplicated');
    $t=ticket($r['id']); ok(strlen($t['token'])===64 && $t['token']!==$r['token'],'random per-person QR tokens are distinct from customer link');
    ok($b->validate($t['token'])['state']==='valid','paid ticket valid on booked date');
    ok((new Booking($s,'live'))->validate($t['token'])['state']==='wrong-mode','test ticket cannot validate in live mode');
    ok($b->validate('TS-123')===null,'booking reference is not a QR credential');
    fails(fn()=>$b->checkIn($t['token'],999),'unauthenticated staff cannot check in');
    $b->checkIn($t['token'],2); ok($b->validate($t['token'])['state']==='already-used','check-in records used state');
    fails(fn()=>$b->checkIn($t['token'],2),'repeat check-in blocked');
    ok((int)ticket($r['id'])['checked_by']===2,'bailiff identity recorded');
    $b->event(event($r,'checkout.session.expired'),false); ok(row($r['id'])['status']==='paid','late expiry cannot revoke paid ticket');
    $refund=['id'=>'evt_refund','type'=>'charge.refunded','livemode'=>false,'data'=>['object'=>['payment_intent'=>'pi_'.$r['id'],'amount_refunded'=>500]]];
    $b->event($refund,false); ok(row($r['id'])['status']==='partially_refunded' && $b->availability($today)['remaining']===0,'partial refund retains all capacity and tracks amount');
    $refund['id']='evt_full'; $refund['data']['object']['amount_refunded']=$r['total']; $b->event($refund,false);
    ok($b->validate($t['token'])['state']==='refunded' && $b->availability($today)['remaining']===2,'full refund invalidates tickets and releases capacity');
    $b->event(event($r),false); ok(row($r['id'])['status']==='refunded','completion cannot revive refunded booking');
    $comp=$b->reserve($tomorrow,2,1,'Junior','junior@example.test',1,'Community invitation');
    ok($b->validate(ticket($comp['id'])['token'])['state']==='wrong-date','future ticket reports wrong date');
    $s->run("UPDATE bookings SET status='cancelled' WHERE id=?",[$comp['id']]); ok($b->validate(ticket($comp['id'])['token'])['state']==='cancelled','cancelled complimentary ticket invalid');
    $s->run('INSERT INTO days(date,closed) VALUES(?,1)',[$tomorrow]); fails(fn()=>$b->reserve($tomorrow,1,1,'Test','test@example.test'),'closed day rejected');
    $s->run('UPDATE days SET closed=0 WHERE date=?',[$tomorrow]);
    $exp=$b->reserve($today,1,2,'Expiring','expire@example.test'); $b->event(event($exp,'checkout.session.expired'),false);
    ok($b->availability($today)['remaining']===2,'expiry event releases reservation');
    $replacement=$b->reserve($today,1,2,'Replacement','replace@example.test'); $b->event(event($exp),false);
    ok(row($exp['id'])['status']==='refund_required' && !ticket($exp['id']),'late paid checkout cannot overbook: refund review instead');
    $b->event(event($replacement,'checkout.session.async_payment_failed'),false); ok($b->availability($today)['remaining']===2,'terminal payment failure releases capacity');
    $attempt=$b->reserve($tomorrow,1,1,'Retry','retry@example.test');
    $ev=['id'=>'evt_attempt','type'=>'payment_intent.payment_failed','livemode'=>false,'data'=>['object'=>['metadata'=>['booking_id'=>$attempt['id']]]]];
    $b->event($ev,false); ok(row($attempt['id'])['status']==='creating','retryable card decline keeps reservation while checkout remains open');
    $config=['stripe_mode'=>'test','stripe_secret'=>'sk_test_fixture','base_url'=>'https://example.test'];
    $p=new Payments($s,$config); $fake=new FakeStripe(); Stripe\ApiRequestor::setHttpClient($fake);
    $url=$p->start($attempt); ok($url==='https://checkout.stripe.com/c/pay/mock' && row($attempt['id'])['session_id']==='cs_mock','Checkout request binds session ID');
    ok($fake->calls[0][3]['line_items'][0]['price_data']['unit_amount']===1000,'Stripe amount is server-side price snapshot');
    ok($fake->calls[0][3]['payment_method_types']===['card'],'checkout uses immediate card methods only');
    fails(fn()=>$p->start($attempt),'stale checkout state cannot initiate another payment');
    $s->run("UPDATE settings SET value='0' WHERE key='bookings_enabled'"); $before=count($fake->calls);
    fails(fn()=>$p->start($replacement),'bookings OFF blocks payment initiation'); ok(count($fake->calls)===$before,'OFF never calls Stripe');
    $fake->state='complete'; $p->expire(row($attempt['id'])); ok(row($attempt['id'])['status']==='pending','completed payment holds capacity awaiting signed webhook');
    $fake->state='open'; $p->expire(row($attempt['id']),'cancelled'); ok(row($attempt['id'])['status']==='cancelled','server expiry confirms cancellation before release');
    $b->event(event(row($attempt['id'])),false); ok(row($attempt['id'])['status']==='refund_required','paid event cannot revive cancelled reservation');
    $raw=json_encode(event($replacement)); $ts=time(); $sig='t='.$ts.',v1='.hash_hmac('sha256',$ts.'.'.$raw,'whsec_fixture');
    ok(Stripe\Webhook::constructEvent($raw,$sig,'whsec_fixture')->type==='checkout.session.completed','signed webhook verification accepts authentic fixture');
    fails(fn()=>Stripe\Webhook::constructEvent($raw.' ',$sig,'whsec_fixture'),'tampered webhook rejected');
    fails(fn()=>Stripe\Webhook::constructEvent($raw,'t=1,v1='.hash_hmac('sha256','1.'.$raw,'whsec_fixture'),'whsec_fixture'),'stale webhook signature rejected');
    $outOfOrder=['id'=>'evt_early_refund','type'=>'charge.refunded','livemode'=>false,'data'=>['object'=>['payment_intent'=>'pi_missing','metadata'=>['booking_id'=>'10000'],'amount_refunded'=>1000]]];
    fails(fn()=>$b->event($outOfOrder,false),'out-of-order refund requests Stripe retry');
    ok(!$s->one('SELECT id FROM events WHERE id=?',[$outOfOrder['id']]),'failed event rolled back for safe retry');
    $s->run("UPDATE settings SET value='1' WHERE key='bookings_enabled'");
    $emailBooking=$b->reserve($tomorrow,1,1,'Mail Test','mail@example.test',1,'Email failure test');
    Mail::drain($s,['stripe_mode'=>'test','base_url'=>'https://example.test','smtp'=>['host'=>'','from'=>'']]);
    $out=$s->one('SELECT * FROM outbox WHERE booking_id=?',[$emailBooking['id']]);
    ok($out['status']==='pending' && (int)$out['attempts']===1 && $out['next_attempt']>time(),'SMTP failure queues retry without revoking tickets');
    $s->rate('limit',1,60); fails(fn()=>$s->rate('limit',1,60),'persistent rate limit enforced');
    $backup=$dir.'/backup.sqlite'; $s->db->exec('VACUUM INTO '.$s->db->quote($backup)); $bs=new Store($backup); ok($bs->one('PRAGMA integrity_check')['integrity_check']==='ok','consistent backup restores with integrity intact'); unset($bs);
    // True multi-process contention for a single remaining place.
    $raceDate=date('Y-m-d',strtotime('+2 days')); $s->run('INSERT INTO days(date,capacity) VALUES(?,1)',[$raceDate]);
    $workers=[];
    for($i=0;$i<6;$i++) {
        $code='require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).';date_default_timezone_set("Europe/London");$s=new Temple\\Store('.var_export($dir.'/test.sqlite',true).');try{(new Temple\\Booking($s))->reserve('.var_export($raceDate,true).',1,1,"Race","race@example.test");echo "won";}catch(RuntimeException $e){echo "full";}';
        $proc=proc_open([PHP_BINARY,'-c',php_ini_loaded_file()?:'', '-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $workers[]=[$proc,$pipes];
    }
    $wins=0; foreach($workers as [$proc,$pipes]) { $text=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);if($text==='won')$wins++; }
    ok($wins===1 && $b->availability($raceDate)['used']===1,'six concurrent processes cannot overbook the final place');
    $raceBooking=$b->reserve($today,1,1,'Check in race','race@example.test',1,'Concurrency test');
    $raceToken=ticket($raceBooking['id'])['token']; $workers=[];
    for($i=0;$i<4;$i++) {
        $code='require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).';date_default_timezone_set("Europe/London");$s=new Temple\\Store('.var_export($dir.'/test.sqlite',true).');try{(new Temple\\Booking($s))->checkIn('.var_export($raceToken,true).',2);echo "won";}catch(RuntimeException $e){echo "used";}';
        $proc=proc_open([PHP_BINARY,'-c',php_ini_loaded_file()?:'', '-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $workers[]=[$proc,$pipes];
    }
    $wins=0; foreach($workers as [$proc,$pipes]) { $text=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);if($text==='won')$wins++; }
    ok($wins===1,'four simultaneous bailiffs can only check in the same ticket once');
    echo "\n$count checks passed. No real Stripe calls or emails were sent.\n";
} finally {
    unset($s,$b); foreach(glob($dir.'/*') as $f) @unlink($f); @rmdir($dir);
}
