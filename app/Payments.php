<?php
declare(strict_types=1);
namespace Temple;
final class Payments {
    private \Stripe\StripeClient $client;
    public function __construct(private Store $s,private array $config) {
        $mode=$config['stripe_mode']??'test'; $key=$config['stripe_secret']??'';
        if (!in_array($mode,['test','live'],true) || !str_starts_with($key,'sk_'.$mode.'_')) throw new \RuntimeException('Stripe is not configured for the selected mode.');
        $http=new \Stripe\HttpClient\CurlClient(); $http->setTimeout(15); $http->setConnectTimeout(5); \Stripe\ApiRequestor::setHttpClient($http);
        $this->client=new \Stripe\StripeClient($key);
    }
    public function start(array $b): string {
        // Re-check the OFF switch at payment initiation, serialized with admin changes.
        return $this->s->tx(function() use($b) {
            $b=$this->s->one('SELECT * FROM bookings WHERE id=?',[$b['id']]);
            if (!$b) throw new \RuntimeException('Booking not found.');
            if ($this->s->setting('bookings_enabled')!=='1') throw new \RuntimeException('Bookings are disabled.');
            if ($b['mode']!==$this->config['stripe_mode']) throw new \RuntimeException('Booking mode mismatch.');
            if ($b['status']!=='creating') throw new \RuntimeException('Checkout is unavailable.');
            $base=rtrim($this->config['base_url'],'/');
            $session=$this->client->checkout->sessions->create([
                'mode'=>'payment','payment_method_types'=>['card'],
                'client_reference_id'=>$b['reference'],'customer_email'=>$b['email'],
                'metadata'=>['booking_id'=>(string)$b['id']],
                'payment_intent_data'=>['metadata'=>['booking_id'=>(string)$b['id']]],
                'line_items'=>[['price_data'=>['currency'=>'gbp','unit_amount'=>$b['unit_price'],'product_data'=>['name'=>$b['type_name'].' — '.$b['date']]],'quantity'=>$b['quantity']]],
                'expires_at'=>$b['expires_at'],
                'success_url'=>$base.'/booking.php?token='.$b['token'],
                'cancel_url'=>$base.'/booking.php?token='.$b['token'].'&returned=1',
            ],['idempotency_key'=>'temple-checkout-'.$b['token']]);
            $this->s->run("UPDATE bookings SET session_id=?,status='pending' WHERE id=?",[$session->id,$b['id']]);
            return $session->url;
        });
    }
    public function expire(array $b,string $status='expired'): void {
        // Never release capacity while Stripe might still accept payment.
        if ($b['session_id']) {
            $session=$this->client->checkout->sessions->retrieve($b['session_id'],[]);
            if ($session->status==='open') $session=$this->client->checkout->sessions->expire($session->id,[]);
            if ($session->status!=='expired') return; // completed awaits signed webhook
        } elseif ($b['expires_at']+120>time()) return; // uncertain create failure: hold beyond Stripe expiry
        $this->s->tx(function() use($b,$status) {
            $this->s->run("UPDATE bookings SET status=? WHERE id=? AND status IN ('creating','pending')",[$status,$b['id']]);
        });
    }
}
