<?php

namespace App\Models\Service;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ServiceFormConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'service_code',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
    ];

    public static function forCode(string $code): ?self
    {
        return static::where('service_code', $code)->where('is_active', true)->first();
    }
}
