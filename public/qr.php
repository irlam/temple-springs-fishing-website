<?php
require dirname(__DIR__).'/app/web.php';
$token=is_string($_GET['token']??null)?$_GET['token']:'';
if(!preg_match('/^[a-f0-9]{64}$/D',$token) || !$s->one('SELECT id FROM tickets WHERE token=?',[$token])) { http_response_code(404); exit; }
$renderer=new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(300),new BaconQrCode\Renderer\Image\SvgImageBackEnd());
header('Content-Type: image/svg+xml');
echo (new BaconQrCode\Writer($renderer))->writeString($config['base_url'].'/scan.php?token='.$token);
