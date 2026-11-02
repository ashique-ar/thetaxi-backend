<?php

namespace Tests\Unit;

use App\Services\AIContentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIContentServiceTourSuggestionTest extends TestCase
{
    public function test_tour_helper_sends_only_trip_preferences_and_catalogue_and_parses_json_days(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        $content = json_encode(['days' => [[
            'day_number' => 1,
            'stops' => [['kind' => 'destination', 'catalogue_id' => 'published-place']],
        ]]]);
        Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $content]]],
        ])]);

        $result = app(AIContentService::class)->suggestTourItinerary(
            ['duration_days' => 1, 'start_date' => '2026-12-01', 'interests' => 'gardens'],
            [['id' => 'published-place', 'name' => 'Kandy']]
        );

        self::assertSame(1, $result['days'][0]['day_number']);
        Http::assertSent(function (Request $request): bool {
            $prompt = json_decode($request['messages'][1]['content'], true);
            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $prompt['trip']['interests'] === 'gardens'
                && $prompt['published_catalogue'][0]['id'] === 'published-place'
                && !array_key_exists('customer_id', $prompt['trip']);
        });
    }
}
