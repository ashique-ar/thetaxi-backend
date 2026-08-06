<?php

namespace App\Models;

class AgreementTemplate extends BaseModel
{
    protected $fillable = [
        'name',
        'type',
        'description',
        'content',
        'variables',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];
    protected $casts = ['variables' => 'array', 'is_active' => 'boolean'];
}
