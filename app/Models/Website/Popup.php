<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Popup model for marketing popups displayed on the public website.
 * 
 * @property string $id
 * @property string $title
 * @property string $content
 * @property string|null $image
 * @property string|null $cta_text
 * @property string|null $cta_link
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property string $display_frequency
 * @property array $target_pages
 * @property int $priority
 * @property bool $is_active
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 */
class Popup extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'popups';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'content',
        'image',
        'cta_text',
        'cta_link',
        'start_date',
        'end_date',
        'display_frequency',
        'target_pages',
        'priority',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'target_pages' => 'array',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Append computed attributes to the model's array / JSON form
     */
    protected $appends = ['image_url'];

    /**
     * Get the full image URL for the popup using s3_asset helper
     */
    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        try {
            return s3_asset($this->image);
        } catch (\Exception $e) {
            // Fallback to returning the raw image path
            return $this->image;
        }
    }

    /**
     * Display frequency constants.
     */
    public const FREQUENCY_ALWAYS = 'always';
    public const FREQUENCY_ONCE_PER_SESSION = 'once_per_session';
    public const FREQUENCY_ONCE_PER_DAY = 'once_per_day';

    /**
     * Target page constants.
     */
    public const TARGET_ALL = 'all';
    public const TARGET_HOMEPAGE = 'homepage';
    public const TARGET_CHECKOUT = 'checkout';

    /**
     * Get the user who created this popup.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this popup.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Scope to get only active popups.
     * Active popups are those that are enabled and within their date range.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Scope to filter popups by target page.
     * Returns popups that target 'all' pages or the specific page.
     */
    public function scopeForPage(Builder $query, string $page): Builder
    {
        return $query->where(function (Builder $q) use ($page) {
            $q->whereJsonContains('target_pages', self::TARGET_ALL)
                ->orWhereJsonContains('target_pages', $page);
        });
    }

    /**
     * Scope to order by priority (highest first).
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'desc');
    }

    /**
     * Check if the popup is currently within its valid date range.
     */
    public function isWithinDateRange(): bool
    {
        $now = now();

        if ($this->start_date && $now->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the popup should be displayed on a given page.
     */
    public function shouldDisplayOnPage(string $page): bool
    {
        if (!$this->is_active || !$this->isWithinDateRange()) {
            return false;
        }

        $targetPages = $this->target_pages ?? [self::TARGET_ALL];

        return in_array(self::TARGET_ALL, $targetPages) || in_array($page, $targetPages);
    }

    /**
     * Get available display frequency options.
     */
    public static function getDisplayFrequencyOptions(): array
    {
        return [
            self::FREQUENCY_ALWAYS => 'Always',
            self::FREQUENCY_ONCE_PER_SESSION => 'Once per session',
            self::FREQUENCY_ONCE_PER_DAY => 'Once per day',
        ];
    }

    /**
     * Get available target page options.
     */
    public static function getTargetPageOptions(): array
    {
        return [
            self::TARGET_ALL => 'All pages',
            self::TARGET_HOMEPAGE => 'Homepage',
            self::TARGET_CHECKOUT => 'Checkout',
        ];
    }
}
