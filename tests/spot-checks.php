<?php
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('Europe/London');
$s=new Temple\Store(':memory:');$s->db->exec(file_get_contents(dirname(__DIR__).'/migrations/001.sql'));
$b=new Temple\Booking($s);$checks=new Temple\SpotChecks($s,$b);
$s->run("INSERT INTO users(email,name,password,role) VALUES('admin@test.test','Admin','unused','admin'),('bailiff@test.test','Original Name','unused','bailiff')");
$s->run("UPDATE settings SET value='1' WHERE key='bookings_enabled'");
$r=$b->reserve(date('Y-m-d'),1,1,'Test Angler','angler@test.test',1,'Test invitation');
$t=$s->one('SELECT * FROM tickets WHERE booking_id=?',[$r['id']]);$token=$t['token'];
function verify($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS: $label\n";}
$b->checkIn($token,2);
verify($checks->inspect($token)['state']==='valid','legacy check-in does not consume spot-check ticket');
$request=bin2hex(random_bytes(32));$checks->record($token,2,$request,'Peg 7');$checks->record($token,2,$request,'Repeat POST');
verify($s->one("SELECT COUNT(*) n FROM audit WHERE action='spot_check'")['n']===1,'duplicate submission records once');
$checks->record($token,2,bin2hex(random_bytes(32)));
verify($checks->inspect($token)['state']==='valid','repeated spot check leaves ticket valid');
$s->run("UPDATE users SET name='Changed Name' WHERE id=2");
$d=json_decode($s->one("SELECT detail FROM audit WHERE action='spot_check' ORDER BY id LIMIT 1")['detail'],true);
verify($d['bailiff_name']==='Original Name'&&$d['note']==='Peg 7','log preserves staff name snapshot and note');
$s->run('UPDATE bookings SET date=? WHERE id=?',[date('Y-m-d',strtotime('+1 day')),$r['id']]);
verify($checks->inspect($token)['state']==='wrong-date','legacy check-in cannot hide wrong date');
$checks->record($token,2,bin2hex(random_bytes(32)));
$d=json_decode($s->one("SELECT detail FROM audit WHERE action='spot_check' ORDER BY id DESC LIMIT 1")['detail'],true);
verify($d['result']==='wrong-date','invalid-date inspection recorded without approval');
$s->run("UPDATE bookings SET status='cancelled' WHERE id=?",[$r['id']]);
verify($checks->inspect($token)['state']==='cancelled','cancelled remains invalid after previous checks');
$s->run('UPDATE users SET active=0 WHERE id=2');$denied=false;
try{$checks->record($token,2,bin2hex(random_bytes(32)));}catch(RuntimeException $e){$denied=true;}
verify($denied,'disabled staff cannot record checks');
