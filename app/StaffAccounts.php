<?php
declare(strict_types=1);
namespace Temple;
use RuntimeException;
final class StaffAccounts {
    public function __construct(private Store $s) {}
    public function stamp(array $user,string $key): string {
        return hash_hmac('sha256',json_encode([$user['id'],$user['password'],$user['email'],$user['role'],$this->s->setting('staff_revision_'.$user['id'])],JSON_THROW_ON_ERROR),$key);
    }
    public function saveBailiff(int $admin,int $id,string $name,string $email,string $password,bool $active): int {
        $name=trim($name);$email=strtolower(trim($email));
        if($name==='' || mb_strlen($name)>100 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a name (up to 100 characters) and a valid email address.');
        if(($id===0 || $password!=='') && (strlen($password)<14 || strlen($password)>72)) throw new RuntimeException('Use a password between 14 and 72 bytes long.');
        $hash=$password!==''?password_hash($password,PASSWORD_DEFAULT):null;
        return $this->s->tx(function() use($admin,$id,$name,$email,$hash,$active){
            $actor=$this->s->one("SELECT id FROM users WHERE id=? AND role='admin' AND active=1",[$admin]);
            if(!$actor) throw new RuntimeException('Administrator access required.');
            $old=$id?$this->s->one('SELECT * FROM users WHERE id=?',[$id]):null;
            if($id && (!$old || $old['role']!=='bailiff')) throw new RuntimeException('Only bailiff accounts can be edited here.');
            if($this->s->one('SELECT id FROM users WHERE lower(email)=? AND id<>?',[$email,$id])) throw new RuntimeException('That email address is already used by another staff account.');
            if($old) $this->s->run("UPDATE users SET name=?,email=?,password=?,active=? WHERE id=? AND role='bailiff'",[$name,$email,$hash??$old['password'],(int)$active,$id]);
            else {
                $this->s->run("INSERT INTO users(name,email,password,role,active) VALUES(?,?,?,'bailiff',?)",[$name,$email,$hash,(int)$active]);
                $id=(int)$this->s->db->lastInsertId();
            }
            // Persist a revocation marker without requiring a database upgrade.
            $this->s->run('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)',['staff_revision_'.$id,bin2hex(random_bytes(16))]);
            $this->s->audit($admin,$old?'bailiff_updated':'bailiff_created',null,json_encode(['user_id'=>$id,'email'=>$email,'active'=>$active,'password_reset'=>$hash!==null],JSON_THROW_ON_ERROR));
            return $id;
        });
    }
}
