<?php
namespace App\Models\Finance;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DriverCashSettlement extends BaseModel {
 protected $fillable=['settlement_number','driver_id','amount','status','due_date','handed_over_at','reference','proof_files','notes','received_by','created_user_id'];
 protected $casts=['amount'=>'decimal:2','due_date'=>'date','handed_over_at'=>'datetime','proof_files'=>'array'];
 public function items():HasMany{return $this->hasMany(DriverCashSettlementItem::class);}
}
