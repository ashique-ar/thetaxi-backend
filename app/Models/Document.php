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
        'employment_spell_id',
        'document_type',
        'document_number',
        'upload_idempotency_key',
        'upload_request_checksum',
        'expiry_date',
        'disk',
        'path',
        'file_name',
        'file_size',
        'file_type',
        'status',
        'verification_notes',
        'classification',
        'version',
        'supersedes_id',
        'retention_until',
        'legal_hold',
        'verified_at',
        'verified_by',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        'file_size' => 'integer',
        'version' => 'integer',
        'retention_until' => 'datetime',
        'legal_hold' => 'boolean',
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
        static::forceDeleted(function (Document $document): void {
            Storage::disk($document->disk)->delete($document->path);
        });
    }
}
