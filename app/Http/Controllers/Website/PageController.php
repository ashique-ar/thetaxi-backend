<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Http\Controllers\Website\CmsController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function showOrCmsIndex(string $slug, Request $request, CmsController $cmsController)
    {
        $pageExists = Page::published()
            ->where('slug', $slug)
            ->exists();

        return $pageExists ? $this->show($slug, $request) : $cmsController->index($slug, $request);
    }

    public function show(string $slug, Request $request): View
    {
        $page = Page::published()
            ->where('slug', $slug)
            ->firstOrFail();

        abort_if($page->visibility === 'private', 404);

        $passwordKey = 'website-page-password-' . $page->id;
        $passwordFingerprint = hash('sha256', (string) $page->password . '|' . $page->updated_at?->timestamp);
        $requiresPassword = $page->visibility === 'password'
            && !hash_equals($passwordFingerprint, (string) $request->session()->get($passwordKey, ''));

        if ($requiresPassword) {
            return view('pages.show', [
                'page' => $page,
                'requiresPassword' => true,
                'pdfUrl' => null,
                'pdfOnly' => false,
                'previewMode' => false,
            ]);
        }

        $page->increment('page_views');

        abort_if(in_array($page->content_type, ['pdf-reader', 'magazine'], true) && empty($page->pdf_path), 404);

        return view('pages.show', [
            'page' => $page,
            'pdfUrl' => $page->pdf_path ? s3_asset($page->pdf_path) : null,
            'pdfOnly' => $page->content_type === 'pdf-reader' && trim(strip_tags($page->content ?? '')) === '',
            'requiresPassword' => false,
            'previewMode' => false,
        ]);
    }

    public function preview(Page $page): View
    {
        abort_if(in_array($page->content_type, ['pdf-reader', 'magazine'], true) && empty($page->pdf_path), 404);

        return view('pages.show', [
            'page' => $page,
            'pdfUrl' => $page->pdf_path ? s3_asset($page->pdf_path) : null,
            'pdfOnly' => in_array($page->content_type, ['pdf-reader', 'magazine'], true)
                && trim(strip_tags($page->content ?? '')) === '',
            'requiresPassword' => false,
            'previewMode' => true,
        ]);
    }

    public function access(string $slug, Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $page = Page::published()->where('slug', $slug)->where('visibility', 'password')->firstOrFail();

        if (!hash_equals((string) $page->password, $data['password'])) {
            return back()->withErrors(['password' => 'That password did not match. Try again.']);
        }

        $request->session()->put(
            'website-page-password-' . $page->id,
            hash('sha256', (string) $page->password . '|' . $page->updated_at?->timestamp)
        );

        return redirect()->route('pages.show', $slug);
    }
}
