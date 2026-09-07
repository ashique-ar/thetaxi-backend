<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('website_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('website_settings', 'company_id')) {
                $t->uuid('company_id')->nullable()->index(); } });
    }
    public function down(): void
    {
        if (Schema::hasColumn('website_settings', 'company_id'))
            Schema::table('website_settings', fn(Blueprint $t) => $t->dropColumn('company_id'));
    }
};
