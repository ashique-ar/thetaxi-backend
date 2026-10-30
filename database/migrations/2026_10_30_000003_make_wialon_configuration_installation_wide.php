<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ID = 'installation';

    public function up(): void
    {
        if (! Schema::hasTable('wialon_installation_settings')) {
            Schema::create('wialon_installation_settings', function (Blueprint $table): void {
                $table->string('id', 32)->primary();
                $table->text('token');
                $table->string('base_url')->default('https://hst-api.wialon.com');
                $table->json('resource_ids')->nullable();
                $table->json('group_ids')->nullable();
                $table->json('unit_ids')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('wialon_integrations') && ! DB::table('wialon_installation_settings')->where('id', self::ID)->exists()) {
            $legacy = DB::table('wialon_integrations')->orderBy('created_at')->orderBy('id')->first();
            if ($legacy) {
                DB::table('wialon_installation_settings')->insert([
                    'id' => self::ID,
                    'token' => $legacy->token,
                    'base_url' => $legacy->base_url,
                    'resource_ids' => $legacy->resource_ids,
                    'group_ids' => $legacy->group_ids,
                    'unit_ids' => $legacy->unit_ids,
                    'enabled' => $legacy->enabled,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wialon_installation_settings')) return;

        $settings = DB::table('wialon_installation_settings')->where('id', self::ID)->first();
        if ($settings) {
            if (! Schema::hasTable('wialon_integrations')) {
                throw new RuntimeException('Cannot roll back installation GPS settings because the legacy table is missing.');
            }
            $legacy = DB::table('wialon_integrations')->orderBy('created_at')->orderBy('id')->first();
            if (! $legacy) {
                throw new RuntimeException('Cannot roll back installation GPS settings without a legacy row to preserve them.');
            }
            DB::table('wialon_integrations')->where('id', $legacy->id)->update([
                'token' => $settings->token,
                'base_url' => $settings->base_url,
                'resource_ids' => $settings->resource_ids,
                'group_ids' => $settings->group_ids,
                'unit_ids' => $settings->unit_ids,
                'enabled' => $settings->enabled,
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('wialon_installation_settings');
    }
};
