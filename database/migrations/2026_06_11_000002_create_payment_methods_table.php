<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('payable');
            $table->string('method_type')->index();
            $table->string('label')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('account_number')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedTinyInteger('card_expiry_month')->nullable();
            $table->unsignedSmallInteger('card_expiry_year')->nullable();
            $table->string('wallet_provider')->nullable();
            $table->string('wallet_identifier')->nullable();
            $table->string('cheque_payee_name')->nullable();
            $table->string('cheque_bank_name')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->uuid('owner_payment_method_id')->nullable()->after('payment_model')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['owner_payment_method_id']);
            $table->dropColumn('owner_payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};
