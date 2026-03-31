<?php

namespace App\Console\Commands;

use App\Services\Analysis\DtcSalesQueryService;
use App\Services\Analysis\OdooB2bSalesService;
use App\Services\Support\AnalysisPdfService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:overview-report {--since= : Start date for data scope (default: 6 months ago)}')]
#[Description('Generate Product Portfolio Overview PDF with DTC + B2B data')]
class GenerateProductOverviewCommand extends Command
{
    private string $since;

    public function handle(AnalysisPdfService $pdf, DtcSalesQueryService $dtcQuery, OdooB2bSalesService $b2bService): int
    {
        $this->since = $this->option('since')
            ?? now()->subMonths(6)->startOfMonth()->toDateString();
        $this->info('Gathering data...');

        $dtcByProduct = $dtcQuery->productSalesDetailed($this->since);
        $dtcByCategory = $dtcQuery->categorySales($this->since, now()->toDateString());
        $products = $dtcQuery->productCatalog();
        $stock = $dtcQuery->stockData();
        $this->info('Fetching B2B orders from Odoo...');
        $b2bByProduct = $b2bService->salesByProduct($this->since);
        $this->info('B2B: '.count($b2bByProduct).' products');

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
                .'Scope: '.$this->since.' t/m heden. DTC data uit dashboard database, B2B data uit Odoo sale.order.',
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
        $sections[] = $this->buildCategorySummaryTable($dtcByCategory, $b2bByProduct, $products);

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

    private function buildCategorySummaryTable(array $dtcByCategory, array $b2bByProduct, array $products): array
    {

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
            '<strong>Scope:</strong> '.$this->since.' t/m heden. DTC excl. voided en refunded orders.',
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
