<?php
declare(strict_types=1);
namespace Temple;
use PDO;
use RuntimeException;
final class Store {
    public PDO $db;
    public function __construct(string $path) {
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=15000; PRAGMA journal_mode=WAL;');
    }
    public function run(string $sql, array $args=[]): \PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    public function one(string $sql,array $args=[]): ?array { return $this->run($sql,$args)->fetch() ?: null; }
    public function all(string $sql,array $args=[]): array { return $this->run($sql,$args)->fetchAll(); }
    public function tx(callable $fn): mixed {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $v=$fn(); $this->db->exec('COMMIT'); return $v; } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function setting(string $key): string { return $this->one('SELECT value FROM settings WHERE key=?',[$key])['value'] ?? ''; }
    public function audit(?int $user,string $action,?int $booking=null,string $detail=''): void { $this->run('INSERT INTO audit(user_id,action,booking_id,detail,created_at) VALUES(?,?,?,?,?)',[$user,$action,$booking,$detail,time()]); }
    public function rate(string $key,int $max,int $seconds): void {
        $allowed=$this->tx(function() use($key,$max,$seconds) {
            $r=$this->one('SELECT * FROM rate_limits WHERE key=?',[$key]);
            if (!$r || $r['until']<time()) { $this->run('INSERT OR REPLACE INTO rate_limits VALUES(?,1,?)',[$key,time()+$seconds]); return true; }
            if ($r['count'] >= $max) return false;
            $this->run('UPDATE rate_limits SET count=count+1 WHERE key=?',[$key]); return true;
        });
        if (!$allowed) throw new RuntimeException('Too many attempts. Please try again later.');
    }
}
