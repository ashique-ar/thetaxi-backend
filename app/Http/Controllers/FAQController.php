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
        
        // Get search query
        $search = $request->get('search');
        $categoryId = $request->get('category');
        
        // Get FAQ categories
        $categories = FAQCategory::active()->get();
        
        // Build FAQ query
        $faqQuery = FAQ::active()->with('category');
        
        if ($search) {
            $faqQuery->search($search);
        }
        
        if ($categoryId) {
            $faqQuery->byCategory($categoryId);
        }
        
        $faqs = $faqQuery->paginate(10);
        
        // Get featured FAQs
        $featuredFaqs = FAQ::featured()->with('category')->limit(5)->get();
        
        return view('faq', compact('settings', 'faqs', 'categories', 'featuredFaqs', 'search', 'categoryId'));
    }

    public function category(FAQCategory $category, Request $request): View
    {
        $settings = $this->websiteSettingsService->getFaqPageSettings();
        
        // Get search query
        $search = $request->get('search');
        
        // Get FAQ categories
        $categories = FAQCategory::active()->get();
        
        // Build FAQ query for this category
        $faqQuery = $category->activeFaqs();
        
        if ($search) {
            $faqQuery->search($search);
        }
        
        $faqs = $faqQuery->paginate(10);
        
        // Get featured FAQs
        $featuredFaqs = FAQ::featured()->with('category')->limit(5)->get();
        
        return view('faq', compact('settings', 'faqs', 'categories', 'featuredFaqs', 'search', 'category'));
    }
}
