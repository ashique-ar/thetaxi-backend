<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if the table exists and if it has an 'id' column
        if (Schema::hasTable('shortened_urls')) {
            $columns = Schema::getColumnListing('shortened_urls');
            
            if (!in_array('id', $columns)) {
                // The table exists but doesn't have an 'id' column
                // We need to add it or recreate the table
                
                // Check if there's any data
                $hasData = DB::table('shortened_urls')->exists();
                
                if (!$hasData) {
                    // No data, safe to drop and recreate
                    Schema::dropIfExists('shortened_urls');
                    
                    Schema::create('shortened_urls', function (Blueprint $table) {
                        $table->uuid('id')->primary();
                        $table->string('short_code', 10)->unique()->index();
                        $table->text('original_url');
                        $table->timestamp('expires_at')->nullable()->index();
                        $table->unsignedInteger('access_count')->default(0);
                        $table->timestamp('last_accessed_at')->nullable();
                        $table->string('created_by_type')->nullable();
                        $table->uuid('created_by_id')->nullable();
                        
                        // Analytics fields
                        $table->string('source')->nullable();
                        $table->string('campaign')->nullable();
                        $table->string('medium')->nullable();
                        $table->json('metadata')->nullable();
                        
                        $table->timestamps();
                        $table->softDeletes();

                        // Indexes
                        $table->index(['expires_at', 'created_at']);
                        $table->index(['original_url', 'expires_at']);
                        $table->index(['source', 'campaign']);
                        $table->index(['created_by_type', 'created_by_id']);
                    });
                } else {
                    // Has data, need to migrate carefully
                    // This is more complex - would need to create new table, copy data, swap tables
                    throw new \Exception('shortened_urls table has data but missing id column. Manual intervention required.');
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is a fix, no need to reverse
    }
};
