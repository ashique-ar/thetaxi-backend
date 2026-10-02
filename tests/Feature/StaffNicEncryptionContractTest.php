<?php

it('encrypts staff nic at rest behind a fingerprint-backed cast', function () {
    $model = file_get_contents(app_path('Models/Staff.php'));
    $cast = file_get_contents(app_path('Casts/EncryptedStaffIdentityValue.php'));

    expect($model)
        ->toContain("'nic' => EncryptedStaffIdentityValue::class")
        ->toContain("'nic',\n        'nic_fingerprint',")
        ->toContain('static::saving(function (Staff $staff): void {')
        ->toContain("hash('sha256', mb_strtolower(trim(\$staff->nic)))")
        ->and($cast)
        ->toContain("Crypt::encryptString((string) \$value)")
        ->toContain('Crypt::decryptString(substr((string) $value, strlen(self::PREFIX)))')
        ->not->toContain('if (str_starts_with($value, self::PREFIX))');
});

it('authors a reversible backfill migration for existing plaintext nic values', function () {
    $migration = file_get_contents(database_path('migrations/2026_09_03_130000_encrypt_staff_nic_identity_number.php'));

    expect($migration)
        ->toContain("\$table->dropIndex(['nic'])")
        ->toContain("\$table->text('nic')->nullable()->change()")
        ->toContain("\$table->char('nic_fingerprint', 64)")
        ->toContain('encryptExistingStaffNic')
        ->toContain('decryptExistingStaffNic')
        ->toContain("\$table->string('nic', 20)->nullable()->change()");
});

it('replaces substring nic search/sort with permission-gated exact fingerprint lookup', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));

    expect($controller)
        ->not->toContain("orWhereLikeInsensitive('nic', \$search)")
        ->not->toContain("orderBy('nic', \$sortDirection)")
        ->toContain("orWhere('nic_fingerprint', hash('sha256', mb_strtolower(trim(\$search))))");
});
