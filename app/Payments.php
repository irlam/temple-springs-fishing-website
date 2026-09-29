<?php
declare(strict_types=1);
namespace Temple;

final class Payments {
    private \Stripe\StripeClient $client;

    public function __construct(private Store $s,private array $config) {
        $mode=$config['stripe_mode']??'test';
        $key=$config['stripe_secret']??'';
        if (!in_array($mode,['test','live'],true) || !str_starts_with($key,'sk_'.$mode.'_')) {
            throw new \RuntimeException('Stripe is not configured for the selected mode.');
        }
        $http=new \Stripe\HttpClient\CurlClient();
        $http->setTimeout(15);
        $http->setConnectTimeout(5);
        \Stripe\ApiRequestor::setHttpClient($http);
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
                'mode'=>'payment',
                'automatic_payment_methods'=>['enabled'=>true],
                'client_reference_id'=>$b['reference'],
                'customer_email'=>$b['email'],
                'metadata'=>[
                    'booking_id'=>(string)$b['id'],
                    'booking_reference'=>$b['reference'],
                ],
                'payment_intent_data'=>[
                    'metadata'=>[
                        'booking_id'=>(string)$b['id'],
                        'booking_reference'=>$b['reference'],
                    ],
                ],
                'line_items'=>[[
                    'price_data'=>[
                        'currency'=>'gbp',
                        'unit_amount'=>$b['unit_price'],
                        'product_data'=>[
                            'name'=>$b['type_name'].' — '.$b['date'],
                            'description'=>'Temple Springs fishing ticket',
                        ],
                    ],
                    'quantity'=>$b['quantity'],
                ]],
                'expires_at'=>$b['expires_at'],
                'success_url'=>$base.'/booking.php?token='.$b['token'],
                'cancel_url'=>$base.'/booking.php?token='.$b['token'].'&returned=1',
            ],['idempotency_key'=>'temple-checkout-'.$b['token']]);

            $this->s->run("UPDATE bookings SET session_id=?,status='pending' WHERE id=?",[$session->id,$b['id']]);
            return $session->url;
        });
    }

    public function refund(array $b,int $amount,string $reason,int $userId): array {
        return $this->s->tx(function() use($b,$amount,$reason,$userId) {
            $b=$this->s->one('SELECT * FROM bookings WHERE id=?',[$b['id']]);
            if (!$b) throw new \RuntimeException('Booking not found.');
            if ($b['mode']!==$this->config['stripe_mode']) throw new \RuntimeException('Booking mode mismatch.');
            if (empty($b['payment_intent'])) throw new \RuntimeException('This booking has no Stripe payment to refund.');
            if (!in_array($b['status'],['paid','partially_refunded','refund_required'],true)) {
                throw new \RuntimeException('This booking is not refundable in its current state.');
            }

            $remaining=max(0,(int)$b['amount_paid']-(int)$b['refund_amount']);
            if ($remaining<=0) throw new \RuntimeException('This payment has already been fully refunded.');
            if ($amount<1 || $amount>$remaining) throw new \RuntimeException('Refund amount exceeds the refundable balance.');

            $reason=mb_substr(trim($reason),0,500);
            $refund=$this->client->refunds->create([
                'payment_intent'=>$b['payment_intent'],
                'amount'=>$amount,
                'reason'=>'requested_by_customer',
                'metadata'=>[
                    'booking_id'=>(string)$b['id'],
                    'booking_reference'=>$b['reference'],
                    'admin_reason'=>$reason,
                ],
            ],[
                'idempotency_key'=>'temple-refund-'.$b['id'].'-'.$b['refund_amount'].'-'.$amount,
            ]);

            $stripeStatus=(string)($refund->status??'pending');
            if (!in_array($stripeStatus,['pending','requires_action','succeeded','failed','canceled'],true)) {
                $stripeStatus='pending';
            }

            $this->s->run(
                'INSERT INTO refunds(booking_id,stripe_refund_id,amount,status,reason,requested_at,completed_at,failure_reason)
                 VALUES(?,?,?,?,?,?,?,?)
                 ON CONFLICT(stripe_refund_id) DO UPDATE SET status=excluded.status,completed_at=excluded.completed_at,failure_reason=excluded.failure_reason',
                [
                    $b['id'],
                    $refund->id,
                    $amount,
                    $stripeStatus,
                    $reason,
                    time(),
                    $stripeStatus==='succeeded'?time():null,
                    (string)($refund->failure_reason??''),
                ]
            );
            $this->s->audit($userId,'refund_requested',(int)$b['id'],money($amount).' · '.$reason);
            return $refund->toArray();
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
