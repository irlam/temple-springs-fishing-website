<?php
/** One-time, CLI-only initial setup for Plesk's Run a PHP script task. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
try {
    $root=dirname(__DIR__);
    $email=strtolower($argv[1]??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Supply your admin email in the task arguments.');
    if(!is_file($root.'/vendor/autoload.php')) throw new RuntimeException('Install Composer dependencies in Plesk first.');
    $configPath=getenv('TEMPLE_CONFIG')?:$root.'/config.php';
    // Serialize setup independently of database migrations and configuration writes.
    $lock=fopen(dirname($configPath).'/.temple-initial-setup.lock','c');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another setup is running.');
    $c=require (is_file($configPath)?$configPath:$root.'/config.example.php');
    if(($c['stripe_mode']??'test')!=='test') throw new RuntimeException('Initial setup requires stripe_mode test.');
    if(strlen($c['app_key']??'')<32) {
        if(($c['app_key']??'')!=='') throw new RuntimeException('Existing app_key is too short; correct it in the private configuration.');
        $c['app_key']=bin2hex(random_bytes(32));
        $tmp=$configPath.'.setup-'.bin2hex(random_bytes(8));
        $contents="<?php\n// Private configuration. Never publish or commit this file.\nreturn ".var_export($c,true).";\n";
        if(file_put_contents($tmp,$contents,LOCK_EX)!==strlen($contents)) throw new RuntimeException('Cannot write private configuration.');
        chmod($tmp,0600);
        if(!rename($tmp,$configPath)) { unlink($tmp);throw new RuntimeException('Cannot save private configuration.'); }
    }
    require $root.'/app/bootstrap.php';
    $hasUsers=$s->one("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    if($hasUsers && (int)$s->one('SELECT COUNT(*) n FROM users')['n']>0) throw new RuntimeException('Staff accounts already exist. Initial setup will not reset them.');
    $credentials=$data.'/initial-admin.txt';
    if(file_exists($credentials)) throw new RuntimeException('Private initial-admin.txt already exists. Inspect it before removing it and retrying.');
    // Preserve any pre-existing database before applying migrations.
    if($s->one("SELECT name FROM sqlite_master WHERE type='table' LIMIT 1")) {
        if(!is_dir($data.'/backups')) mkdir($data.'/backups',0700,true);
        $backup=$data.'/backups/pre-setup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
        $s->db->exec('VACUUM INTO '.$s->db->quote($backup));
    }
    $argv=[__FILE__,'migrate'];
    require $root.'/bin/console.php';
    $password=bin2hex(random_bytes(16));
    $handle=fopen($credentials,'x');
    if(!$handle) throw new RuntimeException('Cannot create private credentials file.');
    $text="Temple Springs initial staff login\nEmail: $email\nPassword: $password\n\nSave in your password manager, then delete this file through Plesk.\n";
    try {
        if(fwrite($handle,$text)!==strlen($text)) throw new RuntimeException('Cannot save initial credentials.');
        fclose($handle);$handle=null;
        $s->tx(function() use($s,$email,$password) {
            if((int)$s->one('SELECT COUNT(*) n FROM users')['n']>0) throw new RuntimeException('An account already exists.');
            $s->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',[$email,'Site administrator',password_hash($password,PASSWORD_DEFAULT),'admin']);
            $s->run("UPDATE settings SET value='0' WHERE key='bookings_enabled'");
            $s->audit(null,'initial_admin_created',null,$email.' via private Plesk task');
        });
    } catch(Throwable $e) { if(is_resource($handle)) fclose($handle);unlink($credentials);throw $e; }
    echo "Initial setup complete. Bookings OFF; test mode.\nRead initial-admin.txt in your private data directory using Plesk File Manager.\nSave the password securely, delete that file, and remove this scheduled task.\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
