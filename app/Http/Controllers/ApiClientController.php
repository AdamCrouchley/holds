<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiClientController extends Controller
{
    public function index()
    {
        $clients = ApiClient::orderByDesc('created_at')->get();
        return view('admin.api_clients.index', compact('clients'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
        ]);

        $token = Str::random(48);
        ApiClient::create([
            'name' => $data['name'],
            'token' => $token,
            'scopes' => ['payment:create'],
            'user_id' => auth()->id(),
            'enabled' => true,
        ]);

        return redirect()->route('admin.api-keys.index')->with('status', 'API key created.');
    }

    public function toggle(ApiClient $client)
    {
        $client->enabled = !$client->enabled;
        $client->save();

        return back()->with('status', 'API key '.($client->enabled ? 'enabled' : 'disabled').'.');
    }

    public function regenerate(ApiClient $client)
    {
        $client->token = Str::random(48);
        $client->save();

        return back()->with('status', 'API key token regenerated.');
    }

    public function destroy(ApiClient $client)
    {
        $client->delete();
        return back()->with('status', 'API key deleted.');
    }
}
