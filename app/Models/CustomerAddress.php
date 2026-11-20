<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    use UUID;

    protected $fillable = ['customer_id', 'label', 'type', 'address', 'city', 'state_id', 'country_id', 'postal_code', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
