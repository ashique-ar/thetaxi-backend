<?php

namespace App\Models;

use App\Traits\UUID;

class SystemConstant extends BaseModel
{
    

    protected $fillable = [
        'type',
        'value',
        'description',
        'created_user_id',
        'updated_user_id',
    ];
}
