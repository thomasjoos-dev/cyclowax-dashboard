import { Head } from '@inertiajs/react';
import { DocsBadge, DocsCode, DocsList, DocsSection, DocsTable, DocsText } from '@/components/docs/docs-layout';
import AppLayout from '@/layouts/app-layout';

import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documentation', href: '/docs/api' },
    { title: 'API', href: '/docs/api' },
];

export default function DocsApi() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="API Documentation" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">API Documentation</h1>

                <DocsSection title="Shopify Admin API">
                    <DocsTable
                        headers={['Setting', 'Value']}
                        rows={[
                            ['Endpoint', 'https://cyclowax.myshopify.com/admin/api/2025-04/graphql.json'],
                            ['Auth', 'Offline access token via X-Shopify-Access-Token header'],
                            ['Client', 'App\\Services\\ShopifyClient'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="ShopifyClient Methods">
                    <div className="space-y-4">
                        <div>
                            <DocsCode>{'query(string $query, array $variables = []): array'}</DocsCode>
                            <DocsText>
                                Execute a GraphQL query or mutation. Handles rate limiting automatically with retry (max 3 attempts)
                                and proactive throttling when available points drop below 20%.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'bulkOperation(string $query): array'}</DocsCode>
                            <DocsText>
                                Start a Shopify Bulk Operation. Returns <DocsBadge>{'{id, status}'}</DocsBadge>. Used automatically when syncing {'>'}1000 orders.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'bulkOperationStatus(): array'}</DocsCode>
                            <DocsText>Poll the current bulk operation status.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'bulkOperationResults(string $url): Generator'}</DocsCode>
                            <DocsText>Stream and parse JSONL results from a completed bulk operation. Yields rows one at a time to prevent memory exhaustion.</DocsText>
                        </div>
                    </div>
                </DocsSection>

                <DocsSection title="Rate Limiting">
                    <DocsList
                        items={[
                            'Shopify uses cost-based throttling (1000 points max, 50/sec restore)',
                            'Client proactively sleeps when available points < 20% of max',
                            'HTTP 429 responses trigger automatic retry with Retry-After header',
                        ]}
                    />
                </DocsSection>

                <DocsSection title="Odoo External API">
                    <DocsTable
                        headers={['Setting', 'Value']}
                        rows={[
                            ['Endpoint', '{ODOO_URL}/jsonrpc (JSON-RPC 2.0)'],
                            ['Auth', 'Authenticate with database + username + API key → returns uid'],
                            ['Client', 'App\\Services\\OdooClient'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="OdooClient Methods">
                    <div className="space-y-4">
                        <div>
                            <DocsCode>{'authenticate(): int'}</DocsCode>
                            <DocsText>Authenticate with Odoo. Returns user ID (uid). Cached for instance lifetime.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'execute(string $model, string $method, array $args, array $kwargs): mixed'}</DocsCode>
                            <DocsText>Generic wrapper for Odoo's execute_kw. Authenticates automatically on first call.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'searchRead(string $model, array $domain, array $fields, int $limit, int $offset): array'}</DocsCode>
                            <DocsText>Convenience method for search_read. Returns array of records matching the domain filter.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'searchCount(string $model, array $domain): int'}</DocsCode>
                            <DocsText>Count records matching a domain filter.</DocsText>
                        </div>
                    </div>
                    <DocsText className="mt-4">
                        Relevant Odoo models: <DocsBadge>product.product</DocsBadge> (SKU, COGS, stock),{' '}
                        <DocsBadge>product.template</DocsBadge> (templates), <DocsBadge>stock.quant</DocsBadge> (stock per location).
                    </DocsText>
                </DocsSection>

                <DocsSection title="Dashboard Endpoint">
                    <DocsCode>{'GET /dashboard?period=mtd|qtd|ytd'}</DocsCode>
                    <DocsText>Renders the main dashboard page via Inertia. All metrics except KPI are loaded as deferred props.</DocsText>
                    <DocsTable
                        headers={['Prop', 'Type', 'Deferred']}
                        rows={[
                            ['kpi', 'KpiMetrics', 'No'],
                            ['acquisitionTrend', 'AcquisitionTrendItem[]', 'Yes'],
                            ['acquisitionByRegion', 'RegionItem[]', 'Yes'],
                            ['regionGrowthRates', 'RegionGrowthData', 'Yes'],
                            ['orderTypeSplit', 'OrderTypeSplitItem[]', 'Yes'],
                            ['revenueSplit', 'RevenueSplitItem[]', 'Yes'],
                            ['cohortRetention', 'CohortRetention', 'Yes'],
                            ['timeToSecondOrder', 'TimeToSecondOrder', 'Yes'],
                            ['retentionByRegion', 'RetentionByRegionItem[]', 'Yes'],
                            ['aovTrend', 'AovTrendItem[]', 'Yes'],
                            ['topProductsFirst', 'ProductItem[]', 'Yes'],
                            ['topProductsReturning', 'ProductItem[]', 'Yes'],
                        ]}
                    />
                </DocsSection>

                <DocsSection title="REST API (v1)">
                    <DocsText>Base URL: <DocsCode>/api/v1/</DocsCode> — Currently unauthenticated (Sanctum planned).</DocsText>

                    <div className="mt-4 space-y-6">
                        <div>
                            <DocsCode>{'GET /api/v1/orders'}</DocsCode>
                            <DocsTable
                                headers={['Param', 'Type', 'Description']}
                                rows={[
                                    ['from', 'date', 'Filter orders from this date'],
                                    ['to', 'date', 'Filter orders until this date'],
                                    ['shipping_country', 'string', 'Filter by shipping country (e.g. US)'],
                                    ['billing_country', 'string', 'Filter by billing country (e.g. DE)'],
                                    ['financial_status', 'string', 'Filter by status (e.g. PAID)'],
                                    ['per_page', 'int', 'Items per page (default: 50)'],
                                ]}
                            />
                            <DocsText className="mt-2">
                                Order resource includes: financial fields, address fields (billing/shipping country, province, postal code),
                                <DocsBadge>discount_codes</DocsBadge>, <DocsBadge>total_cost</DocsBadge> (COGS),{' '}
                                <DocsBadge>payment_fee</DocsBadge>, <DocsBadge>gross_margin</DocsBadge>, <DocsBadge>is_first_order</DocsBadge>,
                                and nested <DocsBadge>attribution</DocsBadge> object with source_name, landing_page_url, referrer_url,
                                first_touch and last_touch (source, source_type, UTM params, landing page, referrer).
                            </DocsText>
                        </div>

                        <div>
                            <DocsCode>{'GET /api/v1/customers'}</DocsCode>
                            <DocsTable
                                headers={['Param', 'Type', 'Description']}
                                rows={[
                                    ['from', 'date', 'Filter by first_order_at from'],
                                    ['to', 'date', 'Filter by first_order_at until'],
                                    ['country_code', 'string', 'Filter by country'],
                                    ['min_orders', 'int', 'Minimum order count'],
                                    ['per_page', 'int', 'Items per page (default: 50)'],
                                ]}
                            />
                        </div>

                        <div>
                            <DocsCode>{'GET /api/v1/products'}</DocsCode>
                            <DocsTable
                                headers={['Param', 'Type', 'Description']}
                                rows={[
                                    ['status', 'string', 'Filter by status (e.g. active)'],
                                    ['product_type', 'string', 'Filter by product type'],
                                    ['per_page', 'int', 'Items per page (default: 50)'],
                                ]}
                            />
                        </div>
                    </div>
                </DocsSection>

                <DocsSection title="Artisan Commands">
                    <div className="space-y-4">
                        <div>
                            <DocsCode>{'php artisan sync:all'}</DocsCode>
                            <DocsText>
                                Full daily pipeline orchestrator. Runs in sequence: shopify:sync-orders → odoo:sync-products →
                                orders:compute-margins → cache flush. Each step logs duration. Failures don't block subsequent steps.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan shopify:sync-orders {--from=} {--to=}'}</DocsCode>
                            <DocsText>
                                Sync orders from Shopify. Defaults to last 3 days. Fetches postal codes, resolves province codes
                                for EU countries, syncs first/last-touch attribution from customerJourneySummary.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan odoo:sync-products'}</DocsCode>
                            <DocsText>
                                Sync products from Odoo: COGS, stock quantities, categories, barcodes. Records daily stock snapshots.
                                Enriches product_type from Shopify line items.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan orders:compute-margins'}</DocsCode>
                            <DocsText>
                                Links line items to products via SKU, sets COGS snapshots, computes order-level margins
                                (total_cost, gross_margin), classifies first orders, updates customer aggregates.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan shopify:test'}</DocsCode>
                            <DocsText>Test the Shopify API connection.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan odoo:test'}</DocsCode>
                            <DocsText>Test the Odoo API connection. Authenticates, fetches sample products with SKU/COGS/stock.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan shopify:auth'}</DocsCode>
                            <DocsText>One-time OAuth flow to obtain an access token.</DocsText>
                        </div>
                    </div>
                    <DocsText className="mt-4">Scheduled: <DocsBadge>sync:all</DocsBadge> runs daily at 06:00.</DocsText>
                </DocsSection>
            </div>
        </AppLayout>
    );
}
