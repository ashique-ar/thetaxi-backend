<?php

namespace App\Http\Middleware;

use App\Models\Agent\AgentApi;
use App\Models\Agent\AgentApiSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $this->extractApiKey($request);

        if (!$apiKey) {
            return response()->json(['message' => 'Agent API key is required.'], Response::HTTP_UNAUTHORIZED);
        }

        $agentApi = AgentApi::with('agent.user')
            ->where('api_key', $apiKey)
            ->where('status', 'active')
            ->first();

        if (!$agentApi || !$agentApi->agent || !$agentApi->agent->user || !$agentApi->agent->user->is_active) {
            return response()->json(['message' => 'Invalid or inactive agent API key.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->ipAllowed($agentApi->allowed_ips, $request->ip())) {
            return response()->json(['message' => 'This IP address is not allowed for the agent API key.'], Response::HTTP_FORBIDDEN);
        }

        if ($this->rateLimitExceeded($agentApi)) {
            return response()->json(['message' => 'Agent API rate limit exceeded.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $request->attributes->set('agent_api', $agentApi);
        $request->attributes->set('agent', $agentApi->agent);

        $start = microtime(true);
        $response = $next($request);
        $duration = (int) round((microtime(true) - $start) * 1000);

        $agentApi->forceFill([
            'total_requests' => ((int) $agentApi->total_requests) + 1,
            'last_used_at' => now(),
        ])->save();

        AgentApiSession::create([
            'agent_id' => $agentApi->agent_id,
            'agent_api_id' => $agentApi->id,
            'last_access' => now(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'query' => $request->query(),
                'route' => optional($request->route())->uri(),
            ],
        ]);

        return $response;
    }

    private function extractApiKey(Request $request): ?string
    {
        $header = $request->header('X-Agent-Api-Key')
            ?: $request->header('X-Api-Key')
            ?: $request->bearerToken()
            ?: $request->query('api_key');

        return is_string($header) && trim($header) !== '' ? trim($header) : null;
    }

    private function ipAllowed(?string $allowedIps, string $ip): bool
    {
        if (!$allowedIps) {
            return true;
        }

        $allowed = collect(preg_split('/[\s,]+/', $allowedIps))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->all();

        return !$allowed || in_array($ip, $allowed, true);
    }

    private function rateLimitExceeded(AgentApi $agentApi): bool
    {
        $limit = (int) ($agentApi->rate_limit ?: 0);

        if ($limit <= 0) {
            return false;
        }

        $usedToday = AgentApiSession::where('agent_api_id', $agentApi->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return $usedToday >= $limit;
    }
}
