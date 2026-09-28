<?php
require dirname(__DIR__).'/app/web.php';
$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$user=$id?$s->one("SELECT active FROM users WHERE id=? AND role='bailiff'",[$id]):null;
$profile=json_decode($s->setting('bailiff_profile_'.(int)$id),true)?:[];
if(isset($_GET['preview'])) staff(true);
elseif(!$user || !$user['active'] || empty($profile['published'])) {http_response_code(404);exit;}
$filename=$s->setting('bailiff_photo_'.(int)$id);
if(!$user || !preg_match('/^[a-f0-9]{48}\.jpg$/D',$filename) || !is_file($data.'/bailiff-photos/'.$filename)) {http_response_code(404);exit;}
header('Content-Type: image/jpeg');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
readfile($data.'/bailiff-photos/'.$filename);
