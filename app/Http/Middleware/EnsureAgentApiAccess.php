<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentApiAccess
{
    private const RANKS = [
        'read' => 1,
        'write' => 2,
        'admin' => 3,
    ];

    public function handle(Request $request, Closure $next, string $required = 'read'): Response
    {
        $agentApi = $request->attributes->get('agent_api');
        $current = self::RANKS[$agentApi?->access_level ?? 'read'] ?? 0;
        $needed = self::RANKS[$required] ?? self::RANKS['read'];

        if ($current < $needed) {
            return response()->json(['message' => 'Agent API key does not have the required access level.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
