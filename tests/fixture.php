<?php
// Local automated-test fixture only. No production configuration or database is read.
if(PHP_SAPI!=='cli' || !getenv('TEMPLE_TEST_DIR')) exit(1);
require dirname(__DIR__).'/vendor/autoload.php'; date_default_timezone_set('Europe/London');
$dir=getenv('TEMPLE_TEST_DIR');
$c=require dirname(__DIR__).'/config.example.php';
$c['data_dir']=$dir.'/data';$c['base_url']='http://127.0.0.1:8093';$c['app_key']=bin2hex(random_bytes(32));$c['secure_cookies']=false;
$c['stripe_secret']='sk_test_local_fixture_not_a_real_key';$c['stripe_webhook_secret']='whsec_local_fixture';
file_put_contents($dir.'/config.php','<?php return '.var_export($c,true).';'); chmod($dir.'/config.php',0600);
mkdir($c['data_dir'],0700);$s=new Temple\Store($c['data_dir'].'/temple.sqlite');$s->db->exec(file_get_contents(dirname(__DIR__).'/migrations/001.sql'));
foreach(['admin','bailiff'] as $role) $s->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',[$role.'@example.test',ucfirst($role),password_hash('Local-test-password-7491',PASSWORD_DEFAULT),$role]);
$s->run("UPDATE settings SET value='1' WHERE key='bookings_enabled'");$b=new Temple\Booking($s);
$r=$b->reserve(date('Y-m-d'),1,2,'=Customer Example','customer@example.test');
$s->run("UPDATE settings SET value='0' WHERE key='bookings_enabled'");
echo json_encode($r);
