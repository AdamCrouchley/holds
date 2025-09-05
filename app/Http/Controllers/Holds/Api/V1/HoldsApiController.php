<?php

namespace App\Http\Controllers\Holds\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domain\Holds\Services\HoldService;

class HoldsApiController extends Controller
{
    public function __construct(private HoldService $holds) {}

    public function store(Request $req)
    {
        $payload = $req->validate([
            'customer' => ['required','array'],
            'customer.name' => ['required','string'],
            'customer.email'=> ['required','email'],
            'customer.phone'=> ['nullable','string'],
            'job' => ['required','array'],
            'job.reference' => ['required','string'],
            'job.start_at' => ['required','date'],
            'job.finish_at'=> ['required','date','after:job.start_at'],
            'job.item_ref'  => ['nullable','string'],
            'hold' => ['required','array'],
            'hold.amount_cents' => ['required','integer','min:100'],
            'hold.currency'     => ['required','string','size:3'],
            'hold.auto_renew'   => ['boolean'],
            'hold.auto_release_hours' => ['integer','min:1'],
            'hold.capture_rule' => ['in:manual,auto_if_unpaid'],
        ]);

        $brandId = (int) ($req->user()->brand_id ?? 1);
        return response()->json($this->holds->createFromApi($brandId, $payload), 201);
    }

    public function show(string $id) { return response()->json($this->holds->show($id)); }

    public function capture(string $id, Request $req)
    {
        $validated = $req->validate(['amount_cents'=>['required','integer','min:1']]);
        return response()->json($this->holds->capture($id, $validated['amount_cents']));
    }

    public function release(string $id) { return response()->json($this->holds->release($id)); }
}
