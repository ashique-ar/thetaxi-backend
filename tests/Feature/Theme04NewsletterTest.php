<?php

namespace Tests\Feature;

use App\Services\WebsiteSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class Theme04NewsletterTest extends TestCase
{
    public function test_subscription_is_validated_and_idempotent(): void
    {
        $this->withoutMiddleware();
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('company_id');
            $table->string('email');
            $table->timestamps();
            $table->unique(['company_id', 'email']);
        });

        $settings = Mockery::mock(WebsiteSettingsService::class);
        $settings->shouldReceive('resolveCurrentCompanyId')->andReturn('company-1');
        $settings->shouldReceive('get')->with('footer_newsletter_enabled', true)->andReturn('true');
        $this->app->instance(WebsiteSettingsService::class, $settings);

        $this->from('/')->post(route('newsletter.subscribe'), ['email' => 'invalid'])->assertSessionHasErrors('email', null, 'newsletter');
        $this->from('/')->post(route('newsletter.subscribe'), ['email' => 'Guest@Example.com'])->assertSessionHas('newsletter_success');
        $this->from('/')->post(route('newsletter.subscribe'), ['email' => 'guest@example.com'])->assertSessionHas('newsletter_success');

        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
        $this->assertSame('guest@example.com', DB::table('newsletter_subscribers')->value('email'));
    }
}
