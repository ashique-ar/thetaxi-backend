<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait RecordsBookingHistoryAtomically
{
    protected function bookingHistoryConnectionName(): ?string
    {
        return $this->getConnectionName();
    }

    public function save(array $options = []): bool
    {
        return DB::connection($this->bookingHistoryConnectionName())->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return DB::connection($this->bookingHistoryConnectionName())->transaction(fn (): ?bool => parent::delete());
    }

    public function restore(): bool
    {
        return DB::connection($this->bookingHistoryConnectionName())->transaction(fn (): bool => parent::restore());
    }

    public function forceDelete(): ?bool
    {
        return DB::connection($this->bookingHistoryConnectionName())->transaction(fn (): ?bool => parent::forceDelete());
    }
}
