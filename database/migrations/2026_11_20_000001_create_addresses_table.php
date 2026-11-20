<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('addressable');
            $table->string('label', 100)->nullable();
            $table->string('type', 30)->default('other');
            $table->text('address');
            $table->string('city')->nullable();
            $table->foreignUuid('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignUuid('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('state_name')->nullable();
            $table->string('country_name')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['addressable_type', 'is_default']);
            $table->index(['addressable_type', 'latitude', 'longitude']);
        });
        foreach ([['customers', \App\Models\Customer::class], ['staff', \App\Models\Staff::class], ['drivers', \App\Models\Driver\Driver::class]] as [$table, $type]) {
            DB::table($table)->whereNotNull('address')->whereRaw("TRIM(address) <> ''")->orderBy('id')->chunkById(500, function ($owners) use ($type): void {
                foreach ($owners as $owner) {
                    DB::table('addresses')->insert([
                        'id' => (string) Str::uuid(), 'addressable_type' => $type, 'addressable_id' => $owner->id,
                        'label' => 'Primary address', 'type' => 'other', 'address' => $owner->address,
                        'city' => $owner->city ?? null, 'state_id' => $owner->state_id ?? null, 'country_id' => $owner->country_id ?? null,
                        'country_name' => $owner->country ?? null,
                        'postal_code' => $owner->postal_code ?? null, 'is_default' => true,
                        'created_at' => $owner->created_at ?? now(), 'updated_at' => $owner->updated_at ?? now(),
                    ]);
                }
            });
        }
        foreach ([['customers', \App\Models\Customer::class], ['staff', \App\Models\Staff::class], ['drivers', \App\Models\Driver\Driver::class]] as [$ownerTable, $ownerType]) {
            DB::table('billing_addresses as billing')->join($ownerTable.' as owner', 'owner.user_id', '=', 'billing.user_id')
                ->whereNull('billing.deleted_at')->whereNotNull('billing.address_line1')->whereRaw("TRIM(billing.address_line1) <> ''")
                ->orderBy('billing.id')->select([
                    'billing.id as billing_id', 'owner.id as owner_id', 'billing.address_line1', 'billing.address_line2',
                    'billing.city', 'billing.state', 'billing.postal_code', 'billing.country', 'billing.latitude',
                    'billing.longitude', 'billing.is_default', 'billing.created_at', 'billing.updated_at',
                ])->chunkById(500, function ($addresses) use ($ownerType): void {
                    foreach ($addresses as $address) {
                        $countryId = DB::table('countries')->whereRaw('UPPER(code) = ?', [strtoupper($address->country)])->value('id');
                        $stateId = $countryId ? DB::table('states')->where('country_id', $countryId)->whereRaw('LOWER(name) = ?', [strtolower((string) $address->state)])->value('id') : null;
                        $hasProfileDefault = DB::table('addresses')->where('addressable_type', $ownerType)->where('addressable_id', $address->owner_id)->where('is_default', true)->exists();
                        DB::table('addresses')->insert([
                            'id' => (string) Str::uuid(), 'addressable_type' => $ownerType, 'addressable_id' => $address->owner_id,
                            'label' => 'Billing address', 'type' => 'billing',
                            'address' => trim($address->address_line1.' '.($address->address_line2 ?? '')),
                            'city' => $address->city, 'state_id' => $stateId, 'country_id' => $countryId,
                            'state_name' => $address->state, 'country_name' => DB::table('countries')->where('id', $countryId)->value('name') ?: $address->country,
                            'postal_code' => $address->postal_code, 'latitude' => $address->latitude,
                            'longitude' => $address->longitude, 'is_default' => ! $hasProfileDefault && (bool) $address->is_default,
                            'created_at' => $address->created_at ?? now(), 'updated_at' => $address->updated_at ?? now(),
                        ]);
                    }
                }, 'billing_id', 'billing.id');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
