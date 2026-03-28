<?php

namespace App\Console\Commands;

use App\Services\AnalysisPdfService;
use App\Services\ForecastService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:revenue-report')]
#[Description('Generate 2026 Revenue Forecast PDF with 3 scenarios')]
class GenerateForecastReportCommand extends Command
{
    public function handle(AnalysisPdfService $pdf, ForecastService $forecast): int
    {
        $this->info('Generating 2026 Revenue Forecast...');

        $q1Actual = $forecast->periodActuals('2026-01-01', '2026-04-01');
        $rev2025 = $forecast->yearRevenue(2025);

        $scenarios = $this->buildScenarios($forecast, $q1Actual);

        $data = [
            'title' => '2026 Revenue Forecast',
            'subtitle' => 'Scenario-analyse op basis van Q1 2026 acquisitie en portfolio retentie-patronen',
            'context' => 'Leadership Team Update',
            'quote' => 'Always a clean chain',
            'landscape' => true,
            'intro' => 'Deze forecast projecteert de Cyclowax net revenue voor 2026 op basis van drie scenario\'s. '
                .'Het model combineert het actuele acquisitietempo uit Q1 2026, seizoenspatronen uit 2024-2025, '
                .'en de retentiecurves uit de Portfolio Product Map analyse. '
                .'Q1 2026 is afgesloten met €'.number_format($q1Actual['total_rev']).' net revenue, '
                .'waarvan €'.number_format($q1Actual['acq_rev']).' uit acquisitie en €'.number_format($q1Actual['rep_rev']).' uit herbestellingen.',
            'metrics' => [
                ['label' => 'Q1 2026 Actueel', 'value' => '€'.number_format($q1Actual['total_rev']), 'change' => '62% van heel 2025 in 3 maanden'],
                ['label' => '2025 Net Revenue', 'value' => '€'.number_format($rev2025), 'change' => 'referentiejaar'],
                ['label' => 'Maart 2026 Piek', 'value' => '1.240', 'change' => 'nieuwe klanten (Performance Wax Kit launch)'],
                ['label' => 'Dagelijks Tempo Mrt', 'value' => '€9.562/dag', 'change' => 'vs €2.557/dag in januari'],
            ],
            'sections' => $this->buildSections($q1Actual, $rev2025, $scenarios),
        ];

        $this->info('Rendering PDF...');
        $draftPath = $pdf->save($data, '2026-revenue-forecast_draft-1.pdf');
        $paths = $pdf->finalize($draftPath, '2026-revenue-forecast.pdf');
        $this->info("Finalized: {$paths['desktop']}");

        return self::SUCCESS;
    }

    private function buildSections(array $q1, float $rev2025, array $scenarios): array
    {
        $sections = [];

        // === PAGE 1: What happened in Q1 ===
        $sections[] = ['type' => 'heading', 'content' => 'Wat Q1 2026 ons vertelt'];
        $sections[] = ['type' => 'text', 'content' => 'De Performance Wax Kit launch in maart veranderde het speelveld. Waar januari nog op het niveau van 2025 zat (~19 orders/dag), versnelde februari naar 25 orders/dag en explodeerde maart naar 55 orders/dag. Het dagelijkse tempo na de launch-piek is structureel hoger dan ervoor.'];

        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Maand', 'width' => '12%'],
                ['label' => 'Nieuwe klanten', 'width' => '15%', 'align' => 'text-right'],
                ['label' => 'Acquisitie revenue', 'width' => '18%', 'align' => 'text-right'],
                ['label' => 'Repeat orders', 'width' => '15%', 'align' => 'text-right'],
                ['label' => 'Repeat revenue', 'width' => '18%', 'align' => 'text-right'],
                ['label' => 'Totaal', 'width' => '18%', 'align' => 'text-right'],
            ],
            'rows' => [
                [['value' => 'Januari'], ['value' => '389'], ['value' => '€'.number_format(56372)], ['value' => '192'], ['value' => '€'.number_format(22902)], ['value' => '€'.number_format(79274)]],
                [['value' => 'Februari'], ['value' => '491'], ['value' => '€'.number_format(75308)], ['value' => '200'], ['value' => '€'.number_format(24153)], ['value' => '€'.number_format(99461)]],
                [['value' => 'Maart', 'class' => 'highlight'], ['value' => '1.240', 'class' => 'highlight'], ['value' => '€'.number_format(224281), 'class' => 'highlight'], ['value' => '258'], ['value' => '€'.number_format(33898)], ['value' => '€'.number_format(258178), 'class' => 'highlight']],
                [['value' => 'Q1 Totaal'], ['value' => '2.120'], ['value' => '€'.number_format(355961)], ['value' => '650'], ['value' => '€'.number_format(80953)], ['value' => '€'.number_format(436914)]],
            ],
        ];

        $sections[] = ['type' => 'analysis', 'content' => '<strong>De Performance Wax Kit als groeiversneller</strong><br><br>'
            .'996 van de 1.240 nieuwe klanten in maart kwamen binnen via de Performance Wax Kit. '
            .'Dit is niet alleen een launch-piek: het dagelijkse verkooptempo na de piek bleef structureel hoger. '
            .'De marketing-activiteiten rond de Performance Heater creëren een nieuw baseline-niveau voor acquisitie. '
            .'Vergelijkbare piekmomenten worden verwacht bij events, seizoenscampagnes en verdere productlanceringen.'];

        // === PAGE 2: Three scenarios overview ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Drie scenario\'s voor 2026'];
        $sections[] = ['type' => 'text', 'content' => 'Elk scenario deelt dezelfde Q1 2026 actuals (€'.number_format($q1['total_rev']).'). Het verschil zit in het acquisitietempo voor Q2-Q4 en de retentie-dynamiek van de Performance Wax Kit klanten.'];

        // Scenario comparison table
        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => '', 'width' => '28%'],
                ['label' => 'Voorzichtig', 'width' => '24%', 'align' => 'text-right'],
                ['label' => 'Medium', 'width' => '24%', 'align' => 'text-right'],
                ['label' => 'Best Case', 'width' => '24%', 'align' => 'text-right'],
            ],
            'rows' => [
                [['value' => 'Net Revenue 2026'], ['value' => '€'.number_format($scenarios['voorzichtig']['total'])], ['value' => '€'.number_format($scenarios['medium']['total'])], ['value' => '€'.number_format($scenarios['best_case']['total']), 'class' => 'highlight']],
                [['value' => 'Groei vs 2025 (€'.number_format($rev2025).')'], ['value' => '+'.round(($scenarios['voorzichtig']['total'] / $rev2025 - 1) * 100).'%'], ['value' => '+'.round(($scenarios['medium']['total'] / $rev2025 - 1) * 100).'%'], ['value' => '+'.round(($scenarios['best_case']['total'] / $rev2025 - 1) * 100).'%']],
                [['value' => 'Nieuwe klanten'], ['value' => number_format($scenarios['voorzichtig']['new_cust'])], ['value' => number_format($scenarios['medium']['new_cust'])], ['value' => number_format($scenarios['best_case']['new_cust'])]],
                [['value' => 'Acquisitie revenue'], ['value' => '€'.number_format($scenarios['voorzichtig']['acq_total'])], ['value' => '€'.number_format($scenarios['medium']['acq_total'])], ['value' => '€'.number_format($scenarios['best_case']['acq_total'])]],
                [['value' => 'Repeat revenue'], ['value' => '€'.number_format($scenarios['voorzichtig']['rep_total'])], ['value' => '€'.number_format($scenarios['medium']['rep_total'])], ['value' => '€'.number_format($scenarios['best_case']['rep_total'])]],
                [['value' => 'Repeat als % van totaal'], ['value' => round($scenarios['voorzichtig']['rep_total'] * 100 / $scenarios['voorzichtig']['total'], 1).'%'], ['value' => round($scenarios['medium']['rep_total'] * 100 / $scenarios['medium']['total'], 1).'%'], ['value' => round($scenarios['best_case']['rep_total'] * 100 / $scenarios['best_case']['total'], 1).'%']],
            ],
        ];

        // Scenario descriptions
        $sections[] = ['type' => 'analysis', 'content' => '<strong>Voorzichtig</strong>: 2 kwartalen op Q1-niveau, 2 kwartalen op 70%. Nog 1 piekmoment. '
            .'Performance Wax Kit repeat rate ~20%. Repeat AOV normaliseert naar €85.<br><br>'
            .'<strong>Medium</strong>: 2 kwartalen op Q1-niveau, 2 kwartalen op 85%. 2 piekmomenten. '
            .'PWK repeat rate ~25% door actieve upsell naar chain en pocket wax. Repeat AOV €95.<br><br>'
            .'<strong>Best Case</strong>: Elk kwartaal op of boven Q1. 3 piekmomenten. '
            .'PWK repeat rate bouwt op van 22% (Q2) naar 32% (Q4) naarmate upsell-flows geoptimaliseerd worden. '
            .'Chain en pocket wax upsell drijft repeat AOV naar €110-120.'];

        // === PAGE 3: Quarterly breakdown per scenario ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Kwartaaloverzicht per scenario'];

        foreach (['voorzichtig' => 'Voorzichtig', 'medium' => 'Medium', 'best_case' => 'Best Case'] as $key => $label) {
            $s = $scenarios[$key];
            $sections[] = ['type' => 'text', 'content' => '<strong>'.$label.'</strong>: €'.number_format($s['total']).' net revenue (+'.round(($s['total'] / $rev2025 - 1) * 100).'% vs 2025)'];

            $rows = [];
            foreach ($s['quarters'] as $qName => $q) {
                $tag = $qName === 'Q1' ? ' (act)' : '';
                $rows[] = [
                    ['value' => $qName.$tag],
                    ['value' => number_format($q['new_cust'])],
                    ['value' => '€'.number_format($q['acq_rev'])],
                    ['value' => number_format($q['rep_orders'])],
                    ['value' => '€'.number_format($q['rep_rev'])],
                    ['value' => '€'.number_format($q['acq_rev'] + $q['rep_rev'])],
                ];
            }
            $rows[] = [
                ['value' => '2026 Totaal'],
                ['value' => number_format($s['new_cust'])],
                ['value' => '€'.number_format($s['acq_total'])],
                ['value' => number_format($s['rep_orders'])],
                ['value' => '€'.number_format($s['rep_total'])],
                ['value' => '€'.number_format($s['total'])],
            ];

            $sections[] = [
                'type' => 'compact-table',
                'headers' => [
                    ['label' => 'Kwartaal', 'width' => '14%'],
                    ['label' => 'Nieuwe kl.', 'width' => '14%', 'align' => 'text-right'],
                    ['label' => 'Acq. rev.', 'width' => '18%', 'align' => 'text-right'],
                    ['label' => 'Rep. orders', 'width' => '14%', 'align' => 'text-right'],
                    ['label' => 'Rep. rev.', 'width' => '18%', 'align' => 'text-right'],
                    ['label' => 'Totaal', 'width' => '18%', 'align' => 'text-right'],
                ],
                'rows' => $rows,
            ];
        }

        // === PAGE 4: Key drivers and assumptions ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Wat de forecast drijft'];

        $sections[] = ['type' => 'list', 'items' => [
            '<strong>Acquisitie: de Performance Wax Kit verandert de baseline.</strong> '
                .'Q1 laat zien dat marketing-activiteiten rond de Performance Heater structureel meer klanten aantrekken. '
                .'Maart was een piek, maar het dagelijkse tempo erna bleef hoger dan in januari en februari. '
                .'De forecast gaat uit van vergelijkbare piekmomenten bij events en campagnes.',

            '<strong>Retentie: de tweede aankoop is de sleutel.</strong> '
                .'De Portfolio Product Map toont dat 31,6% van de Starter Kit klanten terugkomt, tegenover 11,8% voor de oude Wax Kit. '
                .'De Performance Wax Kit klanten zijn nieuw: hun repeat-gedrag is nog onbekend. '
                .'De scenario\'s variëren op basis van hoe snel en hoe effectief we deze klanten naar een tweede aankoop begeleiden.',

            '<strong>Chain en pocket wax als upsell zijn de hefboom.</strong> '
                .'De Performance Wax Kit bevat geen ketting. Uit de Starter Kit data weten we dat klanten die het volledige ecosysteem ervaren (inclusief ketting) 3x vaker terugkomen. '
                .'Actieve upsell naar prewaxed chains en pocket wax kan het verschil maken tussen voorzichtig en best case.',

            '<strong>Repeat AOV stijgt.</strong> '
                .'Q1 2026 repeat AOV is €125, waar 2025 op €76 zat. Dit komt deels door de hogere productprijzen van de Performance lijn. '
                .'De forecast gebruikt conservatieve AOV-aannames (€85-120) die onder het Q1-niveau liggen.',
        ]];

        $sections[] = ['type' => 'heading', 'content' => 'Risico\'s en onzekerheden'];
        $sections[] = ['type' => 'list', 'items' => [
            '<strong>Performance Wax Kit retentie is onbewezen.</strong> '
                .'We hebben nog geen repeat-data voor dit product. De aannames zijn gebaseerd op Starter Kit patronen en Thomas\' verwachting, niet op bewezen gedrag.',

            '<strong>Maart-effect kan eenmalig zijn.</strong> '
                .'De pre-order piek was deels een opgespaarde vraag. Als vervolgcampagnes minder impact hebben, valt het voorzichtige scenario realistischer uit.',

            '<strong>Seizoenaliteit is gebaseerd op 2 jaar data.</strong> '
                .'2024-2025 patronen hoeven zich niet exact te herhalen. Externe factoren (weer, economie, concurrentie) zijn niet meegenomen.',

            '<strong>COGS-stijging niet meegenomen.</strong> '
                .'Deze forecast betreft net revenue, niet marge. Als de Performance Heater COGS hoger is dan de Original, kan de winstgevendheid bij gelijke omzet lager uitvallen.',
        ]];

        return $sections;
    }

    /**
     * Build the three forecast scenarios using ForecastService.
     *
     * Scenario definitions (assumptions) live here — they are presentation choices.
     * The actual calculations are delegated to ForecastService.
     *
     * @return array<string, array{quarters: array, totals: array}>
     */
    private function buildScenarios(ForecastService $forecast, array $q1Actual): array
    {
        // Voorzichtig: 2 kwartalen op 70%, 1 piekmoment, PWK repeat ~20%, AOV €85
        $voorzichtig = $forecast->calculateScenario([
            'Q2' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
            'Q3' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
            'Q4' => ['acq_rate' => 1.00, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
        ], $q1Actual);

        // Medium: 2 kwartalen op 85%, 2 piekmomenten, PWK repeat ~25%, AOV €95
        $medium = $forecast->calculateScenario([
            'Q2' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
            'Q3' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
            'Q4' => ['acq_rate' => 1.08, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
        ], $q1Actual);

        // Best Case: elk kwartaal op/boven Q1, 3 piekmomenten, repeat bouwt op
        $bestCase = $forecast->calculateScenario([
            'Q2' => ['acq_rate' => 1.08, 'repeat_rate' => 0.22, 'repeat_aov' => 95],
            'Q3' => ['acq_rate' => 1.00, 'repeat_rate' => 0.28, 'repeat_aov' => 110],
            'Q4' => ['acq_rate' => 1.20, 'repeat_rate' => 0.32, 'repeat_aov' => 120],
        ], $q1Actual);

        return [
            'voorzichtig' => ['quarters' => $voorzichtig['quarters'], ...$voorzichtig['totals']],
            'medium' => ['quarters' => $medium['quarters'], ...$medium['totals']],
            'best_case' => ['quarters' => $bestCase['quarters'], ...$bestCase['totals']],
        ];
    }
}
