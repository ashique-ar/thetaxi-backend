<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Document extends BaseModel
{
    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'document_type',
        'document_number',
        'expiry_date',
        'disk',
        'path',
        'file_name',
        'file_size',
        'file_type',
        'status',
        'verification_notes',
        'verified_at',
        'verified_by',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    protected static function booted(): void
    {
        static::deleting(function (Document $document): void {
            Storage::disk($document->disk)->delete($document->path);
        });
    }
}
