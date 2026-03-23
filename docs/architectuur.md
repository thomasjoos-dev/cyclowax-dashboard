# Architectuur

## Stack

| Laag | Technologie |
|------|-------------|
| Backend | Laravel 13, PHP 8.4 |
| Frontend | React 19, TypeScript, Inertia v2 |
| Styling | Tailwind CSS v4, ShadCN UI, tweakcn Lara theme |
| Charts | Recharts |
| Database | SQLite (local), migreerbaar naar MySQL/PostgreSQL |
| Shopify | GraphQL Admin API (2025-04), custom client |

## Lagen

```
Browser
  └── React (Inertia pages)
        └── Dashboard components (KPI, charts, tables)
              └── Recharts / ShadCN UI

Laravel
  └── DashboardController (Inertia render)
        └── DashboardService (queries + caching)
              └── Eloquent Models (ShopifyOrder, ShopifyCustomer, etc.)

Shopify Sync
  └── ShopifySyncOrdersCommand (artisan)
        └── ShopifyOrderSyncer (sync logic)
              └── ShopifyClient (GraphQL HTTP client)
                    └── Shopify Admin API
```

## Dataflow

### Sync (dagelijks 06:00 + handmatig)
1. `shopify:sync-orders` command start
2. `ShopifyOrderSyncer` telt orders in date range
3. <1000: cursor-based pagination (50/page)
4. >1000: Bulk Operations API (JSONL download)
5. Per order: upsert customer → upsert order → replace line items
6. Customer `first_order_at` / `last_order_at` berekend uit orders
7. Dashboard cache geflusht

### Dashboard request
1. `GET /dashboard?period=mtd` → `DashboardController`
2. KPI metrics worden direct berekend (niet deferred)
3. Alle andere metrics via `Inertia::defer()` — laden async na page render
4. Elke metric gecached voor 1 uur via `Cache::remember()`
5. Frontend toont skeletons voor deferred props, vult in als data arriveert

## Models & Relaties

```
ShopifyCustomer
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
  └── id, shopify_id, title, product_type, status
```

## Revenue berekening
Alle omzetcijfers zijn **netto** (excl. BTW): `total_price - tax`. Dit geldt voor KPI omzet, revenue split, en AOV trend.

## Caching strategie
- Elke `DashboardService` methode cached apart met unieke key
- TTL: 3600 seconden (1 uur)
- Cache flush: automatisch na `shopify:sync-orders`
- Cache driver: database (configureerbaar via `CACHE_STORE`)

## Shopify authenticatie
- Custom App via Shopify Partners dev dashboard
- OAuth 2.0 one-time token exchange → offline `shpat_*` token
- Token opgeslagen in `.env` (nooit in git)
- Client ID/Secret alleen nodig voor initiële token exchange
