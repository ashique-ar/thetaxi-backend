<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceFormConfig;
use App\Services\DynamicServiceConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceFormConfigController extends Controller
{
    public function __construct(
        private readonly DynamicServiceConfigurationService $configService,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:service-configs.view')->only(['index', 'show']);
        $this->middleware('permission:service-configs.edit')->only(['update', 'store']);
        $this->middleware('permission:service-configs.delete')->only(['destroy']);
    }

    /**
     * GET /api/admin/service-form-configs
     * List all stored form configs (one row per service code).
     */
    public function index(): JsonResponse
    {
        $configs = ServiceFormConfig::orderBy('service_code')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $configs,
        ]);
    }

    /**
     * GET /api/admin/service-form-configs/{serviceCode}
     * Return the stored config for a service code, or the compiled default if none exists.
     */
    public function show(string $serviceCode): JsonResponse
    {
        $dbConfig = ServiceFormConfig::forCode($serviceCode);

        $compiled = $this->configService->getServiceFormConfiguration($serviceCode);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'service_code'   => $serviceCode,
                'source'         => $compiled['config_source'] ?? ($dbConfig ? 'database' : 'default'),
                'db_record'      => $dbConfig,
                'compiled_config' => $compiled,
            ],
        ]);
    }

    /**
     * POST /api/admin/service-form-configs
     * Create or replace a form config for a service code.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_code'   => 'required|string|max:100',
            'config'         => 'required|array',
            'is_active'      => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $record = ServiceFormConfig::updateOrCreate(
            ['service_code' => $request->service_code],
            [
                'config'    => $request->config,
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        $this->configService->clearCache();

        return response()->json([
            'status'  => 'success',
            'message' => 'Service form config saved.',
            'data'    => $record,
        ], 201);
    }

    /**
     * PUT /api/admin/service-form-configs/{serviceCode}
     * Update an existing form config.
     */
    public function update(Request $request, string $serviceCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'config'    => 'required|array',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $record = ServiceFormConfig::where('service_code', $serviceCode)->first();

        if (!$record) {
            return response()->json(['status' => 'error', 'message' => 'Config not found for this service code.'], 404);
        }

        $record->update([
            'config'    => $request->config,
            'is_active' => $request->boolean('is_active', $record->is_active),
        ]);

        $this->configService->clearCache();

        return response()->json([
            'status'  => 'success',
            'message' => 'Service form config updated.',
            'data'    => $record->fresh(),
        ]);
    }

    /**
     * DELETE /api/admin/service-form-configs/{serviceCode}
     * Remove the DB override — the service will revert to the hardcoded default.
     */
    public function destroy(string $serviceCode): JsonResponse
    {
        $deleted = ServiceFormConfig::where('service_code', $serviceCode)->delete();

        if (!$deleted) {
            return response()->json(['status' => 'error', 'message' => 'Config not found.'], 404);
        }

        $this->configService->clearCache();

        return response()->json([
            'status'  => 'success',
            'message' => "Config for '{$serviceCode}' removed — default will be used.",
        ]);
    }
}
