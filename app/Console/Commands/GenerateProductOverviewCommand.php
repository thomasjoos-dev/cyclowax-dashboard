<?php

namespace App\Console\Commands;

use App\Services\AnalysisPdfService;
use App\Services\OdooClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('products:overview-report')]
#[Description('Generate Product Portfolio Overview PDF with DTC + B2B data')]
class GenerateProductOverviewCommand extends Command
{
    private const SINCE = '2025-10-01';

    public function handle(AnalysisPdfService $pdf, OdooClient $odoo): int
    {
        $this->info('Gathering data...');

        $dtcByProduct = $this->getDtcSalesData();
        $dtcByCategory = $this->getDtcCategoryData();
        $products = $this->getProductCatalog();
        $stock = $this->getStockData();
        $b2bByProduct = $this->getB2bSalesData($odoo);

        $this->info('Building PDF...');

        $totalDtcRevenue = collect($dtcByCategory)->sum('total_revenue');
        $totalB2bRevenue = collect($b2bByProduct)->sum('revenue');
        $totalDtcUnits = collect($dtcByCategory)->sum('total_units');
        $totalB2bUnits = collect($b2bByProduct)->sum('qty');
        $activeDtcSkus = count($dtcByProduct);
        $activeB2bSkus = collect($b2bByProduct)->filter(fn (array $p): bool => $p['revenue'] > 0)->count();
        $portfolioMargin = $totalDtcRevenue > 0
            ? round(collect($dtcByCategory)->sum('total_margin') * 100 / $totalDtcRevenue, 1)
            : 0;

        $data = [
            'title' => 'Product Portfolio Overview',
            'subtitle' => 'Complete product analysis DTC + B2B — Oct 2025 – Mar 2026',
            'context' => 'Internal Analysis',
            'quote' => 'Always a clean chain',
            'landscape' => true,
            'intro' => 'Overzicht van alle Cyclowax producten met verkoopcijfers uit Shopify (DTC) en Odoo (B2B). '
                .'Scope: '.self::SINCE.' t/m heden. DTC data uit dashboard database, B2B data uit Odoo sale.order.',
            'metrics' => [
                ['label' => 'DTC Revenue', 'value' => '€'.number_format($totalDtcRevenue, 0, ',', '.'), 'change' => number_format($activeDtcSkus).' actieve SKU\'s'],
                ['label' => 'B2B Revenue', 'value' => '€'.number_format($totalB2bRevenue, 0, ',', '.'), 'change' => number_format($activeB2bSkus).' actieve SKU\'s'],
                ['label' => 'DTC Units', 'value' => number_format($totalDtcUnits), 'change' => 'Portfolio marge: '.$portfolioMargin.'%'],
                ['label' => 'B2B Units', 'value' => number_format($totalB2bUnits), 'change' => number_format(collect($b2bByProduct)->count('orders')).' B2B orders'],
            ],
            'sections' => $this->buildSections($dtcByProduct, $dtcByCategory, $products, $stock, $b2bByProduct),
        ];

        $this->info('Rendering PDF...');
        $draftPath = $pdf->save($data, 'product-portfolio-overview_draft-1.pdf');
        $this->info("Draft saved: {$draftPath}");

        $paths = $pdf->finalize($draftPath, 'product-portfolio-overview.pdf');
        $this->info("Finalized: {$paths['desktop']}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getDtcSalesData(): array
    {
        $rows = DB::select("
            SELECT
                p.sku,
                p.name,
                p.product_category,
                p.portfolio_role,
                p.journey_phase,
                p.cost_price,
                p.list_price,
                p.is_active,
                p.is_discontinued,
                COUNT(li.id) as line_items,
                SUM(li.quantity) as units_sold,
                ROUND(SUM(li.price * li.quantity), 2) as gross_revenue,
                ROUND(SUM(li.cost_price * li.quantity), 2) as total_cogs,
                ROUND(SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity), 2) as gross_margin,
                ROUND(
                    CASE WHEN SUM(li.price * li.quantity) > 0
                    THEN ((SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity)) / SUM(li.price * li.quantity)) * 100
                    ELSE 0 END, 1
                ) as margin_pct,
                ROUND(AVG(li.price), 2) as avg_sell_price,
                ROUND(AVG(li.cost_price), 2) as avg_cost_price,
                SUM(CASE WHEN o.is_first_order = 1 THEN li.quantity ELSE 0 END) as units_first_order,
                SUM(CASE WHEN o.is_first_order = 0 THEN li.quantity ELSE 0 END) as units_repeat_order
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ?
              AND o.financial_status NOT IN ('voided', 'refunded')
            GROUP BY p.id, p.sku, p.name, p.product_category, p.portfolio_role, p.journey_phase,
                     p.cost_price, p.list_price, p.is_active, p.is_discontinued
            ORDER BY gross_revenue DESC
        ", [self::SINCE]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = (array) $row;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDtcCategoryData(): array
    {
        $rows = DB::select("
            SELECT
                p.product_category,
                COUNT(DISTINCT p.id) as sku_count,
                SUM(li.quantity) as total_units,
                ROUND(SUM(li.price * li.quantity), 2) as total_revenue,
                ROUND(SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity), 2) as total_margin,
                ROUND(
                    CASE WHEN SUM(li.price * li.quantity) > 0
                    THEN ((SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity)) / SUM(li.price * li.quantity)) * 100
                    ELSE 0 END, 1
                ) as margin_pct,
                SUM(CASE WHEN o.is_first_order = 1 THEN li.quantity ELSE 0 END) as first_order_units,
                SUM(CASE WHEN o.is_first_order = 0 THEN li.quantity ELSE 0 END) as repeat_units
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ?
              AND o.financial_status NOT IN ('voided', 'refunded')
            GROUP BY p.product_category
            ORDER BY total_revenue DESC
        ", [self::SINCE]);

        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getProductCatalog(): array
    {
        $rows = DB::select('
            SELECT
                p.sku,
                p.name,
                p.product_category,
                p.portfolio_role,
                p.journey_phase,
                p.wax_recipe,
                p.heater_generation,
                p.cost_price,
                p.list_price,
                p.is_active,
                p.is_discontinued,
                p.discontinued_at,
                succ.sku as successor_sku,
                succ.name as successor_name
            FROM products p
            LEFT JOIN products succ ON p.successor_product_id = succ.id
            ORDER BY p.product_category, p.name
        ');

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = (array) $row;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getStockData(): array
    {
        $rows = DB::select('
            SELECT
                p.sku,
                ps.qty_on_hand,
                ps.qty_forecasted,
                ps.recorded_at
            FROM product_stock_snapshots ps
            JOIN products p ON ps.product_id = p.id
            WHERE ps.recorded_at = (SELECT MAX(recorded_at) FROM product_stock_snapshots)
        ');

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = (array) $row;
        }

        return $result;
    }

    /**
     * @return array<string, array{name: string, sku: string, qty: float, revenue: float, orders: int}>
     */
    private function getB2bSalesData(OdooClient $odoo): array
    {
        $this->info('Fetching B2B orders from Odoo...');

        $b2bOrders = $odoo->searchRead(
            'sale.order',
            [
                ['date_order', '>=', self::SINCE],
                ['state', 'in', ['sale', 'done']],
                ['shopify_order_number', '=', false],
            ],
            ['name', 'partner_id', 'date_order', 'amount_untaxed', 'order_line'],
            500,
        );

        $allLineIds = [];
        foreach ($b2bOrders as $order) {
            if (is_array($order['order_line'])) {
                $allLineIds = array_merge($allLineIds, $order['order_line']);
            }
        }

        $this->info('Fetching '.count($allLineIds).' B2B line items...');

        $byProduct = [];
        $offset = 0;
        $batchSize = 1000;

        while ($offset < count($allLineIds)) {
            $lines = $odoo->searchRead(
                'sale.order.line',
                [['id', 'in', $allLineIds]],
                ['product_id', 'name', 'product_uom_qty', 'price_subtotal'],
                $batchSize,
                $offset,
            );

            foreach ($lines as $line) {
                $productName = is_array($line['product_id']) ? $line['product_id'][1] : $line['name'];

                // Extract SKU from product name pattern "[SKU] Name"
                $sku = 'unknown';
                if (preg_match('/^\[([^\]]+)\]/', $productName, $matches)) {
                    $sku = $matches[1];
                    $productName = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $productName));
                }

                if (! isset($byProduct[$sku])) {
                    $byProduct[$sku] = [
                        'name' => $productName,
                        'sku' => $sku,
                        'qty' => 0,
                        'revenue' => 0,
                        'orders' => 0,
                    ];
                }
                $byProduct[$sku]['qty'] += $line['product_uom_qty'];
                $byProduct[$sku]['revenue'] += $line['price_subtotal'];
                $byProduct[$sku]['orders']++;
            }

            $offset += $batchSize;
        }

        // Sort by revenue descending
        uasort($byProduct, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        $this->info('B2B: '.count($b2bOrders).' orders, '.count($byProduct).' products');

        return $byProduct;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(
        array $dtcByProduct,
        array $dtcByCategory,
        array $products,
        array $stock,
        array $b2bByProduct,
    ): array {
        $sections = [];

        // ── Page 1: Portfolio Summary ──
        $sections[] = ['type' => 'heading', 'content' => 'Revenue per categorie — DTC vs B2B'];
        $sections[] = $this->buildCategorySummaryTable($dtcByCategory, $b2bByProduct);

        // ── Category detail pages ──
        $categories = [
            'starter_kit' => 'Starter Kits',
            'wax_kit' => 'Wax Kits',
            'chain' => 'Chains',
            'wax_tablet' => 'Wax Tablets',
            'pocket_wax' => 'Pocket Wax',
            'heater' => 'Heaters',
            'heater_accessory' => 'Heater Accessories',
            'chain_consumable' => 'Quick Links',
            'chain_tool' => 'Chain Tools',
            'multi_tool' => 'Multi-Tools (Daysaver)',
            'cleaning' => 'Cleaning',
            'accessory' => 'Accessories',
        ];

        foreach ($categories as $categoryKey => $categoryLabel) {
            $categoryProducts = collect($products)
                ->filter(fn (array $p): bool => $p['product_category'] === $categoryKey)
                ->filter(fn (array $p): bool => $this->hasActivity($p['sku'], $dtcByProduct, $b2bByProduct, $stock));

            if ($categoryProducts->isEmpty()) {
                continue;
            }

            $sections[] = ['type' => 'page-break'];
            $sections[] = ['type' => 'heading', 'content' => $categoryLabel];

            // Category summary line
            $catData = collect($dtcByCategory)->firstWhere('product_category', $categoryKey);
            if ($catData) {
                $b2bCatRevenue = collect($b2bByProduct)
                    ->filter(fn (array $p): bool => isset($products[$p['sku']]) && $products[$p['sku']]['product_category'] === $categoryKey)
                    ->sum('revenue');

                $sections[] = ['type' => 'text', 'content' => '<strong>DTC:</strong> '.number_format($catData['total_units']).' units, €'.number_format($catData['total_revenue'], 0, ',', '.')
                    .' (marge '.$catData['margin_pct'].'%)'
                    .' — <strong>B2B:</strong> €'.number_format($b2bCatRevenue, 0, ',', '.')
                    .' — <strong>First order:</strong> '.round($catData['first_order_units'] * 100 / max($catData['total_units'], 1)).'%'
                    .' <strong>Repeat:</strong> '.round($catData['repeat_units'] * 100 / max($catData['total_units'], 1)).'%',
                ];
            }

            $sections[] = $this->buildProductTable($categoryProducts, $dtcByProduct, $b2bByProduct, $stock);
        }

        // ── Discontinued & Long Tail ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Discontinued & Long Tail'];
        $sections[] = $this->buildDiscontinuedTable($products, $dtcByProduct);
        $sections[] = $this->buildLongTailTable($dtcByProduct, $b2bByProduct);

        // ── Data Completeness ──
        $sections[] = ['type' => 'page-break'];
        $sections[] = ['type' => 'heading', 'content' => 'Data Completeness & Notes'];
        $sections[] = $this->buildDataCompletenessSection($dtcByProduct, $b2bByProduct, $products, $stock);

        return $sections;
    }

    private function buildCategorySummaryTable(array $dtcByCategory, array $b2bByProduct): array
    {
        // Get product catalog for category mapping
        $products = $this->getProductCatalog();

        $headers = [
            ['label' => 'Categorie', 'width' => '16%'],
            ['label' => 'SKUs', 'width' => '5%', 'align' => 'text-right'],
            ['label' => 'DTC Units', 'width' => '9%', 'align' => 'text-right'],
            ['label' => 'DTC Revenue', 'width' => '12%', 'align' => 'text-right'],
            ['label' => 'DTC Marge %', 'width' => '9%', 'align' => 'text-right'],
            ['label' => 'B2B Units', 'width' => '9%', 'align' => 'text-right'],
            ['label' => 'B2B Revenue', 'width' => '12%', 'align' => 'text-right'],
            ['label' => '% First Order', 'width' => '9%', 'align' => 'text-right'],
            ['label' => '% Repeat', 'width' => '9%', 'align' => 'text-right'],
        ];

        $categoryLabels = [
            'wax_kit' => 'Wax Kits',
            'starter_kit' => 'Starter Kits',
            'chain' => 'Chains',
            'wax_tablet' => 'Wax Tablets',
            'pocket_wax' => 'Pocket Wax',
            'chain_consumable' => 'Quick Links',
            'cleaning' => 'Cleaning',
            'heater' => 'Heaters',
            'chain_tool' => 'Chain Tools',
            'heater_accessory' => 'Heater Accessories',
            'multi_tool' => 'Multi-Tools',
            'gift_card' => 'Gift Cards',
            'accessory' => 'Accessories',
        ];

        $rows = [];
        $totalDtcRev = 0;
        $totalDtcUnits = 0;
        $totalB2bRev = 0;
        $totalB2bUnits = 0;

        foreach ($dtcByCategory as $cat) {
            $key = $cat['product_category'];
            $label = $categoryLabels[$key] ?? $key;

            $b2bCatData = collect($b2bByProduct)
                ->filter(fn (array $p): bool => isset($products[$p['sku']]) && $products[$p['sku']]['product_category'] === $key);
            $b2bRev = $b2bCatData->sum('revenue');
            $b2bUnits = $b2bCatData->sum('qty');

            $totalDtcRev += $cat['total_revenue'];
            $totalDtcUnits += $cat['total_units'];
            $totalB2bRev += $b2bRev;
            $totalB2bUnits += $b2bUnits;

            $firstPct = round($cat['first_order_units'] * 100 / max($cat['total_units'], 1));
            $repeatPct = round($cat['repeat_units'] * 100 / max($cat['total_units'], 1));

            $rows[] = [
                ['value' => $label, 'class' => 'bold'],
                ['value' => $cat['sku_count']],
                ['value' => number_format($cat['total_units'])],
                ['value' => '€'.number_format($cat['total_revenue'], 0, ',', '.')],
                ['value' => $cat['margin_pct'].'%'],
                ['value' => $b2bUnits > 0 ? number_format($b2bUnits) : '—'],
                ['value' => $b2bRev > 0 ? '€'.number_format($b2bRev, 0, ',', '.') : '—'],
                ['value' => $firstPct.'%'],
                ['value' => $repeatPct.'%'],
            ];
        }

        // Total row
        $rows[] = [
            ['value' => 'Totaal', 'class' => 'bold highlight'],
            ['value' => ''],
            ['value' => number_format($totalDtcUnits), 'class' => 'bold'],
            ['value' => '€'.number_format($totalDtcRev, 0, ',', '.'), 'class' => 'bold'],
            ['value' => ''],
            ['value' => number_format($totalB2bUnits), 'class' => 'bold'],
            ['value' => '€'.number_format($totalB2bRev, 0, ',', '.'), 'class' => 'bold'],
            ['value' => ''],
            ['value' => ''],
        ];

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildProductTable(mixed $categoryProducts, array $dtcByProduct, array $b2bByProduct, array $stock): array
    {
        $headers = [
            ['label' => 'SKU', 'width' => '12%'],
            ['label' => 'Product', 'width' => '20%'],
            ['label' => 'Prijs', 'width' => '7%', 'align' => 'text-right'],
            ['label' => 'COGS', 'width' => '7%', 'align' => 'text-right'],
            ['label' => 'Marge %', 'width' => '7%', 'align' => 'text-right'],
            ['label' => 'DTC Units', 'width' => '8%', 'align' => 'text-right'],
            ['label' => 'DTC Rev', 'width' => '9%', 'align' => 'text-right'],
            ['label' => 'B2B Units', 'width' => '8%', 'align' => 'text-right'],
            ['label' => 'B2B Rev', 'width' => '9%', 'align' => 'text-right'],
            ['label' => 'Voorraad', 'width' => '7%', 'align' => 'text-right'],
            ['label' => 'Status', 'width' => '6%'],
        ];

        // Sort products: by DTC revenue descending, then B2B revenue
        $sorted = $categoryProducts->sortByDesc(function (array $p) use ($dtcByProduct, $b2bByProduct): float {
            $dtcRev = $dtcByProduct[$p['sku']]['gross_revenue'] ?? 0;
            $b2bRev = $b2bByProduct[$p['sku']]['revenue'] ?? 0;

            return $dtcRev + $b2bRev;
        });

        $rows = [];
        foreach ($sorted as $p) {
            $sku = $p['sku'];
            $dtc = $dtcByProduct[$sku] ?? null;
            $b2b = $b2bByProduct[$sku] ?? null;
            $stk = $stock[$sku] ?? null;

            // Use average sell price from DTC if available, otherwise list price
            $price = $dtc ? '€'.number_format($dtc['avg_sell_price'], 0) : ($p['list_price'] > 0 ? '€'.number_format($p['list_price'], 0) : '—');
            $cogs = $p['cost_price'] > 0 ? '€'.number_format($p['cost_price'], 1) : '—';
            $margin = $dtc ? $dtc['margin_pct'].'%' : '—';
            $dtcUnits = $dtc ? number_format($dtc['units_sold']) : '—';
            $dtcRev = $dtc ? '€'.number_format($dtc['gross_revenue'], 0, ',', '.') : '—';
            $b2bUnits = $b2b && $b2b['qty'] > 0 ? number_format($b2b['qty']) : '—';
            $b2bRev = $b2b && $b2b['revenue'] > 0 ? '€'.number_format($b2b['revenue'], 0, ',', '.') : '—';
            $qtyOnHand = $stk ? number_format($stk['qty_on_hand']) : '—';
            $status = $p['is_discontinued'] ? 'Discontinued' : ($p['is_active'] ? 'Active' : 'Inactive');

            // Skip OEM-only products with no sales
            if (str_starts_with($sku, 'OEM_') && ! $dtc && ! $b2b) {
                continue;
            }

            $rows[] = [
                ['value' => $sku],
                ['value' => mb_substr($p['name'], 0, 35)],
                ['value' => $price],
                ['value' => $cogs],
                ['value' => $margin],
                ['value' => $dtcUnits],
                ['value' => $dtcRev],
                ['value' => $b2bUnits],
                ['value' => $b2bRev],
                ['value' => $qtyOnHand],
                ['value' => $status, 'class' => $p['is_discontinued'] ? 'highlight' : ''],
            ];
        }

        return ['type' => 'compact-table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildDiscontinuedTable(array $products, array $dtcByProduct): array
    {
        $discontinued = collect($products)->filter(fn (array $p): bool => (bool) $p['is_discontinued']);

        $headers = [
            ['label' => 'SKU', 'width' => '15%'],
            ['label' => 'Product', 'width' => '30%'],
            ['label' => 'Discontinued', 'width' => '15%'],
            ['label' => 'Successor SKU', 'width' => '15%'],
            ['label' => 'Successor', 'width' => '25%'],
        ];

        $rows = [];
        foreach ($discontinued as $p) {
            $rows[] = [
                ['value' => $p['sku']],
                ['value' => $p['name']],
                ['value' => $p['discontinued_at'] ?? '—'],
                ['value' => $p['successor_sku'] ?? '—'],
                ['value' => $p['successor_name'] ?? '—'],
            ];
        }

        if (empty($rows)) {
            return ['type' => 'text', 'content' => 'Geen discontinued producten gevonden.'];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildLongTailTable(array $dtcByProduct, array $b2bByProduct): array
    {
        // Products with less than 5 DTC units sold in 6 months
        $longTail = collect($dtcByProduct)
            ->filter(fn (array $p): bool => $p['units_sold'] < 5)
            ->sortBy('units_sold');

        if ($longTail->isEmpty()) {
            return ['type' => 'text', 'content' => 'Geen long tail producten.'];
        }

        $headers = [
            ['label' => 'SKU', 'width' => '15%'],
            ['label' => 'Product', 'width' => '30%'],
            ['label' => 'DTC Units', 'width' => '10%', 'align' => 'text-right'],
            ['label' => 'DTC Revenue', 'width' => '12%', 'align' => 'text-right'],
            ['label' => 'B2B Units', 'width' => '10%', 'align' => 'text-right'],
            ['label' => 'B2B Revenue', 'width' => '12%', 'align' => 'text-right'],
        ];

        $sections = [['type' => 'heading', 'content' => 'Long Tail (< 5 DTC units in 6 maanden)']];

        $rows = [];
        foreach ($longTail as $p) {
            $b2b = $b2bByProduct[$p['sku']] ?? null;
            $rows[] = [
                ['value' => $p['sku']],
                ['value' => mb_substr($p['name'], 0, 45)],
                ['value' => $p['units_sold']],
                ['value' => '€'.number_format($p['gross_revenue'], 0, ',', '.')],
                ['value' => $b2b ? number_format($b2b['qty']) : '—'],
                ['value' => $b2b ? '€'.number_format($b2b['revenue'], 0, ',', '.') : '—'],
            ];
        }

        return ['type' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    private function buildDataCompletenessSection(array $dtcByProduct, array $b2bByProduct, array $products, array $stock): array
    {
        $totalDtcSkus = count($dtcByProduct);
        $dtcWithCogs = collect($dtcByProduct)->filter(fn (array $p): bool => $p['total_cogs'] > 0)->count();
        $cogsCoverage = $totalDtcSkus > 0 ? round($dtcWithCogs * 100 / $totalDtcSkus, 1) : 0;

        $totalProducts = count($products);
        $withStock = count($stock);
        $stockCoverage = $totalProducts > 0 ? round($withStock * 100 / $totalProducts, 1) : 0;

        $b2bWithSku = collect($b2bByProduct)->filter(fn (array $p): bool => $p['sku'] !== 'unknown' && ! str_starts_with($p['name'], 'FedEx') && ! str_starts_with($p['name'], 'Bpost') && ! str_starts_with($p['name'], 'SendCloud'))->count();
        $totalB2bProducts = collect($b2bByProduct)->filter(fn (array $p): bool => $p['revenue'] > 0)->count();

        $stockDate = collect($stock)->first()['recorded_at'] ?? 'onbekend';

        $items = [
            '<strong>DTC verkoopdata:</strong> '.$totalDtcSkus.' SKU\'s met verkopen in scope-periode. Bron: Shopify orders via dashboard database.',
            '<strong>COGS coverage (DTC):</strong> '.$cogsCoverage.'% van verkochte SKU\'s heeft kostprijsdata uit Odoo.',
            '<strong>B2B verkoopdata:</strong> '.$totalB2bProducts.' producten met omzet in Odoo sale.orders. '.$b2bWithSku.' daarvan herkend via SKU. BOM data niet beschikbaar — alleen COGS per product.',
            '<strong>Voorraad snapshot:</strong> '.$withStock.' producten met voorraaddata (coverage: '.$stockCoverage.'%). Laatst gesynct: '.$stockDate.'.',
            '<strong>B2B COGS:</strong> Niet beschikbaar in B2B orders. B2B toont alleen verkoopprijzen (excl. BTW).',
            '<strong>Shipping products:</strong> FedEx, Bpost en SendCloud delivery lines verschijnen in B2B orders als aparte line items — deze zijn apart van productomzet.',
            '<strong>Scope:</strong> '.self::SINCE.' t/m heden. DTC excl. voided en refunded orders.',
        ];

        return ['type' => 'list', 'items' => $items];
    }

    private function hasActivity(string $sku, array $dtcByProduct, array $b2bByProduct, array $stock): bool
    {
        // Skip system/service products
        if (str_starts_with($sku, 'shopify') || in_array($sku, ['COMM', 'FOOD', 'MIL', 'EXP_GEN', 'GIFT', 'TRANS & ACC'])) {
            return false;
        }

        // Skip delivery products
        if (str_starts_with($sku, 'Delivery_')) {
            return false;
        }

        // Has DTC or B2B sales
        if (isset($dtcByProduct[$sku]) || isset($b2bByProduct[$sku])) {
            return true;
        }

        // Has stock
        $stk = $stock[$sku] ?? null;
        if ($stk && $stk['qty_on_hand'] > 0) {
            return true;
        }

        return false;
    }
}
