<?php

namespace App\Models;

class AgreementTemplate extends BaseModel
{
    protected $guarded = [];
    protected $casts = ['variables' => 'array', 'is_active' => 'boolean'];
}
