<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TourClassPricingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['service_types', 'vehicle_groups', 'vehicle_classes'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->string('id')->primary());
        }
        DB::table('service_types')->insert(['id' => 'existing-service']);
        DB::table('vehicle_groups')->insert(['id' => 'group']);
        DB::table('vehicle_classes')->insert(['id' => 'standard']);
        foreach (['vehicle_group_pricing' => 'slab_definition_id', 'vehicle_group_common_rate_pricing' => 'common_rate_definition_id'] as $name => $definition) {
            Schema::create($name, function (Blueprint $table) use ($definition): void {
                $table->string('id')->primary();
                $table->string('vehicle_group_id')->nullable();
                $table->string($definition);
                $table->string('owner_type')->nullable();
                $table->string('owner_id')->nullable();
                $table->softDeletes();
            });
            DB::table($name)->insert(['id' => 'existing', 'vehicle_group_id' => 'group', $definition => 'definition']);
        }
    }

    public function test_additive_migration_keeps_existing_rates_and_group_pricing_defaults(): void
    {
        $this->migration()->up();
        self::assertTrue(Schema::hasColumn('vehicle_group_pricing', 'vehicle_class_id'));
        self::assertSame('vehicle_group', DB::table('service_types')->value('pricing_basis'));
        self::assertSame(0, (int) DB::table('service_types')->value('tour_booking_enabled'));
        self::assertSame('group', DB::table('vehicle_group_pricing')->where('id', 'existing')->value('vehicle_group_id'));
        DB::table('vehicle_group_pricing')->insert(['id' => 'class', 'vehicle_class_id' => 'standard', 'slab_definition_id' => 'definition']);
        self::assertSame(2, DB::table('vehicle_group_pricing')->count());
    }

    public function test_rate_cannot_target_both_a_class_and_a_group(): void
    {
        $this->migration()->up();
        $this->expectException(QueryException::class);
        DB::table('vehicle_group_pricing')->insert([
            'id' => 'invalid', 'vehicle_class_id' => 'standard', 'vehicle_group_id' => 'group', 'slab_definition_id' => 'definition',
        ]);
    }

    public function test_rate_cannot_have_no_target(): void
    {
        $this->migration()->up();
        $this->expectException(QueryException::class);
        DB::table('vehicle_group_common_rate_pricing')->insert(['id' => 'invalid', 'common_rate_definition_id' => 'definition']);
    }

    public function test_public_class_rate_uniqueness_handles_null_owner_scope(): void
    {
        $this->migration()->up();
        DB::table('vehicle_group_pricing')->insert(['id' => 'first', 'vehicle_class_id' => 'standard', 'slab_definition_id' => 'definition']);
        $this->expectException(QueryException::class);
        DB::table('vehicle_group_pricing')->insert(['id' => 'duplicate', 'vehicle_class_id' => 'standard', 'slab_definition_id' => 'definition']);
    }

    public function test_different_corporate_owners_can_have_their_own_class_rates(): void
    {
        $this->migration()->up();
        foreach (['company-a', 'company-b'] as $owner) {
            DB::table('vehicle_group_common_rate_pricing')->insert([
                'id' => $owner, 'vehicle_class_id' => 'standard', 'common_rate_definition_id' => 'definition', 'owner_type' => 'corporate', 'owner_id' => $owner,
            ]);
        }
        self::assertSame(3, DB::table('vehicle_group_common_rate_pricing')->count());
    }

    public function test_migration_refuses_orphan_legacy_rates_before_changing_schema(): void
    {
        DB::table('vehicle_group_pricing')->insert(['id' => 'orphan', 'slab_definition_id' => 'definition']);
        try {
            $this->migration()->up();
            self::fail('An orphan rate requires explicit repair before schema changes.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('vehicle_group_pricing', $exception->getMessage());
            self::assertFalse(Schema::hasColumn('service_types', 'pricing_basis'));
            self::assertSame(2, DB::table('vehicle_group_pricing')->count());
        }
    }

    public function test_rollback_refuses_to_delete_class_rate_agreements(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('vehicle_group_pricing')->insert(['id' => 'class', 'vehicle_class_id' => 'standard', 'slab_definition_id' => 'definition']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class');
        $migration->down();
    }

    public function test_existing_service_clone_recomputes_generated_rate_scopes_for_the_copied_definitions(): void
    {
        $this->migration()->up();
        Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('service_type_id');
        });
        DB::table('vehicle_pricing_slab_definitions')->insert([
            ['id' => 'definition', 'service_type_id' => 'source'],
            ['id' => 'copied-definition', 'service_type_id' => 'copy'],
        ]);
        DB::table('vehicle_group_pricing')->insert(['id' => 'class', 'vehicle_class_id' => 'standard', 'slab_definition_id' => 'definition']);
        $service = (new \ReflectionClass(\App\Services\ServiceTypeCloneService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'cloneTableWithServiceType');
        $copies = $method->invoke($service, 'vehicle_group_pricing', 'source', 'copy', null, [],
            ['slab_definition_id' => ['definition' => 'copied-definition']], 'vehicle_pricing_slab_definitions', 'slab_definition_id');

        self::assertCount(2, $copies);
        self::assertSame('standard', DB::table('vehicle_group_pricing')->where('id', $copies['class'])->value('vehicle_class_id'));
        self::assertStringContainsString('copied-definition', DB::table('vehicle_group_pricing')->where('id', $copies['class'])->value('class_rate_scope'));
        self::assertSame('group', DB::table('vehicle_group_pricing')->where('id', $copies['existing'])->value('vehicle_group_id'));
    }

    public function test_unused_class_schema_can_roll_back_without_losing_existing_group_rates(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        self::assertFalse(Schema::hasColumn('vehicle_group_pricing', 'vehicle_class_id'));
        self::assertFalse(Schema::hasColumn('service_types', 'pricing_basis'));
        self::assertSame('group', DB::table('vehicle_group_pricing')->where('id', 'existing')->value('vehicle_group_id'));
        self::assertSame('group', DB::table('vehicle_group_common_rate_pricing')->where('id', 'existing')->value('vehicle_group_id'));
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_11_02_000001_add_class_targets_to_vehicle_pricing.php');
    }
}
