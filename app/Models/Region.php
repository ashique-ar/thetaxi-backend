<?php

namespace App\Models;

use App\Traits\UUID;

class Region extends BaseModel
{
    
    protected $fillable = ['name', 'description'];
    
}
