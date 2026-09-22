<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inquiry_forms') || !Schema::hasTable('inquiry_service_pages')) {
            return;
        }

        DB::table('inquiry_forms')->orderBy('id')->get(['id', 'settings'])->each(function ($form): void {
            $settings = json_decode($form->settings ?? '{}', true) ?: [];
            if (!empty($settings['submission_workflow'])) {
                return;
            }

            $workflow = DB::table('inquiry_service_pages')
                ->where('inquiry_form_id', $form->id)
                ->whereNotNull('inquiry_type')
                ->orderBy('sort_order')
                ->value('inquiry_type');

            $settings['submission_workflow'] = in_array($workflow, ['general', 'corporate', 'point_to_point'], true)
                ? $workflow
                : 'general';

            DB::table('inquiry_forms')->where('id', $form->id)->update([
                'settings' => json_encode($settings),
            ]);
        });
    }

    public function down(): void
    {
        // Form workflow remains valid configuration if this data migration is rolled back.
    }
};
