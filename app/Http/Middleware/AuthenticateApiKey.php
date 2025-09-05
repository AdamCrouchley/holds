<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ApiKey;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->header('X-API-Key');
        if (!$token) {
            return response()->json(['message' => 'API key required'], 401);
        }

        $key = ApiKey::query()->where('hashed_key', hash('sha256', $token))->first();
        if (!$key || !($key->active ?? true)) {
            return response()->json(['message' => 'Invalid API key'], 401);
        }

        $request->setUserResolver(fn() => (object)[
            'id' => $key->id,
            'brand_id' => $key->brand_id,
            'scopes' => $key->scopes ?? [],
        ]);

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
