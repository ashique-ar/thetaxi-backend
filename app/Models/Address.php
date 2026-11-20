<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\UUID;

class Address extends Model
{
    use UUID;

    protected $table = 'addresses';
    protected $fillable = ['label', 'type', 'address', 'city', 'state_id', 'country_id', 'state_name', 'country_name', 'postal_code', 'latitude', 'longitude', 'is_default'];
    protected $casts = ['is_default' => 'boolean'];

    public function addressable()
    {
        return $this->morphTo();
    }

    public function country() { return $this->belongsTo(Country::class); }
    public function state() { return $this->belongsTo(State::class); }
}
