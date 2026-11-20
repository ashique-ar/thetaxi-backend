<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class AddressSyncService
{
    public function sync(Model $owner, array $addresses): void
    {
        $defaultIndex = collect($addresses)->search(fn ($address) => ! empty($address['is_default']));
        if ($defaultIndex === false && $addresses) $defaultIndex = 0;
        $kept = [];
        foreach (array_values($addresses) as $index => $address) {
            $id = $address['id'] ?? null;
            unset($address['id']);
            $address['is_default'] = $index === $defaultIndex;
            $record = $id
                ? $owner->addresses()->whereKey($id)->firstOrFail()
                : $owner->addresses()->make();
            $record->fill($address);
            $owner->addresses()->save($record);
            $kept[] = $record->id;
        }
        $owner->addresses()->whereNotIn('id', $kept ?: [''])->delete();
    }
}
