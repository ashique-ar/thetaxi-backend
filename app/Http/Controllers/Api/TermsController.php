<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TermsAndCondition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TermsController extends Controller
{
    public function index(Request $request)
    {
        $serviceType = $request->query('service_type');
        $serviceTypeId = $request->query('service_type_id');
        $paymentType = $request->query('payment_type');
        $scope = $request->query('scope');

        $query = TermsAndCondition::with('serviceType');

        if ($scope === 'service') {
            $query->whereNull('payment_type');
        } elseif ($scope === 'payment') {
            $query->whereNull('service_type_id')->orWhereNull('service_type');
        }

        if ($serviceTypeId) {
            $query->where('service_type_id', $serviceTypeId);
        } elseif ($serviceType) {
            $query->where('service_type', $serviceType);
        }

        if ($paymentType) {
            $query->where('payment_type', $paymentType);
        }

        $terms = $query->orderBy('display_order', 'asc')->paginate(20);

        return response()->json(['success' => true, 'data' => $terms]);
    }

    public function show($id)
    {
        $term = TermsAndCondition::findOrFail($id);
        return response()->json(['success' => true, 'data' => $term]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:terms_and_conditions,slug',
            'content' => 'required|string',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
            'service_type' => 'nullable|string|max:100',
            'payment_type' => 'nullable|string|in:full,advance,quotation,checkin',
            'version' => 'nullable|integer',
            'is_active' => 'boolean',
            'effective_date' => 'nullable|date',
            'display_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $serviceTypeId = $payload['service_type_id'] ?? null;
        $legacyServiceType = $payload['service_type'] ?? null;
        $paymentType = $payload['payment_type'] ?? null;

        $hasService = !empty($serviceTypeId) || !empty($legacyServiceType);
        $hasPayment = !empty($paymentType);

        if (!$hasService && !$hasPayment) {
            return response()->json([
                'success' => false,
                'errors' => ['scope' => ['Either service_type_id/service_type or payment_type is required.']]
            ], 422);
        }
        if ($hasService && $hasPayment) {
            return response()->json([
                'success' => false,
                'errors' => ['scope' => ['Only one of service_type_id/service_type or payment_type can be set.']]
            ], 422);
        }

        // Prefer service_type_id when provided, but keep legacy service_type when present for compatibility
        $term = TermsAndCondition::create($payload);

        return response()->json(['success' => true, 'data' => $term]);
    }

    public function update(Request $request, $id)
    {
        $term = TermsAndCondition::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:terms_and_conditions,slug,' . $term->id,
            'content' => 'sometimes|required|string',
            'service_type' => 'nullable|string|max:100',
            'payment_type' => 'nullable|string|in:full,advance,quotation,checkin',
            'version' => 'nullable|integer',
            'is_active' => 'boolean',
            'effective_date' => 'nullable|date',
            'display_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $serviceTypeId = array_key_exists('service_type_id', $payload) ? $payload['service_type_id'] : $term->service_type_id;
        $legacyServiceType = array_key_exists('service_type', $payload) ? $payload['service_type'] : $term->service_type;
        $paymentType = array_key_exists('payment_type', $payload) ? $payload['payment_type'] : $term->payment_type;

        $hasService = !empty($serviceTypeId) || !empty($legacyServiceType);
        $hasPayment = !empty($paymentType);

        if (!$hasService && !$hasPayment) {
            return response()->json([
                'success' => false,
                'errors' => ['scope' => ['Either service_type_id/service_type or payment_type is required.']]
            ], 422);
        }
        if ($hasService && $hasPayment) {
            return response()->json([
                'success' => false,
                'errors' => ['scope' => ['Only one of service_type_id/service_type or payment_type can be set.']]
            ], 422);
        }

        $term->update($payload);
        return response()->json(['success' => true, 'data' => $term]);
    }

    public function destroy($id)
    {
        $term = TermsAndCondition::findOrFail($id);
        $term->delete();
        return response()->json(['success' => true]);
    }
}
