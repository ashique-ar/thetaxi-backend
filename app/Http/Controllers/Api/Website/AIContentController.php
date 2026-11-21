<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Services\AIContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AI Content Generation Controller
 * 
 * Provides endpoints for AI-powered content generation using OpenAI
 */
class AIContentController extends Controller
{
    protected AIContentService $aiContentService;

    public function __construct(AIContentService $aiContentService)
    {
        $this->aiContentService = $aiContentService;
        $this->middleware('permission:cms-contents.create')->only(['generateFromTitle', 'generateMeta', 'suggestIdeas']);
        $this->middleware('permission:cms-content-types.view')->only(['suggestContentTypes']);
        $this->middleware('permission:cms-contents.edit')->only(['improveContent']);
    }

    /**
     * Generate complete CMS content from a title
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateFromTitle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:3|max:255',
            'content_type' => 'nullable|string|max:50',
            'word_count' => 'nullable|integer|min:100|max:3000',
            'tone' => 'nullable|string|max:100',
            'business_context' => 'nullable|string|max:500',
            'target_audience' => 'nullable|string|max:500',
            'locale' => 'nullable|string|in:en,tr',
            'audience_mode' => 'nullable|string|in:local,international',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($audienceModeError = $this->internationalAudienceModeError($request)) return $audienceModeError;

        if (!$this->aiContentService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI content generation is not configured. Please add OPENAI_API_KEY to your environment.',
            ], 503);
        }

        try {
            $title = $request->input('title');
            $contentType = $request->input('content_type', 'blog');
            $options = [
                'word_count' => $request->input('word_count', 800),
                'tone' => $request->input('tone', 'professional yet friendly'),
                'business_context' => $request->input('business_context'),
                'target_audience' => $request->input('target_audience'),
                'locale' => $request->input('locale'),
                'audience_mode' => $request->input('audience_mode', 'local'),
            ];

            $generatedContent = $this->aiContentService->generateContentFromTitle(
                $title,
                $contentType,
                array_filter($options)
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Content generated successfully',
                'data' => [
                    'content' => $generatedContent,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate content: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function suggestIdeas(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content_type' => 'nullable|string|max:50',
            'content_type_locked' => 'nullable|boolean',
            'focus' => 'nullable|string|max:240',
            'locale' => 'nullable|string|in:en,tr',
            'audience_mode' => 'nullable|string|in:local,international',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($audienceModeError = $this->internationalAudienceModeError($request)) return $audienceModeError;

        if (!$this->aiContentService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI content generation is not configured.',
            ], 503);
        }

        try {
            $ideas = $this->aiContentService->suggestContentIdeas($request->only([
                'content_type', 'content_type_locked', 'focus', 'locale', 'audience_mode',
            ]));

            return response()->json([
                'status' => 'success',
                'message' => 'Content ideas generated successfully',
                'data' => ['ideas' => $ideas],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate content ideas: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function suggestContentTypes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locale' => 'nullable|string|in:en,tr',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        if (!$this->aiContentService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI content generation is not configured.',
            ], 503);
        }

        try {
            return response()->json([
                'status' => 'success',
                'message' => 'Content type suggestions generated successfully',
                'data' => ['types' => $this->aiContentService->suggestContentTypes($request->input('locale', 'en'))],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to suggest content types: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate only meta content for existing content
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateMeta(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:3|max:255',
            'body' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$this->aiContentService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI content generation is not configured.',
            ], 503);
        }

        try {
            $metaContent = $this->aiContentService->generateMetaContent(
                $request->input('title'),
                $request->input('body', '')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Meta content generated successfully',
                'data' => [
                    'meta' => $metaContent,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate meta content: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Improve existing content for better SEO
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function improveContent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:3|max:255',
            'body' => 'required|string|min:50',
            'business_context' => 'nullable|string|max:500',
            'target_audience' => 'nullable|string|max:500',
            'locale' => 'nullable|string|in:en,tr',
            'audience_mode' => 'nullable|string|in:local,international',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($audienceModeError = $this->internationalAudienceModeError($request)) return $audienceModeError;

        if (!$this->aiContentService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI content generation is not configured.',
            ], 503);
        }

        try {
            $improvedContent = $this->aiContentService->improveContent(
                $request->input('title'),
                $request->input('body'),
                [
                    'business_context' => $request->input('business_context'),
                    'target_audience' => $request->input('target_audience'),
                    'locale' => $request->input('locale'),
                    'audience_mode' => $request->input('audience_mode', 'local'),
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Content improved successfully',
                'data' => [
                    'content' => $improvedContent,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to improve content: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if AI content generation is available
     *
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'configured' => $this->aiContentService->isConfigured(),
                'model' => config('services.openai.model', 'gpt-4o'),
                'international_audience_enabled' => (bool) config('services.ai_content.international_audience_enabled', false),
            ],
        ]);
    }

    private function internationalAudienceModeError(Request $request): ?JsonResponse
    {
        if ($request->input('audience_mode', 'local') === 'international'
            && !config('services.ai_content.international_audience_enabled', false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'International visitor content is not enabled for this deployment.',
            ], 403);
        }
        return null;
    }
}
