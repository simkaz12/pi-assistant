<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VerifyOuraToolsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.oura.hermes_token');

        if (! is_string($configured) || $configured === '') {
            return new Response('', 503);
        }

        $authorization = (string) $request->header('Authorization', '');

        if (! str_starts_with($authorization, 'Bearer ')) {
            return new Response('', 401);
        }

        if (! hash_equals($configured, substr($authorization, 7))) {
            return new Response('', 403);
        }

        return $next($request);
    }
}
