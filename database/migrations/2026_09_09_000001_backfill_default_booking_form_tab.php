<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('booking_form_tabs')) {
            return;
        }

        $tabs = DB::table('booking_form_tabs')
            ->whereNull('deleted_at')
            ->orderByDesc('enabled')
            ->orderBy('sort_order')
            ->get(['id', 'enabled', 'metadata']);

        $hasEnabledDefault = $tabs->contains(function ($tab): bool {
            $metadata = json_decode($tab->metadata ?? '{}', true) ?: [];

            return (bool) $tab->enabled && !empty($metadata['is_default']);
        });

        if ($hasEnabledDefault) {
            return;
        }

        $defaultTab = $tabs->first(fn ($tab): bool => (bool) $tab->enabled);
        if (!$defaultTab) {
            return;
        }

        foreach ($tabs as $tab) {
            $metadata = json_decode($tab->metadata ?? '{}', true) ?: [];
            $metadata['is_default'] = $tab->id === $defaultTab->id;

            DB::table('booking_form_tabs')
                ->where('id', $tab->id)
                ->update(['metadata' => json_encode($metadata)]);
        }
    }

    public function down(): void
    {
        // The selected default is valid application data and is intentionally retained.
    }
};
