<?php

namespace App\Support;

class MagicLinks
{
    /**
     * Build a signed magic link for the payment portal.
     */
    public static function generate(string $reference, ?float $amountDollars, string $type = 'balance', int $ttlSeconds = 7*24*3600): string
    {
        $exp    = now()->timestamp + $ttlSeconds;
        $amount = $amountDollars !== null ? number_format($amountDollars, 2, '.', '') : null;

        $baseQ  = array_filter(['ref' => $reference, 'amount' => $amount, 'type' => $type, 'exp' => $exp], fn($v) => $v !== null);
        $base   = http_build_query($baseQ);
        $sig    = hash_hmac('sha256', $base, config('services.magic_links.secret', env('MAGIC_LINK_SECRET')));

        return url('/p/t?' . $base . '&sig=' . $sig);
    }
}
