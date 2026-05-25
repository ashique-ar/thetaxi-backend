<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'brand_name')) {
                $table->string('brand_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('companies', 'email')) {
                $table->string('email')->nullable()->after('address');
            }
            if (!Schema::hasColumn('companies', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (!Schema::hasColumn('companies', 'website')) {
                $table->string('website')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('companies', 'domain')) {
                $table->string('domain')->nullable()->after('website')->index();
            }
            if (!Schema::hasColumn('companies', 'logo')) {
                $table->string('logo')->nullable()->after('domain');
            }
            if (!Schema::hasColumn('companies', 'description')) {
                $table->text('description')->nullable()->after('logo');
            }
        });

        Schema::table('website_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('website_settings', 'company_id')) {
                $table->uuid('company_id')->nullable()->after('id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            if (Schema::hasColumn('website_settings', 'company_id')) {
                $table->dropColumn('company_id');
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            foreach (['brand_name', 'email', 'phone', 'website', 'domain', 'logo', 'description'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
