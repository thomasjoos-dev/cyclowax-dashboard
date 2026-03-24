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
        └── Customer aggregates (local_orders_count, total_cost, first_order_channel)
```

## Dataflow

### Sync pipeline (dagelijks 06:00 via `sync:all`)
1. **Shopify orders** (`shopify:sync-orders`)
   - `ShopifyOrderSyncer` telt orders in date range
   - <1000: cursor-based pagination (50/page)
   - \>1000: Bulk Operations API (JSONL download)
   - Per order: upsert customer → upsert order → replace line items
   - Province codes resolved via `PostalProvinceResolver`
   - Attribution data (first/last-touch) opgeslagen vanuit `customerJourneySummary`
   - Customer `first_order_at` / `last_order_at` berekend uit orders
2. **Odoo products** (`odoo:sync-products`)
   - Products tabel updaten (COGS, categorie, barcode, gewicht)
   - Stock snapshot vastleggen (qty_on_hand, qty_forecasted, qty_free)
   - Product types verrijken vanuit Shopify line items
3. **Margin computation** (`orders:compute-margins`)
   - Line items linken aan products via SKU
   - COGS snapshot op line items zetten
   - total_cost + gross_margin op orders berekenen
   - is_first_order classificeren
   - Customer aggregates updaten
4. **Dashboard cache flush**

### Dashboard request
1. `GET /dashboard?period=mtd` → `DashboardController`
2. KPI metrics worden direct berekend (niet deferred)
3. Alle andere metrics via `Inertia::defer()` — laden async na page render
4. Elke metric gecached voor 1 uur via `Cache::remember()`
5. Frontend toont skeletons voor deferred props, vult in als data arriveert

## Models & Relaties

```
Product (centraal koppelpunt Shopify ↔ Odoo)
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
  ├── id, order_id, product_id (FK → Product), product_title, product_type, sku, quantity, price
  ├── cost_price (COGS snapshot op moment van order)
  ├── belongsTo → ShopifyOrder
  └── belongsTo → Product

ShopifyProduct
  └── id, shopify_id, title, product_type, status

AdSpendRecord (toekomstig — tabel klaar, import command volgt)
  ├── period, channel, country_code, campaign_name
  ├── spend, impressions, clicks, conversions, notes
  └── imported_at
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

## Contribution Margin berekening

- **Net revenue** = `total_price - tax`
- **CM1 (gross margin)** = `(total_price - tax - refunded) - total_cost - payment_fee`
- **Payment fee** = `total_price × 1.9% + €0.25` (configureerbaar via `config/fees.php`)
- **COGS** = som van line item `cost_price × quantity` (frozen snapshot uit Odoo)

## Revenue berekening
Alle omzetcijfers zijn **netto** (excl. BTW): `total_price - tax`. Dit geldt voor KPI omzet, revenue split, en AOV trend.

## Caching strategie
- Elke `DashboardService` methode cached apart met unieke key
- TTL: 3600 seconden (1 uur)
- Cache flush: automatisch na `sync:all` pipeline
- Cache driver: database (configureerbaar via `CACHE_STORE`)

## Shopify authenticatie
- Custom App via Shopify Partners dev dashboard
- OAuth 2.0 one-time token exchange → offline `shpat_*` token
- Token opgeslagen in `.env` (nooit in git)
- Client ID/Secret alleen nodig voor initiële token exchange
