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
| Odoo | JSON-RPC External API, custom client |

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

Odoo Sync
  └── OdooClient (JSON-RPC HTTP client)
        └── Odoo External API (product.product, stock.quant)
```

## Dataflow

### Sync (dagelijks 06:00 + handmatig)
1. `shopify:sync-orders` command start
2. `ShopifyOrderSyncer` telt orders in date range
3. <1000: cursor-based pagination (50/page)
4. >1000: Bulk Operations API (JSONL download)
5. Per order: upsert customer → upsert order → replace line items
6. Province codes resolved via `PostalProvinceResolver` voor EU-landen zonder Shopify province data
7. Attribution data (first/last-touch) opgeslagen vanuit `customerJourneySummary`
8. Customer `first_order_at` / `last_order_at` berekend uit orders
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
  ├── financial_status, fulfillment_status, currency
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
  ├── id, order_id, product_title, product_type, sku, quantity, price
  └── belongsTo → ShopifyOrder

ShopifyProduct
  └── id, shopify_id, title, product_type, status
```

## Postcode → Province Mapping

Shopify levert geen `provinceCode` voor EU-landen (DE, BE, NL, AT, CH, FR, DK, SE, LU). De `PostalProvinceResolver` service lost dit op via postcode-prefix mapping.

```
config/postal-provinces.php          — hoofd-config: prefix-lengte per land
config/postal-provinces/{land}.php   — mapping: prefix → province code (9 bestanden)
app/Services/PostalProvinceResolver.php — resolve(countryCode, postalCode): ?string
```

De resolver wordt aangeroepen in `ShopifyOrderSyncer::resolveProvinces()` na elke order upsert. Shopify's eigen province code heeft altijd voorrang — de resolver springt alleen in als Shopify null retourneert.

**Coverage:** 97% province, 99% postal code over alle orders (2024+).

## Acquisitie-attributie

Orders bevatten first-touch (ft_) en last-touch (lt_) attribution vanuit Shopify's `customerJourneySummary`. Dit geeft per order:
- `source` — kanaalnaam (Google, Instagram, direct)
- `source_type` — type (SEO, null)
- UTM parameters (source, medium, campaign, content, term)
- Landing page en referrer URL

**Coverage:** 83% heeft source data, 23% heeft UTM parameters (paid traffic).

De API Resource groepeert attributie onder een `attribution` object met `first_touch` en `last_touch` sub-objecten.

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
