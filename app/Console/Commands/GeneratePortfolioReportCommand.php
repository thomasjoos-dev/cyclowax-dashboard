<?php

namespace App\Console\Commands;

use App\Enums\ProductCategory;
use App\Services\AnalysisPdfService;
use App\Services\ProductPortfolioService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('products:portfolio-report')]
#[Description('Generate Portfolio Product Map PDF report')]
class GeneratePortfolioReportCommand extends Command
{
    public function handle(ProductPortfolioService $portfolio, AnalysisPdfService $pdf): int
    {
        $this->info('Generating Portfolio Product Map report...');

        $scorecard = $portfolio->portfolioScorecard();
        $acquisition = $portfolio->acquisitionProfile();
        $transitions = $portfolio->transitionMatrix();
        $productTransitions = $portfolio->transitionMatrix(drillDown: true);
        $timing = $portfolio->timingProfile();
        $ladder = $this->purchaseLadder();
        $repeatProbability = $this->repeatProbabilityPerCategory();
        $starterKitPathways = $this->productPathways('starter_kit');
        $waxKitPathways = $this->productPathways('wax_kit');
        $threeStepJourney = $this->threeStepJourney();

        $totalCustomers = collect($ladder)->sum('customers');
        $totalRevenue = collect($ladder)->sum('total_revenue');
        $repeaters = collect($ladder)->where('order_count', '>', 1)->sum('customers');
        $repeaterRevenue = collect($ladder)->where('order_count', '>', 1)->sum('total_revenue');
        $repeatRate = round($repeaters * 100 / $totalCustomers, 1);
        $avgLtvRepeaters = $repeaters > 0 ? round($repeaterRevenue / $repeaters) : 0;

        $data = [
            'title' => 'Portfolio Product Map',
            'subtitle' => 'Behavioral Sequencing Analysis — Product portfolio 2024+',
            'context' => 'Leadership Team Update',
            'quote' => 'Always a clean chain',
            'intro' => 'Analyse van '.number_format($totalCustomers).' klanten en hun aankoopgedrag op productniveau. '
                .'Dit rapport identificeert de rol van elk producttype in de customer journey: welke producten brengen klanten binnen, '
                .'welke stimuleren herhaalaankopen, en welke flows leiden tot de hoogste lifetime value. '
                .'COGS coverage: 99,5% van revenue (2024+ scope).',
            'metrics' => [
                ['label' => 'Klanten (2024+)', 'value' => number_format($totalCustomers), 'change' => number_format($repeaters).' herbestelden'],
                ['label' => 'Second Purchase Rate', 'value' => $repeatRate.'%', 'change' => 'van 1e naar 2e order'],
                ['label' => 'LTV Repeaters', 'value' => '€'.number_format($avgLtvRepeaters), 'change' => 'gem. bij 2+ orders'],
                ['label' => 'Revenue Repeaters', 'value' => round($repeaterRevenue * 100 / $totalRevenue, 1).'%', 'change' => 'van totale revenue'],
            ],
            'sections' => $this->buildSections(
                $scorecard, $acquisition, $transitions, $timing,
                $ladder, $repeatProbability,
                $starterKitPathways, $waxKitPathways, $threeStepJourney,
                $totalCustomers, $totalRevenue
            ),
        ];

        $this->info('Rendering PDF...');
        $draftPath = $pdf->save($data, 'portfolio-product-map_draft-1.pdf');
        $this->info("Draft saved: {$draftPath}");

        $paths = $pdf->finalize($draftPath, 'portfolio-product-map.pdf');
        $this->info("Finalized: {$paths['desktop']}");

        return self::SUCCESS;
    }

    private function buildSections(
        array $scorecard, array $acquisition, array $transitions, array $timing,
        array $ladder, array $repeatProbability,
        array $starterKitPathways, array $waxKitPathways, array $threeStepJourney,
        int $totalCustomers, float $totalRevenue,
    ): array {
        $sections = [];

        // === PAGE 1: Purchase Ladder ===
        $sections[] = ['type' => 'heading', 'content' => 'De aankoop-ladder'];
        $sections[] = ['type' => 'text', 'content' => 'De tweede aankoop is de bottleneck. Slechts 19,6% maakt die stap. Maar zodra ze dat doen, stijgt de kans op een derde aankoop naar 28,7% — en daarna naar 30%+. De '.round(collect($ladder)->where('order_count', '>', 1)->sum('customers') * 100 / $totalCustomers).'% klanten die herbestellen genereren '.round(collect($ladder)->where('order_count', '>', 1)->sum('total_revenue') * 100 / $totalRevenue).'% van de revenue.'];

        $ladderRows = [];
        $prev = null;
        foreach ($ladder as $row) {
            $convRate = $prev ? round($row['customers'] * 100 / $prev, 1).'%' : '—';
            $ladderRows[] = [
                ['value' => $row['label']],
                ['value' => number_format($row['customers'])],
                ['value' => round($row['customers'] * 100 / $totalCustomers, 1).'%'],
                ['value' => '€'.number_format($row['avg_ltv'])],
                ['value' => round($row['total_revenue'] * 100 / $totalRevenue, 1).'%'],
                ['value' => $convRate, 'class' => $convRate !== '—' ? 'highlight' : ''],
            ];
            $prev = $row['customers'];
        }

        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Orders', 'width' => '18%'],
                ['label' => 'Klanten', 'width' => '14%', 'align' => 'text-right'],
                ['label' => '% Totaal', 'width' => '14%', 'align' => 'text-right'],
                ['label' => 'Gem. LTV', 'width' => '14%', 'align' => 'text-right'],
                ['label' => '% Revenue', 'width' => '14%', 'align' => 'text-right'],
                ['label' => 'Conversie', 'width' => '14%', 'align' => 'text-right'],
            ],
            'rows' => $ladderRows,
        ];

        // === PAGE 2: Portfolio Classification ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Product Portfolio Classificatie'];
        $sections[] = ['type' => 'text', 'content' => 'Elk producttype heeft een strategische rol in de customer journey. <strong>Acquisition SKUs</strong> brengen klanten binnen. <strong>Retention Drivers</strong> zijn de producten die klanten als tweede kopen — ze activeren het herhalingsgedrag. <strong>Loyalty Builders</strong> zijn goedkope, frequente aankopen die de relatie warm houden. <strong>Margin Protectors</strong> hebben hoge marge en zijn geschikt voor upsells naar bestaande klanten.'];

        $scorecardRows = [];
        foreach ($scorecard as $s) {
            if ($s->label === 'Accessory' && $s->units_sold < 50) {
                continue;
            }
            $scorecardRows[] = [
                ['value' => $s->label],
                ['value' => $s->portfolio_role],
                ['value' => number_format($s->units_sold)],
                ['value' => '€'.number_format($s->revenue, 0)],
                ['value' => $s->margin_pct.'%'],
                ['value' => $s->repeat_rate ? $s->repeat_rate.'%' : '—'],
                ['value' => $s->avg_days_to_repeat ?? '—'],
                ['value' => $s->top_next_category ? ProductCategory::tryFrom($s->top_next_category)?->label() ?? $s->top_next_category : '—'],
            ];
        }

        $sections[] = [
            'type' => 'compact-table',
            'headers' => [
                ['label' => 'Categorie', 'width' => '16%'],
                ['label' => 'Rol', 'width' => '14%'],
                ['label' => 'Units', 'width' => '8%', 'align' => 'text-right'],
                ['label' => 'Revenue', 'width' => '12%', 'align' => 'text-right'],
                ['label' => 'Marge', 'width' => '8%', 'align' => 'text-right'],
                ['label' => 'Repeat%', 'width' => '9%', 'align' => 'text-right'],
                ['label' => 'Dagen', 'width' => '8%', 'align' => 'text-right'],
                ['label' => 'Next →', 'width' => '16%'],
            ],
            'rows' => $scorecardRows,
        ];

        // === PAGE 3: Acquisition Deep Dive ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Acquisition SKUs — Getting Started'];

        $starterProb = collect($repeatProbability)->firstWhere('product_category', 'starter_kit');
        $waxKitProb = collect($repeatProbability)->firstWhere('product_category', 'wax_kit');

        if ($starterProb && $waxKitProb) {
            $sections[] = ['type' => 'analysis', 'content' => '<strong>Starter Kit vs. Wax Kit — fundamenteel verschillend gedrag</strong><br><br>'
                .'Starter Kit: <strong>'.$starterProb->pct_2nd.'% repeat rate</strong>, '
                .$starterProb->pct_3rd_given_2nd.'% kans op 3e order na 2e, '
                .'LTV repeaters €'.number_format($starterProb->avg_ltv_repeaters).'<br>'
                .'Wax Kit: <strong>'.$waxKitProb->pct_2nd.'% repeat rate</strong>, '
                .$waxKitProb->pct_3rd_given_2nd.'% kans op 3e order na 2e, '
                .'LTV repeaters €'.number_format($waxKitProb->avg_ltv_repeaters).'<br><br>'
                .'De Starter Kit bevat een prewaxed ketting — de klant ervaart het volledige wax-ecosysteem. '
                .'De Wax Kit klant mist dat onderdeel. Dit verschil van <strong>3x in repeat rate</strong> '
                .'is de belangrijkste finding van deze analyse.',
            ];
        }

        $acqRows = [];
        foreach ($repeatProbability as $r) {
            $acqRows[] = [
                ['value' => $r->product_category],
                ['value' => number_format($r->total_customers)],
                ['value' => $r->pct_2nd.'%', 'class' => $r->pct_2nd >= 25 ? 'highlight' : ''],
                ['value' => ($r->pct_3rd_given_2nd ?? '—').'%'],
                ['value' => '€'.number_format($r->avg_ltv)],
                ['value' => '€'.number_format($r->avg_ltv_repeaters)],
            ];
        }

        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Eerste aankoop', 'width' => '20%'],
                ['label' => 'Klanten', 'width' => '12%', 'align' => 'text-right'],
                ['label' => 'P(2e)', 'width' => '10%', 'align' => 'text-right'],
                ['label' => 'P(3e|2e)', 'width' => '10%', 'align' => 'text-right'],
                ['label' => 'Gem. LTV', 'width' => '12%', 'align' => 'text-right'],
                ['label' => 'LTV repeaters', 'width' => '14%', 'align' => 'text-right'],
            ],
            'rows' => $acqRows,
        ];

        // === PAGE 4: Behavioral Pathways ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Behavioral Pathways — First → Second Purchase'];
        $sections[] = ['type' => 'text', 'content' => 'Wat kopen klanten als tweede na hun eerste aankoop? De top-producten per acquisitie-categorie:'];

        // Starter Kit pathways
        $sections[] = ['type' => 'text', 'content' => '<strong>Na Starter Kit aankoop</strong> (1.101 repeaters van 3.482):'];
        $skRows = [];
        foreach (array_slice($starterKitPathways, 0, 10) as $p) {
            $skRows[] = [
                ['value' => $p->product_title],
                ['value' => ProductCategory::tryFrom($p->product_category)?->label() ?? $p->product_category],
                ['value' => $p->cnt],
                ['value' => $p->pct.'%'],
            ];
        }
        $sections[] = [
            'type' => 'compact-table',
            'headers' => [
                ['label' => 'Product (order 2)', 'width' => '40%'],
                ['label' => 'Categorie', 'width' => '22%'],
                ['label' => 'Klanten', 'width' => '14%', 'align' => 'text-right'],
                ['label' => '% van totaal', 'width' => '14%', 'align' => 'text-right'],
            ],
            'rows' => $skRows,
        ];

        // Wax Kit pathways
        $sections[] = ['type' => 'text', 'content' => '<strong>Na Wax Kit aankoop</strong> (338 repeaters van 2.881):'];
        $wkRows = [];
        foreach (array_slice($waxKitPathways, 0, 10) as $p) {
            $wkRows[] = [
                ['value' => $p->product_title],
                ['value' => ProductCategory::tryFrom($p->product_category)?->label() ?? $p->product_category],
                ['value' => $p->cnt],
                ['value' => $p->pct.'%'],
            ];
        }
        $sections[] = [
            'type' => 'compact-table',
            'headers' => [
                ['label' => 'Product (order 2)', 'width' => '40%'],
                ['label' => 'Categorie', 'width' => '22%'],
                ['label' => 'Klanten', 'width' => '14%', 'align' => 'text-right'],
                ['label' => '% van totaal', 'width' => '14%', 'align' => 'text-right'],
            ],
            'rows' => $wkRows,
        ];

        // Compounding loops
        $sections[] = ['type' => 'analysis', 'content' => '<strong>Drie compounding loops</strong><br><br>'
            .'<strong>Loop 1 — De Wax Cyclus</strong> (sterkst): Starter Kit → Wax Tablet (38,5%) → Wax Tablet (33%) → ... Verbruiksproduct, ~190 dagen interval.<br><br>'
            .'<strong>Loop 2 — De Chain Cyclus</strong>: Chain → Chain (36%) → Chain (33%) → ... Zelf-herhalend, ~148 dagen. Multi-bike of competitieve renners.<br><br>'
            .'<strong>Loop 3 — De Pocket Wax Bridge</strong>: Starter Kit → Pocket Wax (14,2%) → Wax Tablet (32,5%) → ... Pocket wax als tussenstap naar hot wax herbestelling.',
        ];

        // === PAGE 5: Full Journey + Timing ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Customer Journey — 3 stappen vanuit Starter Kit'];

        $journeyRows = [];
        foreach (array_slice($threeStepJourney, 0, 15) as $j) {
            $journeyRows[] = [
                ['value' => ProductCategory::tryFrom($j->step2)?->label() ?? $j->step2],
                ['value' => ProductCategory::tryFrom($j->step3)?->label() ?? $j->step3],
                ['value' => $j->customers],
            ];
        }
        $sections[] = [
            'type' => 'table',
            'headers' => [
                ['label' => 'Order 2 (categorie)', 'width' => '35%'],
                ['label' => 'Order 3 (categorie)', 'width' => '35%'],
                ['label' => 'Klanten', 'width' => '15%', 'align' => 'text-right'],
            ],
            'rows' => $journeyRows,
        ];

        // Timing
        $sections[] = ['type' => 'heading', 'content' => 'Timing — Dagen tot tweede aankoop'];
        $sections[] = ['type' => 'text', 'content' => 'Optimale timing voor next-best-offer flows per acquisitie-categorie:'];

        $timingRows = [];
        foreach ($timing as $t) {
            $timingRows[] = [
                ['value' => ProductCategory::tryFrom($t->product_category)?->label() ?? $t->product_category],
                ['value' => number_format($t->repeat_customers)],
                ['value' => $t->avg_days.'d'],
                ['value' => $t->pct_30d.'%'],
                ['value' => $t->pct_60d.'%'],
                ['value' => $t->pct_90d.'%'],
                ['value' => $t->pct_180d.'%'],
            ];
        }
        $sections[] = [
            'type' => 'compact-table',
            'headers' => [
                ['label' => 'Eerste aankoop', 'width' => '20%'],
                ['label' => 'Repeaters', 'width' => '11%', 'align' => 'text-right'],
                ['label' => 'Gem. dagen', 'width' => '11%', 'align' => 'text-right'],
                ['label' => '≤30d', 'width' => '10%', 'align' => 'text-right'],
                ['label' => '≤60d', 'width' => '10%', 'align' => 'text-right'],
                ['label' => '≤90d', 'width' => '10%', 'align' => 'text-right'],
                ['label' => '≤180d', 'width' => '10%', 'align' => 'text-right'],
            ],
            'rows' => $timingRows,
        ];

        // === PAGE 6: Strategic Implications ===
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Strategische Implicaties'];

        $sections[] = ['type' => 'list', 'items' => [
            '<strong>De Wax Kit paradox:</strong> 11,8% repeat rate is structureel laag. De Performance Wax Kit is het nieuwe flagship — als die dezelfde dynamiek vertoont, verlies je 88% van die klanten na order 1. Hypothese: wax kit klanten missen de ketting-ervaring en ervaren niet het volledige ecosysteem. Aanbeveling: monitor Performance Wax Kit repeat rate apart en overweeg een chain add-on als next-best-offer.',

            '<strong>Pocket Wax als onverwachte retention bridge:</strong> 14,2% van starter kit kopers koopt pocket wax als tweede product — meer dan elke individuele ketting. Goedkoop (€12,74), hoge marge (79%), en leidt in 32,5% naar wax tablet herbestelling. Aanbeveling: pocket wax actiever positioneren in post-purchase flows.',

            '<strong>Wax Tablet is de universele retention driver:</strong> #1 tweede aankoop na bijna elke categorie (33-42% van transitions). 77,7% marge, €19 per unit. Dit verbruiksproduct is de kern van alle retentiecycli.',

            '<strong>Multi-bike renners identificeren:</strong> Chain → Chain loop (36% herhalend) wijst op een segment dat meerdere kettingen nodig heeft. Aanbeveling: chain bundles of quick link packs als upsell naar dit segment.',

            '<strong>Next-best-offer timing:</strong> Starter Kit klanten herbestellen gemiddeld na 160 dagen, maar 24% bestelt al binnen 30 dagen. De sweet spot voor de eerste reactivatie-flow is dag 90-120 — vroeg genoeg om top-of-mind te zijn, laat genoeg dat de wax bijna op is.',
        ]];

        return $sections;
    }

    /**
     * @return array<int, array{label: string, order_count: int, customers: int, avg_ltv: float, total_revenue: float}>
     */
    private function purchaseLadder(): array
    {
        $rows = DB::select("
            SELECT
                order_count,
                COUNT(*) as customers,
                ROUND(AVG(total_ltv), 0) as avg_ltv,
                ROUND(SUM(total_ltv), 0) as total_revenue
            FROM (
                SELECT
                    sc.id,
                    COUNT(so.id) as order_count,
                    SUM(so.net_revenue) as total_ltv
                FROM shopify_customers sc
                INNER JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE so.ordered_at >= '2024-01-01'
                    AND so.financial_status NOT IN ('voided', 'refunded')
                GROUP BY sc.id
            )
            GROUP BY order_count
            ORDER BY order_count
        ");

        $result = [];
        foreach ($rows as $row) {
            $oc = (int) $row->order_count;
            if ($oc <= 4) {
                $result[] = [
                    'label' => $oc.($oc === 1 ? ' order' : ' orders'),
                    'order_count' => $oc,
                    'customers' => (int) $row->customers,
                    'avg_ltv' => (float) $row->avg_ltv,
                    'total_revenue' => (float) $row->total_revenue,
                ];
            } else {
                // Aggregate 5+
                if (isset($result[4])) {
                    $result[4]['customers'] += (int) $row->customers;
                    $result[4]['total_revenue'] += (float) $row->total_revenue;
                    $result[4]['avg_ltv'] = round($result[4]['total_revenue'] / $result[4]['customers']);
                } else {
                    $result[] = [
                        'label' => '5+ orders',
                        'order_count' => 5,
                        'customers' => (int) $row->customers,
                        'avg_ltv' => (float) $row->avg_ltv,
                        'total_revenue' => (float) $row->total_revenue,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, object>
     */
    private function repeatProbabilityPerCategory(): array
    {
        return DB::select("
            WITH first_order_products AS (
                SELECT DISTINCT so.customer_id, p.product_category
                FROM shopify_orders so
                INNER JOIN shopify_line_items sli ON sli.order_id = so.id
                INNER JOIN products p ON p.id = sli.product_id
                WHERE so.is_first_order = 1
                    AND so.ordered_at >= '2024-01-01'
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND p.product_category IN ('starter_kit', 'wax_kit', 'chain', 'wax_tablet', 'pocket_wax')
            ),
            customer_orders AS (
                SELECT customer_id, COUNT(*) as order_count, SUM(net_revenue) as total_ltv
                FROM shopify_orders
                WHERE ordered_at >= '2024-01-01' AND financial_status NOT IN ('voided', 'refunded')
                GROUP BY customer_id
            )
            SELECT
                fop.product_category,
                COUNT(DISTINCT fop.customer_id) as total_customers,
                ROUND(COUNT(DISTINCT CASE WHEN co.order_count >= 2 THEN fop.customer_id END) * 100.0 / COUNT(DISTINCT fop.customer_id), 1) as pct_2nd,
                ROUND(COUNT(DISTINCT CASE WHEN co.order_count >= 3 THEN fop.customer_id END) * 100.0 / NULLIF(COUNT(DISTINCT CASE WHEN co.order_count >= 2 THEN fop.customer_id END), 0), 1) as pct_3rd_given_2nd,
                ROUND(AVG(co.total_ltv), 0) as avg_ltv,
                ROUND(AVG(CASE WHEN co.order_count >= 2 THEN co.total_ltv END), 0) as avg_ltv_repeaters
            FROM first_order_products fop
            INNER JOIN customer_orders co ON co.customer_id = fop.customer_id
            GROUP BY fop.product_category
            ORDER BY pct_2nd DESC
        ");
    }

    /**
     * @return array<int, object>
     */
    private function productPathways(string $category): array
    {
        return DB::select("
            WITH customer_orders AS (
                SELECT customer_id, id as order_id, ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ordered_at) as order_num
                FROM shopify_orders so
                WHERE so.ordered_at >= '2024-01-01' AND so.financial_status NOT IN ('voided', 'refunded') AND so.customer_id IS NOT NULL
            ),
            first_second AS (
                SELECT co1.customer_id, co1.order_id as first_order_id, co2.order_id as second_order_id
                FROM customer_orders co1
                INNER JOIN customer_orders co2 ON co2.customer_id = co1.customer_id AND co2.order_num = 2
                WHERE co1.order_num = 1
            )
            SELECT sli2.product_title, p2.product_category, COUNT(*) as cnt,
                ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1) as pct
            FROM first_second fs
            INNER JOIN shopify_line_items sli1 ON sli1.order_id = fs.first_order_id
            INNER JOIN products p1 ON p1.id = sli1.product_id
            INNER JOIN shopify_line_items sli2 ON sli2.order_id = fs.second_order_id
            INNER JOIN products p2 ON p2.id = sli2.product_id
            WHERE p1.product_category = '{$category}'
                AND p2.product_category NOT IN ('promotional', 'gift_card')
            GROUP BY sli2.product_title, p2.product_category
            HAVING cnt >= 5
            ORDER BY cnt DESC
        ");
    }

    /**
     * @return array<int, object>
     */
    private function threeStepJourney(): array
    {
        // Optimized: first determine dominant category per order, then join
        return DB::select("
            WITH order_categories AS (
                SELECT sli.order_id, p.product_category,
                    ROW_NUMBER() OVER (PARTITION BY sli.order_id ORDER BY sli.price * sli.quantity DESC) as rn
                FROM shopify_line_items sli
                INNER JOIN products p ON p.id = sli.product_id
                WHERE p.product_category NOT IN ('promotional', 'gift_card')
                    AND p.product_category IS NOT NULL
            ),
            order_main_cat AS (
                SELECT order_id, product_category FROM order_categories WHERE rn = 1
            ),
            customer_journey AS (
                SELECT
                    so.customer_id,
                    so.id as order_id,
                    ROW_NUMBER() OVER (PARTITION BY so.customer_id ORDER BY so.ordered_at) as n
                FROM shopify_orders so
                WHERE so.ordered_at >= '2024-01-01'
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.customer_id IS NOT NULL
            )
            SELECT
                oc2.product_category as step2,
                oc3.product_category as step3,
                COUNT(*) as customers
            FROM customer_journey cj1
            INNER JOIN customer_journey cj2 ON cj2.customer_id = cj1.customer_id AND cj2.n = 2
            INNER JOIN customer_journey cj3 ON cj3.customer_id = cj1.customer_id AND cj3.n = 3
            INNER JOIN order_main_cat oc1 ON oc1.order_id = cj1.order_id AND oc1.product_category = 'starter_kit'
            INNER JOIN order_main_cat oc2 ON oc2.order_id = cj2.order_id
            INNER JOIN order_main_cat oc3 ON oc3.order_id = cj3.order_id
            WHERE cj1.n = 1
            GROUP BY step2, step3
            HAVING customers >= 5
            ORDER BY customers DESC
        ");
    }
}
