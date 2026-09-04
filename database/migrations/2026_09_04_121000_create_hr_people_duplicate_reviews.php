<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('hr_people_duplicate_reviews',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();$t->string('match_kind',40);$t->char('match_fingerprint',64);$t->json('candidate_staff_ids');$t->json('safe_candidate_snapshot');$t->string('status',30)->default('pending_review');$t->string('disposition',40)->nullable();$t->foreignUuid('canonical_staff_id')->nullable()->constrained('staff')->restrictOnDelete();$t->text('reason')->nullable();$t->foreignUuid('prepared_by')->constrained('users')->restrictOnDelete();$t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('decided_at')->nullable();$t->unsignedInteger('version')->default(1);$t->foreignUuid('created_user_id')->nullable()->constrained('users')->restrictOnDelete();$t->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();$t->timestamps();$t->softDeletes();$t->unique(['company_id','match_kind','match_fingerprint'],'hr_people_duplicate_review_match_unique');});}
 public function down():void{if(Schema::hasTable('hr_people_duplicate_reviews')&&DB::table('hr_people_duplicate_reviews')->exists())throw new RuntimeException('Rollback refused: export and reconcile retained People Core duplicate-review decisions first.');Schema::dropIfExists('hr_people_duplicate_reviews');}
};
