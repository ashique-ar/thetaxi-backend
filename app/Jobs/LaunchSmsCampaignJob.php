<?php

namespace App\Jobs;

use App\Models\Sms\SmsCampaign;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

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
            DB::table('system_background_tasks')->where('related_id', $this->campaignId)->where('type', 'sms_campaign_launch')->update([
                'status' => 'failed', 'error' => 'Campaign is no longer available.', 'updated_at' => now(),
            ]);
            return;
        }

        DB::table('system_background_tasks')->where('related_id', $this->campaignId)->where('type', 'sms_campaign_launch')->update([
            'status' => 'processing', 'updated_at' => now(),
        ]);
        $smsService->launchCampaign($campaign);
    }

    public function failed(Throwable $exception): void
    {
        SmsCampaign::query()->whereKey($this->campaignId)->whereNotIn('status', ['completed'])->update([
            'status' => 'failed', 'completed_at' => now(),
        ]);
        DB::table('system_background_tasks')->where('related_id', $this->campaignId)->where('type', 'sms_campaign_launch')->update([
            'status' => 'failed', 'error' => 'Campaign processing failed. Open the campaign for details.', 'updated_at' => now(),
        ]);
    }
}
