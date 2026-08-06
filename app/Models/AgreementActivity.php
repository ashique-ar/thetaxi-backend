<?php

namespace App\Models;

class AgreementActivity extends BaseModel
{
    protected $fillable = [
        'agreement_id',
        'type',
        'description',
        'metadata',
        'user_id',
        'created_user_id',
        'updated_user_id',
    ];
    protected $casts = ['metadata' => 'array'];
}
