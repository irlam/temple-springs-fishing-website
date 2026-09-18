<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';date_default_timezone_set('Europe/London');
$dir=sys_get_temp_dir().'/temple-demo-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$s=new Temple\Store($dir.'/demo.sqlite');$d=new Temple\Demo($s);$checks=0;
function test(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label."\n";}
function refused(callable $f,string $label):void{$failed=false;try{$f();}catch(RuntimeException $e){$failed=true;}test($failed,$label);}
try{
 $date=date('Y-m-d');$future=date('Y-m-d',strtotime('+1 day'));
 $b=$d->reserve($date,'adult',2,'Practice Angler');test($b['total']===2000&&$b['tickets']===[],'pending demo prices calculated server-side and has no QR tickets');
 test($d->availability($date)['remaining']===18,'demo reservation holds demo capacity');
 $b=$d->outcome($b['token'],'confirmed');test(count($b['tickets'])===2,'simulated confirmation creates one QR per angler');
 test(count($d->outcome($b['token'],'confirmed')['tickets'])===2,'repeated simulation does not duplicate tickets');
 test($b['tickets'][0]['token']!==$b['tickets'][1]['token']&&strlen($b['tickets'][0]['token'])===64,'demo ticket tokens are distinct cryptographic tokens');
 $t=$b['tickets'][0]['token'];$v=$d->validate($t);test($v['state']==='valid'&&!isset($v['name'],$v['email'],$v['token']),'demo scan shows validity without customer details');
 $d->checkIn($t);test($d->validate($t)['state']==='already-used','explicit demo check-in records use');refused(fn()=>$d->checkIn($t),'repeat demo check-in rejected');
 $d->outcome($b['token'],'refunded');test($d->validate($t)['state']==='refunded'&&$d->availability($date)['remaining']===20,'simulated refund invalidates tickets and releases demo capacity');
 refused(fn()=>$d->outcome($b['token'],'confirmed'),'refunded demo cannot be revived');
 $failed=$d->reserve($date,'junior',1,'Guest');$failed=$d->outcome($failed['token'],'failed');test($failed['tickets']===[]&&$d->availability($date)['remaining']===20,'simulated failure issues no tickets and releases holds');
 $cancel=$d->reserve($date,'adult',1,'Guest');$d->outcome($cancel['token'],'cancelled');test($d->availability($date)['remaining']===20,'demo cancellation releases capacity');
 $wrong=$d->reserve($future,'adult',1,'Guest');$wrong=$d->outcome($wrong['token'],'confirmed');test($d->validate($wrong['tickets'][0]['token'])['state']==='wrong-date','future demo ticket reports wrong date');
 refused(fn()=>$d->reserve($date,'made-up',1,'Guest'),'invalid demo type refused');refused(fn()=>$d->reserve($date,'adult',7,'Guest'),'demo quantity capped');
 $expired=$d->reserve($date,'adult',1,'Guest');$s->run('UPDATE demo_bookings SET expires_at=? WHERE token=?',[time()-1,$expired['token']]);$d->tidy();test($d->get($expired['token'])['status']==='expired','expired demo holds released');
 refused(fn()=>$d->outcome($expired['token'],'confirmed'),'expired demo cannot confirm');
 $s->run('UPDATE demo_bookings SET created_at=? WHERE token=?',[time()-86401,$b['token']]);$d->tidy();test($d->get($b['token'])===null&&$d->validate($t)===null,'24-hour retention removes bookings and related QR tickets');
 test(!$s->one("SELECT name FROM sqlite_master WHERE name='bookings'"),'demo database has no production booking table');
 echo "\n$checks demo service checks passed. No payment credentials used.\n";
}finally{unset($s,$d);foreach(glob($dir.'/*')as$f)@unlink($f);@rmdir($dir);}
