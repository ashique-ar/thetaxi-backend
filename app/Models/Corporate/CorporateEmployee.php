<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\User;
use App\Models\UserContext;

class CorporateEmployee extends BaseModel
{
    protected $table = 'corporate_employees';

    protected $logName = 'CorporateEmployee';

    protected $fillable = [
        'user_id',
        'corporate_id',
        'department_id',
        'division_id',
        'employee_code',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function corporate()
    {
        return $this->belongsTo(Corporate::class, 'corporate_id');
    }

    public function department()
    {
        return $this->belongsTo(CorporateDepartment::class, 'department_id');
    }

    public function division()
    {
        return $this->belongsTo(CorporateDivision::class, 'division_id');
    }

    public function userContext()
    {
        return $this->hasOne(UserContext::class, 'context_id')
            ->where('context_type', 'corporate');
    }
}
