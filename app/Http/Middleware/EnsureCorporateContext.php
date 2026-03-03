<?php

namespace App\Http\Middleware;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure the authenticated user has an active corporate context.
 *
 * Validates the user has a UserContext with context_type = 'corporate',
 * resolves the CorporateEmployee and parent Corporate, checks both are active,
 * and injects corporate_id, corporate_employee_id, and corporate_employee into the request.
 */
class EnsureCorporateContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Find the active corporate UserContext for this user
        $corporateContext = $user->contexts()
            ->where('context_type', 'corporate')
            ->where('is_active', true)
            ->first();

        if (!$corporateContext) {
            return response()->json([
                'status' => 'error',
                'message' => 'No corporate context found.',
            ], 403);
        }

        // Resolve the CorporateEmployee record from context_id
        $corporateEmployee = CorporateEmployee::find($corporateContext->context_id);

        if (!$corporateEmployee) {
            return response()->json([
                'status' => 'error',
                'message' => 'No corporate context found.',
            ], 403);
        }

        // Resolve the parent Corporate
        $corporate = $corporateEmployee->corporate;

        if (!$corporate) {
            return response()->json([
                'status' => 'error',
                'message' => 'No corporate context found.',
            ], 403);
        }

        // Check Corporate is active
        if (!$corporate->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your corporate account is currently inactive.',
            ], 403);
        }

        // Check CorporateEmployee is active
        if (!$corporateEmployee->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your employee account is currently inactive.',
            ], 403);
        }

        // Inject corporate data into the request for downstream use
        $request->merge([
            'corporate_id' => $corporate->id,
            'corporate_employee_id' => $corporateEmployee->id,
        ]);
        $request->attributes->set('corporate_employee', $corporateEmployee);

        return $next($request);
    }
}
