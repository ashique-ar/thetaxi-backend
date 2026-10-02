<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIX = 'enc:';
    private const FIELDS = ['dob', 'license_no', 'license_expiry', 'address'];

    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropUnique(['license_no']);
            foreach (self::FIELDS as $field) {
                $table->text($field)->nullable()->change();
            }
            $table->char('license_no_fingerprint', 64)->nullable()->after('license_no');
        });

        $this->transform(false);

        Schema::table('staff', function (Blueprint $table) {
            $table->unique('license_no_fingerprint');
        });
    }

    public function down(): void
    {
        $this->assertRollbackFitsLegacyColumns();

        Schema::table('staff', function (Blueprint $table) {
            $table->dropUnique(['license_no_fingerprint']);
        });

        $this->transform(true);

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('license_no_fingerprint');
            $table->date('dob')->nullable()->change();
            $table->string('license_no', 100)->nullable()->change();
            $table->date('license_expiry')->nullable()->change();
            $table->string('address', 255)->nullable()->change();
            $table->unique('license_no');
        });
    }

    private function transform(bool $decrypt): void
    {
        DB::table('staff')->orderBy('id')->chunkById(
            200,
            function ($rows) use ($decrypt): void {
                $rows->each(function (object $row) use ($decrypt): void {
                    $changes = [];
                    foreach (self::FIELDS as $field) {
                        $value = $row->{$field};
                        if ($value === null || $value === '') {
                            continue;
                        }

                        $isEncrypted = str_starts_with((string) $value, self::PREFIX);
                        if ($decrypt && $isEncrypted) {
                            $changes[$field] = Crypt::decryptString(substr((string) $value, strlen(self::PREFIX)));
                        } elseif (! $decrypt && ! $isEncrypted) {
                            $changes[$field] = self::PREFIX.Crypt::encryptString((string) $value);
                        }
                    }

                    if (! $decrypt) {
                        $license = (string) ($row->license_no ?? '');
                        if (str_starts_with($license, self::PREFIX)) {
                            $license = Crypt::decryptString(substr($license, strlen(self::PREFIX)));
                        }
                        $changes['license_no_fingerprint'] = $license === ''
                            ? null
                            : hash('sha256', mb_strtolower(trim($license)));
                    }

                    if ($changes !== []) {
                        DB::table('staff')->where('id', $row->id)->update($changes);
                    }
                });
            },
            'id',
        );
    }

    private function assertRollbackFitsLegacyColumns(): void
    {
        DB::table('staff')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                foreach (['license_no' => 100, 'address' => 255] as $field => $limit) {
                    $value = (string) ($row->{$field} ?? '');
                    if (str_starts_with($value, self::PREFIX)) {
                        $value = Crypt::decryptString(substr($value, strlen(self::PREFIX)));
                    }
                    if (mb_strlen($value) > $limit) {
                        throw new RuntimeException("Cannot roll back Staff personal encryption: {$field} exceeds the legacy {$limit}-character column for Staff {$row->id}.");
                    }
                }
            }
        }, 'id');
    }
};
