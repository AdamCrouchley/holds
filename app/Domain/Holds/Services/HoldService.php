<?php

namespace App\Domain\Holds\Services;

use App\Domain\Holds\Models\{Job, Hold};
use App\Domain\Holds\DTO\HoldDTO;
use App\Domain\Holds\Services\Contracts\HoldGateway;

class HoldService
{
    public function __construct(private HoldGateway $gateway) {}

    public function createFromApi(int $brandId, array $payload): array
    {
        // Minimal stub: persist Job + Hold locally; call gateway later
        $job = Job::create([
            'flow_id'    => null,
            'customer_id'=> null, // TODO: link/create customer
            'reference'  => $payload['job']['reference'],
            'start_at'   => $payload['job']['start_at'],
            'finish_at'  => $payload['job']['finish_at'],
            'item_ref'   => $payload['job']['item_ref'] ?? null,
            'status'     => 'active',
        ]);

        $hold = Hold::create([
            'job_id' => $job->id,
            'status' => 'pending',
            'amount_cents' => $payload['hold']['amount_cents'],
            'currency' => $payload['hold']['currency'],
            'timeline_json' => [['ts'=>now()->toIso8601String(),'event'=>'api.accepted']],
        ]);

        // Later: call gateway, update provider_id/expires_at/status
        return [
            'id' => (string)$hold->id,
            'status' => $hold->status,
            'amount_cents' => $hold->amount_cents,
            'currency' => $hold->currency,
        ];
    }

    public function show(string $id): array
    {
        $hold = Hold::findOrFail($id);
        return [
            'id'=>(string)$hold->id,
            'status'=>$hold->status,
            'amount_cents'=>$hold->amount_cents,
            'currency'=>$hold->currency,
            'provider_id'=>$hold->provider_id,
            'expires_at'=>optional($hold->expires_at)?->toIso8601String(),
            'captured_amount_cents'=>$hold->captured_amount_cents,
        ];
    }

    public function capture(string $id, int $amountCents): array
    {
        $hold = Hold::findOrFail($id);
        // TODO: gateway->capture($hold->provider_id, $amountCents)
        $hold->captured_amount_cents += $amountCents;
        $hold->status = 'captured';
        $hold->save();

        return $this->show($id);
    }

    public function release(string $id): array
    {
        $hold = Hold::findOrFail($id);
        // TODO: gateway->release($hold->provider_id)
        $hold->status = 'released';
        $hold->save();

        return $this->show($id);
    }
}
