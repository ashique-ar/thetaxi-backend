<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;

class CorporateDepartment extends BaseModel
{
    protected $table = 'corporate_departments';

    protected $logName = 'CorporateDepartment';

    protected $fillable = [
        'corporate_id',
        'name',
        'description',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships

    public function corporate()
    {
        return $this->belongsTo(Corporate::class, 'corporate_id');
    }

    public function divisions()
    {
        return $this->hasMany(CorporateDivision::class, 'department_id');
    }

    public function employees()
    {
        return $this->hasMany(CorporateEmployee::class, 'department_id');
    }
}
