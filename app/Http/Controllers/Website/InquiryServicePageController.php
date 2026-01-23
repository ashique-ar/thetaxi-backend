<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\InquiryServicePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InquiryServicePageController extends Controller
{
    /**
     * Render a dynamic inquiry service page by slug.
     */
    public function show(string $slug)
    {
        try {
            $page = InquiryServicePage::withInactive()
                ->where('slug', $slug)
                ->firstOrFail();

            if (!$page->is_active || $page->status !== 'published') {
                abort(404);
            }

            // Load both form fields and sections if available
            $cacheKey = 'inquiry_service_page:' . $page->slug;
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cached) {
                $sections = $cached['sections'] ?? [];
            } else {
                $page->load(['form.fields', 'sections']);

                // Prefer normalized sections from the DB relation, otherwise fallback to the legacy content JSON
                if ($page->relationLoaded('sections') && $page->sections->isNotEmpty()) {
                    $sections = $page->sections->map(function ($section) {
                        return [
                            'id' => $section->id,
                            'type' => $section->type,
                            'data' => $section->data,
                            'sort_order' => $section->sort_order,
                            'is_active' => $section->is_active,
                        ];
                    })->toArray();
                } else {
                    $content = $page->content ?? [];
                    $sections = $content['sections'] ?? [];
                }

                // Cache the assembled sections (short TTL)
                \Illuminate\Support\Facades\Cache::put($cacheKey, ['sections' => $sections], now()->addMinutes(60));
            }

            return view('inquiry.service-page', [
                'servicePage' => $page,
                'sections' => $sections,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error loading inquiry service page', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('home')
                ->with('error', 'Unable to load the service page at this time.');
        }
    }
}
