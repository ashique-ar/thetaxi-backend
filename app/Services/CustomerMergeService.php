<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Models\UserContext;
use App\Support\PhoneNumberMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerMergeService
{
    private const CUSTOMER_REFERENCES = [
        'bookings', 'inquiries', 'billing_addresses', 'customer_loyalty_points',
        'loyalty_point_transactions', 'booking_searches', 'carts', 'promo_code_usages',
        'invoices', 'booking_customer_mobile_activities', 'customer_devices',
        'sales_booking_attributions', 'sales_opportunities', 'sales_activities',
        'sales_tasks', 'phone_calls',
    ];

    public function merge(Customer $primary, Customer $duplicate, string $companyId, string $actorId, string $reason): Customer
    {
        if ((string) $primary->id === (string) $duplicate->id) {
            throw ValidationException::withMessages(['customer_id' => ['Choose two different customer records.']]);
        }

        return DB::transaction(function () use ($primary, $duplicate, $companyId, $actorId, $reason): Customer {
            $locked = Customer::query()->whereIn('id', [$primary->id, $duplicate->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $primary = $locked->get($primary->id);
            $duplicate = $locked->get($duplicate->id);
            abort_unless($primary && $duplicate, 404, 'One of these customer records is no longer available.');
            $this->assertSingleCompanyMerge($primary, $duplicate, $companyId);

            $sourceUser = User::query()->whereKey($duplicate->user_id)->lockForUpdate()->firstOrFail();
            $alias = DB::table('customer_user_aliases')->where('user_id', $sourceUser->id)->first();
            if ($alias && (string) $alias->customer_id !== (string) $primary->id) {
                throw ValidationException::withMessages(['customer_id' => ['This login is already linked to another customer profile.']]);
            }

            $primaryIdentities = $this->identityUsers($primary);
            $duplicateIdentities = $this->identityUsers($duplicate);
            $emailMatches = $primaryIdentities->contains(fn ($left) => filled($left->email)
                && $duplicateIdentities->contains(fn ($right) => strtolower(trim((string) $left->email)) === strtolower(trim((string) $right->email))));
            $phoneMatches = $primaryIdentities->contains(fn ($left) => PhoneNumberMatch::normalized((string) $left->phone) !== ''
                && $duplicateIdentities->contains(fn ($right) => PhoneNumberMatch::normalized((string) $left->phone) === PhoneNumberMatch::normalized((string) $right->phone)));
            if (! $emailMatches && ! $phoneMatches) {
                throw ValidationException::withMessages(['customer_id' => ['The records no longer share a matching email or phone. Review them again before merging.']]);
            }

            if (DB::table('customer_duplicate_decisions')->where('company_id', $companyId)
                ->where('customer_id', $primary->id)->where('other_customer_id', $duplicate->id)->exists()) {
                throw ValidationException::withMessages(['customer_id' => ['This customer pair already has a review decision.']]);
            }

            $this->moveRelationshipOwnership($primary, $duplicate, $actorId);
            foreach (self::CUSTOMER_REFERENCES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'customer_id')) {
                    DB::table($table)->where('customer_id', $duplicate->id)->update(['customer_id' => $primary->id]);
                }
            }
            if (Schema::hasTable('integration_contact_messages') && Schema::hasColumn('integration_contact_messages', 'linked_customer_id')) {
                DB::table('integration_contact_messages')->where('linked_customer_id', $duplicate->id)
                    ->update(['linked_customer_id' => $primary->id]);
            }
            if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'documentable_id')) {
                DB::table('documents')->where('documentable_type', $duplicate->getMorphClass())
                    ->where('documentable_id', $duplicate->id)->update(['documentable_id' => $primary->id]);
            }
            if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'payable_id')) {
                DB::table('payment_methods')->where('payable_type', $duplicate->getMorphClass())
                    ->where('payable_id', $duplicate->id)->update(['payable_id' => $primary->id]);
            }

            $contexts = UserContext::query()->where('user_id', $sourceUser->id)->where('context_type', 'customer')
                ->where('context_id', $duplicate->id)->get();
            foreach ($contexts as $context) {
                $alreadyLinked = UserContext::query()->where('user_id', $sourceUser->id)->where('context_type', 'customer')
                    ->where('context_id', $primary->id)->where('id', '!=', $context->id)->exists();
                $alreadyLinked
                    ? $context->update(['is_active' => false, 'updated_user_id' => $actorId])
                    : $context->update(['context_id' => $primary->id, 'updated_user_id' => $actorId]);
            }

            if ((string) $sourceUser->id !== (string) $primary->user_id) {
                DB::table('customer_user_aliases')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'source_customer_id' => $duplicate->id, 'customer_id' => $primary->id,
                    'user_id' => $sourceUser->id, 'merged_by_user_id' => $actorId,
                    'reason' => trim($reason), 'created_at' => now(), 'updated_at' => now(),
                ]);
                abort_unless(DB::table('customer_user_aliases')->where('user_id', $sourceUser->id)
                    ->where('customer_id', $primary->id)->exists(), 409, 'This login is already linked to another customer profile.');
            }
            DB::table('customer_duplicate_decisions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'customer_id' => $primary->id, 'other_customer_id' => $duplicate->id,
                'canonical_customer_id' => $primary->id, 'reviewed_by_user_id' => $actorId,
                'decision' => 'same_person_merged', 'reason' => trim($reason),
                'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $duplicate->updated_user_id = $actorId;
            $duplicate->delete();
            activity('customer-duplicate-review')->causedBy(User::query()->find($actorId))->performedOn($primary)
                ->withProperties(['company_id' => $companyId, 'merged_customer_id' => $duplicate->id, 'merged_user_id' => $sourceUser->id])
                ->log('customer_duplicate_merged');

            return $primary->fresh(['user', 'state', 'paymentMethods']);
        });
    }

    private function assertSingleCompanyMerge(Customer $primary, Customer $duplicate, string $companyId): void
    {
        foreach (DB::table('companies')->pluck('id') as $candidateCompanyId) {
            foreach ([$primary, $duplicate] as $customer) {
                $query = Customer::query()->whereKey($customer->id);
                CompanyCustomerScope::constrain($query, 'customers', (string) $candidateCompanyId);
                if ($query->exists() && (string) $candidateCompanyId !== $companyId) {
                    throw ValidationException::withMessages(['customer_id' => ['These customer records are used by more than one company. Ask a system administrator to review the cross-company records.']]);
                }
            }
        }

        foreach ([
            ['customer_relationship_owner_assignments', 'customer_id'], ['inquiries', 'customer_id'],
            ['sales_opportunities', 'customer_id'], ['sales_booking_attributions', 'customer_id'],
            ['phone_calls', 'customer_id'], ['integration_contact_messages', 'linked_customer_id'],
            ['sales_activities', 'customer_id'], ['sales_tasks', 'customer_id'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'company_id')) continue;
            $otherCompanyHasReferences = DB::table($table)->whereIn($column, [$primary->id, $duplicate->id])
                ->where(fn ($scope) => $scope->whereNull('company_id')->orWhere('company_id', '!=', $companyId))->exists();
            if ($otherCompanyHasReferences) {
                throw ValidationException::withMessages(['customer_id' => ['These customer records have activity in another company. Ask a system administrator to review the cross-company records.']]);
            }
        }
    }

    private function identityUsers(Customer $customer)
    {
        $userIds = DB::table('customer_user_aliases')->where('customer_id', $customer->id)->pluck('user_id')->push($customer->user_id)->unique();
        return User::withTrashed()->whereIn('id', $userIds)->get(['id', 'email', 'phone']);
    }

    private function moveRelationshipOwnership(Customer $primary, Customer $duplicate, string $actorId): void
    {
        if (! Schema::hasTable('customer_relationship_owner_assignments')) return;

        $current = DB::table('customer_relationship_owner_assignments')->whereIn('customer_id', [$primary->id, $duplicate->id])
            ->whereNull('ended_at')->orderByRaw('CASE WHEN customer_id = ? AND owner_sales_profile_id IS NOT NULL THEN 0 WHEN customer_id = ? AND owner_sales_profile_id IS NOT NULL THEN 1 ELSE 2 END', [$primary->id, $duplicate->id])
            ->orderByDesc('effective_from')->get();
        $keepId = $current->first(fn ($row) => $row->owner_sales_profile_id !== null)?->id;
        $now = now();

        DB::table('customer_relationship_owner_assignments')->whereIn('customer_id', [$primary->id, $duplicate->id])
            ->whereNull('ended_at')->update(['ended_at' => $now, 'ended_by_user_id' => $actorId, 'updated_at' => $now]);
        DB::table('customer_relationship_owner_assignments')->where('customer_id', $duplicate->id)
            ->update(['customer_id' => $primary->id, 'updated_at' => $now]);
        if ($keepId) {
            DB::table('customer_relationship_owner_assignments')->where('id', $keepId)
                ->update(['ended_at' => null, 'ended_by_user_id' => null, 'updated_at' => $now]);
        }
    }
}
