<?php

namespace App\Console\Commands;

use App\Enums\ForecastGroup;
use App\Enums\ForecastRegion;
use App\Models\DemandEvent;
use App\Models\Scenario;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\Demand\RegionalForecastAggregator;
use App\Services\Forecast\Demand\SalesBaselineService;
use App\Services\Forecast\Tracking\ScenarioService;
use App\Services\Support\AnalysisPdfService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('forecast:revenue-report')]
#[Description('Generate 2026 Revenue Forecast PDF using the full demand forecast pipeline')]
class GenerateForecastReportCommand extends Command
{
    private const int YEAR = 2026;

    /** @var array<string, string> */
    private const array SCENARIO_LABELS = [
        'conservative' => 'Voorzichtig',
        'base' => 'Medium',
        'ambitious' => 'Best Case',
    ];

    /** @var array<int, string> */
    private const array SCENARIO_KEYS = ['conservative', 'base', 'ambitious'];

    public function handle(
        AnalysisPdfService $pdf,
        SalesBaselineService $baseline,
        DemandForecastService $demandForecast,
        RegionalForecastAggregator $regionalAggregator,
        ScenarioService $scenarioService,
    ): int {
        $this->info('Generating 2026 Revenue Forecast...');

        $q1Actual = $baseline->periodActuals('2026-01-01', '2026-04-01');
        $monthlyQ1 = $baseline->monthlyActuals('2026-01-01', '2026-04-01');
        $rev2025 = $baseline->yearRevenue(2025);

        $scenarios = $scenarioService->forYear(self::YEAR)
            ->filter(fn (Scenario $s) => in_array($s->name, self::SCENARIO_KEYS));

        $this->info('Running demand forecast for '.count($scenarios).' scenarios...');
        $forecastData = [];
        $categoryData = [];
        $regionalData = [];

        foreach ($scenarios as $scenario) {
            $key = $scenario->name;
            $forecastData[$key] = $demandForecast->totalForecast($scenario, self::YEAR);
            $categoryData[$key] = $demandForecast->forecastYear($scenario, self::YEAR);

            $this->info("  Regional forecast: {$key}...");
            $regionalData[$key] = $regionalAggregator->forecastAllRegions($scenario, self::YEAR);
        }

        $data = [
            'title' => '2026 Revenue Forecast',
            'subtitle' => 'DTC Intelligence Agent. Omzetforecast op basis van klantgedrag, seizoenspatronen en campagne-effecten.',
            'context' => 'Leadership Team',
            'quote' => 'Always a clean chain',
            'intro' => $this->buildIntro($q1Actual, $rev2025, $forecastData),
            'metrics' => $this->buildMetrics($q1Actual, $forecastData, $regionalData),
            'sections' => $this->buildSections($q1Actual, $monthlyQ1, $rev2025, $forecastData, $categoryData, $regionalData, $scenarios),
        ];

        $this->info('Rendering PDF...');
        $draftPath = $pdf->save($data, '2026-revenue-forecast_draft-1.pdf');
        $paths = $pdf->finalize($draftPath, '2026-revenue-forecast.pdf');
        $this->info("Finalized: {$paths['desktop']}");

        return self::SUCCESS;
    }

    private function buildIntro(array $q1Actual, float $rev2025, array $forecastData): string
    {
        $baseTotal = $forecastData['base']['year_total']['revenue'] ?? 0;
        $consTotal = $forecastData['conservative']['year_total']['revenue'] ?? 0;
        $ambiTotal = $forecastData['ambitious']['year_total']['revenue'] ?? 0;

        return 'Dit rapport is gegenereerd door de <strong>Cyclowax DTC Intelligence Agent</strong>. '
            .'Het toont de omzetforecast voor onze directe verkoop aan consumenten (DTC) in 2026. '
            .'Alle bedragen zijn <strong>netto omzet</strong>: wat er overblijft na kortingen, met geannuleerde en teruggestuurde bestellingen uitgesloten.<br><br>'
            .'De forecast range voor 2026 ligt tussen €'.number_format($consTotal)
            .' en €'.number_format($ambiTotal).', '
            .'met een medium scenario op <strong>€'.number_format($baseTotal).'</strong>. '
            .'Q1 2026 is afgesloten met €'.number_format($q1Actual['total_rev']).' netto omzet.';
    }

    /**
     * @param  array<string, array{region_totals: array, cm1_total: array}>  $regionalData
     * @return array<int, array{label: string, value: string, change: string}>
     */
    private function buildMetrics(array $q1Actual, array $forecastData, array $regionalData): array
    {
        $consTotal = $forecastData['conservative']['year_total']['revenue'] ?? 0;
        $ambiTotal = $forecastData['ambitious']['year_total']['revenue'] ?? 0;

        // CM1 margin percentage per scenario
        $cm1Parts = [];
        foreach (self::SCENARIO_KEYS as $key) {
            $cm1Pct = $regionalData[$key]['cm1_total']['cm1_pct'] ?? 0;
            $cm1Parts[] = $cm1Pct.'%';
        }

        return [
            ['label' => 'Q1 2026 Netto Omzet', 'value' => '€'.number_format($q1Actual['total_rev']), 'change' => number_format($q1Actual['new_customers']).' nieuwe klanten'],
            ['label' => 'Forecast Range 2026', 'value' => '€'.number_format($consTotal).' – €'.number_format($ambiTotal), 'change' => 'voorzichtig tot best case'],
            ['label' => 'CM1 Marge', 'value' => implode(' / ', $cm1Parts), 'change' => 'voorzichtig / medium / best case'],
        ];
    }

    private function buildSections(array $q1Actual, array $monthlyQ1, float $rev2025, array $forecastData, array $categoryData, array $regionalData, Collection $scenarios): array
    {
        $sections = [];

        // Page 1: How we forecast + glossary
        $sections = array_merge($sections, $this->buildMethodologySection());
        $sections = array_merge($sections, $this->buildGlossary());

        // Page 2: Q1 Actuals
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildQ1Section($q1Actual, $monthlyQ1));

        // Page 3: Scenario overview
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildScenarioOverview($forecastData, $rev2025));

        // Page 4: Quarterly breakdown
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildQuarterlyBreakdown($forecastData));

        // Page 5: Monthly curve
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildMonthlyCurve($forecastData));

        // Page 6: Product group breakdown
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildProductGroupSection($categoryData));

        // Page 7: Regional breakdown
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildRegionalSection($regionalData));

        // Page 8: Campaign calendar
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildCampaignSection());

        // Page 9: Configuration summary
        $sections[] = ['type' => 'page-break'];
        $sections = array_merge($sections, $this->buildConfigurationSection($scenarios));

        // Page 10: Risks
        $sections = array_merge($sections, $this->buildRisksSection());

        return $sections;
    }

    private function buildMethodologySection(): array
    {
        return [
            ['type' => 'heading', 'content' => 'Hoe deze forecast werkt'],
            ['type' => 'text', 'content' => 'Deze forecast telt niet simpelweg een groeipercentage op bij vorig jaar. Het model kijkt naar vier aspecten van ons bedrijf en combineert die tot een maandelijkse omzetprojectie:'],
            ['type' => 'list', 'items' => [
                '<strong>Klantgedrag in de tijd.</strong> '
                    .'We groeperen klanten op basis van wanneer ze voor het eerst kochten (een zogenaamde "cohort", bijvoorbeeld: alle klanten die in maart 2026 voor het eerst bestelden). '
                    .'Per cohort volgen we hoeveel procent na 1, 2, 3 maanden opnieuw bestelt. '
                    .'Dat patroon noemen we een retentiecurve. Die curve voorspelt hoeveel herbestellingen we de komende maanden mogen verwachten van bestaande klanten.',

                '<strong>Seizoenspatronen.</strong> '
                    .'Fietsen is een seizoenssport. De verkoop van heaters en wax tablets is in de zomer anders dan in de winter. '
                    .'Het model past per productcategorie per maand een seizoensfactor toe, gebaseerd op de patronen uit 2024 en 2025. '
                    .'Zo wordt de forecast realistischer dan een vlak gemiddelde.',

                '<strong>Campagne-effecten.</strong> '
                    .'Geplande campagnes zoals GCN video\'s, Black Friday en seizoensacties genereren extra verkopen bovenop het basisniveau. '
                    .'Het model berekent hoeveel extra units elke campagne oplevert. '
                    .'Het houdt ook rekening met het "pull-forward effect": klanten die door een actie eerder kopen dan ze anders zouden doen, wat de weken erna iets minder oplevert.',

                '<strong>Regionale verschillen.</strong> '
                    .'De forecast draait apart voor negen regio\'s (o.a. België, Duitsland, VS, UK). '
                    .'Elke regio heeft eigen groeiaannames en eigen gemiddelde orderwaarden. '
                    .'Regio\'s met voldoende bestelhistorie gebruiken hun eigen retentiecurve. Kleinere regio\'s vallen terug op het globale patroon.',
            ]],
        ];
    }

    private function buildGlossary(): array
    {
        return [
            ['type' => 'heading', 'content' => 'Begrippen in dit rapport'],
            ['type' => 'analysis', 'content' => '<strong>Netto omzet:</strong> Het bedrag dat overblijft na kortingen, met geannuleerde en teruggestuurde bestellingen uitgesloten. Alle bedragen in dit rapport zijn netto.<br><br>'
                .'<strong>Acquisitie vs herbestellingen:</strong> Omzet uit eerste bestellingen van nieuwe klanten (acquisitie) versus omzet uit vervolgbestellingen van bestaande klanten (herbestellingen of "repeat").<br><br>'
                .'<strong>Cohort:</strong> Een groep klanten die in dezelfde maand voor het eerst kochten. We volgen per cohort hoe het koopgedrag zich ontwikkelt over de maanden erna.<br><br>'
                .'<strong>Gemiddelde orderwaarde (AOV):</strong> Het gemiddelde bedrag per bestelling. Dit verschilt tussen eerste bestellingen (vaak een kit) en herbestellingen (vaak wax of kettingen).<br><br>'
                .'<strong>Seizoensindex:</strong> Een vermenigvuldiger die aangeeft of een maand typisch sterker (boven 1,0) of zwakker (onder 1,0) is voor een bepaalde productcategorie.<br><br>'
                .'<strong>Groeitempo:</strong> De factor waarmee het aantal nieuwe klanten groeit ten opzichte van Q1 2026. 100% = zelfde tempo als Q1, 150% = anderhalf keer zoveel nieuwe klanten per maand.',
            ],
        ];
    }

    private function buildQ1Section(array $q1Actual, array $monthlyQ1): array
    {
        $rows = [];
        foreach ($monthlyQ1 as $m) {
            $monthName = Carbon::parse($m['month'].'-01')->locale('nl')->translatedFormat('F');
            $isHighlight = str_starts_with($m['month'], '2026-03');
            $rows[] = [
                ['value' => ucfirst($monthName), 'class' => $isHighlight ? 'highlight' : ''],
                ['value' => number_format($m['new_customers']), 'class' => $isHighlight ? 'highlight' : ''],
                ['value' => '€'.number_format($m['acq_rev'])],
                ['value' => number_format($m['repeat_orders'])],
                ['value' => '€'.number_format($m['rep_rev'])],
                ['value' => '€'.number_format($m['total_rev']), 'class' => $isHighlight ? 'highlight' : ''],
            ];
        }
        $rows[] = [
            ['value' => 'Q1 Totaal'],
            ['value' => number_format($q1Actual['new_customers'])],
            ['value' => '€'.number_format($q1Actual['acq_rev'])],
            ['value' => number_format($q1Actual['repeat_orders'])],
            ['value' => '€'.number_format($q1Actual['rep_rev'])],
            ['value' => '€'.number_format($q1Actual['total_rev'])],
        ];

        return [
            ['type' => 'heading', 'content' => 'Q1 2026: de basis van deze forecast'],
            ['type' => 'text', 'content' => 'De werkelijke verkoopcijfers uit Q1 vormen het startpunt. Alle drie de scenario\'s delen dezelfde Q1 data. De Performance Wax Kit launch in maart zorgde voor een structureel hoger aantal nieuwe klanten.'],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Maand', 'width' => '14%'],
                    ['label' => 'Nieuwe klanten', 'width' => '14%', 'align' => 'text-right'],
                    ['label' => 'Eerste bestellingen', 'width' => '18%', 'align' => 'text-right'],
                    ['label' => 'Herbestellingen', 'width' => '14%', 'align' => 'text-right'],
                    ['label' => 'Herbest. omzet', 'width' => '18%', 'align' => 'text-right'],
                    ['label' => 'Totaal netto', 'width' => '18%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ],
        ];
    }

    private function buildScenarioOverview(array $forecastData, float $rev2025): array
    {
        $rows = [];

        // Net Revenue 2026
        $row = [['value' => 'Netto omzet 2026']];
        foreach (self::SCENARIO_KEYS as $key) {
            $total = $forecastData[$key]['year_total']['revenue'] ?? 0;
            $row[] = ['value' => '€'.number_format($total), 'class' => $key === 'ambitious' ? 'highlight' : ''];
        }
        $rows[] = $row;

        // Growth vs 2025
        $row = [['value' => 'Groei vs 2025 (€'.number_format($rev2025).')']];
        foreach (self::SCENARIO_KEYS as $key) {
            $total = $forecastData[$key]['year_total']['revenue'] ?? 0;
            $pct = $rev2025 > 0 ? round(($total / $rev2025 - 1) * 100) : 0;
            $row[] = ['value' => '+'.$pct.'%'];
        }
        $rows[] = $row;

        // Acquisition revenue
        $row = [['value' => 'Omzet uit eerste bestellingen']];
        foreach (self::SCENARIO_KEYS as $key) {
            $row[] = ['value' => '€'.number_format($forecastData[$key]['year_total']['acq_revenue'] ?? 0)];
        }
        $rows[] = $row;

        // Repeat revenue
        $row = [['value' => 'Omzet uit herbestellingen']];
        foreach (self::SCENARIO_KEYS as $key) {
            $row[] = ['value' => '€'.number_format($forecastData[$key]['year_total']['rep_revenue'] ?? 0)];
        }
        $rows[] = $row;

        // Repeat as % of total
        $row = [['value' => 'Herbestellingen als % van totaal']];
        foreach (self::SCENARIO_KEYS as $key) {
            $total = $forecastData[$key]['year_total']['revenue'] ?? 0;
            $rep = $forecastData[$key]['year_total']['rep_revenue'] ?? 0;
            $pct = $total > 0 ? round($rep * 100 / $total, 1) : 0;
            $row[] = ['value' => $pct.'%'];
        }
        $rows[] = $row;

        $descriptions = '<strong>Voorzichtig:</strong> Het acquisitietempo uit Q1 blijft het hele jaar stabiel. '
            .'Elk kwartaal trekt evenveel nieuwe klanten als Q1 (100%). '
            .'Van de Performance Wax Kit klanten komt 20% terug voor een vervolgaankoop. De gemiddelde herbestelling is €85.<br><br>'
            .'<strong>Medium:</strong> Het acquisitietempo versnelt geleidelijk: Q2 op 120% van Q1, Q3 op 140%, Q4 op 160%. '
            .'25% van de Performance Wax Kit klanten komt terug, geholpen door actieve productaanbevelingen richting kettingen en pocket wax. Gemiddelde herbestelling €95.<br><br>'
            .'<strong>Best Case:</strong> Sterke versnelling: Q2 op 150% van Q1, Q3 op 175%, Q4 op 200%. '
            .'Het aandeel terugkerende Performance Wax Kit klanten start op 20% in Q2 en groeit naar 30% in Q3 en Q4 naarmate de productaanbevelingen effectiever worden. '
            .'Gemiddelde herbestelling bereikt €110 tot €120.';

        return [
            ['type' => 'heading', 'content' => 'Drie scenario\'s voor 2026'],
            ['type' => 'text', 'content' => 'Het verschil tussen de scenario\'s zit in twee factoren: hoe snel we nieuwe klanten blijven aantrekken na Q1, en hoe groot het aandeel Performance Wax Kit klanten is dat terugkomt voor een tweede aankoop.'],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => '', 'width' => '28%'],
                    ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ],
            ['type' => 'analysis', 'content' => $descriptions],
        ];
    }

    private function buildQuarterlyBreakdown(array $forecastData): array
    {
        $sections = [
            ['type' => 'heading', 'content' => 'Eerste bestellingen vs herbestellingen per kwartaal'],
            ['type' => 'text', 'content' => 'Twee tabellen: omzet uit eerste bestellingen (nieuwe klanten) en omzet uit herbestellingen (terugkerende klanten). Q1 is in alle scenario\'s gelijk omdat dat werkelijke cijfers zijn.'],
        ];

        $quarterNames = ['Q1', 'Q2', 'Q3', 'Q4'];

        // Acquisition table
        $acqRows = [];
        foreach ($quarterNames as $qi => $qName) {
            $startMonth = $qi * 3 + 1;
            $row = [['value' => $qName.($qi === 0 ? ' (werkelijk)' : '')]];
            foreach (self::SCENARIO_KEYS as $key) {
                $qAcq = 0;
                for ($m = $startMonth; $m <= $startMonth + 2; $m++) {
                    $qAcq += $forecastData[$key]['months'][$m]['acq_revenue'] ?? 0;
                }
                $row[] = ['value' => '€'.number_format($qAcq)];
            }
            $acqRows[] = $row;
        }
        $row = [['value' => 'Totaal']];
        foreach (self::SCENARIO_KEYS as $key) {
            $row[] = ['value' => '€'.number_format($forecastData[$key]['year_total']['acq_revenue'] ?? 0)];
        }
        $acqRows[] = $row;

        $sections[] = ['type' => 'subheading', 'content' => 'Omzet uit eerste bestellingen'];
        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Kwartaal', 'width' => '28%'],
                ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
            ],
            'rows' => $acqRows,
        ];

        // Repeat table
        $repRows = [];
        foreach ($quarterNames as $qi => $qName) {
            $startMonth = $qi * 3 + 1;
            $row = [['value' => $qName.($qi === 0 ? ' (werkelijk)' : '')]];
            foreach (self::SCENARIO_KEYS as $key) {
                $qRep = 0;
                for ($m = $startMonth; $m <= $startMonth + 2; $m++) {
                    $qRep += $forecastData[$key]['months'][$m]['rep_revenue'] ?? 0;
                }
                $row[] = ['value' => '€'.number_format($qRep)];
            }
            $repRows[] = $row;
        }
        $row = [['value' => 'Totaal']];
        foreach (self::SCENARIO_KEYS as $key) {
            $row[] = ['value' => '€'.number_format($forecastData[$key]['year_total']['rep_revenue'] ?? 0)];
        }
        $repRows[] = $row;

        $sections[] = ['type' => 'subheading', 'content' => 'Omzet uit herbestellingen'];
        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Kwartaal', 'width' => '28%'],
                ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
            ],
            'rows' => $repRows,
        ];

        $sections[] = ['type' => 'analysis', 'content' => '<strong>Herbestellingen groeien elk kwartaal</strong> in alle scenario\'s. '
            .'Dat is het cohort-effect: elke maand nieuwe klanten vergroot de groep die later kan terugkomen. '
            .'Q4 heeft daarom het hoogste aandeel herbestellingen, omdat alle klanten uit Q1 tot en met Q3 bijdragen. '
            .'Het verschil tussen de scenario\'s hangt af van hoe snel en effectief we Performance Wax Kit klanten begeleiden naar een vervolgaankoop.'];

        return $sections;
    }

    private function buildMonthlyCurve(array $forecastData): array
    {
        $monthNames = ['Jan', 'Feb', 'Mrt', 'Apr', 'Mei', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = [['value' => $monthNames[$m - 1].($m <= 3 ? ' (werkelijk)' : '')]];
            foreach (self::SCENARIO_KEYS as $key) {
                $rev = $forecastData[$key]['months'][$m]['revenue'] ?? 0;
                $row[] = ['value' => '€'.number_format($rev)];
            }
            $rows[] = $row;
        }

        $row = [['value' => '2026 Totaal']];
        foreach (self::SCENARIO_KEYS as $key) {
            $row[] = ['value' => '€'.number_format($forecastData[$key]['year_total']['revenue'] ?? 0)];
        }
        $rows[] = $row;

        return [
            ['type' => 'heading', 'content' => 'Maandelijks omzetprofiel'],
            ['type' => 'text', 'content' => 'De maandelijkse verdeling toont hoe seizoenseffecten en campagnes de omzet over het jaar spreiden. Pieken in juni, september en november komen van de geplande GCN video\'s (elk 1.000 extra Performance Wax Kit verkopen). November en december tonen het Black Friday en kersteffect.'],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Maand', 'width' => '28%'],
                    ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Revenue breakdown by product group (Getting Started, Ride Activity, Chain Wear, Companion).
     *
     * @param  array<string, array<int, array<string, array>>>  $categoryData  Per-scenario forecastYear output
     */
    private function buildProductGroupSection(array $categoryData): array
    {
        $rows = [];

        foreach (ForecastGroup::cases() as $group) {
            $groupCategories = collect($group->categories())->map(fn ($c) => $c->value)->all();

            $row = [['value' => $group->label()]];
            foreach (self::SCENARIO_KEYS as $key) {
                $groupRevenue = 0;
                for ($m = 1; $m <= 12; $m++) {
                    foreach ($categoryData[$key][$m] ?? [] as $catValue => $cat) {
                        if (in_array($catValue, $groupCategories)) {
                            $groupRevenue += $cat['revenue'];
                        }
                    }
                }
                $row[] = ['value' => '€'.number_format($groupRevenue)];
            }
            $rows[] = $row;
        }

        // Total
        $row = [['value' => 'Totaal']];
        foreach (self::SCENARIO_KEYS as $key) {
            $total = 0;
            for ($m = 1; $m <= 12; $m++) {
                foreach ($categoryData[$key][$m] ?? [] as $cat) {
                    $total += $cat['revenue'];
                }
            }
            $row[] = ['value' => '€'.number_format($total)];
        }
        $rows[] = $row;

        return [
            ['type' => 'heading', 'content' => 'Omzet per productgroep'],
            ['type' => 'text', 'content' => 'Ons productaanbod is opgebouwd uit vier groepen, elk met een eigen rol in de klantreis:'],
            ['type' => 'list', 'items' => [
                '<strong>Getting Started</strong> (Starter Kit, Wax Kit, Bundles): de instapproducten waarmee klanten beginnen met hot wax.',
                '<strong>Ride Activity</strong> (Wax Tablet, Pocket Wax): de doorlopende verbruiksproducten die terugkerende omzet genereren.',
                '<strong>Chain Wear</strong> (kettingen, quick links, chain tools): vervangingsproducten voor het drivetrain onderhoud.',
                '<strong>Companion</strong> (heater, accessoires, cleaning, multi tools): het ecosysteem dat de wax-ervaring compleet maakt.',
            ]],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Productgroep', 'width' => '28%'],
                    ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ],
            ['type' => 'analysis', 'content' => '<strong>Getting Started domineert</strong> omdat de meeste omzet nog uit eerste bestellingen komt. '
                .'Naarmate het aandeel herbestellingen groeit (zie kwartaaloverzicht), verschuift de mix richting Ride Activity en Chain Wear. '
                .'Dat is het vliegwiel: nieuwe klanten kopen kits, terugkerende klanten kopen wax en kettingen.'],
        ];
    }

    private function buildRegionalSection(array $regionalData): array
    {
        $rows = [];
        foreach (ForecastRegion::cases() as $region) {
            $row = [['value' => $region->label()]];
            foreach (self::SCENARIO_KEYS as $key) {
                $rev = $regionalData[$key]['region_totals'][$region->value]['revenue'] ?? 0;
                $row[] = ['value' => '€'.number_format($rev)];
            }
            $rows[] = $row;
        }

        $row = [['value' => 'Totaal']];
        foreach (self::SCENARIO_KEYS as $key) {
            $row[] = ['value' => '€'.number_format($regionalData[$key]['year_total']['revenue'] ?? 0)];
        }
        $rows[] = $row;

        return [
            ['type' => 'heading', 'content' => 'Regionale breakdown'],
            ['type' => 'text', 'content' => 'De forecast draait apart voor negen regio\'s. Elke regio heeft eigen groeiaannames en gemiddelde orderwaarden. Regio\'s met voldoende bestelhistorie (België, Duitsland, Nederland, VS, UK) gebruiken hun eigen retentiecurve. Kleinere regio\'s vallen terug op het globale patroon.'],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Regio', 'width' => '28%'],
                    ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                    ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ],
            ['type' => 'analysis', 'content' => '<strong>België en Duitsland</strong> zijn de kernmarkten. '
                .'<strong>VS en UK</strong> groeien sneller, gedreven door de Performance Wax Kit en groeiende naamsbekendheid. '
                .'Deze regionale uitsplitsing maakt het mogelijk om marketingbudget toe te wijzen waar de groei het grootst is, '
                .'en supply planning af te stemmen per warehouse (België voor Europa, VS voor Noord-Amerika).'],
        ];
    }

    private function buildCampaignSection(): array
    {
        $events = DemandEvent::query()
            ->planned()
            ->whereYear('start_date', self::YEAR)
            ->with('categories')
            ->orderBy('start_date')
            ->get();

        $rows = [];
        foreach ($events as $event) {
            $totalUplift = $event->categories->sum('expected_uplift_units');
            $categories = $event->categories->pluck('product_category')
                ->map(fn ($c) => $c->value)
                ->join(', ');

            $rows[] = [
                ['value' => $event->name],
                ['value' => $event->start_date->format('d M').' – '.$event->end_date->format('d M')],
                ['value' => $categories],
                ['value' => number_format($totalUplift).' units'],
            ];
        }

        return [
            ['type' => 'heading', 'content' => 'Geplande campagnes 2026'],
            ['type' => 'text', 'content' => 'Campagnes voegen extra verkopen toe bovenop het basisniveau. De kolom "verwachte uplift" toont het totaal aantal extra units dat elke campagne naar verwachting oplevert, verdeeld over de genoemde productcategorieën en de duur van de campagne.'],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Campagne', 'width' => '25%'],
                    ['label' => 'Periode', 'width' => '20%'],
                    ['label' => 'Categorieën', 'width' => '30%'],
                    ['label' => 'Verwachte uplift', 'width' => '25%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ],
            ['type' => 'analysis', 'content' => '<strong>De drie GCN video\'s zijn de grootste pieken.</strong> '
                .'Op basis van de maart-ervaring (de Performance Wax Kit launch met GCN exposure trok bijna 1.000 kit-verkopen in twee weken) '
                .'rekenen we met 1.000 extra Performance Wax Kit units per video. '
                .'Black Friday en kerst richten zich breed op het portfolio: kits, bundles en gift cards voor het cadeausegment.'],
        ];
    }

    /**
     * Summarize the configured parameters: growth assumptions, repeat rates, AOV, and regional weighting.
     */
    private function buildConfigurationSection(Collection $scenarios): array
    {
        // Global assumptions table
        $assumptionRows = [];
        foreach (self::SCENARIO_KEYS as $key) {
            $scenario = $scenarios->first(fn ($s) => $s->name === $key);
            if (! $scenario) {
                continue;
            }

            $globalAssumptions = $scenario->assumptions
                ->whereNull('region')
                ->sortBy('quarter');

            foreach ($globalAssumptions as $a) {
                $assumptionRows[] = [
                    ['value' => self::SCENARIO_LABELS[$key]],
                    ['value' => $a->quarter],
                    ['value' => round($a->acq_rate * 100).'%'],
                    ['value' => round($a->repeat_rate * 100).'%'],
                    ['value' => '€'.number_format($a->repeat_aov)],
                ];
            }
        }

        // Regional AOV sample (base scenario only, Q2)
        $baseScenario = $scenarios->first(fn ($s) => $s->name === 'base');
        $regionalRows = [];
        if ($baseScenario) {
            $q2Regional = $baseScenario->assumptions
                ->where('quarter', 'Q2')
                ->whereNotNull('region')
                ->sortBy('region');

            foreach ($q2Regional as $a) {
                $region = ForecastRegion::from($a->region->value);
                $regionalRows[] = [
                    ['value' => $region->label()],
                    ['value' => round($a->acq_rate * 100).'%'],
                    ['value' => '€'.number_format($a->repeat_aov)],
                ];
            }
        }

        $sections = [
            ['type' => 'heading', 'content' => 'Configuratie achter de forecast'],
            ['type' => 'text', 'content' => 'Hieronder staan de parameters die het model aansturen. Dit zijn de "knoppen" waaraan we draaien per scenario. In een latere fase worden deze configureerbaar via de dashboard interface.'],

            ['type' => 'subheading', 'content' => 'Groeiparameters per kwartaal'],
            ['type' => 'text', 'content' => 'Per scenario en per kwartaal drie kernparameters: het groeitempo van nieuwe klanten ten opzichte van Q1 2026, '
                .'het verwachte percentage terugkerende klanten (als terugvaloptie wanneer het cohort-model onvoldoende data heeft), '
                .'en de verwachte gemiddelde orderwaarde bij herbestellingen.'],
            [
                'type' => 'table',
                'headers' => [
                    ['label' => 'Scenario', 'width' => '20%'],
                    ['label' => 'Kwartaal', 'width' => '12%'],
                    ['label' => 'Groeitempo', 'width' => '20%', 'align' => 'text-right'],
                    ['label' => 'Herbestel %', 'width' => '20%', 'align' => 'text-right'],
                    ['label' => 'Gem. herbestelling', 'width' => '24%', 'align' => 'text-right'],
                ],
                'rows' => $assumptionRows,
            ],
        ];

        if (count($regionalRows) > 0) {
            $sections[] = ['type' => 'subheading', 'content' => 'Regionale parameters (medium scenario, Q2)'];
            $sections[] = ['type' => 'text', 'content' => 'Elke regio heeft eigen instellingen. Hieronder een voorbeeld voor het medium scenario in Q2. Het groeitempo en de gemiddelde orderwaarde per herbestelling variëren per regio op basis van historische patronen.'];
            $sections[] = [
                'type' => 'compact-table',
                'headers' => [
                    ['label' => 'Regio', 'width' => '34%'],
                    ['label' => 'Groeitempo', 'width' => '33%', 'align' => 'text-right'],
                    ['label' => 'Gem. herbestelling', 'width' => '33%', 'align' => 'text-right'],
                ],
                'rows' => $regionalRows,
            ];
        }

        return $sections;
    }

    private function buildRisksSection(): array
    {
        return [
            ['type' => 'heading', 'content' => 'Risico\'s en onzekerheden'],
            ['type' => 'list', 'items' => [
                '<strong>Het herbestelgedrag van Performance Wax Kit klanten is nog onbekend.</strong> '
                    .'Deze klantgroep is pas drie maanden oud. We weten nog niet hoe hun kooppatroon zich ontwikkelt. '
                    .'De drie scenario\'s geven de bandbreedte van wat we redelijk achten, niet wat we met zekerheid weten.',

                '<strong>Het effect van GCN video\'s kan per keer verschillen.</strong> '
                    .'De inschatting van 1.000 extra units per video is gebaseerd op de maart-ervaring. '
                    .'Herhaalde blootstelling kan minder impact hebben (het publiek kent het product al), of juist meer (het kanaal groeit). '
                    .'De forecast behandelt elke video als gelijkwaardig.',

                '<strong>Seizoenspatronen zijn gebaseerd op twee jaar data.</strong> '
                    .'De patronen uit 2024 en 2025 hoeven zich niet exact te herhalen. '
                    .'Externe factoren zoals weer, economische omstandigheden en concurrentie zijn niet meegenomen in het model.',

                '<strong>Dit is een omzetforecast, geen winst-forecast.</strong> '
                    .'De margeberekening per regio (netto omzet minus productkosten, verzending en betaalkosten) is beschikbaar in het systeem, maar niet opgenomen in dit rapport. '
                    .'Als de productkosten van de Performance Heater hoger liggen dan van de Original, kan de winstgevendheid bij gelijke omzet lager uitvallen.',
            ]],
        ];
    }
}
