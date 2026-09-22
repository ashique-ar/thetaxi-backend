<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessNumberSequence extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'entity';
    protected $keyType = 'string';
    protected $fillable = ['entity', 'next_number'];
}
