<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('Europe/London');umask(0077);
$path=getenv('TEMPLE_CONFIG')?:dirname(__DIR__).'/config.php';
$c=is_file($path)?require $path:[];
$demoDir=rtrim($c['data_dir']??dirname(__DIR__).'/var','/').'/demo';
if(!is_dir($demoDir) && !mkdir($demoDir,0700,true) && !is_dir($demoDir)) throw new RuntimeException('Private demo storage is unavailable.');
if(str_starts_with(realpath($demoDir).'/',realpath(dirname(__DIR__).'/public').'/')) throw new RuntimeException('Demo storage must be private.');
$demoStore=new Temple\Store($demoDir.'/demo.sqlite');$demo=new Temple\Demo($demoStore);$demo->tidy();
$demoBase=rtrim($c['base_url']??'https://fishing.defecttracker.uk','/');
header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
if(!is_dir($demoDir.'/sessions'))mkdir($demoDir.'/sessions',0700);
session_name('temple_demo');ini_set('session.use_strict_mode','1');session_save_path($demoDir.'/sessions');
session_set_cookie_params(['httponly'=>true,'secure'=>$c['secure_cookies']??true,'samesite'=>'Lax','path'=>'/']);session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(32));
// Bounded request cleanup, including on hosts without scheduled tasks yet.
foreach(array_slice(glob($demoDir.'/sessions/sess_*'),0,100) as $file)if(filemtime($file)<time()-86400)@unlink($file);
