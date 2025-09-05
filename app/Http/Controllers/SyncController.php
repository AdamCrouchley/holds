<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class SyncController extends Controller
{
    public function byReference(\Illuminate\Http\Request $request, \App\Services\ExternalSyncService $svc)
    {
        $svc->syncByReference((string) $request->input('reference'));
        return back()->with('status', 'Sync triggered');
    }
}
