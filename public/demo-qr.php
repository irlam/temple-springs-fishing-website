<?php
declare(strict_types=1);
header('Cache-Control: no-store');
try {
    require dirname(__DIR__).'/app/demo-bootstrap.php';
    $token=is_string($_GET['token']??null)?$_GET['token']:'';
    if(!$demo->validate($token)){http_response_code(404);exit;}
    $renderer=new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(320),new BaconQrCode\Renderer\Image\SvgImageBackEnd());
    header('Content-Type: image/svg+xml');echo(new BaconQrCode\Writer($renderer))->writeString($demoBase.'/demo-scan.php?token='.$token);
}catch(Throwable $e){http_response_code(503);echo 'Demo QR unavailable.';}
