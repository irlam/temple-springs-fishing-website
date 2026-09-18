<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function demoRespond(int $code,array $body): never {http_response_code($code);echo json_encode($body);exit;}
try {
    require dirname(__DIR__).'/app/demo-bootstrap.php';
    if($_SERVER['REQUEST_METHOD']==='GET') {
        if(isset($_GET['booking'])) {$b=is_string($_GET['booking'])?$demo->get($_GET['booking']):null;demoRespond($b?200:404,['booking'=>$b,'csrf'=>$_SESSION['csrf']]);}
        if(isset($_GET['ticket'])) {$t=is_string($_GET['ticket'])?$demo->validate($_GET['ticket']):null;demoRespond($t?200:404,['ticket'=>$t,'csrf'=>$_SESSION['csrf']]);}
        $date=is_string($_GET['date']??null)?$_GET['date']:date('Y-m-d');
        demoRespond(200,['types'=>Temple\Demo::TYPES,'date'=>$date,'today'=>date('Y-m-d'),'availability'=>$demo->availability($date),'csrf'=>$_SESSION['csrf']]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')demoRespond(405,['error'=>'Method not allowed.']);
    $raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)demoRespond(413,['error'=>'Request too large.']);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input) || !is_string($input['csrf']??null) || !hash_equals($_SESSION['csrf'],$input['csrf']))demoRespond(403,['error'=>'Please reload this demo before continuing.']);
    $key=$demoStore->one("SELECT value FROM demo_settings WHERE key='key'")['value'];
    try{$demoStore->rate('request:'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'local',$key),90,3600);}catch(RuntimeException $e){demoRespond(429,['error'=>$e->getMessage()]);}
    $action=$input['action']??'';$token=is_string($input['token']??null)?$input['token']:'';
    if($action==='reserve') {
        try{$demoStore->rate('reserve:'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'local',$key),12,3600);}catch(RuntimeException $e){demoRespond(429,['error'=>$e->getMessage()]);}
        foreach(['date','type','name'] as $f)if(!is_string($input[$f]??null))demoRespond(422,['error'=>'Please check the demo details.']);
        $qty=filter_var($input['quantity']??null,FILTER_VALIDATE_INT);if($qty===false)demoRespond(422,['error'=>'Choose a whole number of anglers.']);
        demoRespond(201,['booking'=>$demo->reserve($input['date'],$input['type'],$qty,$input['name'])]);
    }
    if($action==='outcome')demoRespond(200,['booking'=>$demo->outcome($token,is_string($input['outcome']??null)?$input['outcome']:'')]);
    if($action==='checkin')demoRespond(200,['ticket'=>$demo->checkIn($token)]);
    demoRespond(400,['error'=>'Unknown demo action.']);
} catch(JsonException $e){demoRespond(400,['error'=>'Invalid request.']);}
catch(PDOException $e){error_log('Temple demo database error');demoRespond(503,['error'=>'The demo is temporarily unavailable. Please try again later.']);}
catch(RuntimeException $e){demoRespond(422,['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Temple demo error: '.get_class($e));demoRespond(503,['error'=>'The demo is unavailable. Check Composer dependencies and private-folder write permissions.']);}
