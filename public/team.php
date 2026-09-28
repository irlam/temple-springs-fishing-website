<?php
require dirname(__DIR__).'/app/web.php';
$people=[];
foreach($s->all("SELECT u.id,s.value FROM users u JOIN settings s ON s.key='bailiff_profile_'||u.id WHERE u.role='bailiff' AND u.active=1 ORDER BY u.id") as $row) {
    $p=json_decode($row['value'],true);
    if(!empty($p['published'])) $people[]=['name'=>(string)$p['name'],'bio'=>(string)$p['bio'],'photo'=>$s->setting('bailiff_photo_'.$row['id'])?'/bailiff-photo.php?id='.$row['id']:null];
}
if(($_GET['format']??'')==='json') {header('Content-Type: application/json');echo json_encode($people);exit;}
head('Meet the bailiffs');
echo '<p>Bailiffs carry out occasional bankside spot checks; they are not always present. Once fishing opens, you do not need an arrival check-in before starting. Rights and access are not yet confirmed.</p>';
foreach($people as $p) echo '<article class="panel">'.($p['photo']?'<img class="bailiff-photo" src="'.h($p['photo']).'" alt="Portrait of '.h($p['name']).'" loading="lazy">':'').'<h2>'.h($p['name']).'</h2><p>'.h($p['bio']).'</p></article>';
if(!$people) notice('Bailiff details will be published when confirmed.');
foot();
