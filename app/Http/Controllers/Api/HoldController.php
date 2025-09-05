<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function index()
    {
        return response()->json(['data' => [], 'message' => 'OK']);
    }

    public function store(Request $request)
    {
        // validate + create a hold…
        return response()->json(['id' => 'hold_123', 'status' => 'authorized'], 201);
    }

    public function show(string $id)
    {
        return response()->json(['id' => $id, 'status' => 'authorized']);
    }

    public function capture(string $id, Request $request)
    {
        // capture some/all of the hold…
        return response()->json(['id' => $id, 'status' => 'captured']);
    }

    public function cancel(string $id)
    {
        // release/cancel the hold…
        return response()->json(['id' => $id, 'status' => 'canceled']);
    }
}
