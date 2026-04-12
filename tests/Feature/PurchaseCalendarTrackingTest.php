<?php

use App\Enums\Warehouse;
use App\Models\PurchaseCalendarEvent;
use App\Models\PurchaseCalendarRun;
use App\Models\Scenario;
use App\Services\Forecast\Supply\PurchaseCalendarService;
use App\Services\Forecast\Supply\PurchaseCalendarTrackingService;

it('persists a purchase calendar run with events', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $mockTimeline = [
        [
            'date' => '2026-04-15',
            'event_type' => 'purchase',
            'product_id' => null,
            'sku' => 'COMP-001',
            'name' => 'Test Component',
            'quantity' => 100,
            'gross_quantity' => 150,
            'net_quantity' => 100,
            'supplier' => 'Supplier A',
            'category' => 'chain',
            'month' => 'Apr',
            'scenario' => $scenario->name,
            'note' => 'Test note',
        ],
        [
            'date' => '2026-05-01',
            'event_type' => 'receipt',
            'product_id' => null,
            'sku' => 'COMP-001',
            'name' => 'Test Component',
            'quantity' => 100,
            'gross_quantity' => 150,
            'net_quantity' => 100,
            'supplier' => 'Supplier A',
            'category' => 'chain',
            'month' => 'May',
            'scenario' => $scenario->name,
            'note' => null,
        ],
    ];

    $mockResult = [
        'timeline' => $mockTimeline,
        'summary' => ['total_events' => 2, 'purchase_events' => 1, 'production_events' => 0, 'categories' => ['chain' => 2]],
        'netting' => ['chain' => []],
        'sku_mix' => ['chain' => []],
        'warehouse' => null,
    ];

    $calendarService = Mockery::mock(PurchaseCalendarService::class);
    $calendarService->shouldReceive('generate')
        ->once()
        ->with($scenario, 2026, null)
        ->andReturn($mockResult);

    $trackingService = new PurchaseCalendarTrackingService($calendarService);
    $run = $trackingService->record($scenario, 2026);

    expect($run)->toBeInstanceOf(PurchaseCalendarRun::class);
    expect($run->scenario_id)->toBe($scenario->id);
    expect($run->year)->toBe(2026);
    expect($run->warehouse)->toBeNull();
    expect($run->summary)->toBe($mockResult['summary']);
    expect($run->events)->toHaveCount(2);

    $purchase = $run->events->firstWhere('event_type', 'purchase');
    expect($purchase->sku)->toBe('COMP-001');
    expect((float) $purchase->quantity)->toBe(100.0);
    expect($purchase->supplier)->toBe('Supplier A');
});

it('replaces events on regeneration', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $makeResult = fn (int $count) => [
        'timeline' => array_map(fn ($i) => [
            'date' => "2026-04-{$i}",
            'event_type' => 'purchase',
            'product_id' => null,
            'sku' => "COMP-{$i}",
            'name' => "Component {$i}",
            'quantity' => $i * 10,
            'gross_quantity' => $i * 15,
            'net_quantity' => $i * 10,
            'supplier' => null,
            'category' => 'chain',
            'month' => 'Apr',
            'scenario' => $scenario->name,
            'note' => null,
        ], range(1, $count)),
        'summary' => ['total_events' => $count, 'purchase_events' => $count, 'production_events' => 0, 'categories' => []],
        'netting' => [],
        'sku_mix' => [],
        'warehouse' => null,
    ];

    $calendarService = Mockery::mock(PurchaseCalendarService::class);
    $calendarService->shouldReceive('generate')->twice()->andReturn(
        $makeResult(3),
        $makeResult(2),
    );

    $trackingService = new PurchaseCalendarTrackingService($calendarService);

    // First run
    $run1 = $trackingService->record($scenario, 2026);
    expect($run1->events)->toHaveCount(3);
    $runId = $run1->id;

    // Second run — should reuse same run record, replace events
    $run2 = $trackingService->record($scenario, 2026);
    expect($run2->id)->toBe($runId);
    expect($run2->events)->toHaveCount(2);

    // Only 1 run in DB
    expect(PurchaseCalendarRun::count())->toBe(1);
    // Only 2 events in DB (old 3 deleted)
    expect(PurchaseCalendarEvent::count())->toBe(2);
});

it('creates separate runs per warehouse', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $emptyResult = [
        'timeline' => [],
        'summary' => ['total_events' => 0, 'purchase_events' => 0, 'production_events' => 0, 'categories' => []],
        'netting' => [],
        'sku_mix' => [],
        'warehouse' => null,
    ];

    $calendarService = Mockery::mock(PurchaseCalendarService::class);
    $calendarService->shouldReceive('generate')->times(3)->andReturn($emptyResult);

    $trackingService = new PurchaseCalendarTrackingService($calendarService);

    $trackingService->record($scenario, 2026);
    $trackingService->record($scenario, 2026, Warehouse::Be);
    $trackingService->record($scenario, 2026, Warehouse::Us);

    expect(PurchaseCalendarRun::count())->toBe(3);
});

it('cascade deletes events when scenario is deleted', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $calendarService = Mockery::mock(PurchaseCalendarService::class);
    $calendarService->shouldReceive('generate')->once()->andReturn([
        'timeline' => [[
            'date' => '2026-04-01',
            'event_type' => 'purchase',
            'product_id' => null,
            'sku' => 'X',
            'name' => 'Y',
            'quantity' => 10,
            'gross_quantity' => 10,
            'net_quantity' => 10,
            'supplier' => null,
            'category' => 'chain',
            'month' => 'Apr',
            'scenario' => 'test',
            'note' => null,
        ]],
        'summary' => ['total_events' => 1, 'purchase_events' => 1, 'production_events' => 0, 'categories' => []],
        'netting' => [],
        'sku_mix' => [],
        'warehouse' => null,
    ]);

    $trackingService = new PurchaseCalendarTrackingService($calendarService);
    $trackingService->record($scenario, 2026);

    expect(PurchaseCalendarRun::count())->toBe(1);
    expect(PurchaseCalendarEvent::count())->toBe(1);

    $scenario->delete();

    expect(PurchaseCalendarRun::count())->toBe(0);
    expect(PurchaseCalendarEvent::count())->toBe(0);
});

it('stores scopes correctly on run model', function () {
    $s1 = Scenario::factory()->create(['year' => 2026, 'name' => 'base']);
    $s2 = Scenario::factory()->create(['year' => 2026, 'name' => 'ambitious']);

    PurchaseCalendarRun::create([
        'scenario_id' => $s1->id,
        'year' => 2026,
        'warehouse' => null,
        'generated_at' => now(),
        'summary' => [],
        'netting_summary' => [],
        'sku_mix_summary' => [],
    ]);
    PurchaseCalendarRun::create([
        'scenario_id' => $s2->id,
        'year' => 2026,
        'warehouse' => 'be',
        'generated_at' => now(),
        'summary' => [],
        'netting_summary' => [],
        'sku_mix_summary' => [],
    ]);

    expect(PurchaseCalendarRun::forScenario($s1)->count())->toBe(1);
    expect(PurchaseCalendarRun::forYear(2026)->count())->toBe(2);
    expect(PurchaseCalendarRun::forWarehouse(null)->count())->toBe(1);
    expect(PurchaseCalendarRun::forWarehouse(Warehouse::Be)->count())->toBe(1);
});
