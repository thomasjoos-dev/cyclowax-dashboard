<?php

namespace App\Services\Forecast\Supply;

use App\Models\PurchaseCalendarRun;
use App\Models\Scenario;
use App\Models\SupplyProfile;
use App\Services\Support\AnalysisPdfService;
use Illuminate\Support\Collection;

class PurchaseCalendarPdfService
{
    /** @var array<string, string> */
    private const array EVENT_LABELS = [
        'purchase' => 'ORDER',
        'receipt' => 'RECEIPT',
        'production_start' => 'PROD START',
        'production_done' => 'PROD DONE',
    ];

    public function __construct(
        private readonly AnalysisPdfService $pdf,
        private readonly ComponentNettingService $netting,
    ) {}

    /**
     * Generate one PDF per scenario and save to Desktop.
     *
     * DomPDF cannot handle all scenarios in a single document due to memory
     * constraints with large event counts, so we render per scenario.
     *
     * @param  Collection<int, Scenario>  $scenarios
     * @param  array<int, PurchaseCalendarRun>  $runs  keyed by scenario id
     * @param  array<int, int>|null  $months  optional month filter (e.g. [4,5,6] for Q2)
     * @return array<string, array{final: string, desktop: string}> keyed by scenario name
     */
    public function generate(Collection $scenarios, array $runs, int $year, ?array $months = null): array
    {
        $results = [];

        foreach ($scenarios as $scenario) {
            $run = $runs[$scenario->id] ?? null;

            if (! $run) {
                continue;
            }

            if ($months) {
                $run->setRelation(
                    'events',
                    $run->events->filter(fn ($e) => in_array($e->date->month, $months)),
                );
            }

            $sections = [
                ...$this->introSection(),
                ['type' => 'page-break'],
                ...$this->scenarioHeader($scenario, $run),
                ...$this->nettingSection($run),
                ...$this->timelineSection($run),
                ['type' => 'page-break'],
                ...$this->supplyChainSection(),
            ];

            $periodLabel = $months
                ? date('M', mktime(0, 0, 0, $months[0], 1)).'-'.date('M', mktime(0, 0, 0, end($months), 1))
                : 'full-year';

            $data = [
                'title' => "Purchase & Production Calendar {$year}",
                'subtitle' => "{$scenario->label} — {$periodLabel} — Inkoop- en productiekalender op basis van demand forecast, BOM explosie en stock netting.",
                'context' => 'Operations & Supply Chain',
                'quote' => 'Always a clean chain',
                'landscape' => true,
                'intro' => $this->buildIntroText($scenario, $run),
                'sections' => $sections,
            ];

            $slug = str($scenario->name)->slug();
            $periodSlug = $months ? 'm'.implode('-', [$months[0], end($months)]) : 'full';
            $filename = "purchase-calendar-{$year}-{$slug}-{$periodSlug}";
            $draftPath = $this->pdf->save($data, "{$filename}_draft-1.pdf");

            $results[$scenario->name] = $this->pdf->finalize($draftPath, "{$filename}.pdf");
        }

        return $results;
    }

    /**
     * Build the intro paragraph shown below the title.
     */
    private function buildIntroText(Scenario $scenario, PurchaseCalendarRun $run): string
    {
        $summary = $run->summary;
        $freshness = $this->netting->stockFreshness();
        $stockAge = $freshness['latest_at']
            ? $freshness['latest_at']->format('d M Y H:i')." ({$freshness['age_hours']}u geleden)"
            : 'geen data';

        return 'Dit rapport is gegenereerd door de <strong>Cyclowax DTC Intelligence Agent</strong>. '
            .'Het toont wanneer inkoop- en productieorders geplaatst moeten worden om aan de verwachte vraag te voldoen '
            ."onder het <strong>{$scenario->label}</strong> scenario. "
            ."De kalender bevat <strong>{$summary['total_events']}</strong> events. "
            ."Voorraaddata: {$stockAge}.";
    }

    /**
     * Build KPI metric cards for the top of the PDF.
     *
     * @return array<int, array{label: string, value: string, change?: string}>
     */
    private function buildMetrics(PurchaseCalendarRun $run): array
    {
        $summary = $run->summary;

        // Find earliest shortfall month across all netting entries
        $firstShortfall = null;
        foreach ($run->netting_summary as $components) {
            foreach ($components as $comp) {
                $month = $comp['first_shortfall_month'] ?? null;
                if ($month !== null && ($firstShortfall === null || $month < $firstShortfall)) {
                    $firstShortfall = $month;
                }
            }
        }

        $shortfallLabel = $firstShortfall
            ? date('F', mktime(0, 0, 0, (int) $firstShortfall, 1))
            : 'Geen tekort';

        // Count components that need ordering
        $orderCount = 0;
        foreach ($run->netting_summary as $components) {
            foreach ($components as $comp) {
                if (($comp['net_need'] ?? 0) > 0) {
                    $orderCount++;
                }
            }
        }

        return [
            [
                'label' => 'Purchase Orders',
                'value' => (string) $summary['purchase_events'],
                'change' => $summary['production_events'].' productieorders',
            ],
            [
                'label' => 'Eerste Tekort',
                'value' => $shortfallLabel,
                'change' => $orderCount.' componenten te bestellen',
            ],
            [
                'label' => 'Voorraaddata',
                'value' => $this->stockFreshnessLabel(),
                'change' => 'Laatste Odoo sync',
            ],
        ];
    }

    /**
     * Get a human-readable stock freshness label.
     */
    private function stockFreshnessLabel(): string
    {
        $freshness = $this->netting->stockFreshness();

        if ($freshness['latest_at'] === null) {
            return 'Geen data';
        }

        $hours = $freshness['age_hours'];

        if ($hours < 1) {
            return '< 1u oud';
        }

        if ($hours < 24) {
            return $hours.'u oud';
        }

        return round($hours / 24).'d oud';
    }

    /**
     * Intro sections: "Hoe deze kalender werkt" + "Begrippen".
     *
     * @return array<int, array<string, mixed>>
     */
    private function introSection(): array
    {
        return [
            ['type' => 'heading', 'content' => 'Hoe deze kalender werkt'],
            [
                'type' => 'text',
                'content' => 'Deze kalender plant niet simpelweg op basis van historische inkoop. Het model kijkt naar vijf databronnen en combineert die tot een concreet inkoopplan per product:',
            ],
            [
                'type' => 'list',
                'items' => [
                    '<strong>Demand forecast.</strong> Op basis van klantgedrag, seizoenspatronen en campagne-effecten berekent het model hoeveel units per productcategorie per maand verkocht worden.',
                    '<strong>SKU mix.</strong> De totale vraag per categorie wordt verdeeld over individuele producten op basis van de verkoopverdeling van de afgelopen 12 maanden. Scenario-specifieke overrides kunnen deze mix aanpassen.',
                    '<strong>BOM explosie.</strong> Elk verkoopproduct wordt opgesplitst in de benodigde componenten en halffabricaten via de Bill of Materials. Het model volgt meerdere niveaus: een kit bevat producten, een product bevat componenten.',
                    '<strong>Stock netting.</strong> Van de bruto componentbehoefte wordt de huidige voorraad en openstaande inkooporders uit Odoo afgetrokken. Wat overblijft is de netto behoefte — wat daadwerkelijk besteld moet worden.',
                    '<strong>Backward scheduling.</strong> Vanaf de maand waarin het product nodig is, rekent het model terug met procurement lead time (leverancier) en assembly lead time (eigen productie). Zo ontstaan concrete datums voor inkooporders, ontvangsten en productiestarts.',
                ],
            ],
            ['type' => 'heading', 'content' => 'Begrippen in dit rapport'],
            [
                'type' => 'list',
                'items' => [
                    '<strong>Gross Need:</strong> De totale componentbehoefte op basis van de demand forecast en BOM explosie, vóór aftrek van voorraad.',
                    '<strong>Net Need:</strong> Wat overblijft na aftrek van huidige stock en openstaande inkooporders. Dit is wat daadwerkelijk besteld moet worden.',
                    '<strong>Netting:</strong> Het proces van bruto behoefte minus voorraad minus open PO\'s = netto behoefte.',
                    '<strong>Lead Time:</strong> De doorlooptijd in dagen. Procurement LT = tijd van bestelling tot levering door leverancier. Assembly LT = tijd voor eigen assemblage.',
                    '<strong>MOQ (Minimum Order Quantity):</strong> De minimale bestelhoeveelheid bij een leverancier.',
                    '<strong>Buffer Days:</strong> Extra dagen marge bovenop de lead time, als veiligheidsbuffer.',
                    '<strong>ORDER:</strong> Inkooporder plaatsen bij leverancier — de datum waarop besteld moet worden.',
                    '<strong>RECEIPT:</strong> Verwachte ontvangst van goederen van leverancier.',
                    '<strong>PROD START:</strong> Start van eigen assemblage van halffabricaten of eindproducten.',
                    '<strong>PROD DONE:</strong> Assemblage afgerond, product beschikbaar voor verkoop of volgende stap.',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scenarioHeader(Scenario $scenario, PurchaseCalendarRun $run): array
    {
        $assumptions = $scenario->assumptions()
            ->whereNull('region')
            ->orderBy('quarter')
            ->get();

        $sections = [
            ['type' => 'heading', 'content' => "Scenario: {$scenario->label}"],
            ['type' => 'text', 'content' => $scenario->description ?? ''],
            ['type' => 'subheading', 'content' => 'Scenario Parameters'],
        ];

        $paramRows = [];
        foreach ($assumptions as $assumption) {
            $paramRows[] = [
                $assumption->quarter,
                number_format((float) $assumption->acq_rate * 100, 0).'%',
                number_format((float) $assumption->repeat_rate * 100, 0).'%',
                '€'.number_format((float) $assumption->repeat_aov, 0),
            ];
        }

        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Quarter'],
                ['label' => 'Acq Rate', 'align' => 'text-right'],
                ['label' => 'Repeat Rate', 'align' => 'text-right'],
                ['label' => 'Repeat AOV', 'align' => 'text-right'],
            ],
            'rows' => $paramRows,
        ];

        return $sections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nettingSection(PurchaseCalendarRun $run): array
    {
        $sections = [
            ['type' => 'subheading', 'content' => 'Component Netting'],
        ];

        $rows = [];
        foreach ($run->netting_summary as $category => $components) {
            foreach ($components as $comp) {
                if (($comp['gross_need'] ?? 0) <= 0) {
                    continue;
                }

                $shortfallMonth = $comp['first_shortfall_month'] ?? null;
                $shortfallLabel = $shortfallMonth
                    ? date('M', mktime(0, 0, 0, (int) $shortfallMonth, 1))
                    : '-';

                $netNeed = $comp['net_need'] ?? 0;
                $statusClass = $netNeed > 0 ? 'highlight' : '';

                $rows[] = [
                    $category,
                    $comp['sku'] ?? '-',
                    substr($comp['name'] ?? '', 0, 30),
                    ['value' => number_format($comp['gross_need']), 'class' => 'text-right'],
                    ['value' => number_format($comp['stock_available'] ?? 0), 'class' => 'text-right'],
                    ['value' => number_format($comp['open_po_qty'] ?? 0), 'class' => 'text-right'],
                    ['value' => number_format($netNeed), 'class' => "text-right {$statusClass}"],
                    $shortfallLabel,
                    ['value' => $netNeed > 0 ? 'ORDER' : 'OK', 'class' => $statusClass],
                ];
            }
        }

        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Category'],
                ['label' => 'SKU'],
                ['label' => 'Product'],
                ['label' => 'Gross Need', 'align' => 'text-right'],
                ['label' => 'Stock', 'align' => 'text-right'],
                ['label' => 'Open PO', 'align' => 'text-right'],
                ['label' => 'Net Need', 'align' => 'text-right'],
                ['label' => '1st Short'],
                ['label' => 'Status'],
            ],
            'rows' => $rows,
        ];

        $sections[] = ['type' => 'page-break'];

        return $sections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timelineSection(PurchaseCalendarRun $run): array
    {
        $sections = [
            ['type' => 'subheading', 'content' => 'Purchase & Production Timeline'],
        ];

        $eventsByMonth = $run->events->groupBy('month_label');

        foreach ($eventsByMonth as $month => $events) {
            $sections[] = ['type' => 'subheading', 'content' => $month];

            $rows = [];
            foreach ($events->sortBy('date') as $event) {
                $label = self::EVENT_LABELS[$event->event_type] ?? $event->event_type;

                $rows[] = [
                    $event->date->format('Y-m-d'),
                    ['value' => $label, 'class' => 'bold'],
                    $event->sku ?: '-',
                    substr($event->name, 0, 35),
                    ['value' => number_format((float) $event->quantity), 'class' => 'text-right'],
                    $event->supplier ?? '-',
                    substr($event->note ?? '', 0, 50),
                ];
            }

            $sections[] = [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Date'],
                    ['label' => 'Type'],
                    ['label' => 'SKU'],
                    ['label' => 'Product'],
                    ['label' => 'Qty', 'align' => 'text-right'],
                    ['label' => 'Supplier'],
                    ['label' => 'Note'],
                ],
                'rows' => $rows,
            ];
        }

        $sections[] = ['type' => 'page-break'];

        return $sections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function supplyChainSection(): array
    {
        $profiles = SupplyProfile::orderBy('product_category')->get();

        $sections = [
            ['type' => 'heading', 'content' => 'Supply Chain Parameters'],
            ['type' => 'text', 'content' => 'Lead times, MOQ en leveranciersconfiguratie per productcategorie. Deze parameters bepalen de backward scheduling van inkoop- en productieorders.'],
        ];

        $rows = [];
        foreach ($profiles as $profile) {
            $validated = $profile->validated_at
                ? $profile->validated_at->format('d M Y')
                : 'Not validated';

            $rows[] = [
                $profile->product_category->value,
                ['value' => $profile->procurement_lead_time_days.'d', 'class' => 'text-right'],
                ['value' => $profile->assembly_lead_time_days.'d', 'class' => 'text-right'],
                ['value' => number_format($profile->moq), 'class' => 'text-right'],
                ['value' => $profile->buffer_days.'d', 'class' => 'text-right'],
                $profile->supplier_name ?? '-',
                $validated,
            ];
        }

        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Category'],
                ['label' => 'Procurement LT', 'align' => 'text-right'],
                ['label' => 'Assembly LT', 'align' => 'text-right'],
                ['label' => 'MOQ', 'align' => 'text-right'],
                ['label' => 'Buffer', 'align' => 'text-right'],
                ['label' => 'Supplier'],
                ['label' => 'Validated'],
            ],
            'rows' => $rows,
        ];

        return $sections;
    }
}
