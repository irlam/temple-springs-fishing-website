<?php
declare(strict_types=1);
namespace Temple;
final class Mail {
    public static function send(array $config,string $to,string $subject,string $body,?string $reply=null): void {
        $c=$config['smtp'];
        if (empty($c['host']) || !filter_var($c['from'],FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('SMTP is not configured.');
        $m=new \PHPMailer\PHPMailer\PHPMailer(true); $m->isSMTP(); $m->Host=$c['host']; $m->Port=(int)$c['port'];
        $m->SMTPAuth=!empty($c['username']); $m->Username=$c['username']; $m->Password=$c['password'];
        $m->SMTPSecure=$c['encryption']; $m->Timeout=15; $m->CharSet='UTF-8';
        $m->setFrom($c['from'],$c['from_name']); $m->addAddress($to); if($reply) $m->addReplyTo($reply);
        $m->Subject=$subject; $m->Body=$body; $m->send();
    }
    public static function drain(Store $s,array $config): void {
        foreach($s->all("SELECT o.id outbox_id,o.attempts,b.* FROM outbox o JOIN bookings b ON b.id=o.booking_id WHERE o.status='pending' AND o.next_attempt<=? LIMIT 30",[time()]) as $b) {
            if (!in_array($b['status'],['paid','complimentary','partially_refunded'],true)) { $s->run("UPDATE outbox SET status='suppressed' WHERE id=?",[$b['outbox_id']]); continue; }
            try {
                self::send($config,$b['email'],($b['mode']==='test'?'TEST — ':'').'Temple Springs ticket '.$b['reference'],($b['mode']==='test'?"TEST TICKET — NOT VALID FOR FISHING OR ACCESS.\n\n":"")."Hello {$b['name']},\n\nYour {$b['type_name']} booking for {$b['date']} is confirmed.\nQuantity: {$b['quantity']}\nReference: {$b['reference']}\n\nView your secure tickets:\n".$config['base_url'].'/booking.php?token='.$b['token']."\n\nKeep this link private. Each QR admits one person once, on the booked date.\nPlease review the rules on the website.\n");
                $s->run("UPDATE outbox SET status='sent',sent_at=?,last_error=NULL WHERE id=?",[time(),$b['outbox_id']]);
            } catch (\Throwable $e) {
                // No SMTP credentials or raw provider error are exposed in staff screens/logs.
                $attempt=(int)$b['attempts']+1;
                $s->run('UPDATE outbox SET status=?,attempts=?,next_attempt=?,last_error=? WHERE id=?',[$attempt>=8?'failed':'pending',$attempt,time()+min(3600,60*2**$attempt),'SMTP delivery failed; check server configuration and retry.',$b['outbox_id']]);
            }
        }
    }
}
