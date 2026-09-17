<?php
declare(strict_types=1);
namespace Temple;
use RuntimeException;
final class Booking {
    public function __construct(public Store $s,private string $mode='test') {}
    public static function date(string $date): void {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/London'));
        if (!$d || $d->format('Y-m-d')!==$date || $date<date('Y-m-d') || $date>date('Y-m-d',strtotime('+365 days'))) throw new RuntimeException('Choose a date within the next year.');
    }
    public function availability(string $date): array {
        $d=$this->s->one('SELECT * FROM days WHERE date=?',[$date]);
        $cap=(int)($d['capacity'] ?? $this->s->setting('daily_capacity'));
        // Holds count until Stripe confirms expiry, including during delayed webhooks.
        $used=(int)$this->s->one("SELECT COALESCE(SUM(quantity),0) n FROM bookings WHERE date=? AND mode=? AND status IN ('creating','pending','paid','complimentary','partially_refunded')",[$date,$this->mode])['n'];
        return ['capacity'=>$cap,'used'=>$used,'remaining'=>max(0,$cap-$used),'closed'=>(bool)($d['closed']??false)];
    }
    public function reserve(string $date,int $type,int $qty,string $name,string $email,?int $staff=null,string $reason=''): array {
        self::date($date); $name=trim($name); $email=trim($email);
        if ($qty<1 || $qty>10 || $name==='' || mb_strlen($name)>100 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Check your name, email and quantity (1–10).');
        if ($staff && trim($reason)==='') throw new RuntimeException('A complimentary ticket reason is required.');
        return $this->s->tx(function() use($date,$type,$qty,$name,$email,$staff,$reason) {
            if ($staff && !$this->s->one("SELECT id FROM users WHERE id=? AND active=1 AND role='admin'",[$staff])) throw new RuntimeException('Administrator access required.');
            if ($this->s->setting('bookings_enabled')!=='1') throw new RuntimeException('Bookings are not yet open. No fishing or site access is granted.');
            $t=$this->s->one('SELECT * FROM ticket_types WHERE id=? AND active=1',[$type]);
            if (!$t || (!$staff && $t['price']<30)) throw new RuntimeException('That ticket type is unavailable.');
            $a=$this->availability($date);
            if ($a['closed'] || $a['remaining']<$qty) throw new RuntimeException('There is not enough availability for that date.');
            $ref='TS-'.strtoupper(bin2hex(random_bytes(5))); $token=bin2hex(random_bytes(32));
            $this->s->run('INSERT INTO bookings(reference,mode,token,date,type_name,quantity,unit_price,total,name,email,status,expires_at,created_at,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$ref,$this->mode,$token,$date,$t['name'],$qty,$staff?0:$t['price'],$staff?0:$t['price']*$qty,$name,$email,$staff?'complimentary':'creating',time()+2100,time(),mb_substr($reason,0,1000),$staff]);
            $id=(int)$this->s->db->lastInsertId();
            if ($staff) { $this->issue($id,$qty); $this->s->audit($staff,'complimentary',$id,$reason); }
            return $this->s->one('SELECT * FROM bookings WHERE id=?',[$id]);
        });
    }
    private function issue(int $id,int $qty): void {
        if ($this->s->one('SELECT id FROM tickets WHERE booking_id=?',[$id])) return;
        for($i=0;$i<$qty;$i++) $this->s->run('INSERT INTO tickets(booking_id,token) VALUES(?,?)',[$id,bin2hex(random_bytes(32))]);
        $this->s->run('INSERT OR IGNORE INTO outbox(booking_id) VALUES(?)',[$id]);
    }
    public function event(array $event,bool $live): void {
        if (!isset($event['id'],$event['type'],$event['data']['object'],$event['livemode']) || (bool)$event['livemode']!==$live) throw new RuntimeException('Invalid event mode or shape.');
        $this->s->tx(function() use($event) {
            if ($this->s->one('SELECT id FROM events WHERE id=?',[$event['id']])) return;
            $o=$event['data']['object']; $type=$event['type'];
            if (str_starts_with($type,'checkout.session.')) {
                $id=$o['metadata']['booking_id']??null;
                $b=$id?$this->s->one('SELECT * FROM bookings WHERE id=?',[$id]):null;
                if ($b) {
                    if ($b['mode']!==$this->mode) throw new RuntimeException('Booking mode mismatch.');
                    if (($o['client_reference_id']??'')!==$b['reference'] || ($b['session_id'] && $b['session_id']!==$o['id'])) throw new RuntimeException('Session mismatch.');
                    if (in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true) && ($o['payment_status']??'')==='paid') {
                        if (($o['currency']??'')!=='gbp' || (int)($o['amount_total']??-1)!==(int)$b['total'] || empty($o['payment_intent'])) throw new RuntimeException('Payment amount mismatch.');
                        if (!in_array($b['status'],['paid','partially_refunded','refunded','refund_required','complimentary'],true)) {
                            $active=in_array($b['status'],['creating','pending'],true); $a=$this->availability($b['date']);
                            $status=(!$active && ($a['closed'] || $a['remaining']<$b['quantity']))?'refund_required':'paid';
                            // A payment arriving after explicit cancellation must be reviewed/refunded, never revived.
                            if ($b['status']==='cancelled' || $b['date']<date('Y-m-d')) $status='refund_required';
                            $this->s->run('UPDATE bookings SET status=?,session_id=?,payment_intent=?,paid_at=? WHERE id=?',[$status,$o['id'],$o['payment_intent'],time(),$b['id']]);
                            if ($status==='paid') $this->issue((int)$b['id'],(int)$b['quantity']);
                            $this->s->audit(null,$status,(int)$b['id']);
                        }
                    } elseif (in_array($type,['checkout.session.expired','checkout.session.async_payment_failed'],true) && in_array($b['status'],['creating','pending'],true)) {
                        $this->s->run('UPDATE bookings SET status=? WHERE id=?',[$type==='checkout.session.expired'?'expired':'failed',$b['id']]);
                    }
                }
            } elseif ($type==='charge.refunded') {
                $b=$this->s->one('SELECT * FROM bookings WHERE payment_intent=?',[$o['payment_intent']??'']);
                // Retry an out-of-order refund until the paid checkout webhook has bound its payment intent.
                if (!$b && !empty($o['metadata']['booking_id'])) throw new RuntimeException('Awaiting payment event.');
                if ($b) {
                    $amount=max((int)$b['refund_amount'],(int)($o['amount_refunded']??0));
                    $status=$amount >= $b['total']?'refunded':($b['status']==='refund_required'?'refund_required':'partially_refunded');
                    $this->s->run('UPDATE bookings SET refund_amount=?,status=? WHERE id=?',[$amount,$status,$b['id']]);
                    $this->s->audit(null,$status,(int)$b['id']);
                }
            } elseif ($type==='payment_intent.payment_failed') {
                $b=$this->s->one('SELECT id FROM bookings WHERE id=?',[$o['metadata']['booking_id']??0]);
                if ($b) $this->s->audit(null,'payment_attempt_failed',(int)$b['id']); // Checkout remains retryable; capacity cannot be released yet.
            }
            $this->s->run('INSERT INTO events VALUES(?,?,?)',[$event['id'],$type,time()]);
        });
    }
    public function validate(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $t=$this->s->one('SELECT t.*,b.reference,b.date,b.type_name,b.name,b.email,b.status,b.mode,u.name checker FROM tickets t JOIN bookings b ON b.id=t.booking_id LEFT JOIN users u ON u.id=t.checked_by WHERE t.token=?',[$token]);
        if (!$t) return null;
        $t['state']=$t['mode']!==$this->mode?'wrong-mode':(!in_array($t['status'],['paid','complimentary','partially_refunded'],true)?$t['status']:($t['checked_at']?'already-used':($t['date']!==date('Y-m-d')?'wrong-date':'valid')));
        return $t;
    }
    public function checkIn(string $token,int $user): array {
        return $this->s->tx(function() use($token,$user) {
            $u=$this->s->one('SELECT id FROM users WHERE id=? AND active=1',[$user]);
            if (!$u) throw new RuntimeException('Staff authentication required.');
            $t=$this->validate($token);
            if (!$t || $t['state']!=='valid') throw new RuntimeException('Check-in refused: '.($t['state']??'invalid ticket'));
            $this->s->run('UPDATE tickets SET checked_at=?,checked_by=? WHERE id=? AND checked_at IS NULL',[time(),$user,$t['id']]);
            $this->s->audit($user,'check_in',(int)$t['booking_id'],'Ticket '.$t['id']);
            return $this->validate($token);
        });
    }
}
