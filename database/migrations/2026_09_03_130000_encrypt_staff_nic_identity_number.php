<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIX = 'enc:';

    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropIndex(['nic']);
            $table->text('nic')->nullable()->change();
            $table->char('nic_fingerprint', 64)->nullable()->after('nic');
        });

        $this->encryptExistingStaffNic();

        Schema::table('staff', function (Blueprint $table) {
            $table->index('nic_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropIndex(['nic_fingerprint']);
        });

        $this->decryptExistingStaffNic();

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('nic_fingerprint');
            $table->string('nic', 20)->nullable()->change();
            $table->index('nic');
        });
    }

    /**
     * One-time backfill: existing plaintext NIC values are encrypted in place
     * and a SHA-256 fingerprint is derived for exact-match lookup, since the
     * ciphertext itself can never support equality/substring search.
     */
    private function encryptExistingStaffNic(): void
    {
        DB::table('staff')
            ->whereNotNull('nic')
            ->orderBy('id')
            ->get(['id', 'nic'])
            ->each(function (object $row): void {
                $value = (string) $row->nic;
                if ($value === '') {
                    return;
                }

                $plain = str_starts_with($value, self::PREFIX)
                    ? Crypt::decryptString(substr($value, strlen(self::PREFIX)))
                    : $value;

                DB::table('staff')->where('id', $row->id)->update([
                    'nic' => str_starts_with($value, self::PREFIX)
                        ? $value
                        : self::PREFIX.Crypt::encryptString($plain),
                    'nic_fingerprint' => hash('sha256', mb_strtolower(trim($plain))),
                ]);
            });
    }

    private function decryptExistingStaffNic(): void
    {
        DB::table('staff')
            ->whereNotNull('nic')
            ->orderBy('id')
            ->get(['id', 'nic'])
            ->each(function (object $row): void {
                $value = (string) $row->nic;
                if (! str_starts_with($value, self::PREFIX)) {
                    return;
                }

                DB::table('staff')->where('id', $row->id)->update([
                    'nic' => Crypt::decryptString(substr($value, strlen(self::PREFIX))),
                ]);
            });
    }
};
