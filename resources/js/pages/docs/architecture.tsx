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
                    └── Shopify Admin API`}
                    </DocsCode>
                </DocsSection>

                <DocsSection title="Dataflow — Sync">
                    <DocsList
                        items={[
                            'shopify:sync-orders command start (dagelijks 06:00 of handmatig)',
                            'ShopifyOrderSyncer telt orders in date range',
                            '<1000 orders: cursor-based pagination (50/page)',
                            '>1000 orders: Bulk Operations API (JSONL download)',
                            'Per order: upsert customer → upsert order → replace line items',
                            'Customer first_order_at / last_order_at berekend uit orders',
                            'Dashboard cache geflusht na sync',
                        ]}
                    />
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
                        {`ShopifyCustomer
  ├── id, shopify_id, email, orders_count, total_spent
  ├── first_order_at, last_order_at, country_code
  └── hasMany → ShopifyOrder

ShopifyOrder
  ├── id, shopify_id, name, ordered_at
  ├── total_price, subtotal, shipping, tax, discounts, refunded
  ├── financial_status, fulfillment_status, country_code, currency
  ├── belongsTo → ShopifyCustomer
  └── hasMany → ShopifyLineItem

ShopifyLineItem
  ├── id, order_id, product_title, product_type, sku, quantity, price
  └── belongsTo → ShopifyOrder

ShopifyProduct
  └── id, shopify_id, title, product_type, status`}
                    </DocsCode>
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
                            ['Invalidatie', 'Automatisch na shopify:sync-orders'],
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
