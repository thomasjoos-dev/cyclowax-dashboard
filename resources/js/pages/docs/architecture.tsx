import { Head } from '@inertiajs/react';
import { DocsCode, DocsList, DocsSection, DocsTable, DocsText } from '@/components/docs/docs-layout';
import AppLayout from '@/layouts/app-layout';

import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documentation', href: '/docs/api' },
    { title: 'Architectuur', href: '/docs/architecture' },
];

export default function DocsArchitecture() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Architectuur" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">Architectuur</h1>

                <DocsSection title="Stack">
                    <DocsTable
                        headers={['Laag', 'Technologie']}
                        rows={[
                            ['Backend', 'Laravel 13, PHP 8.4'],
                            ['Frontend', 'React 19, TypeScript, Inertia v2'],
                            ['Styling', 'Tailwind CSS v4, ShadCN UI, tweakcn Lara theme'],
                            ['Charts', 'Recharts'],
                            ['Database', 'SQLite (local), migreerbaar naar MySQL/PostgreSQL'],
                            ['Shopify', 'GraphQL Admin API (2025-04), custom client'],
                            ['Odoo', 'JSON-RPC External API, custom client'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Lagen">
                    <DocsCode>
                        {`Browser
  └── React (Inertia pages)
        └── Dashboard components (KPI, charts, tables)
              └── Recharts / ShadCN UI

Laravel
  └── DashboardController (Inertia render)
        └── DashboardService (queries + caching)
              └── Eloquent Models

Shopify Sync
  └── ShopifySyncOrdersCommand (artisan)
        └── ShopifyOrderSyncer (sync logic)
              └── ShopifyClient (GraphQL HTTP client)
                    └── Shopify Admin API

Odoo Sync
  └── OdooSyncProductsCommand (artisan)
        └── OdooProductSyncer (sync + stock snapshots)
              └── OdooClient (JSON-RPC HTTP client)
                    └── Odoo External API (product.product)

Margin Computation
  └── ComputeOrderMarginsCommand (artisan)
        ├── Link line items → products via SKU
        ├── COGS snapshot op line items
        ├── total_cost + gross_margin op orders
        ├── is_first_order classificatie
        └── Customer aggregates`}
                    </DocsCode>
                </DocsSection>

                <DocsSection title="Dataflow — Sync pipeline (dagelijks 06:00 via sync:all)">
                    <div className="space-y-4">
                        <div>
                            <DocsText className="font-medium">1. Shopify orders (shopify:sync-orders)</DocsText>
                            <DocsList
                                items={[
                                    'ShopifyOrderSyncer telt orders in date range',
                                    '<1000 orders: cursor-based pagination (50/page)',
                                    '>1000 orders: Bulk Operations API (JSONL download)',
                                    'Per order: upsert customer → upsert order → replace line items',
                                    'Province codes resolved via PostalProvinceResolver voor EU-landen',
                                    'Attribution data (first/last-touch) opgeslagen vanuit customerJourneySummary',
                                    'Customer first_order_at / last_order_at berekend uit orders',
                                ]}
                            />
                        </div>
                        <div>
                            <DocsText className="font-medium">2. Odoo products (odoo:sync-products)</DocsText>
                            <DocsList
                                items={[
                                    'Products tabel updaten (COGS, categorie, barcode, gewicht)',
                                    'Stock snapshot vastleggen (qty_on_hand, qty_forecasted, qty_free)',
                                    'Product types verrijken vanuit Shopify line items',
                                ]}
                            />
                        </div>
                        <div>
                            <DocsText className="font-medium">3. Margin computation (orders:compute-margins)</DocsText>
                            <DocsList
                                items={[
                                    'Line items linken aan products via SKU',
                                    'COGS snapshot op line items zetten',
                                    'total_cost + gross_margin op orders berekenen',
                                    'is_first_order classificeren',
                                    'Customer aggregates updaten (local_orders_count, total_cost, first_order_channel)',
                                ]}
                            />
                        </div>
                        <div>
                            <DocsText className="font-medium">4. Dashboard cache flush</DocsText>
                        </div>
                    </div>
                </DocsSection>

                <DocsSection title="Dataflow — Dashboard request">
                    <DocsList
                        items={[
                            'GET /dashboard?period=mtd → DashboardController',
                            'KPI metrics worden direct berekend (niet deferred)',
                            'Alle andere metrics via Inertia::defer() — laden async na page render',
                            'Elke metric gecached voor 1 uur via Cache::remember()',
                            'Frontend toont skeletons voor deferred props, vult in als data arriveert',
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Models & Relaties">
                    <DocsCode>
                        {`Product (centraal koppelpunt Shopify ↔ Odoo)
  ├── id, sku (unique join key), name
  ├── product_type (Shopify), category (Odoo)
  ├── shopify_product_id, odoo_product_id
  ├── cost_price (COGS uit Odoo), list_price, weight, barcode
  ├── is_active, last_synced_at
  ├── hasMany → ShopifyLineItem
  └── hasMany → ProductStockSnapshot

ProductStockSnapshot
  ├── product_id, qty_on_hand, qty_forecasted, qty_free
  ├── recorded_at (tijdreeks)
  └── belongsTo → Product

ShopifyCustomer
  ├── id, shopify_id, email, orders_count, total_spent
  ├── local_orders_count (berekend), total_cost (COGS), first_order_channel
  ├── first_order_at, last_order_at, country_code
  └── hasMany → ShopifyOrder

ShopifyOrder
  ├── id, shopify_id, name, ordered_at
  ├── total_price, subtotal, shipping, tax, discounts, refunded
  ├── financial_status, fulfillment_status, currency
  ├── net_revenue (total_price - tax - refunded)
  ├── total_cost (COGS), payment_fee, gross_margin (net_revenue - COGS - fee), is_first_order
  ├── discount_codes (comma-separated)
  ├── billing_country_code, billing_province_code, billing_postal_code
  ├── shipping_country_code, shipping_province_code, shipping_postal_code
  ├── landing_page_url, referrer_url, source_name
  ├── ft_source, ft_source_type, ft_utm_source/medium/campaign/content/term
  ├── ft_landing_page, ft_referrer_url
  ├── lt_source, lt_source_type, lt_utm_source/medium/campaign/content/term
  ├── lt_landing_page, lt_referrer_url
  ├── belongsTo → ShopifyCustomer
  └── hasMany → ShopifyLineItem

ShopifyLineItem
  ├── id, order_id, product_id (FK → Product)
  ├── product_title, product_type, sku, quantity, price
  ├── cost_price (COGS snapshot op moment van order)
  ├── belongsTo → ShopifyOrder
  └── belongsTo → Product

AdSpendRecord (tabel klaar, import command volgt)
  ├── period, channel, country_code, campaign_name
  ├── spend, impressions, clicks, conversions, notes
  └── imported_at`}
                    </DocsCode>
                </DocsSection>

                <DocsSection title="Postcode → Province Mapping">
                    <DocsText>
                        Shopify levert geen provinceCode voor EU-landen (DE, BE, NL, AT, CH, FR, DK, SE, LU).
                        De PostalProvinceResolver lost dit op via postcode-prefix mapping in config bestanden.
                    </DocsText>
                    <DocsCode>
                        {`config/postal-provinces.php          — hoofd-config: prefix-lengte per land
config/postal-provinces/{land}.php   — mapping: prefix → province code (9 bestanden)
app/Services/PostalProvinceResolver.php — resolve(countryCode, postalCode): ?string`}
                    </DocsCode>
                    <DocsText>Coverage: 97% province, 99% postal code over alle orders (2024+).</DocsText>
                </DocsSection>

                <DocsSection title="Acquisitie-attributie">
                    <DocsText>
                        Orders bevatten first-touch (ft_) en last-touch (lt_) attribution vanuit Shopify's customerJourneySummary:
                    </DocsText>
                    <DocsList
                        items={[
                            'source — kanaalnaam (Google, Instagram, direct)',
                            'source_type — type (SEO, null)',
                            'UTM parameters (source, medium, campaign, content, term)',
                            'Landing page en referrer URL',
                        ]}
                    />
                    <DocsText>Coverage: 83% heeft source data, 23% heeft UTM parameters (paid traffic).</DocsText>
                </DocsSection>

                <DocsSection title="Contribution Margin berekening">
                    <DocsTable
                        headers={['Metric', 'Formule']}
                        rows={[
                            ['Net revenue', 'total_price - tax - refunded (stored as net_revenue column)'],
                            ['CM1 (gross margin)', '(total_price - tax - refunded) - total_cost - payment_fee'],
                            ['Payment fee', 'total_price × 1.9% + €0.25 (config/fees.php)'],
                            ['COGS', 'SUM(line_item.cost_price × quantity)'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Revenue berekening">
                    <DocsText>
                        Alle omzetcijfers zijn netto (excl. BTW): total_price - tax.
                        Dit geldt voor KPI omzet, revenue split, en AOV trend.
                    </DocsText>
                </DocsSection>

                <DocsSection title="Caching strategie">
                    <DocsTable
                        headers={['Aspect', 'Detail']}
                        rows={[
                            ['Scope', 'Elke DashboardService methode cached apart met unieke key'],
                            ['TTL', '3600 seconden (1 uur)'],
                            ['Invalidatie', 'Automatisch na sync:all pipeline'],
                            ['Driver', 'Database (configureerbaar via CACHE_STORE)'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Shopify authenticatie">
                    <DocsList
                        items={[
                            'Custom App via Shopify Partners dev dashboard',
                            'OAuth 2.0 one-time token exchange → offline shpat_* token',
                            'Token opgeslagen in .env (nooit in git)',
                            'Client ID/Secret alleen nodig voor initiële token exchange',
                        ]}
                    />
                </DocsSection>
            </div>
        </AppLayout>
    );
}
