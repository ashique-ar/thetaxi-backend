<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_types')
            || ! Schema::hasColumn('service_types', 'owner_type')
            || ! Schema::hasColumn('service_types', 'owner_id')) {
            return;
        }

        $collisions = DB::table('service_types as scoped')
            ->where('scoped.owner_type', 'company')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('service_types as other')
                    ->whereColumn('other.context', 'scoped.context')
                    ->whereColumn('other.id', '!=', 'scoped.id')
                    ->where(function ($identity): void {
                        $identity->whereColumn('other.code', 'scoped.code')
                            ->orWhere(function ($slug): void {
                                $slug->whereNotNull('scoped.slug')
                                    ->whereColumn('other.slug', 'scoped.slug');
                            });
                    })
                    ->where(function ($owner): void {
                        $owner->where(function ($global): void {
                            $global->where('other.owner_type', '')
                                ->where('other.owner_id', '');
                        })->orWhere('other.owner_type', 'company');
                    });
            })
            ->limit(30)
            ->get(['scoped.id', 'scoped.context', 'scoped.code']);

        if ($collisions->isNotEmpty()) {
            $details = $collisions->map(fn ($row) => "{$row->context}/{$row->code} ({$row->id})")->implode(', ');
            throw new RuntimeException("Company-owned service types collide when made installation-wide. Reconcile these records before retrying: {$details}");
        }

        DB::table('service_types')
            ->where('owner_type', 'company')
            ->update(['owner_type' => '', 'owner_id' => '']);
    }

    public function down(): void
    {
        throw new RuntimeException('Installation-wide service types cannot be reassigned to company tenants.');
    }
};
