<?php
require dirname(__DIR__).'/app/web.php';
$u=staff();
$bookingId=max(0,(int)($_GET['booking']??0));$page=max(1,min(100000,(int)($_GET['page']??1)));
$where="a.action='spot_check'";$args=[];
if($bookingId) {$where.=' AND a.booking_id=?';$args[]=$bookingId;}
$rows=$s->all('SELECT a.*,b.reference FROM audit a LEFT JOIN bookings b ON b.id=a.booking_id WHERE '.$where.' ORDER BY a.id DESC LIMIT 51 OFFSET '.(($page-1)*50),$args);
head('Bankside spot-check log');
echo '<p><a class="text-link" href="/dashboard.php">← Dashboard</a></p><p>Times are UK local time. Each entry records the staff identity and result at the time of the check. Repeat checks are allowed and do not consume a ticket.</p>';
if(!$rows) notice('No spot checks recorded yet.');
foreach(array_slice($rows,0,50) as $row) {
    $d=json_decode($row['detail'],true)?:[];
    echo '<article class="panel"><h2>'.h($row['reference']??'Booking unavailable').'</h2><p>'.h(date('d M Y H:i:s T',(int)$row['created_at'])).'<br>Bailiff/staff: '.h($d['bailiff_name']??'Unknown').' (staff ID '.(int)$row['user_id'].')<br>Ticket '.(int)($d['ticket_id']??0).' · Result: '.h($d['result']??'Unknown').'</p><p>'.h($d['note']??'').'</p></article>';
}
if($page>1) echo '<a class="button secondary" href="?booking='.$bookingId.'&page='.($page-1).'">Previous</a> ';
if(count($rows)>50) echo '<a class="button" href="?booking='.$bookingId.'&page='.($page+1).'">Next</a>';
foot();
