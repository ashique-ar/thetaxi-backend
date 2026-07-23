<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_addon_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'sort_order']);
        });

        if (Schema::hasTable('vehicle_addons') && Schema::hasColumn('vehicle_addons', 'category_id')) {
            DB::table('vehicle_addons')
                ->whereNotNull('category_id')
                ->distinct()
                ->pluck('category_id')
                ->each(function (string $id): void {
                    DB::table('vehicle_addon_categories')->insertOrIgnore([
                        'id' => $id,
                        'name' => 'Imported category ' . substr($id, 0, 8),
                        'description' => 'Retained from an existing vehicle add-on category reference.',
                        'is_active' => true,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

            Schema::table('vehicle_addons', function (Blueprint $table): void {
                $table->foreign('category_id')
                    ->references('id')
                    ->on('vehicle_addon_categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_addons') && Schema::hasColumn('vehicle_addons', 'category_id')) {
            Schema::table('vehicle_addons', fn (Blueprint $table) => $table->dropForeign(['category_id']));
        }

        Schema::dropIfExists('vehicle_addon_categories');
    }
};
