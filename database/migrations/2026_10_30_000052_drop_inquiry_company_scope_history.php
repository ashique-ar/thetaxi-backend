<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inquiry_company_scope_events');
    }

    public function down(): void
    {
        throw new RuntimeException('Obsolete inquiry company-scope history cannot be restored.');
    }
};
