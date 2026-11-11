<?php

use App\Models\IngestionKey;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

it('rejects the same external_id twice even with a different occurred_at (decision D2)', function () {
    $project = Project::factory()->create();

    IngestionKey::query()->create([
        'project_id' => $project->id,
        'external_id' => 'order-1001-paid',
        'log_uid' => (string) Str::ulid(),
        'occurred_at' => CarbonImmutable::now('UTC'),
    ]);

    expect(fn () => IngestionKey::query()->create([
        'project_id' => $project->id,
        'external_id' => 'order-1001-paid',
        'log_uid' => (string) Str::ulid(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMonth(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same external_id in two different projects', function () {
    [$a, $b] = Project::factory()->count(2)->create();

    foreach ([$a, $b] as $project) {
        IngestionKey::query()->create([
            'project_id' => $project->id,
            'external_id' => 'evt-1',
            'log_uid' => (string) Str::ulid(),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    expect(IngestionKey::query()->count())->toBe(2);
});
