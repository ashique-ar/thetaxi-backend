<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

class Agreement extends BaseModel
{
    protected $guarded = [];
    protected $casts = ['parties' => 'array', 'signatures' => 'array', 'metadata' => 'array', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'auto_renew' => 'boolean', 'signed_at' => 'datetime'];

    public function documents() { return $this->morphMany(Document::class, 'documentable'); }
    public function activities() { return $this->hasMany(AgreementActivity::class); }

    protected static function booted(): void
    {
        static::deleting(function (Agreement $agreement): void {
            $agreement->documents()->get()->each->delete();
            foreach ($agreement->signatures ?? [] as $signature) {
                if (isset($signature['signature'], $signature['signature_disk'])) {
                    Storage::disk($signature['signature_disk'])->delete($signature['signature']);
                }
            }
        });
    }
}
