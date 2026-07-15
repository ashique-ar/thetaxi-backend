<?php

namespace App\Http\Middleware;

use App\Services\UserContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalContext
{
    public function __construct(private UserContextService $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $activeContext = $this->contexts->resolveActiveContextFromRequest($user, $request);

        if (($activeContext['context_type'] ?? null) !== 'internal') {
            return response()->json([
                'status' => 'error',
                'message' => 'This setting is available only in the internal administration context.',
            ], 403);
        }

        return $next($request);
    }
}
