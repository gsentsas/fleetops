<?php

declare(strict_types=1);

namespace Fleetbase\FleetOps\Http\Middleware;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Pairing approval requires explicit bearer authority, never ambient cookies. */
class EnsureNavigatorAdminRequest
{
    public function handle(Request $request, \Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if (!$request->bearerToken() || !$token instanceof PersonalAccessToken
            || $request->query() !== [] || !$request->isJson()) {
            return response()->error('Navigator administration request is not authorized.', 403);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            // Exact origins only. Never trust a request Host or forwarded header,
            // wildcard CORS configuration, referer, or a substring comparison.
            $allowed = array_filter((array) config('cors.allowed_origins', []), static fn ($value) => is_string($value) && $value !== '*');
            if (!in_array($origin, $allowed, true)) {
                return response()->error('Navigator administration origin is not authorized.', 403);
            }
        }

        return $next($request);
    }
}
