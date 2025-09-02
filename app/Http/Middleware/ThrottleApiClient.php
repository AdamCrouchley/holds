<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

class ThrottleApiClient
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, $max = 120, $decay = 60)
    {
        $token = $request->header('X-Holds-Key') ?? 'anonymous';
        $key = 'api-client:'.$token;

        if ($this->limiter->tooManyAttempts($key, (int)$max)) {
            $retry = $this->limiter->availableIn($key);
            throw new ThrottleRequestsException('Too Many Requests', null, [
                'Retry-After' => $retry,
            ]);
        }

        $this->limiter->hit($key, (int)$decay);

        return $next($request);
    }
}
