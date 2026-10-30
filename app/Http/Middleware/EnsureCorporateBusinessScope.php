<?php

namespace App\Http\Middleware;

use App\Models\Corporate\Corporate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorporateBusinessScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeCorporate = $request->route('corporate');
        if ($routeCorporate) {
            $corporateId = $routeCorporate instanceof Corporate ? $routeCorporate->id : (string) $routeCorporate;
            abort_unless(Corporate::query()->whereKey($corporateId)->exists(), 404);
        }

        return $next($request);
    }
}
