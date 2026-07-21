<?php

namespace App\Http\Controllers;

use App\Models\Website\Faq;
use App\Models\Website\FaqCategory;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FAQController extends Controller
{
    private WebsiteSettingsService $websiteSettingsService;

    public function __construct(WebsiteSettingsService $websiteSettingsService)
    {
        $this->websiteSettingsService = $websiteSettingsService;
    }

    public function index(Request $request): View
    {
        $settings = $this->websiteSettingsService->getFaqPageSettings();
        $search = $request->get('search');
        $categoryId = $request->get('category');
        $categories = FAQCategory::active()->get();
        $showFeatured = ($settings['faq_show_featured'] ?? true) && !$search && !$categoryId;
        $featuredLimit = max(1, min(20, (int) ($settings['faq_featured_show_count'] ?? 5)));
        $itemsPerPage = max(1, min(50, (int) ($settings['faq_items_per_page'] ?? 10)));

        $featuredFaqs = $showFeatured
            ? FAQ::featured()->with('category')->limit($featuredLimit)->get()
            : collect();

        $faqQuery = FAQ::active()->with('category');

        if ($search) {
            $faqQuery->search($search);
        }

        if ($categoryId) {
            $faqQuery->byCategory($categoryId);
        }

        if ($featuredFaqs->isNotEmpty()) {
            $faqQuery->whereNotIn('id', $featuredFaqs->modelKeys());
        }

        $faqs = $faqQuery->paginate($itemsPerPage)->withQueryString();

        return view('faq', compact('settings', 'faqs', 'categories', 'featuredFaqs', 'search', 'categoryId'));
    }

    public function category(FAQCategory $category, Request $request): View
    {
        $settings = $this->websiteSettingsService->getFaqPageSettings();
        $search = $request->get('search');
        $categoryId = $category->getKey();
        $categories = FAQCategory::active()->get();
        $faqQuery = $category->activeFaqs();

        if ($search) {
            $faqQuery->search($search);
        }

        $itemsPerPage = max(1, min(50, (int) ($settings['faq_items_per_page'] ?? 10)));
        $faqs = $faqQuery->paginate($itemsPerPage)->withQueryString();
        $featuredFaqs = collect();

        return view('faq', compact(
            'settings',
            'faqs',
            'categories',
            'featuredFaqs',
            'search',
            'category',
            'categoryId'
        ));
    }
}
