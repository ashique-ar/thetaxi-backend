<?php

namespace App\Models\Hr;

use App\Models\BaseModel;

class HrPeopleIdentityLink extends BaseModel
{
    protected $fillable = ['company_id', 'duplicate_review_id', 'alias_staff_id', 'canonical_staff_id', 'status', 'reason', 'evidence_checksum', 'approved_by', 'approved_at'];
    protected $casts = ['approved_at' => 'datetime'];
}
