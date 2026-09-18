<?php
declare(strict_types=1);
namespace Temple;
use RuntimeException;
/** A separate practice ledger. Never writes production bookings, tickets or payments. */
final class Demo {
    public const TYPES=['adult'=>['name'=>'Adult day ticket','price'=>1000],'junior'=>['name'=>'Junior day ticket','price'=>500]];
    public const CAPACITY=20;
    public function __construct(public Store $s) {
        $s->db->exec("CREATE TABLE IF NOT EXISTS demo_settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS demo_bookings(id INTEGER PRIMARY KEY,token TEXT UNIQUE NOT NULL,reference TEXT UNIQUE NOT NULL,date TEXT NOT NULL,type TEXT NOT NULL,quantity INTEGER NOT NULL,name TEXT NOT NULL,total INTEGER NOT NULL,status TEXT NOT NULL CHECK(status IN ('pending','confirmed','failed','cancelled','expired','refunded')),created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS demo_tickets(id INTEGER PRIMARY KEY,booking_id INTEGER NOT NULL REFERENCES demo_bookings(id) ON DELETE CASCADE,token TEXT UNIQUE NOT NULL,checked_at INTEGER);
            CREATE INDEX IF NOT EXISTS demo_date ON demo_bookings(date,status);
            CREATE TABLE IF NOT EXISTS rate_limits(key TEXT PRIMARY KEY,count INTEGER NOT NULL,until INTEGER NOT NULL);");
        $s->run('INSERT OR IGNORE INTO demo_settings VALUES(?,?)',['key',bin2hex(random_bytes(32))]);
    }
    public function tidy(): void {
        $this->s->tx(function(){
            $this->s->run("UPDATE demo_bookings SET status='expired' WHERE status='pending' AND expires_at<?",[time()]);
            $this->s->run('DELETE FROM demo_bookings WHERE created_at<?',[time()-86400]);
            $this->s->run('DELETE FROM rate_limits WHERE until<?',[time()-86400]);
        });
    }
    public function availability(string $date): array {
        Booking::date($date);
        $used=(int)$this->s->one("SELECT COALESCE(SUM(quantity),0) n FROM demo_bookings WHERE date=? AND (status='confirmed' OR (status='pending' AND expires_at>=?))",[$date,time()])['n'];
        return ['capacity'=>self::CAPACITY,'remaining'=>max(0,self::CAPACITY-$used)];
    }
    public function reserve(string $date,string $type,int $qty,string $name): array {
        Booking::date($date);
        if(!isset(self::TYPES[$type]) || $qty<1 || $qty>6 || mb_strlen($name)>80 || trim($name)==='') throw new RuntimeException('Choose a ticket type, 1–6 anglers and a test name.');
        return $this->s->tx(function() use($date,$type,$qty,$name){
            if($this->availability($date)['remaining']<$qty) throw new RuntimeException('Not enough demo places on that date. Try another day.');
            $token=bin2hex(random_bytes(32));
            $this->s->run('INSERT INTO demo_bookings(token,reference,date,type,quantity,name,total,status,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$token,'DEMO-'.strtoupper(bin2hex(random_bytes(4))),$date,$type,$qty,trim($name),self::TYPES[$type]['price']*$qty,'pending',time(),time()+900]);
            return $this->get($token);
        });
    }
    public function get(string $token): ?array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $b=$this->s->one('SELECT * FROM demo_bookings WHERE token=?',[$token]);
        if(!$b) return null;
        $b['type_name']=self::TYPES[$b['type']]['name'];$b['unit_price']=self::TYPES[$b['type']]['price'];
        $b['tickets']=$this->s->all('SELECT token,checked_at FROM demo_tickets WHERE booking_id=? ORDER BY id',[$b['id']]);
        unset($b['id']);return $b;
    }
    public function outcome(string $token,string $outcome): array {
        if(!in_array($outcome,['confirmed','failed','cancelled','refunded'],true)) throw new RuntimeException('Choose a demo outcome.');
        return $this->s->tx(function() use($token,$outcome){
            $b=$this->s->one('SELECT * FROM demo_bookings WHERE token=?',[$token]);
            if(!$b) throw new RuntimeException('Demo booking not found or expired.');
            if($b['status']===$outcome) return $this->get($token);
            if($outcome==='refunded') {
                if($b['status']!=='confirmed') throw new RuntimeException('Only a confirmed demo can be marked refunded.');
            } elseif($b['status']!=='pending' || $b['expires_at']<time()) throw new RuntimeException('This demo checkout has ended. Start a new demo.');
            $this->s->run('UPDATE demo_bookings SET status=? WHERE id=?',[$outcome,$b['id']]);
            if($outcome==='confirmed') for($i=0;$i<$b['quantity'];$i++) $this->s->run('INSERT INTO demo_tickets(booking_id,token) VALUES(?,?)',[$b['id'],bin2hex(random_bytes(32))]);
            return $this->get($token);
        });
    }
    /** Public demo scan deliberately returns no customer name or private booking link. */
    public function validate(string $token): ?array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $t=$this->s->one('SELECT b.reference,b.date,b.type,b.status,t.checked_at FROM demo_tickets t JOIN demo_bookings b ON b.id=t.booking_id WHERE t.token=?',[$token]);
        if(!$t) return null;
        $t['type_name']=self::TYPES[$t['type']]['name'];
        $t['state']=$t['status']!=='confirmed'?$t['status']:($t['checked_at']?'already-used':($t['date']!==date('Y-m-d')?'wrong-date':'valid'));
        return $t;
    }
    public function checkIn(string $token): array {
        return $this->s->tx(function() use($token){
            $t=$this->validate($token);
            if(!$t || $t['state']!=='valid') throw new RuntimeException('Demo check-in refused: '.($t['state']??'invalid ticket'));
            $this->s->run('UPDATE demo_tickets SET checked_at=? WHERE token=? AND checked_at IS NULL',[time(),$token]);
            return $this->validate($token);
        });
    }
}
