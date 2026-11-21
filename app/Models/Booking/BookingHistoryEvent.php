<?php

namespace App\Models\Booking;

use App\Models\BaseModel;

class BookingHistoryEvent extends BaseModel
{
    protected $table = 'booking_history_events';

    protected $guarded = [];

    protected $useUserTracking = false;

    public $timestamps = true;

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Booking history events are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Booking history events are append-only.');
    }

    public function forceDelete(): ?bool
    {
        throw new \LogicException('Booking history events are append-only.');
    }

    public function restore(): bool
    {
        throw new \LogicException('Booking history events are append-only.');
    }
}
