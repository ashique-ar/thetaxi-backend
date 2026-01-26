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
        $this->apiKey = config('services.openai.api_key') ?: null;
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
        $businessContext = $options['business_context'] ?? 'TheTaxi - a taxi booking service in Sri Lanka offering airport transfers, wedding cars, corporate travel, and tour packages';
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
You are an expert SEO content strategist and copywriter specializing in creating high-ranking, optimized content. Your expertise covers:

1. **SEO (Search Engine Optimization)**: Keyword optimization, semantic relevance, search intent matching
2. **AEO (Answer Engine Optimization)**: Structured content for AI assistants, featured snippets, voice search
3. **SXO (Search Experience Optimization)**: User engagement, readability, conversion-focused content
4. **GEO (Generative Engine Optimization)**: Content optimized for AI-generated search results and citations

Business Context: {$businessContext}
Target Audience: {$targetAudience}

When generating content, always:
- Include primary and secondary keywords naturally
- Structure content for featured snippets (lists, tables, direct answers)
- Use engaging hooks and clear value propositions
- Optimize meta descriptions for click-through rates
- Include semantic keyword variations
- Create content that answers user questions directly
- Use headers and structure that search engines prefer
- Make content shareable and linkable
- Consider local SEO for Sri Lanka market when relevant

Respond ONLY with valid JSON. No markdown code blocks, no explanations outside the JSON.
PROMPT;
    }

    /**
     * Build the user prompt for specific content generation
     */
    protected function buildUserPrompt(string $title, ?string $contentType, array $options): string
    {
        $wordCount = $options['word_count'] ?? 800;
        $tone = $options['tone'] ?? 'professional yet friendly';

        return <<<PROMPT
Generate comprehensive CMS content for the following:

**Title**: "{$title}"
**Content Type**: {$contentType}
**Word Count**: Approximately {$wordCount} words for the body
**Tone**: {$tone}

Return a JSON object with these exact fields:

{
    "title": "Optimized title (may slightly improve the original for SEO)",
    "slug": "url-friendly-slug",
    "excerpt": "Compelling 150-160 character excerpt for listings and previews",
    "body": "Full HTML content with proper headings (h2, h3), paragraphs, lists where appropriate. Include FAQ section if relevant. Must be engaging and SEO-optimized.",
    "meta_title": "SEO title under 60 characters with primary keyword",
    "meta_description": "Compelling meta description 150-160 characters with call-to-action",
    "meta_tags": ["keyword1", "keyword2", "keyword3", "keyword4", "keyword5"],
    "suggested_titles": [
        "High-CTR improved title 1",
        "High-CTR improved title 2",
        "High-CTR improved title 3",
        "Optional localized or question-based title 4"
    ],
    "read_time": "X min read",
    "author": "TheTaxi Editorial",
    "faq_schema": [
        {"question": "Relevant question 1?", "answer": "Concise answer 1"},
        {"question": "Relevant question 2?", "answer": "Concise answer 2"},
        {"question": "Relevant question 3?", "answer": "Concise answer 3"}
    ],
    "seo_score_tips": ["Tip 1 for improving content", "Tip 2 for further optimization"],
    "suggested_internal_links": ["Related topic 1", "Related topic 2"],
    "primary_keyword": "main target keyword",
    "secondary_keywords": ["secondary keyword 1", "secondary keyword 2", "secondary keyword 3"]
}

Important:
- The body should be well-structured HTML with semantic tags
- Include a compelling introduction that addresses user intent
- Add a clear conclusion with call-to-action
- Naturally incorporate keywords without stuffing
- Make content valuable for both users and search engines
- Include structured data hints for FAQ if applicable
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

        // Ensure all required fields exist with defaults
        return [
            'title' => $data['title'] ?? $originalTitle,
            'slug' => $data['slug'] ?? Str::slug($originalTitle),
            'excerpt' => $data['excerpt'] ?? '',
            'body' => $data['body'] ?? '',
            'meta_title' => $data['meta_title'] ?? Str::limit($originalTitle, 60),
            'meta_description' => $data['meta_description'] ?? '',
            'meta_tags' => $data['meta_tags'] ?? [],
            'suggested_titles' => $data['suggested_titles'] ?? [],
            'read_time' => $data['read_time'] ?? '5 min read',
            'author' => $data['author'] ?? 'TheTaxi Editorial',
            'custom_fields' => [
                'faq_schema' => $data['faq_schema'] ?? [],
                'seo_score_tips' => $data['seo_score_tips'] ?? [],
                'suggested_internal_links' => $data['suggested_internal_links'] ?? [],
                'primary_keyword' => $data['primary_keyword'] ?? '',
                'secondary_keywords' => $data['secondary_keywords'] ?? [],
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
            $options['business_context'] ?? 'TheTaxi - premium taxi booking service in Sri Lanka',
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
    "seo_score_after": 0-100
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
