<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
try {
    require dirname(__DIR__).'/app/bootstrap.php';
    $command=$argv[1]??'help';
    if($command==='migrate') {
        $s->run('CREATE TABLE IF NOT EXISTS migrations(version TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
        foreach(glob(dirname(__DIR__).'/migrations/*.sql') as $file) {
            $s->tx(function() use($s,$file) {
                $version=basename($file);
                if(!$s->one('SELECT version FROM migrations WHERE version=?',[$version])) { $s->db->exec(file_get_contents($file)); $s->run('INSERT INTO migrations VALUES(?,?)',[$version,time()]); echo "Applied $version\n"; }
            });
        }
        echo "Database ready. Existing booking controls were preserved; new installations default OFF.\n";
    } elseif($command==='user') {
        $email=strtolower($argv[2]??''); $role=$argv[3]??''; $name=$argv[4]??'';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,['admin','bailiff'],true) || !$name) throw new RuntimeException('Usage: user email admin|bailiff "Full name"');
        if(!function_exists('stream_isatty') || !stream_isatty(STDIN)) throw new RuntimeException('Run account creation in an interactive terminal. Passwords must not be passed on the command line.');
        fwrite(STDOUT,'New password (minimum 14 characters, hidden): '); system('stty -echo');
        try { $password=rtrim(fgets(STDIN),"\r\n"); } finally { system('stty echo'); echo "\n"; }
        if(strlen($password)<14) throw new RuntimeException('Password must have at least 14 characters.');
        $s->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?) ON CONFLICT(email) DO UPDATE SET name=excluded.name,password=excluded.password,role=excluded.role,active=1',[$email,$name,password_hash($password,PASSWORD_DEFAULT),$role]);
        // Invalidate existing sessions after any account reset or role change.
        foreach(glob($data.'/sessions/sess_*') as $file) unlink($file);
        $s->audit(null,'account_created_or_reset',null,$email.' '.$role); echo "Account saved. Existing staff sessions invalidated.\n";
    } elseif($command==='disable-user') {
        $s->run('UPDATE users SET active=0 WHERE email=?',[strtolower($argv[2]??'')]); echo "Account disabled.\n";
    } elseif($command==='bookings-off') {
        $s->tx(fn()=>$s->run("UPDATE settings SET value='0' WHERE key='bookings_enabled'")); echo "Bookings OFF. Run maintenance to expire existing unpaid sessions.\n";
    } elseif($command==='maintenance') {
        $lock=fopen($data.'/maintenance.lock','c'); if(!flock($lock,LOCK_EX|LOCK_NB)) exit(0);
        $failed=0;
        if(!empty($config['stripe_secret'])) {
            $p=new Temple\Payments($s,$config); $off=$s->setting('bookings_enabled')!=='1';
            foreach($s->all("SELECT * FROM bookings WHERE status IN ('creating','pending') AND (expires_at<? OR ?=1)",[time(),(int)$off]) as $b) {
                try { $p->expire($b,$off?'cancelled':'expired'); } catch(Throwable $e) { $failed++; }
            }
        }
        Temple\Mail::drain($s,$config);
        $s->run('DELETE FROM rate_limits WHERE until<?',[time()-86400]);
        foreach(glob($data.'/sessions/sess_*') as $file) if(filemtime($file)<time()-86400) @unlink($file);
        echo 'Maintenance complete; payment reconciliation failures: '.$failed.".\n";
        if($failed) exit(1);
    } elseif($command==='backup') {
        if(!is_dir($data.'/backups')) mkdir($data.'/backups',0700);
        $path=$data.'/backups/temple-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        $s->db->exec('VACUUM INTO '.$s->db->quote($path)); chmod($path,0600);
        echo "Consistent SQLite backup created: $path\n";
        // Retain 30 days. Copy encrypted snapshots off-server as documented.
        foreach(glob($data.'/backups/*.sqlite') as $file) if(filemtime($file)<time()-30*86400) unlink($file);
    } elseif($command==='health') {
        echo 'Database: '.$s->one('PRAGMA integrity_check')['integrity_check']."\n";
        echo 'Mode: '.$config['stripe_mode'].'; bookings: '.($s->setting('bookings_enabled')==='1'?'ON':'OFF')."\n";
        echo 'Pending/failed emails: '.$s->one("SELECT COUNT(*) n FROM outbox WHERE status!='sent'")['n']."\n";
        echo 'Payments needing review: '.$s->one("SELECT COUNT(*) n FROM bookings WHERE status='refund_required'")['n']."\n";
    } else echo "Commands: migrate, user EMAIL ROLE NAME, disable-user EMAIL, bookings-off, maintenance, backup, health\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
