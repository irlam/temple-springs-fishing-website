<?php
declare(strict_types=1);
use Temple\Store;
use Temple\Booking;
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('Europe/London');
umask(0077);
$configPath=getenv('TEMPLE_CONFIG') ?: dirname(__DIR__).'/config.php';
if (!is_file($configPath)) throw new RuntimeException('Application configuration is missing.');
$config=require $configPath;
if (strlen($config['app_key']??'')<32) throw new RuntimeException('Configure a strong app_key.');
$data=rtrim($config['data_dir'],'/');
if (!is_dir($data)) mkdir($data,0700,true);
if (str_starts_with(realpath($data).'/',realpath(dirname(__DIR__).'/public').'/')) throw new RuntimeException('Data directory must be outside public.');
$s=new Store($data.'/temple.sqlite'); $booking=new Booking($s,$config['stripe_mode']??'test');
if (PHP_SAPI!=='cli' && !defined('TEMPLE_WEBHOOK')) {
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'none'");
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if (!is_dir($data.'/sessions')) mkdir($data.'/sessions',0700);
    ini_set('session.use_strict_mode','1'); session_save_path($data.'/sessions');
    session_name('temple_session');
    session_set_cookie_params(['httponly'=>true,'secure'=>$config['secure_cookies']??true,'samesite'=>'Lax','path'=>'/']);
    session_start(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
