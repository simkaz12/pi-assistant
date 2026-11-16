<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthenticateTrainingNutritionMcpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('training_nutrition.mcp_token');
        $ownerId = config('training_nutrition.user_id');

        if (! is_string($token) || $token === '' || ! is_numeric($ownerId) || (int) $ownerId < 1) {
            return new Response('', 503);
        }

        $owner = User::find((int) $ownerId);
        if (! $owner) {
            return new Response('', 503);
        }

        $authorization = (string) $request->header('Authorization', '');

        if (! str_starts_with($authorization, 'Bearer ')) {
            return new Response('', 401);
        }

        if (! hash_equals($token, substr($authorization, 7))) {
            return new Response('', 403);
        }

        $request->attributes->set('training_nutrition_owner', $owner);

        return $next($request);
    }
}
