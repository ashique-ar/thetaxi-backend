<?php

namespace App\Models\Tours;

use App\Models\Service\ServicePackage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourPackageTemplateVersion extends Model
{
    use HasUuids;
    protected $table = 'tour_package_template_versions';
    public $timestamps = false;
    protected $fillable = ['service_package_id', 'version', 'template', 'created_user_id', 'created_at'];
    protected $casts = ['template' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }
}
