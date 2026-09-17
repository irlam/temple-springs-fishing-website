<?php
require dirname(__DIR__).'/app/web.php';
$u=staff(); $error='';
$token=is_string($_GET['token']??null)?trim($_GET['token']):'';
if(str_starts_with($token,$config['base_url'].'/scan.php?token=')) $token=substr($token,strlen($config['base_url'].'/scan.php?token='));
if(post()) { checkCsrf(); $token=field('token'); try { $booking->checkIn($token,(int)$u['id']); } catch(RuntimeException $e) { $error=$e->getMessage(); } }
$t=$booking->validate($token);
head('Ticket check-in'); echo '<p><a class="text-link" href="/dashboard.php">← Dashboard</a></p>';
if($error) notice($error);
echo '<section class="panel"><h2>Scan a ticket</h2><p>Use the rear camera, or paste the ticket link/token below. Scanning never checks a ticket in automatically.</p><button class="button" id="start-camera">Start camera</button> <button class="button secondary" id="stop-camera" hidden>Stop camera</button><video id="scanner" playsinline muted hidden></video><p id="scanner-status" role="status"></p><form method="get"><label>Ticket link or token<input name="token" id="scan-token" autocomplete="off" required value="'.h($token).'"></label><button class="button">Look up ticket</button></form><p><a class="text-link" href="/dashboard.php">Search by booking reference or customer</a></p></section>';
if($token && !$t) notice('Invalid ticket. No matching QR token was found.');
if($t) {
    echo '<section class="panel state-'.h($t['state']).'"><p class="eyebrow">TICKET STATUS</p><h2>'.h(ucwords(str_replace('-',' ',$t['state']))).'</h2><p>'.h($t['name']).'<br>'.h($t['email']).'<br>'.h($t['reference']).' · '.h($t['type_name']).'<br>Booked date: '.h($t['date']).'</p>';
    if($t['checked_at']) echo '<p>Checked in '.h(date('d M Y H:i:s',$t['checked_at'])).' by '.h($t['checker']).'</p>';
    if($t['state']==='valid') echo '<form method="post">'.csrf().'<input type="hidden" name="token" value="'.h($token).'"><button class="button">Confirm check-in for this person</button></form>';
    echo '</section>';
}
foot();
