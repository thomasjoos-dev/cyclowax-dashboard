<?php

namespace App\Services\Forecast\Supply;

use App\Enums\Warehouse;
use App\Models\PurchaseCalendarEvent;
use App\Models\PurchaseCalendarRun;
use App\Models\Scenario;

class PurchaseCalendarTrackingService
{
    public function __construct(
        private PurchaseCalendarService $calendarService,
    ) {}

    /**
     * Generate and persist a purchase calendar for a scenario.
     *
     * Upserts the run record and replaces all events.
     * Returns the persisted run with its events.
     */
    public function record(Scenario $scenario, int $year, ?Warehouse $warehouse = null): PurchaseCalendarRun
    {
        $result = $this->calendarService->generate($scenario, $year, $warehouse);

        $run = PurchaseCalendarRun::updateOrCreate(
            [
                'scenario_id' => $scenario->id,
                'year' => $year,
                'warehouse' => $warehouse?->value,
            ],
            [
                'generated_at' => now(),
                'summary' => $result['summary'],
                'netting_summary' => $result['netting'],
                'sku_mix_summary' => $result['sku_mix'],
            ],
        );

        $run->events()->delete();
        $this->insertEvents($run, $result['timeline']);

        return $run->load('events');
    }

    /**
     * Bulk insert timeline events in chunks.
     *
     * @param  array<int, array>  $timeline
     */
    private function insertEvents(PurchaseCalendarRun $run, array $timeline): void
    {
        $rows = array_map(fn (array $event) => [
            'run_id' => $run->id,
            'date' => $event['date'],
            'event_type' => $event['event_type'],
            'product_id' => $event['product_id'],
            'sku' => $event['sku'],
            'name' => $event['name'],
            'quantity' => $event['quantity'],
            'gross_quantity' => $event['gross_quantity'],
            'net_quantity' => $event['net_quantity'],
            'supplier' => $event['supplier'] ?? null,
            'product_category' => $event['category'],
            'month_label' => $event['month'],
            'note' => $event['note'] ?? null,
        ], $timeline);

        foreach (array_chunk($rows, 500) as $chunk) {
            PurchaseCalendarEvent::insert($chunk);
        }
    }
}
