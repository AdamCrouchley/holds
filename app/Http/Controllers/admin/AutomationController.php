<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationSetting;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function index()
    {
        $settings = AutomationSetting::first() ?? new AutomationSetting([
            'active' => true,
            'send_balance_days_before' => 7,
            'send_bond_days_before' => 2,
            'send_at_local' => '09:00:00',
            'timezone' => config('app.timezone', 'Pacific/Auckland'),
        ]);

        return view('admin.automations.index', compact('settings'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'active' => ['nullable','boolean'],
            'send_balance_days_before' => ['required','integer','min:0','max:60'],
            'send_bond_days_before' => ['required','integer','min:0','max:60'],
            'send_at_local' => ['required','date_format:H:i'],
            'timezone' => ['required','string','max:64'],
        ]);

        $settings = AutomationSetting::first();
        if (!$settings) $settings = new AutomationSetting();
        $settings->fill([
            'active' => (bool)($data['active'] ?? false),
            'send_balance_days_before' => $data['send_balance_days_before'],
            'send_bond_days_before' => $data['send_bond_days_before'],
            'send_at_local' => $data['send_at_local'].':00',
            'timezone' => $data['timezone'],
        ])->save();

        return back()->with('status', 'Automations updated.');
    }
}
