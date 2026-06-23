<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI Content Generation Service using OpenAI API
 * 
 * Generates SEO/AEO/SXO/GEO optimized content for CMS
 */
class AIContentService
{
    protected ?string $apiKey;
    protected string $model;
    protected ?string $organization;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?? null;
        $this->model = config('services.openai.model', 'gpt-4o');
        $this->organization = config('services.openai.organization');
    }

    /**
     * Generate complete CMS content from a title
     *
     * @param string $title The content title
     * @param string|null $contentType The type of content (blog, page, faq, etc.)
     * @param array $options Additional options for generation
     * @return array Generated content fields
     */
    public function generateContentFromTitle(string $title, ?string $contentType = 'blog', array $options = []): array
    {
        $businessContext = $options['business_context'] ?? 'A taxi booking service offering airport transfers, wedding cars, corporate travel, and tour packages';
        $targetAudience = $options['target_audience'] ?? 'travelers, tourists, business professionals, and locals in Sri Lanka';
        $locale = $options['locale'] ?? 'en-US';

        $systemPrompt = $this->buildSystemPrompt($businessContext, $targetAudience);
        $userPrompt = $this->buildUserPrompt($title, $contentType, $options);

        try {
            $response = $this->callOpenAI($systemPrompt, $userPrompt);
            return $this->parseResponse($response, $title);
        } catch (\Exception $e) {
            Log::error('AI Content Generation failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build the system prompt for content generation
     */
    protected function buildSystemPrompt(string $businessContext, string $targetAudience): string
    {
        return <<<PROMPT
You are an expert SEO content strategist and CMS copywriter for transport, taxi, rent-a-car, airport transfer, wedding car, corporate travel, and tour package businesses. Your job is to turn a page title into publish-ready CMS content that is useful for customers and technically strong for search.

Your expertise covers:
1. SEO: keyword optimization, semantic relevance, and search intent matching
2. AEO: direct answers, featured-snippet structure, and voice-search friendly phrasing
3. SXO: clear readability, trust signals, and conversion-focused content
4. GEO: content that AI search experiences can understand, cite, and summarize

Business Context: {$businessContext}
Target Audience: {$targetAudience}

When generating content, always:
- Identify one primary keyword from the page title and use it naturally in the meta title, meta description, H1, slug, and opening paragraph
- Include relevant secondary keywords without stuffing
- Match the title's search intent and answer what the reader is likely trying to decide
- Write in active voice with a professional, friendly, trusted transport-service tone
- Support this application: mention easy booking, reliable transfers, fleet choice, airport transfers, corporate travel, wedding cars, tours, or real-time coordination only when relevant to the title
- Consider local SEO for Sri Lanka when the title implies a local route, destination, service area, or travel use case
- Avoid unsupported claims, fake guarantees, keyword stuffing, markdown, and vague filler

Respond ONLY with valid JSON. No markdown code blocks, no comments, and no explanations outside the JSON.
PROMPT;
    }

    /**
     * Build the user prompt for specific content generation
     */
    protected function buildUserPrompt(string $title, ?string $contentType, array $options): string
    {
        $wordCount = $options['word_count'] ?? 800;
        $tone = $options['tone'] ?? 'professional yet friendly';
        $appUrl = config('app.url') ?: '/';

        return <<<PROMPT
Given the webpage title below, generate SEO-optimized CMS content for this application.

PAGE TITLE: "{$title}"
CONTENT TYPE: {$contentType}
TARGET BODY LENGTH: Approximately {$wordCount} words
TONE: {$tone}

Return ONLY a valid JSON object with these exact fields:

{
    "title": "SEO-improved page title",
    "slug": "keyword-first-url-slug",
    "excerpt": "Short SEO story paragraph, 120-140 words",
    "body": "Full HTML CMS body using the generated H1, H2 and H3 structure, story paragraph, helpful sections, and a booking CTA",
    "meta_title": "Under 60 characters, include primary keyword + brand hint",
    "meta_description": "150-160 characters exactly, include primary keyword + value proposition + soft CTA",
    "meta_tags": ["primary keyword", "secondary keyword 1", "secondary keyword 2", "secondary keyword 3", "secondary keyword 4"],
    "suggested_titles": [
        "High-CTR improved title 1",
        "High-CTR improved title 2",
        "High-CTR improved title 3",
        "Localized or question-based title 4"
    ],
    "read_time": "X min read",
    "author": "Editorial Team",
    "faq_schema": [
        {"question": "Relevant question 1?", "answer": "Concise answer 1"},
        {"question": "Relevant question 2?", "answer": "Concise answer 2"},
        {"question": "Relevant question 3?", "answer": "Concise answer 3"}
    ],
    "seo_score_tips": ["Tip 1 for improving content", "Tip 2 for further optimization"],
    "suggested_internal_links": ["Related topic 1", "Related topic 2"],
    "primary_keyword": "main target keyword",
    "secondary_keywords": ["secondary keyword 1", "secondary keyword 2", "secondary keyword 3"],
    "h1": "Rephrased H1 that keeps the primary keyword but does not mirror the title word for word",
    "h2h4": ["H2: ...", "H3: ...", "H3: ...", "H2: ...", "H3: ..."],
    "breadcrumb": "Short Label",
    "story": "Short SEO-optimized story paragraph, 120-140 words",
    "booking_link": "{$appUrl}/",
    "booking_cta_html": "Small HTML snippet for a booking CTA",
    "application_links": ["{$appUrl}/"]
}

Important:
- meta_title must be under 60 characters and include the primary keyword plus a subtle brand hint
- meta_description must be 150-160 characters exactly and include the primary keyword, a value proposition, and a soft CTA
- title should be an SEO-improved version of the page title
- h1 must rephrase the page title slightly while keeping the primary keyword
- h2h4 must contain exactly 5 heading strings logically derived from the title's search intent, using this pattern: H2, H3, H3, H2, H3
- slug must be lowercase, hyphenated, keyword-first, under 75 characters, and must not include a leading slash.
- breadcrumb must be 1-3 words max, sentence case
- story must be 120-140 words, one paragraph, SEO-friendly, active voice, reader-focused, natural primary keyword use, and end with a soft call to action
- excerpt must reuse the story or a close 120-140 word variant
- body must be valid HTML, start with <h1>, include the story paragraph immediately after the H1, then include the h2h4 headings with useful supporting paragraphs and an unobtrusive booking CTA
- Do not use markdown, backticks, bullet-only content, placeholder text, or generic claims
- Keep booking links aligned with this application URL: {$appUrl}/
PROMPT;
    }

    /**
     * Call the OpenAI API
     */
    protected function callOpenAI(string $systemPrompt, string $userPrompt): string
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 4000,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error');
            Log::error('OpenAI API Error', [
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \Exception('OpenAI API Error: ' . $error);
        }

        return $response->json('choices.0.message.content', '{}');
    }

    /**
     * Parse and validate the API response
     */
    protected function parseResponse(string $response, string $originalTitle): array
    {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse AI response as JSON');
        }

        $slug = ltrim((string) ($data['slug'] ?? Str::slug($originalTitle)), '/');
        $story = $data['story'] ?? '';

        // Ensure all required fields exist with defaults
        return [
            'title' => $data['title'] ?? $originalTitle,
            'slug' => $slug ?: Str::slug($originalTitle),
            'excerpt' => $data['excerpt'] ?? $story,
            'body' => $data['body'] ?? '',
            'meta_title' => $data['meta_title'] ?? Str::limit($originalTitle, 60),
            'meta_description' => $data['meta_description'] ?? '',
            'meta_tags' => $data['meta_tags'] ?? [],
            'suggested_titles' => $data['suggested_titles'] ?? [],
            'read_time' => $data['read_time'] ?? '5 min read',
            'author' => $data['author'] ?? 'Editorial Team',
            'custom_fields' => [
                'h1' => $data['h1'] ?? '',
                'h2h4' => $data['h2h4'] ?? [],
                'breadcrumb' => $data['breadcrumb'] ?? '',
                'story' => $story,
                'faq_schema' => $data['faq_schema'] ?? [],
                'seo_score_tips' => $data['seo_score_tips'] ?? [],
                'suggested_internal_links' => $data['suggested_internal_links'] ?? [],
                'primary_keyword' => $data['primary_keyword'] ?? '',
                'secondary_keywords' => $data['secondary_keywords'] ?? [],
                'booking_link' => $data['booking_link'] ?? '',
                'booking_cta_html' => $data['booking_cta_html'] ?? '',
                'application_links' => $data['application_links'] ?? [],
            ],
        ];
    }

    /**
     * Generate only meta content (title, description, tags) for existing content
     */
    public function generateMetaContent(string $title, string $body = ''): array
    {
        $systemPrompt = "You are an SEO expert. Generate optimized meta content. Respond only with valid JSON.";

        $userPrompt = <<<PROMPT
Generate SEO meta content for:
Title: "{$title}"
Body excerpt: "{$body}"

Return JSON:
{
    "meta_title": "Under 60 chars with keyword",
    "meta_description": "150-160 chars compelling description",
    "meta_tags": ["tag1", "tag2", "tag3", "tag4", "tag5"]
}
PROMPT;

        try {
            $response = $this->callOpenAI($systemPrompt, $userPrompt);
            $data = json_decode($response, true);

            return [
                'meta_title' => $data['meta_title'] ?? '',
                'meta_description' => $data['meta_description'] ?? '',
                'meta_tags' => $data['meta_tags'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('Meta content generation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Improve existing content for better SEO
     */
    public function improveContent(string $title, string $body, array $options = []): array
    {
        $systemPrompt = $this->buildSystemPrompt(
            $options['business_context'] ?? 'Premium taxi booking service',
            $options['target_audience'] ?? 'travelers and business professionals'
        );

        $userPrompt = <<<PROMPT
Improve the following content for better SEO, AEO, and user engagement:

**Title**: "{$title}"
**Current Body**: 
{$body}

Return improved content as JSON:
{
    "title": "Improved title",
    "body": "Improved HTML body with better structure, keywords, and engagement",
    "excerpt": "New compelling excerpt",
    "meta_title": "Optimized meta title",
    "meta_description": "Optimized meta description",
    "meta_tags": ["improved", "keywords"],
    "improvements_made": ["List of improvements made"],
    "seo_score_before": 0-100,
    "seo_score_after": 0-100,
    "booking_link": "Booking URL or deep link if added",
    "booking_cta_html": "HTML CTA snippet for booking",
    "application_links": ["Optional application-related links included in content"]
}
PROMPT;

        try {
            $response = $this->callOpenAI($systemPrompt, $userPrompt);
            return json_decode($response, true) ?? [];
        } catch (\Exception $e) {
            Log::error('Content improvement failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if the service is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}
