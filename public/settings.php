<?php
require dirname(__DIR__).'/app/web.php'; $u=staff(true); $error=''; $message='';
if(post()) {
    checkCsrf();
    try {
        $action=field('action');
        if($action==='settings') {
            $enabled=field('enabled')==='1'; $cap=filter_var(field('capacity'),FILTER_VALIDATE_INT);
            if($cap===false || $cap<1 || $cap>10000) throw new RuntimeException('Daily capacity must be 1–10000.');
            if($enabled && field('rights_confirmed')!=='yes') throw new RuntimeException('Confirm that angling rights and access permission have been secured before opening bookings.');
            if($enabled && (empty($config['stripe_secret']) || empty($config['stripe_webhook_secret']) || empty($config['smtp']['host']) || empty($config['privacy_contact']))) throw new RuntimeException('Configure Stripe, webhook signing, SMTP and the privacy contact before opening.');
            $s->tx(function() use($s,$u,$cap,$enabled) {
                $s->run("UPDATE settings SET value=? WHERE key='daily_capacity'",[(string)$cap]);
                $s->run("UPDATE settings SET value=? WHERE key='bookings_enabled'",[$enabled?'1':'0']);
                $s->audit($u['id'],'settings',null,'Bookings '.($enabled?'ON; rights confirmation recorded':'OFF').'; capacity '.$cap);
            });
        } elseif($action==='type') {
            $name=field('name'); $price=field('price');
            if($name==='' || mb_strlen($name)>100 || !preg_match('/^\d{1,5}(\.\d{1,2})?$/D',$price)) throw new RuntimeException('Enter a ticket name and valid price.');
            $p=(int)round((float)$price*100); if($p<30) throw new RuntimeException('Paid tickets must cost at least £0.30; use complimentary issue for free tickets.');
            $id=(int)field('id');
            if($id) $s->run('UPDATE ticket_types SET name=?,price=?,active=? WHERE id=?',[$name,$p,field('active')==='1'?1:0,$id]);
            else $s->run('INSERT INTO ticket_types(name,price,active) VALUES(?,?,1)',[$name,$p]);
            $s->audit($u['id'],'ticket_type',null,$name);
        } elseif($action==='day') {
            Temple\Booking::date(field('date')); $cap=field('capacity')===''?null:filter_var(field('capacity'),FILTER_VALIDATE_INT);
            if($cap===false || ($cap!==null && ($cap<0 || $cap>10000))) throw new RuntimeException('Invalid capacity.');
            $s->tx(function() use($s,$u,$cap) {
                $s->run('INSERT INTO days(date,capacity,closed,note) VALUES(?,?,?,?) ON CONFLICT(date) DO UPDATE SET capacity=excluded.capacity,closed=excluded.closed,note=excluded.note',[field('date'),$cap,field('closed')==='1'?1:0,mb_substr(field('note'),0,500)]);
                $s->audit($u['id'],'day_override',null,field('date'));
            });
        } elseif($action==='comp') {
            $b=$booking->reserve(field('date'),(int)field('type'),(int)field('quantity'),field('name'),field('email'),(int)$u['id'],field('reason'));
            redirect('/dashboard.php?q='.urlencode($b['reference']));
        } else throw new RuntimeException('Unknown action.');
        $message='Changes saved.';
    } catch(RuntimeException $e) { $error=$e->getMessage(); }
}
head('Manage Temple Springs'); echo '<p><a class="text-link" href="/dashboard.php">← Dashboard</a></p>'; if($error) notice($error); if($message) notice($message);
echo '<section class="panel"><h2>Bookings & capacity</h2><p>Keep bookings OFF while rights and access are unconfirmed. Turning OFF blocks new bookings and payment initiation. The maintenance task expires open unpaid checkouts; payments already completed are still processed. Existing paid tickets are retained.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="settings"><label>Public bookings<select name="enabled"><option value="0">OFF — not open</option><option value="1" '.($s->setting('bookings_enabled')==='1'?'selected':'').'>ON — accept bookings</option></select></label><label>Default daily angler capacity<input type="number" min="1" max="10000" name="capacity" required value="'.h($s->setting('daily_capacity')).'"></label><label class="consent"><input type="checkbox" name="rights_confirmed" value="yes"><span>I have confirmed the necessary angling rights, access permission and readiness to operate. This is required each time ON is saved.</span></label><button class="button">Save booking controls</button></form></section>';
echo '<section class="panel"><h2>Ticket types & prices</h2><p>Prices are in GBP. Changes apply to new bookings; existing bookings retain their original price.</p>';
$types=$s->all('SELECT * FROM ticket_types');
foreach(array_merge($types,[['id'=>0,'name'=>'','price'=>1000,'active'=>1]]) as $t) {
    echo '<form method="post" class="type-form">'.csrf().'<input type="hidden" name="action" value="type"><input type="hidden" name="id" value="'.$t['id'].'"><div class="form-row"><label>'.($t['id']?'Ticket name':'New ticket name').'<input name="name" value="'.h($t['name']).'" required maxlength="100"></label><label>Price (£)<input type="number" name="price" min="0.30" step="0.01" value="'.number_format($t['price']/100,2,'.','').'" required></label></div><label class="consent"><input type="checkbox" name="active" value="1" '.($t['active']?'checked':'').'><span>Available</span></label><button class="button secondary">'.($t['id']?'Update ticket type':'Add ticket type').'</button></form>';
}
echo '</section><section class="panel"><h2>Closures & date capacity</h2><p>Closures block new bookings. They do not cancel existing tickets: contact affected customers and refund paid bookings in Stripe. Lowering capacity never deletes reservations.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="day"><div class="form-row"><label>Date<input type="date" name="date" min="'.date('Y-m-d').'" required></label><label>Capacity override (blank uses default)<input name="capacity" type="number" min="0" max="10000"></label></div><label class="consent"><input type="checkbox" name="closed" value="1"><span>Closed to new bookings</span></label><label>Internal note<input name="note" maxlength="500"></label><button class="button">Save date</button></form><ul>';
foreach($s->all('SELECT * FROM days WHERE date>=? ORDER BY date',[date('Y-m-d')]) as $d) echo '<li>'.h($d['date']).' · '.($d['closed']?'Closed':'Open').' · Capacity '.h($d['capacity']??'default').' · '.h($d['note']).'</li>';
echo '</ul><p class="quiet">To reopen a date, save it again with Closed unchecked.</p></section><section class="panel"><h2>Complimentary tickets</h2><p>Complimentary tickets use capacity and require bookings to be ON. Every issue records your account and reason.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="comp"><div class="form-row"><label>Date<input type="date" name="date" required min="'.date('Y-m-d').'"></label><label>Ticket type<select name="type">';
foreach($types as $t) if($t['active']) echo '<option value="'.$t['id'].'">'.h($t['name']).'</option>';
echo '</select></label></div><label>Quantity<input type="number" name="quantity" min="1" max="10" value="1" required></label><div class="form-row"><label>Name<input name="name" required maxlength="100"></label><label>Email<input type="email" name="email" required maxlength="254"></label></div><label>Reason<textarea name="reason" required maxlength="1000"></textarea></label><button class="button">Issue recorded complimentary tickets</button></form></section><section class="panel"><h2>Recent audit trail</h2><ul>';
foreach($s->all('SELECT a.*,u.name FROM audit a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 30') as $a) echo '<li>'.h(date('d M H:i',$a['created_at'])).' · '.h($a['name']??'System').' · '.h($a['action']).' · '.h($a['detail']).'</li>';
echo '</ul></section>'; foot();
