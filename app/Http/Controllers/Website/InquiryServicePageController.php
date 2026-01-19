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

            $page->load(['form.fields']);

            $content = $page->content ?? [];
            $sections = $content['sections'] ?? [];

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
