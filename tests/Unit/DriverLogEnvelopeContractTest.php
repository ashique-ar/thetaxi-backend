<?php

it('keeps active logsheet CRUD responses on one direct data resource envelope', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Driver/DriverLogController.php');

    expect(substr_count($source, "'data' => new DriverLogResource("))->toBe(6)
        ->and($source)->not->toContain("'data' => ['log' => new DriverLogResource(")
        ->and($source)->toContain('return DriverLogResource::collection($q->paginate(');
});
