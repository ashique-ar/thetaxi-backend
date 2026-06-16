<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgreementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([]);
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->emptyStats());
    }

    public function reports(): JsonResponse
    {
        return response()->json([
            'stats' => $this->emptyStats(),
            'items' => [],
        ]);
    }

    public function templates(): JsonResponse
    {
        return response()->json([]);
    }

    public function show(string $agreement): JsonResponse
    {
        return response()->json([
            'message' => 'Agreement not found.',
        ], Response::HTTP_NOT_FOUND);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Agreement storage is not configured yet.',
        ], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function update(Request $request, string $agreement): JsonResponse
    {
        return response()->json([
            'message' => 'Agreement storage is not configured yet.',
        ], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function destroy(string $agreement): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function emptyStats(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'expired' => 0,
            'pending' => 0,
            'draft' => 0,
            'cancelled' => 0,
            'nearExpiry' => 0,
            'total_agreements' => 0,
            'active_agreements' => 0,
            'pending_signature' => 0,
            'expiring_soon' => 0,
            'expired_agreements' => 0,
            'draft_agreements' => 0,
        ];
    }
}
