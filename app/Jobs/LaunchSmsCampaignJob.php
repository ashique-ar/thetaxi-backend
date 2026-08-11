<?php

namespace App\Jobs;

use App\Models\Sms\SmsCampaign;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LaunchSmsCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $campaignId
    ) {
        $this->queue = 'sms-campaigns';
    }

    public function handle(SmsService $smsService): void
    {
        $campaign = SmsCampaign::query()->find($this->campaignId);
        if (!$campaign) {
            return;
        }

        $smsService->launchCampaign($campaign);
    }
}
