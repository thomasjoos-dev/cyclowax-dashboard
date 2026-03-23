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
                            <DocsCode>{'bulkOperationResults(string $url): array'}</DocsCode>
                            <DocsText>Download and parse JSONL results from a completed bulk operation.</DocsText>
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

                <DocsSection title="Artisan Commands">
                    <div className="space-y-4">
                        <div>
                            <DocsCode>{'php artisan shopify:test'}</DocsCode>
                            <DocsText>Test the Shopify API connection. Displays store name, email, domain and plan.</DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan shopify:sync-orders {--from=} {--to=}'}</DocsCode>
                            <DocsText>
                                Sync orders from Shopify. Defaults to last 3 days. Uses cursor pagination for {'<'}1000 orders,
                                Bulk Operations API for {'>'}1000 orders. Flushes dashboard cache after sync.
                            </DocsText>
                        </div>
                        <div>
                            <DocsCode>{'php artisan shopify:auth'}</DocsCode>
                            <DocsText>
                                One-time OAuth flow to obtain an access token. Opens authorize URL, user pastes callback URL,
                                exchanges code for token.
                            </DocsText>
                        </div>
                    </div>
                    <DocsText>Scheduled: <DocsBadge>shopify:sync-orders</DocsBadge> runs daily at 06:00.</DocsText>
                </DocsSection>
            </div>
        </AppLayout>
    );
}
