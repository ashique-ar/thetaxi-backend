<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class AccountActivityController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:customers.view|drivers.view')->only('index');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_type' => ['nullable', 'in:customer,driver'],
            'event' => ['nullable', 'in:created,updated,deleted,restored'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $types = match ($data['account_type'] ?? null) {
            'customer' => [Customer::class],
            'driver' => [Driver::class],
            default => [Customer::class, Driver::class],
        };

        $profileQuery = ($data['account_type'] ?? null) === 'driver'
            ? Driver::withTrashed()->select('user_id')
            : (($data['account_type'] ?? null) === 'customer'
                ? Customer::withTrashed()->select('user_id')
                : Customer::withTrashed()->select('user_id')->union(Driver::withTrashed()->select('user_id')));

        $query = Activity::query()
            ->where(function (Builder $query) use ($types, $profileQuery) {
                $query->whereIn('subject_type', $types)
                    ->orWhere(fn (Builder $query) => $query->where('subject_type', User::class)->whereIn('subject_id', $profileQuery));
            })
            ->when($data['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($data['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->whereLikeInsensitive('description', $search)
                        ->orWhereLikeInsensitive('subject_id', $search)
                        ->orWhereLikeInsensitive('causer_id', $search);
                });
            })
            ->latest('created_at')
            ->paginate($data['per_page'] ?? 25);

        $actorIds = $query->getCollection()->pluck('causer_id')->filter()->unique();
        $actors = User::withTrashed()->whereIn('id', $actorIds)->get()->keyBy('id');
        $userIds = $query->getCollection()->where('subject_type', User::class)->pluck('subject_id');
        $customerProfiles = Customer::withTrashed()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $driverProfiles = Driver::withTrashed()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $deletedCustomers = Customer::onlyTrashed()->whereIn('id', $query->getCollection()->where('subject_type', Customer::class)->pluck('subject_id'))->pluck('id')->all();
        $deletedDrivers = Driver::onlyTrashed()->whereIn('id', $query->getCollection()->where('subject_type', Driver::class)->pluck('subject_id'))->pluck('id')->all();

        $query->through(function (Activity $activity) use ($actors, $customerProfiles, $driverProfiles, $deletedCustomers, $deletedDrivers) {
            $properties = $activity->properties->toArray();
            if ($activity->subject_type === User::class) {
                $properties = collect($properties)->map(fn ($values) => is_array($values)
                    ? collect($values)->only(['email', 'first_name', 'last_name', 'phone', 'role_id', 'agent_id', 'is_active', 'email_verified_at', 'phone_verified_at', 'status'])->all()
                    : $values)->all();
            }
            $attributes = $properties['attributes'] ?? [];
            $old = $properties['old'] ?? [];
            $snapshot = $attributes + $old;
            $actor = $actors->get($activity->causer_id);
            $customer = $activity->subject_type === User::class ? $customerProfiles->get($activity->subject_id) : null;
            $driver = $activity->subject_type === User::class ? $driverProfiles->get($activity->subject_id) : null;
            $profile = $customer ?? $driver;
            $accountType = $activity->subject_type === Customer::class || $customer ? 'customer' : 'driver';
            $accountId = $profile?->id ?? $activity->subject_id;

            return [
                'id' => $activity->id,
                'account_type' => $accountType,
                'account_id' => $accountId,
                'account_code' => $snapshot['code'] ?? $profile?->code,
                'event' => $activity->event ?: $activity->description,
                'description' => $activity->description,
                'actor' => $actor ? [
                    'id' => $actor->id,
                    'name' => trim($actor->first_name.' '.$actor->last_name),
                    'email' => $actor->email,
                    'deleted' => $actor->trashed(),
                ] : null,
                'changes' => $properties,
                'restorable' => $activity->event === 'deleted' && ($accountType === 'customer'
                    ? in_array($accountId, $deletedCustomers, true)
                    : in_array($accountId, $deletedDrivers, true)),
                'occurred_at' => $activity->created_at?->toIso8601String(),
            ];
        });

        return response()->json(['status' => 'success', 'data' => $query]);
    }

    public function restoreCustomer(string $customer): JsonResponse
    {
        abort_unless(request()->user()->can('customers.delete'), 403);
        $customer = Customer::onlyTrashed()->findOrFail($customer);

        DB::transaction(function () use ($customer) {
            User::withTrashed()->find($customer->user_id)?->restore();
            $customer->disableLogging();
            $customer->restore();
            $context = UserContext::withTrashed()->firstOrNew([
                'user_id' => $customer->user_id,
                'context_type' => 'customer',
                'context_id' => $customer->id,
            ]);
            $context->fill(['is_active' => true, 'updated_user_id' => request()->user()->id]);
            if ($context->exists && $context->trashed()) {
                $context->restore();
            }
            $context->save();
            \App\Models\Activity::create([
                'log_name' => 'Customer', 'description' => 'restored', 'event' => 'restored',
                'subject_type' => Customer::class, 'subject_id' => $customer->id,
                'causer_type' => User::class, 'causer_id' => request()->user()->id,
                'properties' => ['attributes' => $customer->getAttributes()],
            ]);
        });

        return response()->json(['status' => 'success', 'message' => 'Customer restored']);
    }

    public function restoreDriver(string $driver): JsonResponse
    {
        abort_unless(request()->user()->can('drivers.delete'), 403);
        $driver = Driver::onlyTrashed()->findOrFail($driver);

        DB::transaction(function () use ($driver) {
            User::withTrashed()->find($driver->user_id)?->restore();
            $driver->disableLogging();
            $driver->restore();
            $context = UserContext::withTrashed()->firstOrNew([
                'user_id' => $driver->user_id,
                'context_type' => 'driver',
                'context_id' => $driver->id,
            ]);
            $context->fill(['is_active' => true, 'updated_user_id' => request()->user()->id]);
            if ($context->exists && $context->trashed()) {
                $context->restore();
            }
            $context->save();
            \App\Models\Activity::create([
                'log_name' => 'Driver', 'description' => 'restored', 'event' => 'restored',
                'subject_type' => Driver::class, 'subject_id' => $driver->id,
                'causer_type' => User::class, 'causer_id' => request()->user()->id,
                'properties' => ['attributes' => $driver->getAttributes()],
            ]);
        });

        return response()->json(['status' => 'success', 'message' => 'Driver restored']);
    }
}
