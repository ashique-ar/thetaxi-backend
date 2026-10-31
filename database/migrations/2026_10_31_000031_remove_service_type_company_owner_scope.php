<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_types')) {
            return;
        }

        $hasOwnerType = Schema::hasColumn('service_types', 'owner_type');
        $hasOwnerId = Schema::hasColumn('service_types', 'owner_id');
        if ($hasOwnerType || $hasOwnerId) {
            $this->reconcileGlobalKeys();
        }

        foreach (Schema::getIndexes('service_types') as $index) {
            if (array_intersect($index['columns'], ['owner_type', 'owner_id'])) {
                TenantIndexDropper::drop('service_types', $index);
            }
        }

        Schema::table('service_types', function (Blueprint $table) use ($hasOwnerType, $hasOwnerId): void {
            if ($hasOwnerId) {
                $table->dropColumn('owner_id');
            }
            if ($hasOwnerType) {
                $table->dropColumn('owner_type');
            }
        });

        $indexes = Schema::getIndexes('service_types');
        foreach ([
            'service_types_code_context_unique' => ['code', 'context'],
            'service_types_slug_context_unique' => ['slug', 'context'],
        ] as $name => $columns) {
            $exists = collect($indexes)->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && $index['columns'] === $columns);
            if (! $exists) {
                Schema::table('service_types', fn (Blueprint $table) => $table->unique($columns, $name));
            }
        }

        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        Cache::forget('sitemap');
    }

    private function reconcileGlobalKeys(): void
    {
        $query = DB::table('service_types')->orderBy('context');
        if (Schema::hasColumn('service_types', 'owner_type') && Schema::hasColumn('service_types', 'owner_id')) {
            $query->orderByRaw("CASE WHEN owner_type = '' AND owner_id = '' THEN 0 ELSE 1 END");
        } elseif (Schema::hasColumn('service_types', 'owner_type')) {
            $query->orderByRaw("CASE WHEN owner_type = '' THEN 0 ELSE 1 END");
        }
        $services = $query
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('is_active')->orderByDesc('updated_at')->orderBy('code')->orderBy('id')
            ->get(['id', 'context', 'code', 'slug', 'is_active']);

        $reservedCodes = [];
        $reservedSlugs = [];
        foreach ($services as $service) {
            $reservedCodes[$this->key($service->context, $service->code)] = true;
            if ($service->slug !== null) {
                $reservedSlugs[$this->key($service->context, $service->slug)] = true;
            }
        }
        $seenCodes = [];
        $seenSlugs = [];
        foreach ($services as $service) {
            $codeKey = $this->key($service->context, $service->code);
            $slugKey = $service->slug === null ? null : $this->key($service->context, $service->slug);
            $values = [];

            if (isset($seenCodes[$codeKey])) {
                $legacyCode = $this->uniqueValue('legacy_', $service->id, $service->context, $reservedCodes + $seenCodes);
                $values['code'] = $legacyCode;
                $seenCodes[$this->key($service->context, $legacyCode)] = true;
                $reservedCodes[$this->key($service->context, $legacyCode)] = true;
                if (Schema::hasColumn('service_types', 'is_active')) {
                    $values['is_active'] = false;
                }
            } else {
                $seenCodes[$codeKey] = true;
            }

            if ($slugKey !== null && isset($seenSlugs[$slugKey])) {
                $legacySlug = $this->uniqueValue('legacy-', $service->id, $service->context, $reservedSlugs + $seenSlugs);
                $values['slug'] = $legacySlug;
                $seenSlugs[$this->key($service->context, $legacySlug)] = true;
                $reservedSlugs[$this->key($service->context, $legacySlug)] = true;
            } elseif ($slugKey !== null) {
                $seenSlugs[$slugKey] = true;
            }

            if ($values !== []) {
                $values['updated_at'] = now();
                DB::table('service_types')->where('id', $service->id)->update($values);
            }
        }
    }

    private function uniqueValue(string $prefix, string $id, ?string $context, array $used): string
    {
        $base = $prefix.substr(str_replace('-', '', $id), 0, 12);
        $value = $base;
        $suffix = 1;
        while (isset($used[$this->key($context, $value)])) {
            $value = $base.'-'.$suffix++;
        }

        return $value;
    }

    private function key(?string $context, ?string $value): string
    {
        return ($context ?? '')."\0".($value ?? '');
    }

    public function down(): void
    {
        throw new RuntimeException('Installation-wide service type ownership cannot be restored.');
    }
};
