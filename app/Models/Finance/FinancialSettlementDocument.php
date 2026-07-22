<?php
namespace App\Models\Finance;
use App\Models\BaseModel;
class FinancialSettlementDocument extends BaseModel { protected $fillable=['settlement_id','invoice_number','status','pdf_path','pdf_disk','generated_at','sent_at','sent_to','last_error']; protected $casts=['generated_at'=>'datetime','sent_at'=>'datetime']; }
