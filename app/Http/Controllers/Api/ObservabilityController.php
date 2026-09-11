<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Support\ObservabilitySanitizer;

class ObservabilityController extends Controller
{
    public function frontend(Request $request): JsonResponse
    {
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 32768, 413);

        $data = $request->validate([
            'level' => ['required', Rule::in(['error', 'warning'])],
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:12000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'route' => ['nullable', 'string', 'max:1000'],
            'timestamp' => ['required', 'date'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'application_version' => ['nullable', 'string', 'max:100'],
            'http_status' => ['nullable', 'integer', 'between:0,599'],
            'failed_api_path' => ['nullable', 'string', 'max:2000'],
            'request_id' => ['nullable', 'string', 'max:100'],
        ]);
        $data = ObservabilitySanitizer::strings($data);

        Log::channel('observability_frontend')->log($data['level'], $data['message'], [
            ...$data,
            'project' => config('app.project_slug'),
            'project_display_name' => config('app.project_display_name'),
            'authenticated_user_id' => $request->user()?->id,
        ]);

        return response()->json(null, 202);
    }
}
