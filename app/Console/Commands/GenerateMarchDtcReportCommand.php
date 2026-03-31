<?php

namespace App\Console\Commands;

use App\Services\AnalysisPdfService;
use App\Services\DtcSalesQueryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('report:march-dtc')]
#[Description('Generate March 2026 DTC sales report with PWK pre-order forecast')]
class GenerateMarchDtcReportCommand extends Command
{
    private const PERIOD_START = '2026-03-01';

    private const PERIOD_END = '2026-04-01';

    private const DATA_CUTOFF = '2026-03-25';

    private const PWK_SKU = 'SK-PWK';

    private const PWK_AVG_PRICE = 205;

    public function handle(AnalysisPdfService $pdf, DtcSalesQueryService $dtcQuery): int
    {
        $this->info('Gathering March DTC data...');

        $totals = $dtcQuery->orderTotals(self::PERIOD_START, self::PERIOD_END);
        $products = $dtcQuery->productSales(self::PERIOD_START, self::PERIOD_END);
        $categories = $dtcQuery->categorySales(self::PERIOD_START, self::PERIOD_END);
        $countries = $dtcQuery->countrySales(self::PERIOD_START, self::PERIOD_END);
        $provinces = $dtcQuery->provinceSales(self::PERIOD_START, self::PERIOD_END);
        $pwkMonthly = $dtcQuery->monthlySales('2026-01-01', self::PERIOD_END, skuPrefix: self::PWK_SKU);
        $pwkWeekly = $dtcQuery->weeklySales('2026-01-01', self::PERIOD_END, skuPrefix: self::PWK_SKU);

        $this->info('Building PDF...');

        $data = [
            'title' => 'DTC Sales — Maart 2026',
            'subtitle' => 'Product sales + Performance Wax Kit pre-order forecast',
            'context' => 'Overleg Jakob',
            'quote' => 'Always a clean chain',
            'landscape' => true,
            'intro' => 'Overzicht DTC verkoop maart 2026 (data t/m '.self::DATA_CUTOFF.'). '
                .'Inclusief 3-scenario forecast voor de Performance Wax Kit pre-order t/m eind mei 2026.',
            'metrics' => [
                ['label' => 'Orders', 'value' => number_format($totals->total_orders), 'change' => 'data t/m 25 maart'],
                ['label' => 'Bruto omzet', 'value' => '€'.number_format($totals->total_revenue, 0, ',', '.'), 'change' => 'incl. shipping & tax'],
                ['label' => 'Netto omzet', 'value' => '€'.number_format($totals->total_net_revenue, 0, ',', '.'), 'change' => '€'.number_format($totals->total_discounts, 0, ',', '.').' korting'],
                ['label' => 'Gross margin', 'value' => '€'.number_format($totals->total_gross_margin, 0, ',', '.'), 'change' => round($totals->total_gross_margin * 100 / max($totals->total_revenue, 1)).'% op bruto'],
            ],
            'sections' => $this->buildSections($products, $categories, $countries, $provinces, $pwkMonthly, $pwkWeekly),
        ];

        $this->info('Rendering PDF...');
        $draftPath = $pdf->save($data, 'march-dtc-2026_draft-1.pdf');
        $this->info("Draft saved: {$draftPath}");

        $paths = $pdf->finalize($draftPath, 'march-dtc-2026.pdf');
        $this->info("Finalized: {$paths['desktop']}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(
        array $products,
        array $categories,
        array $countries,
        array $provinces,
        array $pwkMonthly,
        array $pwkWeekly,
    ): array {
        $sections = [];

        // ── Page 1: Top Products ──
        $sections[] = ['type' => 'heading', 'content' => 'Top 15 producten — omzet & marge'];
        $sections[] = $this->buildProductTable(array_slice($products, 0, 15));

        // ── Category summary ──
        $sections[] = ['type' => 'heading', 'content' => 'Omzet per productcategorie'];
        $sections[] = $this->buildCategoryTable($categories);

        // ── Page 2: Regions ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Top 15 landen'];
        $sections[] = $this->buildCountryTable($countries);

        $sections[] = ['type' => 'heading', 'content' => 'Top 10 provincies'];
        $sections[] = $this->buildProvinceTable($provinces);

        // ── Page 3: PWK Growth Dynamics ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Performance Wax Kit — Groeidynamiek Q1 2026'];
        $sections[] = $this->buildPwkMonthlyTable($pwkMonthly);
        $sections[] = ['type' => 'heading', 'content' => 'Weekpatroon maart — verkoopspike analyse'];
        $sections[] = $this->buildPwkWeeklyTable($pwkWeekly);
        $sections[] = [
            'type' => 'analysis',
            'content' => '<strong>Spike W11 (16-22 mrt):</strong> 606 units in één week — 5,5x het niveau van begin maart (W9: 109 units). '
                .'Post-spike (W12) zakt de dagrate naar ~33/dag, wat nog steeds 2x het pre-spike niveau is. '
                .'Dit wijst op een structureel hoger plateau na de initiële campagne-push. '
                .'<br><br><strong>Data cutoff:</strong> Shopify data reikt t/m 25 maart. '
                .'Geschatte volledige maart: ~1.274 units (resterende 6 dagen à ~33/dag).',
        ];

        // ── Page 4: Forecast ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Pre-order forecast t/m eind mei 2026'];
        $sections[] = $this->buildForecastTable($pwkMonthly);
        $sections[] = [
            'type' => 'list',
            'items' => [
                '<strong>Conservative:</strong> Spike was eenmalig (campagne-gedreven). Demand daalt terug naar ~2x pre-spike baseline. April ~20/dag, mei ~18/dag.',
                '<strong>Base:</strong> Post-spike demand houdt stand, seizoenseffect (wielrenseizoen) compenseert pre-order afname. April ~25/dag, mei ~28/dag.',
                '<strong>Optimistic:</strong> Seizoensramp versterkt momentum, word-of-mouth van early adopters drijft groei. April ~35/dag, mei ~40/dag.',
            ],
        ];
        $sections[] = ['type' => 'heading', 'content' => 'Discussiepunten'];
        $sections[] = [
            'type' => 'list',
            'items' => [
                'Wat triggerde de W11 spike (606 kits)? Is dit herhaalbaar?',
                'Supply capacity: kunnen we 4.000+ kits leveren bij base/optimistic scenario?',
                'Nieuwe campagne-push gepland voor april/mei?',
                'Base scenario als werkgetal voor operations planning?',
            ],
        ];

        return $sections;
    }

    private function buildProductTable(array $products): array
    {
        $headers = [
            ['label' => 'Product', 'width' => '25%'],
            ['label' => 'Categorie', 'width' => '12%'],
            ['label' => 'Units', 'width' => '8%', 'align' => 'text-right'],
            ['label' => 'Omzet', 'width' => '12%', 'align' => 'text-right'],
            ['label' => 'Kostprijs', 'width' => '12%', 'align' => 'text-right'],
            ['label' => 'Contributie marge', 'width' => '14%', 'align' => 'text-right'],
            ['label' => 'Marge %', 'width' => '8%', 'align' => 'text-right'],
            ['label' => 'Orders', 'width' => '9%', 'align' => 'text-right'],
        ];

        $categoryLabels = [
            'wax_kit' => 'Wax Kit',
            'wax_tablet' => 'Wax Tablet',
            'chain' => 'Chain',
            'starter_kit' => 'Starter Kit',
            'pocket_wax' => 'Pocket Wax',
            'chain_consumable' => 'Quick Link',
            'chain_tool' => 'Tool',
            'heater' => 'Heater',
            'heater_accessory' => 'Accessory',
            'cleaning' => 'Cleaning',
            'multi_tool' => 'Multi-Tool',
            'accessory' => 'Accessory',
        ];

        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                ['value' => mb_substr($p->product_name, 0, 40)],
                ['value' => $categoryLabels[$p->product_category] ?? $p->product_category ?? '—'],
                ['value' => number_format($p->units_sold)],
                ['value' => '€'.number_format($p->gross_revenue, 0, ',', '.')],
                ['value' => '€'.number_format($p->total_cost, 0, ',', '.')],
                ['value' => '€'.number_format($p->contribution_margin, 0, ',', '.')],
                ['value' => $p->margin_pct.'%'],
                ['value' => number_format($p->order_count)],
            ];
        }

        return ['type' => 'compact-table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildCategoryTable(array $categories): array
    {
        $categoryLabels = [
            'wax_kit' => 'Wax Kits',
            'wax_tablet' => 'Wax Tablets',
            'chain' => 'Chains',
            'starter_kit' => 'Starter Kits',
            'pocket_wax' => 'Pocket Wax',
            'chain_consumable' => 'Quick Links',
            'chain_tool' => 'Chain Tools',
            'heater' => 'Heaters',
            'heater_accessory' => 'Heater Accessories',
            'cleaning' => 'Cleaning',
            'multi_tool' => 'Multi-Tools',
            'accessory' => 'Accessories',
            'overig' => 'Overig',
        ];

        $headers = [
            ['label' => 'Categorie', 'width' => '20%'],
            ['label' => 'Units', 'width' => '10%', 'align' => 'text-right'],
            ['label' => 'Omzet', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Kostprijs', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Contributie marge', 'width' => '18%', 'align' => 'text-right'],
            ['label' => 'Marge %', 'width' => '10%', 'align' => 'text-right'],
        ];

        $rows = [];
        $totalUnits = 0;
        $totalRevenue = 0;
        $totalCost = 0;
        $totalMargin = 0;

        foreach ($categories as $cat) {
            $label = $categoryLabels[$cat->category] ?? $cat->category;
            $totalUnits += $cat->units_sold;
            $totalRevenue += $cat->gross_revenue;
            $totalCost += $cat->total_cost;
            $totalMargin += $cat->contribution_margin;

            $rows[] = [
                ['value' => $label],
                ['value' => number_format($cat->units_sold)],
                ['value' => '€'.number_format($cat->gross_revenue, 0, ',', '.')],
                ['value' => '€'.number_format($cat->total_cost, 0, ',', '.')],
                ['value' => '€'.number_format($cat->contribution_margin, 0, ',', '.')],
                ['value' => $cat->margin_pct.'%'],
            ];
        }

        $totalMarginPct = $totalRevenue > 0 ? round($totalMargin * 100 / $totalRevenue, 1) : 0;
        $rows[] = [
            ['value' => 'Totaal', 'class' => 'bold'],
            ['value' => number_format($totalUnits), 'class' => 'bold'],
            ['value' => '€'.number_format($totalRevenue, 0, ',', '.'), 'class' => 'bold'],
            ['value' => '€'.number_format($totalCost, 0, ',', '.'), 'class' => 'bold'],
            ['value' => '€'.number_format($totalMargin, 0, ',', '.'), 'class' => 'bold'],
            ['value' => $totalMarginPct.'%', 'class' => 'bold'],
        ];

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildCountryTable(array $countries): array
    {
        $headers = [
            ['label' => 'Land', 'width' => '15%'],
            ['label' => 'Orders', 'width' => '12%', 'align' => 'text-right'],
            ['label' => 'Bruto omzet', 'width' => '18%', 'align' => 'text-right'],
            ['label' => 'Netto omzet', 'width' => '18%', 'align' => 'text-right'],
            ['label' => 'Gross margin', 'width' => '18%', 'align' => 'text-right'],
            ['label' => 'Gem. orderwaarde', 'width' => '15%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($countries as $c) {
            $aov = $c->orders > 0 ? round($c->revenue / $c->orders, 0) : 0;
            $rows[] = [
                ['value' => $c->country, 'class' => 'bold'],
                ['value' => number_format($c->orders)],
                ['value' => '€'.number_format($c->revenue, 0, ',', '.')],
                ['value' => '€'.number_format($c->net_revenue, 0, ',', '.')],
                ['value' => '€'.number_format($c->gross_margin, 0, ',', '.')],
                ['value' => '€'.number_format($aov)],
            ];
        }

        return ['type' => 'compact-table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildProvinceTable(array $provinces): array
    {
        $headers = [
            ['label' => 'Land', 'width' => '12%'],
            ['label' => 'Provincie', 'width' => '20%'],
            ['label' => 'Orders', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Omzet', 'width' => '20%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($provinces as $p) {
            $rows[] = [
                ['value' => $p->country, 'class' => 'bold'],
                ['value' => $p->province ?? '—'],
                ['value' => number_format($p->orders)],
                ['value' => '€'.number_format($p->revenue, 0, ',', '.')],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildPwkMonthlyTable(array $monthly): array
    {
        $headers = [
            ['label' => 'Maand', 'width' => '20%'],
            ['label' => 'Units', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Omzet', 'width' => '20%', 'align' => 'text-right'],
            ['label' => 'Orders', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'MoM groei', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Gem/dag', 'width' => '15%', 'align' => 'text-right'],
        ];

        $monthLabels = [
            '2026-01' => 'Januari',
            '2026-02' => 'Februari',
            '2026-03' => 'Maart (t/m 25/3)',
        ];

        $daysInMonth = [
            '2026-01' => 31,
            '2026-02' => 28,
            '2026-03' => 25,
        ];

        $rows = [];
        $prevUnits = null;

        foreach ($monthly as $m) {
            $days = $daysInMonth[$m->month] ?? 30;
            $avgPerDay = round($m->units / $days, 1);
            $mom = $prevUnits !== null ? round(($m->units - $prevUnits) * 100 / max($prevUnits, 1)).'%' : '—';

            $rows[] = [
                ['value' => $monthLabels[$m->month] ?? $m->month],
                ['value' => number_format($m->units), 'class' => 'bold'],
                ['value' => '€'.number_format($m->revenue, 0, ',', '.')],
                ['value' => number_format($m->orders)],
                ['value' => $mom],
                ['value' => $avgPerDay],
            ];

            $prevUnits = $m->units;
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildPwkWeeklyTable(array $weekly): array
    {
        $headers = [
            ['label' => 'Week', 'width' => '10%'],
            ['label' => 'Periode', 'width' => '25%'],
            ['label' => 'Units', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Orders', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Per dag', 'width' => '15%', 'align' => 'text-right'],
        ];

        // Only show March weeks
        $marchWeeks = array_filter($weekly, fn (object $w): bool => $w->week_start >= '2026-03-01');

        $rows = [];
        foreach ($marchWeeks as $w) {
            $startDate = new \DateTime($w->week_start);
            $endDate = new \DateTime($w->week_end);
            $days = max((int) $startDate->diff($endDate)->days + 1, 1);
            $perDay = round($w->units / $days, 1);

            $rows[] = [
                ['value' => 'W'.ltrim(explode('-', $w->week)[1], '0')],
                ['value' => $w->week_start.' — '.$w->week_end],
                ['value' => number_format($w->units), 'class' => $w->units > 500 ? 'bold highlight' : ''],
                ['value' => number_format($w->orders)],
                ['value' => $perDay],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildForecastTable(array $pwkMonthly): array
    {
        $janUnits = 262;
        $febUnits = 344;
        $marEstimated = 1274;

        $scenarios = [
            'conservative' => ['apr' => 600, 'may' => 558],
            'base' => ['apr' => 750, 'may' => 868],
            'optimistic' => ['apr' => 1050, 'may' => 1240],
        ];

        $headers = [
            ['label' => 'Maand', 'width' => '20%'],
            ['label' => 'Conservative', 'width' => '20%', 'align' => 'text-right'],
            ['label' => 'Base', 'width' => '20%', 'align' => 'text-right'],
            ['label' => 'Optimistic', 'width' => '20%', 'align' => 'text-right'],
        ];

        $rows = [
            [
                ['value' => 'Januari (actueel)'],
                ['value' => number_format($janUnits)],
                ['value' => number_format($janUnits)],
                ['value' => number_format($janUnits)],
            ],
            [
                ['value' => 'Februari (actueel)'],
                ['value' => number_format($febUnits)],
                ['value' => number_format($febUnits)],
                ['value' => number_format($febUnits)],
            ],
            [
                ['value' => 'Maart (geschat)'],
                ['value' => '~'.number_format($marEstimated)],
                ['value' => '~'.number_format($marEstimated)],
                ['value' => '~'.number_format($marEstimated)],
            ],
            [
                ['value' => 'April (forecast)', 'class' => 'bold'],
                ['value' => number_format($scenarios['conservative']['apr']), 'class' => 'bold'],
                ['value' => number_format($scenarios['base']['apr']), 'class' => 'bold'],
                ['value' => number_format($scenarios['optimistic']['apr']), 'class' => 'bold'],
            ],
            [
                ['value' => 'Mei (forecast)', 'class' => 'bold'],
                ['value' => number_format($scenarios['conservative']['may']), 'class' => 'bold'],
                ['value' => number_format($scenarios['base']['may']), 'class' => 'bold'],
                ['value' => number_format($scenarios['optimistic']['may']), 'class' => 'bold'],
            ],
        ];

        // Totals
        foreach (['conservative', 'base', 'optimistic'] as $i => $key) {
            $total[$key] = $janUnits + $febUnits + $marEstimated + $scenarios[$key]['apr'] + $scenarios[$key]['may'];
        }

        $rows[] = [
            ['value' => 'Cumulatief t/m mei', 'class' => 'bold highlight'],
            ['value' => number_format($total['conservative']), 'class' => 'bold'],
            ['value' => number_format($total['base']), 'class' => 'bold'],
            ['value' => number_format($total['optimistic']), 'class' => 'bold'],
        ];

        $rows[] = [
            ['value' => 'Geschatte omzet', 'class' => 'bold highlight'],
            ['value' => '€'.number_format($total['conservative'] * self::PWK_AVG_PRICE, 0, ',', '.'), 'class' => 'bold'],
            ['value' => '€'.number_format($total['base'] * self::PWK_AVG_PRICE, 0, ',', '.'), 'class' => 'bold'],
            ['value' => '€'.number_format($total['optimistic'] * self::PWK_AVG_PRICE, 0, ',', '.'), 'class' => 'bold'],
        ];

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }
}
