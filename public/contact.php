<?php
require dirname(__DIR__).'/app/web.php';
header('Content-Type: application/json; charset=utf-8');
function respond(int $code,array $data): never { http_response_code($code); echo json_encode($data); exit; }
$enabled=!empty($config['smtp']['host']) && filter_var($config['smtp']['from']??'',FILTER_VALIDATE_EMAIL) && filter_var($config['recipient']??'',FILTER_VALIDATE_EMAIL);
if($_SERVER['REQUEST_METHOD']==='GET') respond(200,['enabled'=>(bool)$enabled,'token'=>$_SESSION['csrf']]);
if(!post()) respond(405,['message'=>'Method not allowed.']);
if(!$enabled) respond(503,['message'=>'Enquiries are not available yet. No message has been sent.']);
if(!hash_equals($_SESSION['csrf'],field('token'))) respond(403,['message'=>'Please reload the page before sending.']);
if(field('website')!=='') respond(400,['message'=>'Unable to accept this enquiry.']);
$name=field('name'); $email=field('email'); $message=field('message'); $interest=field('interest');
if(!$name || mb_strlen($name)>100 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($message)<10 || strlen($message)>20000 || strlen($interest)>100 || field('consent')!=='yes') respond(422,['message'=>'Please check your details, message and consent.']);
try { $s->rate('contact:'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR'],$config['app_key']),3,600); }
catch(RuntimeException $e) { respond(429,['message'=>$e->getMessage()]); }
try { Temple\Mail::send($config,$config['recipient'],'Temple Springs project enquiry',"Name: $name\nEmail: $email\nInterest: $interest\n\n$message",$email); }
catch(Throwable $e) { respond(503,['message'=>'Email delivery could not be confirmed. Please try again later.']); }
respond(200,['message'=>'Thank you. Your enquiry has been accepted for email delivery to the organiser.']);
