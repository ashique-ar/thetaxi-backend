<?php

namespace App\Models\Hr;

use App\Models\BaseModel;

class HrEpfEtfContributionPolicy extends BaseModel
{
    protected $table = 'hr_epf_etf_contribution_policies';

    protected $useUserTracking = false;

    protected $fillable = [
        'company_id', 'version', 'status',
        'employee_epf_rate_percent', 'employer_epf_rate_percent', 'employer_etf_rate_percent',
        'earnings_basis', 'statutory_reference', 'effective_from', 'effective_until',
        'reason', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'employee_epf_rate_percent' => 'decimal:2',
        'employer_epf_rate_percent' => 'decimal:2',
        'employer_etf_rate_percent' => 'decimal:2',
        'earnings_basis' => 'array',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
