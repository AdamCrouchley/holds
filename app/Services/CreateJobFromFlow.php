<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\Job;
use Illuminate\Support\Arr;

class CreateJobFromFlow
{
    /**
     * Create a Job from a Flow, applying Flow defaults and enforcing required fields.
     */
    public function handle(Flow $flow, array $data): Job
    {
        // app/Services/CreateJobFromFlow.php
        $defaults = [
            'flow_id'            => $flow->id,
            'status'             => 'pending',
            'hold_amount_cents'  => $flow->hold_amount_cents, // snapshot
        ];

        // Enforce required fields defined on the Flow
        $required = $flow->required_fields ?? [];
        foreach ($required as $field) {
            if (!Arr::has($data, $field)) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return Job::create(array_merge($defaults, $data));
    }
}
