<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalCategory extends BaseModel
{
    protected $table = 'medical_categories';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'medical_category_id');
    }
}
