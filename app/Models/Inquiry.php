<?php

namespace App\Models;

use App\Models\Website\CmsContent;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * App\Models\Inquiry
 *
 * @property string $id Primary key (UUID)
 * @property string|null $customer_id Foreign key to customers table (optional)
 * @property string|null $subject Inquiry subject (optional)
 * @property string|null $message Inquiry message (optional)
 * @property string|null $status Inquiry status (optional)
 * @property string|null $priority Inquiry priority (optional)
 * @property string|null $response Response to inquiry (optional)
 * @property Carbon|null $responded_at Response date (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Customer|null $customer Customer who made the inquiry
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class Inquiry extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'agent_id',
        'assigned_to',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'priority',
        'response',
        'notes',
        'responded_at',
        'source',
        'payload',
        'created_user_id',
        'updated_user_id',
        // Inquiry number
        'inquiry_number',
        // Additional fields for quotation requests
        'inquiry_type',
        'inquiry_service_page_id',
        'cms_content_id',
        'vehicle_group_id',
        'service_type',
        'company_name',
        'search_context',
        'form_data',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'responded_at' => 'datetime',
        'payload' => 'array',
        'search_context' => 'array',
        'form_data' => 'array',
    ];

    // Relations

    /**
     * Get the customer who made this inquiry.
     *
     * @return BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the inquiry service page that generated this inquiry.
     */
    public function inquiryServicePage()
    {
        return $this->belongsTo(InquiryServicePage::class, 'inquiry_service_page_id')->withInactive()->withTrashed();
    }

    public function cmsContent()
    {
        return $this->belongsTo(CmsContent::class, 'cms_content_id')->withInactive()->withTrashed();
    }

    /**
     * Get the user who created this record.
     *
     * @return BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    /**
     * Generate inquiry number (INQxxxxxx)
     */
    public static function generateInquiryNumber(): string
    {
        $prefix = 'INQ';

        $max = static::withTrashed()
            ->where('inquiry_number', 'like', $prefix.'%')
            ->pluck('inquiry_number')
            ->reduce(function (int $carry, ?string $number) {
                if ($number && preg_match('/(\d{1,})$/', $number, $matches)) {
                    return max($carry, (int) $matches[1]);
                }

                return $carry;
            }, 0);

        $next = $max + 1;

        return $prefix.str_pad($next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Create an inquiry while recovering from concurrent number generation.
     *
     * The unique database constraint remains the authority. Two requests can
     * observe the same current maximum, so retry only that specific conflict
     * and allow the creating hook to calculate the next available number.
     */
    public static function createWithUniqueNumber(array $attributes, int $attempts = 5): static
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                unset($attributes['inquiry_number']);

                return static::create($attributes);
            } catch (QueryException $exception) {
                if (! static::isInquiryNumberCollision($exception)) {
                    throw $exception;
                }

                $lastException = $exception;
            }
        }

        throw $lastException ?? new \RuntimeException('Unable to allocate a unique inquiry number.');
    }

    private static function isInquiryNumberCollision(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = $exception->getMessage();

        return $sqlState === '23505'
            && (str_contains($message, 'inquiries_inquiry_number_unique')
                || str_contains($message, 'inquiry_number'));
    }

    /**
     * Boot method to set inquiry number on create
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($inquiry) {
            if (empty($inquiry->inquiry_number)) {
                $inquiry->inquiry_number = static::generateInquiryNumber();
            }
        });
    }
}
