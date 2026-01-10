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
        $paymentType = $request->query('payment_type');

        $query = TermsAndCondition::query();

        if ($serviceType) {
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
            'service_type' => 'nullable|string|max:100',
            'payment_type' => 'nullable|string|max:50',
            'version' => 'nullable|integer',
            'is_active' => 'boolean',
            'effective_date' => 'nullable|date',
            'display_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
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
            'payment_type' => 'nullable|string|max:50',
            'version' => 'nullable|integer',
            'is_active' => 'boolean',
            'effective_date' => 'nullable|date',
            'display_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $term->update($validator->validated());
        return response()->json(['success' => true, 'data' => $term]);
    }

    public function destroy($id)
    {
        $term = TermsAndCondition::findOrFail($id);
        $term->delete();
        return response()->json(['success' => true]);
    }
}
