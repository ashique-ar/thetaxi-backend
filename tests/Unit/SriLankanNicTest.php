<?php

use App\Support\SriLankanNic;

it('derives date of birth from old and new Sri Lankan NIC numbers', function (): void {
    expect(SriLankanNic::dateOfBirth('901230001V'))->toBe('1990-05-02')
        ->and(SriLankanNic::dateOfBirth('199012300001'))->toBe('1990-05-02')
        ->and(SriLankanNic::dateOfBirth('906230001X'))->toBe('1990-05-02')
        ->and(SriLankanNic::dateOfBirth('invalid'))->toBeNull();
});
