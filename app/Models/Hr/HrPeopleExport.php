<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrPeopleExport extends BaseModel
{
    protected $fillable=['company_id','disk','path','file_name','file_checksum','file_size','row_count','scope_checksum','idempotency_key','generated_by','generated_at','expires_at','download_count','last_downloaded_by','last_downloaded_at'];
    protected $casts=['generated_at'=>'datetime','expires_at'=>'datetime','last_downloaded_at'=>'datetime','file_size'=>'integer','row_count'=>'integer','download_count'=>'integer'];
}
