<?php
declare(strict_types=1);
namespace Temple;
use RuntimeException;
final class SpotChecks {
    public function __construct(private Store $s,private Booking $booking) {}
    public function inspect(string $token): ?array {
        $t=$this->booking->validate($token);
        if($t && $t['state']==='already-used') $t['state']=$t['date']===date('Y-m-d')?'valid':'wrong-date';
        return $t;
    }
    public function record(string $token,int $user,string $request,string $note=''): void {
        if(!preg_match('/^[a-f0-9]{64}$/D',$request) || mb_strlen($note)>500) throw new RuntimeException('Invalid check request or note (maximum 500 characters).');
        $this->s->tx(function() use($token,$user,$request,$note){
            $u=$this->s->one("SELECT id,name,role FROM users WHERE id=? AND active=1 AND role IN ('admin','bailiff')",[$user]);
            if(!$u) throw new RuntimeException('Staff authentication required.');
            if($this->s->one("SELECT id FROM audit WHERE action='spot_check' AND detail LIKE ?",['%"request":"'.$request.'"%'])) return;
            $t=$this->inspect($token);
            if(!$t) throw new RuntimeException('No matching ticket. Nothing has been recorded.');
            $this->s->audit($user,'spot_check',(int)$t['booking_id'],json_encode(['request'=>$request,'ticket_id'=>(int)$t['id'],'bailiff_name'=>$u['name'],'result'=>$t['state'],'note'=>trim($note)],JSON_THROW_ON_ERROR));
        });
    }
}
