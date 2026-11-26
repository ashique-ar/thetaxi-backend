<?php

namespace App\Http\Middleware;

use App\Services\UserContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalContext
{
    public function __construct(private UserContextService $contexts) {}

    public function handle(Request $request, Closure $next, string ...$additionalAllowedContexts): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $activeContext = $this->contexts->resolveActiveContextFromRequest($user, $request);

        $contextType = $activeContext['context_type'] ?? null;
        if (! in_array($contextType, ['internal', ...$additionalAllowedContexts], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This setting is available only in the internal administration context.',
            ], 403);
        }

        $request->attributes->set('resolved_active_context_type', $contextType);
        $request->attributes->set('resolved_active_context_id', $activeContext['id'] ?? $contextType);

        return $next($request);
    }
}
