<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('newsletter_subscribers')) {
            return;
        }

        $duplicateEmails = DB::table('newsletter_subscribers')->selectRaw('LOWER(email) AS normalized_email')
            ->groupByRaw('LOWER(email)')->havingRaw('COUNT(*) > 1')->pluck('normalized_email');
        if ($duplicateEmails->isNotEmpty()) {
            $columns = ['id', 'email'];
            if (Schema::hasColumn('newsletter_subscribers', 'company_id')) {
                $columns[] = 'company_id';
            }
            $conflicts = DB::table('newsletter_subscribers')
                ->whereIn(DB::raw('LOWER(email)'), $duplicateEmails)
                ->orderBy('email')->orderBy('id')->get($columns);
            throw new RuntimeException('Reconcile duplicate newsletter subscriber emails before removing company scope: '.json_encode($conflicts->all()));
        }
        DB::table('newsletter_subscribers')->whereRaw('email <> LOWER(email)')
            ->update(['email' => DB::raw('LOWER(email)')]);

        if (Schema::hasColumn('newsletter_subscribers', 'company_id')) {
            foreach (Schema::getIndexes('newsletter_subscribers') as $index) {
                if (in_array('company_id', $index['columns'] ?? [], true)) {
                    TenantIndexDropper::drop('newsletter_subscribers', $index);
                }
            }
            Schema::table('newsletter_subscribers', function (Blueprint $table): void {
                $table->dropColumn('company_id');
            });
        }

        $hasEmailUnique = collect(Schema::getIndexes('newsletter_subscribers'))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['email']
                && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'),
        );
        if (! $hasEmailUnique) {
            Schema::table('newsletter_subscribers', function (Blueprint $table): void {
                $table->unique('email', 'newsletter_subscribers_installation_email_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Newsletter subscribers cannot regain tenant company scope.');
    }
};
