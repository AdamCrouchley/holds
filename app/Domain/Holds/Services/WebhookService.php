<?php

namespace App\Domain\Holds\Services;

use App\Domain\Holds\Services\Contracts\WebhookDispatcher;
use App\Domain\Holds\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookService implements WebhookDispatcher
{
    public function dispatch(string $event, array $payload, int $brandId): void
    {
        $endpoints = WebhookEndpoint::where('brand_id',$brandId)->where('active',true)->get();
        foreach ($endpoints as $ep) {
            if (!in_array($event, $ep->events ?? [])) continue;

            $body = json_encode(['type'=>$event,'data'=>$payload,'sent_at'=>now()->toIso8601String()]);
            $sig  = hash_hmac('sha256', $body, $ep->secret ?? Str::random(32));

            Http::withHeaders([config('holds.webhook_header') => $sig])
                ->asJson()
                ->post($ep->url, json_decode($body, true));
        }
    }
}
