<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MagicTriggerController extends Controller
{
    // GET /p/t?ref=...&amount=409.00&type=balance&exp=1735862400&sig=...
    public function fire(Request $req)
    {
        $ref    = (string) $req->query('ref');
        $amount = $req->query('amount'); // dollars
        $type   = $req->query('type', 'balance');
        $exp    = (int) $req->query('exp');
        $sig    = (string) $req->query('sig');

        abort_if(!$ref || !$sig || !$exp, 400, 'bad link');
        abort_if(now()->timestamp > $exp, 410, 'link expired');

        $secret = config('services.magic_links.secret', env('MAGIC_LINK_SECRET'));
        $base   = http_build_query(['ref'=>$ref,'amount'=>$amount,'type'=>$type,'exp'=>$exp]);
        $good   = hash_hmac('sha256', $base, $secret);
        abort_if(!hash_equals($good, $sig), 403, 'invalid signature');

        $booking = Booking::where('reference', $ref)->firstOrFail();
        if (empty($booking->portal_token)) {
            $booking->forceFill(['portal_token' => Str::random(48)])->save();
        }

        $amountCents = ($amount !== null && $amount !== '') ? (int) round(((float)$amount) * 100) : null;

        PaymentRequest::firstOrCreate(
            [
                'booking_id' => $booking->id,
                'type'       => $type,
                'amount'     => $amountCents,
                'status'     => 'pending',
            ],
            [
                'currency'   => $booking->currency ?? 'NZD',
                'meta'       => ['created_via' => 'magic_link'],
            ]
        );

        // Take them to the guest portal (you can deep-link to a "pay now" step)
        return redirect()->to(url('/p/b/'.$booking->portal_token));
    }
}
