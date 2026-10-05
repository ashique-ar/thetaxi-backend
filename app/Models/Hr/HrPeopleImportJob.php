<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
use Spatie\Activitylog\Support\LogOptions;
class HrPeopleImportJob extends BaseModel
{
    protected $fillable=['company_id','mode','status','original_file_name','disk','path','file_checksum','mapping_version','row_count','accepted_count','rejected_count','reconciliation_totals','request_checksum','idempotency_key','created_by','committed_by','committed_at'];
    protected $casts=['reconciliation_totals'=>'array','committed_at'=>'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['company_id', 'mode', 'status', 'row_count', 'accepted_count', 'rejected_count'])
            ->logOnlyDirty()->dontLogEmptyChanges()->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('HrPeopleImportJob');
    }
}
