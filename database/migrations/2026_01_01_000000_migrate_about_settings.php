<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Website\WebsiteSetting;

class MigrateAboutSettings extends Migration
{
    public function up()
    {
        // Build about_services from service_1..service_4 if about_services is not present
        $exists = WebsiteSetting::where('type', 'about_services')->exists();
        if (!$exists) {
            $services = [];
            for ($i = 1; $i <= 4; $i++) {
                $titleRow = WebsiteSetting::where('type', "service_{$i}_title")->first();
                $descRow = WebsiteSetting::where('type', "service_{$i}_description")->first();
                $iconRow = WebsiteSetting::where('type', "service_{$i}_icon")->first();

                if ($titleRow || $descRow) {
                    $services[] = [
                        'title' => $titleRow ? $titleRow->value : '',
                        'description' => $descRow ? $descRow->value : '',
                        'icon' => $iconRow ? $iconRow->value : ''
                    ];
                }
            }

            if (!empty($services)) {
                WebsiteSetting::updateOrCreate(['type' => 'about_services'], ['value' => json_encode($services)]);
            }
        }

        // Build about_journey_items from legacy about_journey_section_* if present
        $existsJourney = WebsiteSetting::where('type', 'about_journey_items')->exists();
        if (!$existsJourney) {
            $title = WebsiteSetting::where('type', 'about_journey_section_title')->first();
            $desc = WebsiteSetting::where('type', 'about_journey_section_description')->first();

            if ($title || $desc) {
                $items = [
                    [
                        'title' => $title ? $title->value : '',
                        'description' => $desc ? $desc->value : '',
                        'image' => ''
                    ]
                ];

                WebsiteSetting::updateOrCreate(['type' => 'about_journey_items'], ['value' => json_encode($items)]);
            }
        }

        // Build why cards from existing hardcoded texts if needed (best-effort)
        $existsWhy = WebsiteSetting::where('type', 'about_why_cards')->exists();
        if (!$existsWhy) {
            $cards = [];
            // Try to infer from feature_1..feature_4 / why_feature_*
            for ($i = 1; $i <= 4; $i++) {
                $titleRow = WebsiteSetting::where('type', "why_feature_{$i}")->first();
                $iconRow = WebsiteSetting::where('type', "why_feature_icon_{$i}")->first();
                if ($titleRow) {
                    $cards[] = [
                        'title' => $titleRow->value,
                        'description' => '',
                        'icon' => $iconRow ? $iconRow->value : ''
                    ];
                }
            }

            if (!empty($cards)) {
                WebsiteSetting::updateOrCreate(['type' => 'about_why_cards'], ['value' => json_encode($cards)]);
            }
        }
    }

    public function down()
    {
        // Remove created aggregated settings
        WebsiteSetting::where('type', 'about_services')->delete();
        WebsiteSetting::where('type', 'about_journey_items')->delete();
        WebsiteSetting::where('type', 'about_why_cards')->delete();
    }
}
