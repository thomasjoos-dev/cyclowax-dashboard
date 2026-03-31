<?php

namespace App\Console\Commands;

use App\Services\Analysis\DtcSalesQueryService;
use App\Services\Support\AnalysisPdfService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('report:march-record')]
#[Description('Generate March 2026 DTC record month report for team Slack update')]
class GenerateMarchRecordReportCommand extends Command
{
    private DtcSalesQueryService $dtcQuery;

    private const PERIOD_START = '2026-03-01';

    private const PERIOD_END = '2026-04-01';

    private const PREV_YEAR_START = '2025-03-01';

    private const PREV_YEAR_END = '2025-04-01';

    public function handle(AnalysisPdfService $pdf, DtcSalesQueryService $dtcQuery): int
    {
        $this->dtcQuery = $dtcQuery;
        $this->info('Gathering March record data...');

        $marchTotals = $dtcQuery->orderTotals(self::PERIOD_START, self::PERIOD_END);
        $marchPrevYear = $dtcQuery->orderTotals(self::PREV_YEAR_START, self::PREV_YEAR_END);
        $monthlyTrend = $dtcQuery->monthlyOrderTrend('2025-01-01', '2026-04-01');
        $weeklyPattern = $dtcQuery->weeklyOrderPattern(self::PERIOD_START, self::PERIOD_END);
        $topProducts = $dtcQuery->topProducts(self::PERIOD_START, self::PERIOD_END);
        $channels = $dtcQuery->channelBreakdown(self::PERIOD_START, self::PERIOD_END);
        $countries = $dtcQuery->countrySales(self::PERIOD_START, self::PERIOD_END, 10);
        $newCustomerProducts = $dtcQuery->productSalesByCustomerType(self::PERIOD_START, self::PERIOD_END, true);
        $returningCustomerProducts = $dtcQuery->productSalesByCustomerType(self::PERIOD_START, self::PERIOD_END, false);
        $pwkMonthly = $dtcQuery->monthlySales('2026-01-01', self::PERIOD_END, skuPrefix: 'SK-PWK');

        $newPct = round($marchTotals->first_orders * 100 / max($marchTotals->total_orders, 1));
        $repeatPct = 100 - $newPct;

        $this->info('Building PDF...');

        $data = [
            'title' => 'DTC Revenue Report: March 2026',
            'subtitle' => 'Hoe onze grootste online maand ooit tot stand kwam',
            'context' => 'Slack team update',
            'quote' => 'Always a clean chain',
            'landscape' => false,
            'intro' => 'Maart 2026 is de grootste online maand ooit. De lancering van de Performance Wax Kit, '
                .'gecombineerd met het nieuwe GCN partnership en onze eigen advertising en content marketing, '
                .'zorgde voor een recordmaand. GCN nam het product mee in hun content, wat bovenop onze eigen '
                .'campagnes voor een enorme boost in bereik en verkoop zorgde.'
                .'<br><br>Data loopt van 1 tot 30 maart. Thomas was te enthousiast om te wachten op de 31ste.',
            'metrics' => [
                [
                    'label' => 'Netto omzet',
                    'value' => '€'.number_format($marchTotals->net_revenue, 0, ',', '.'),
                    'change' => 'Wat er effectief binnenkwam na belasting, kortingen en refunds',
                ],
                [
                    'label' => 'Bruto marge',
                    'value' => '€'.number_format($marchTotals->gross_margin, 0, ',', '.'),
                    'change' => 'Wat overblijft na productkosten, verzending en betaalkosten',
                ],
                [
                    'label' => 'Nieuwe klanten',
                    'value' => $newPct.'%',
                    'change' => number_format($marchTotals->first_orders).' van de '.number_format($marchTotals->total_orders).' bestellingen',
                ],
                [
                    'label' => 'Bestaande klanten',
                    'value' => $repeatPct.'%',
                    'change' => number_format($marchTotals->repeat_orders).' bestellingen van klanten die eerder al kochten',
                ],
            ],
            'sections' => $this->buildSections(
                $marchTotals,
                $marchPrevYear,
                $monthlyTrend,
                $weeklyPattern,
                $topProducts,
                $channels,
                $countries,
                $newCustomerProducts,
                $returningCustomerProducts,
                $pwkMonthly,
            ),
        ];

        $this->info('Rendering PDF...');
        $draftPath = $pdf->save($data, 'march-record-2026_draft-1.pdf');
        $this->info("Draft saved: {$draftPath}");

        $paths = $pdf->finalize($draftPath, 'march-record-2026.pdf');
        $this->info("Finalized: {$paths['desktop']}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(
        object $totals,
        object $prevYear,
        array $monthlyTrend,
        array $weeklyPattern,
        array $topProducts,
        array $channels,
        array $countries,
        array $newCustomerProducts,
        array $returningCustomerProducts,
        array $pwkMonthly,
    ): array {
        $sections = [];

        // ── Section 1: Record in cijfers ──
        $sections[] = ['type' => 'heading', 'content' => '1. Record in cijfers'];
        $sections[] = $this->buildYoyTable($totals, $prevYear);
        // ── Section 2: Weekpatroon ──
        $sections[] = ['type' => 'heading', 'content' => '2. Weekpatroon maart'];
        $sections[] = $this->buildWeeklyTable($weeklyPattern);
        $sections[] = [
            'type' => 'analysis',
            'content' => 'Week 11 was het GCN-piekmoment: 751 bestellingen en €138K netto omzet in één week. '
                .'Het volume viel daarna niet terug naar het oude ritme. '
                .'De online verkoop na het piekmoment lag 5x hoger dan het daggemiddelde in Q4 2025.',
        ];

        // ── Section 3: Wat is er verkocht ──
        $sections[] = ['type' => 'heading', 'content' => '3. Wat is er verkocht'];
        $sections[] = $this->buildProductTable($topProducts);

        $tabletPct = $this->dtcQuery->categoryRevenueShare(self::PERIOD_START, self::PERIOD_END, 'wax_tablet');

        $sections[] = [
            'type' => 'analysis',
            'content' => 'De Performance Wax Kit is goed voor 1.291 units en 75% van de productomzet. '
                .'De overige 25% is verdeeld over chains (10%), pocket wax (8%), starter kits (3%) '
                .'en wax tablets, chain tools en quick links (samen 4%). '
                .'Wax tablets (Performance, Race en Core) komen uit op 673 units en '.$tabletPct.'% van de omzet.',
        ];

        // ── Section 4: Wie koopt & via welk kanaal ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => '4. Wie koopt en via welk kanaal'];
        $sections[] = ['type' => 'subheading', 'content' => 'Top 10 landen'];
        $sections[] = $this->buildCountryTable($countries);
        $sections[] = ['type' => 'subheading', 'content' => 'Kanalen'];
        $sections[] = $this->buildChannelTable($channels);
        $sections[] = [
            'type' => 'analysis',
            'content' => 'Bijna de helft van de omzet (46%) komt binnen zonder mediakosten: '
                .'via zoekmachines (organic) en direct verkeer (bezoekers die de website rechtstreeks bezoeken). '
                .'Paid advertising is verantwoordelijk voor 24%. Het organische fundament is sterk, '
                .'en het GCN partnership versterkt dat. De VS en Duitsland zijn samen goed voor bijna de helft '
                .'van de totale omzet, met België en het UK als stabiele kernmarkten.',
        ];

        // ── Section 5: Nieuwe vs terugkerende klanten ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => '5. Nieuwe vs terugkerende klanten'];
        $sections[] = ['type' => 'subheading', 'content' => 'Top 5 producten nieuwe klanten'];
        $sections[] = $this->buildCustomerProductTable($newCustomerProducts);
        $sections[] = ['type' => 'subheading', 'content' => 'Top 5 producten terugkerende klanten'];
        $sections[] = $this->buildCustomerProductTable($returningCustomerProducts);

        $returningTabletPct = $this->dtcQuery->categoryRevenueShare(self::PERIOD_START, self::PERIOD_END, 'wax_tablet', firstOrder: false);
        $newTabletPct = $this->dtcQuery->categoryRevenueShare(self::PERIOD_START, self::PERIOD_END, 'wax_tablet', firstOrder: true);

        $sections[] = [
            'type' => 'analysis',
            'content' => '83% van de bestellingen kwam van nieuwe klanten. '
                .'Bij nieuwe klanten draait vrijwel alles om de Performance Wax Kit. '
                .'Wax tablets zijn goed voor '.$returningTabletPct.'% van de omzet bij terugkerende klanten, '
                .'tegenover '.$newTabletPct.'% bij nieuwe klanten.',
        ];

        // ── Section 6: PWK pre-order prognose ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => '6. Performance Wax Kit pre-order prognose'];
        $sections[] = ['type' => 'subheading', 'content' => 'Verkoop januari tot maart'];
        $sections[] = $this->buildPwkMonthlyTable($pwkMonthly);

        $sections[] = ['type' => 'subheading', 'content' => 'Scenario-opbouw'];
        $sections[] = [
            'type' => 'analysis',
            'content' => 'Het post GCN-piekmoment daggemiddelde ligt op 40 kits per dag (week 12). Dat is het vertrekpunt.',
        ];
        $sections[] = $this->buildScenarioExplanationTable();
        $sections[] = ['type' => 'subheading', 'content' => 'Forecast april en mei'];
        $sections[] = $this->buildForecastTable($pwkMonthly);

        $sections[] = [
            'type' => 'analysis',
            'content' => 'Sinds januari zijn er 1.897 Performance Wax Kits verkocht via pre-order. '
                .'Er zijn 5.000 nieuwe heaters besteld. Bij het huidige ritme van 40 kits per dag '
                .'zitten we eind mei tussen de 3.400 en 5.300 units cumulatief.',
        ];

        return $sections;
    }

    private function buildYoyTable(object $current, object $prevYear): array
    {
        $rows = [
            $this->yoyRow('Netto omzet', $current->net_revenue, $prevYear->net_revenue, true),
            $this->yoyRow('Orders', $current->total_orders, $prevYear->total_orders, false),
            $this->yoyRow('Nieuwe klanten', $current->first_orders, $prevYear->first_orders, false),
            $this->yoyRow('Bruto marge', $current->gross_margin, $prevYear->gross_margin, true),
        ];

        return [
            'type' => 'table',
            'headers' => [
                ['label' => '', 'width' => '25%'],
                ['label' => 'Maart 2026', 'width' => '25%', 'align' => 'text-right'],
                ['label' => 'Maart 2025', 'width' => '25%', 'align' => 'text-right'],
                ['label' => 'Groei', 'width' => '25%', 'align' => 'text-right'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array{value: string, class?: string}>
     */
    private function yoyRow(string $label, float $current, float $previous, bool $isCurrency): array
    {
        $growth = $previous > 0 ? round(($current - $previous) * 100 / $previous) : 0;
        $growthStr = ($growth >= 0 ? '+' : '').$growth.'%';

        return [
            ['value' => $label, 'class' => 'bold'],
            ['value' => $isCurrency ? '€'.number_format($current, 0, ',', '.') : number_format($current), 'class' => 'bold'],
            ['value' => $isCurrency ? '€'.number_format($previous, 0, ',', '.') : number_format($previous)],
            ['value' => $growthStr, 'class' => 'bold'],
        ];
    }

    private function buildMonthlyTrendTable(array $monthly): array
    {
        $monthLabels = [
            '01' => 'Jan', '02' => 'Feb', '03' => 'Mrt', '04' => 'Apr',
            '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
            '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Dec',
        ];

        $headers = [
            ['label' => 'Maand', 'width' => '14%'],
            ['label' => 'Orders', 'width' => '14%', 'align' => 'text-right'],
            ['label' => 'Netto omzet', 'width' => '18%', 'align' => 'text-right'],
            ['label' => 'Bruto marge', 'width' => '18%', 'align' => 'text-right'],
            ['label' => 'Nieuwe klanten', 'width' => '16%', 'align' => 'text-right'],
            ['label' => 'Repeat', 'width' => '14%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($monthly as $m) {
            $parts = explode('-', $m->month);
            $label = ($monthLabels[$parts[1]] ?? $parts[1]).' \''.substr($parts[0], 2);
            $isRecord = $m->month === '2026-03';

            $rows[] = [
                ['value' => $label, 'class' => $isRecord ? 'bold highlight' : ''],
                ['value' => number_format($m->total_orders), 'class' => $isRecord ? 'bold' : ''],
                ['value' => '€'.number_format($m->net_revenue, 0, ',', '.'), 'class' => $isRecord ? 'bold highlight' : ''],
                ['value' => '€'.number_format($m->gross_margin, 0, ',', '.'), 'class' => $isRecord ? 'bold' : ''],
                ['value' => number_format($m->first_orders), 'class' => $isRecord ? 'bold' : ''],
                ['value' => number_format($m->repeat_orders)],
            ];
        }

        return ['type' => 'compact-table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildWeeklyTable(array $weeks): array
    {
        $headers = [
            ['label' => 'Week', 'width' => '10%'],
            ['label' => 'Periode', 'width' => '28%'],
            ['label' => 'Orders', 'width' => '16%', 'align' => 'text-right'],
            ['label' => 'Netto omzet', 'width' => '22%', 'align' => 'text-right'],
            ['label' => 'Nieuwe klanten', 'width' => '18%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($weeks as $w) {
            $weekLabel = 'W'.ltrim($w->week_nr, '0');
            $isPeak = $w->orders > 500;

            $rows[] = [
                ['value' => $weekLabel, 'class' => $isPeak ? 'bold highlight' : ''],
                ['value' => $w->week_start.' — '.$w->week_end],
                ['value' => number_format($w->orders), 'class' => $isPeak ? 'bold highlight' : ''],
                ['value' => '€'.number_format($w->net_revenue, 0, ',', '.'), 'class' => $isPeak ? 'bold' : ''],
                ['value' => number_format($w->first_orders)],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildProductTable(array $products): array
    {
        $headers = [
            ['label' => 'Product', 'width' => '40%'],
            ['label' => 'Units', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Omzet', 'width' => '20%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                ['value' => mb_substr($this->cleanProductName($p->product_name), 0, 45)],
                ['value' => number_format($p->units)],
                ['value' => '€'.number_format($p->revenue, 0, ',', '.')],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildCountryTable(array $countries): array
    {
        $countryNames = [
            'US' => 'Verenigde Staten', 'DE' => 'Duitsland', 'BE' => 'België',
            'GB' => 'Verenigd Koninkrijk', 'CH' => 'Zwitserland', 'NL' => 'Nederland',
            'CA' => 'Canada', 'AT' => 'Oostenrijk', 'AU' => 'Australië',
            'SE' => 'Zweden', 'FR' => 'Frankrijk', 'DK' => 'Denemarken',
            'NO' => 'Noorwegen', 'IT' => 'Italië', 'ES' => 'Spanje',
        ];

        $headers = [
            ['label' => 'Land', 'width' => '30%'],
            ['label' => 'Orders', 'width' => '20%', 'align' => 'text-right'],
            ['label' => 'Netto omzet', 'width' => '25%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($countries as $c) {
            $rows[] = [
                ['value' => $countryNames[$c->country] ?? $c->country, 'class' => 'bold'],
                ['value' => number_format($c->orders)],
                ['value' => '€'.number_format($c->net_revenue, 0, ',', '.')],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildChannelTable(array $channels): array
    {
        $channelLabels = [
            'organic_google' => 'Organic Google',
            'direct' => 'Direct (rechtstreeks websitebezoek)',
            'paid_google' => 'Paid Google',
            'unknown' => 'Unknown',
            'referral' => 'Referral',
            'paid_instagram' => 'Paid Instagram',
            'manual_customer_service' => 'Customer Service',
            'organic_bing' => 'Organic Bing',
            'paid_facebook' => 'Paid Facebook',
            'organic_instagram' => 'Organic Instagram',
            'email' => 'Email',
            'google_shopping_free' => 'Google Shopping (free)',
            'organic_facebook' => 'Organic Facebook',
            'ai_referral' => 'AI Referral',
            'manual_internal' => 'Internal',
        ];

        $headers = [
            ['label' => 'Kanaal', 'width' => '30%'],
            ['label' => 'Orders', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Netto omzet', 'width' => '20%', 'align' => 'text-right'],
            ['label' => 'Aandeel', 'width' => '15%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($channels as $ch) {
            $rows[] = [
                ['value' => $channelLabels[$ch->refined_channel] ?? $ch->refined_channel],
                ['value' => number_format($ch->orders)],
                ['value' => '€'.number_format($ch->net_revenue, 0, ',', '.')],
                ['value' => $ch->revenue_share.'%'],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildCustomerProductTable(array $products): array
    {
        $headers = [
            ['label' => 'Product', 'width' => '40%'],
            ['label' => 'Units', 'width' => '15%', 'align' => 'text-right'],
            ['label' => 'Netto omzet', 'width' => '20%', 'align' => 'text-right'],
        ];

        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                ['value' => mb_substr($this->cleanProductName($p->product_name), 0, 45)],
                ['value' => number_format($p->units)],
                ['value' => '€'.number_format($p->revenue, 0, ',', '.')],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildPwkMonthlyTable(array $monthly): array
    {
        $monthLabels = [
            '2026-01' => 'Januari',
            '2026-02' => 'Februari',
            '2026-03' => 'Maart',
        ];

        $headers = [
            ['label' => 'Maand', 'width' => '40%'],
            ['label' => 'Units', 'width' => '30%', 'align' => 'text-right'],
        ];

        $rows = [];
        $totalUnits = 0;

        foreach ($monthly as $m) {
            $totalUnits += $m->units;
            $rows[] = [
                ['value' => $monthLabels[$m->month] ?? $m->month],
                ['value' => number_format($m->units)],
            ];
        }

        $rows[] = [
            ['value' => 'Totaal Q1', 'class' => 'bold'],
            ['value' => number_format($totalUnits), 'class' => 'bold'],
        ];

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildScenarioExplanationTable(): array
    {
        return [
            'type' => 'table',
            'headers' => [
                ['label' => 'Scenario', 'width' => '20%'],
                ['label' => 'Kits/dag', 'width' => '15%', 'align' => 'text-right'],
                ['label' => 'Logica', 'width' => '65%'],
            ],
            'rows' => [
                [
                    ['value' => 'Worst', 'class' => 'bold'],
                    ['value' => '25/dag'],
                    ['value' => 'Hype neemt af, seizoenseffect compenseert deels'],
                ],
                [
                    ['value' => 'Middle', 'class' => 'bold'],
                    ['value' => '40/dag'],
                    ['value' => 'Huidig ritme houdt aan'],
                ],
                [
                    ['value' => 'Best', 'class' => 'bold'],
                    ['value' => '55/dag'],
                    ['value' => 'GCN en eigen content marketing zorgen voor versnelling, versterkt door het zomerseizoen en mond-tot-mondreclame van renners die de pre-order aanbevelen'],
                ],
            ],
        ];
    }

    private function buildForecastTable(array $pwkMonthly): array
    {
        $totalQ1 = 0;
        foreach ($pwkMonthly as $m) {
            $totalQ1 += $m->units;
        }

        $scenarios = [
            'worst' => ['apr' => 750, 'may' => 775],
            'middle' => ['apr' => 1200, 'may' => 1240],
            'best' => ['apr' => 1650, 'may' => 1705],
        ];

        $headers = [
            ['label' => '', 'width' => '25%'],
            ['label' => 'Worst', 'width' => '25%', 'align' => 'text-right'],
            ['label' => 'Middle', 'width' => '25%', 'align' => 'text-right'],
            ['label' => 'Best', 'width' => '25%', 'align' => 'text-right'],
        ];

        $rows = [
            [
                ['value' => 'April'],
                ['value' => number_format($scenarios['worst']['apr'])],
                ['value' => number_format($scenarios['middle']['apr'])],
                ['value' => number_format($scenarios['best']['apr'])],
            ],
            [
                ['value' => 'Mei'],
                ['value' => number_format($scenarios['worst']['may'])],
                ['value' => number_format($scenarios['middle']['may'])],
                ['value' => number_format($scenarios['best']['may'])],
            ],
        ];

        $cumulative = [];
        foreach (['worst', 'middle', 'best'] as $key) {
            $cumulative[$key] = $totalQ1 + $scenarios[$key]['apr'] + $scenarios[$key]['may'];
        }

        $rows[] = [
            ['value' => 'Cumulatief jan t/m mei', 'class' => 'bold'],
            ['value' => number_format($cumulative['worst']), 'class' => 'bold'],
            ['value' => number_format($cumulative['middle']), 'class' => 'bold'],
            ['value' => number_format($cumulative['best']), 'class' => 'bold'],
        ];

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function cleanProductName(string $name): string
    {
        return str_replace(' - OEM', '', $name);
    }

    private function pctChange(float $current, float $previous): string
    {
        if ($previous == 0) {
            return '—';
        }

        $change = round(($current - $previous) * 100 / $previous);

        return ($change >= 0 ? '+' : '').$change.'%';
    }
}
