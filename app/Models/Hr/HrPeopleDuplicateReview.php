<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
use Spatie\Activitylog\Support\LogOptions;
class HrPeopleDuplicateReview extends BaseModel {protected $fillable=['company_id','match_kind','match_fingerprint','candidate_staff_ids','safe_candidate_snapshot','status','disposition','canonical_staff_id','reason','prepared_by','decided_by','decided_at','version','consolidation_status','consolidation_snapshot','consolidated_by','consolidated_at'];protected $casts=['candidate_staff_ids'=>'array','safe_candidate_snapshot'=>'array','decided_at'=>'datetime','version'=>'integer','consolidation_snapshot'=>'array','consolidated_at'=>'datetime'];
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'status', 'disposition', 'version', 'consolidation_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('HrPeopleDuplicateReview');
    }
}
