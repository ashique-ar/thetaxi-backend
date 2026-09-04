<?php

it('encrypts all remaining Staff personal columns and preserves date semantics', function () {
    $model = file_get_contents(app_path('Models/Staff.php'));
    $dateCast = file_get_contents(app_path('Casts/EncryptedStaffDateValue.php'));

    expect($model)
        ->toContain("'dob' => EncryptedStaffDateValue::class")
        ->toContain("'license_expiry' => EncryptedStaffDateValue::class")
        ->toContain("'license_no' => EncryptedStaffIdentityValue::class")
        ->toContain("'address' => EncryptedStaffIdentityValue::class")
        ->toContain("'license_no_fingerprint'")
        ->toContain("->logOnly(array_values(array_diff(\$this->getFillable(), [")
        ->and($dateCast)
        ->toContain("Carbon::createFromFormat('Y-m-d', \$plain)")
        ->toContain("Crypt::encryptString(\$plain)");
});

it('authors a reversible migration without searchable personal ciphertext', function () {
    $migration = file_get_contents(database_path('migrations/2026_09_03_140000_encrypt_remaining_staff_personal_data.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));

    expect($migration)
        ->toContain("private const FIELDS = ['dob', 'license_no', 'license_expiry', 'address']")
        ->toContain("\$table->dropUnique(['license_no'])")
        ->toContain("\$table->unique('license_no_fingerprint')")
        ->toContain('assertRollbackFitsLegacyColumns')
        ->toContain('chunkById(')
        ->toContain('$this->transform(true)')
        ->and($controller)
        ->toContain("orWhere('license_no_fingerprint'")
        ->not->toContain("orWhereLikeInsensitive('license_no', \$search)")
        ->not->toContain("orWhereLikeInsensitive('address', \$search)");
});
