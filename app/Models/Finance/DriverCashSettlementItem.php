<?php
namespace App\Models\Finance;
use App\Models\BaseModel;
class DriverCashSettlementItem extends BaseModel { protected $fillable=['driver_cash_settlement_id','payment_receipt_id','booking_id','amount']; }
