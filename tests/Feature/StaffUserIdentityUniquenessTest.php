<?php

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects duplicate active Staff identities for one User', function () {
    $user = User::factory()->create();
    Staff::factory()->create(['user_id' => $user->id]);

    expect(fn () => Staff::factory()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

it('allows a new active Staff identity after the previous one was soft-deleted', function () {
    $user = User::factory()->create();
    Staff::factory()->create(['user_id' => $user->id])->delete();

    Staff::factory()->create(['user_id' => $user->id]);

    expect(Staff::withTrashed()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Staff::where('user_id', $user->id)->count())->toBe(1);
});
