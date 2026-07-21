<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RolePermissionCleanupUuidContractTest extends TestCase
{
    public function test_role_permission_cleanup_preserves_uuid_model_ids(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/RoleController.php');

        $cleanupSource = strstr($source, 'private function removeRoleDerivedDirectPermissions');
        $cleanupSource = strstr($cleanupSource, 'private function extractContextData', true);

        $this->assertNotFalse($cleanupSource);
        $this->assertStringNotContainsString('(int) $row->model_id', $cleanupSource);
        $this->assertSame(2, substr_count($cleanupSource, 'map(fn ($id) => (string) $id)'));
        $this->assertSame(2, substr_count($cleanupSource, 'groupBy(fn ($row) => (string) $row->model_id)'));
        $this->assertSame(2, substr_count($cleanupSource, '$modelId = (string) $row->model_id;'));
    }
}
