<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;

class CorporateDivision extends BaseModel
{
    protected $table = 'corporate_divisions';

    protected $logName = 'CorporateDivision';

    protected $fillable = [
        'department_id',
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

    public function department()
    {
        return $this->belongsTo(CorporateDepartment::class, 'department_id');
    }

    public function corporate()
    {
        return $this->hasOneThrough(
            Corporate::class,
            CorporateDepartment::class,
            'id',           // corporate_departments.id
            'id',           // corporates.id
            'department_id', // corporate_divisions.department_id
            'corporate_id'  // corporate_departments.corporate_id
        );
    }

    public function employees()
    {
        return $this->hasMany(CorporateEmployee::class, 'division_id');
    }
}
