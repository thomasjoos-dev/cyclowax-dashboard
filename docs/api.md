# API Documentation

## Shopify Admin API

### Connection
- **Endpoint:** `https://cyclowax.myshopify.com/admin/api/2025-04/graphql.json`
- **Auth:** Offline access token via `X-Shopify-Access-Token` header
- **Client:** `App\Services\ShopifyClient`

### Methods

#### `query(string $query, array $variables = []): array`
Execute a GraphQL query or mutation. Handles rate limiting automatically with retry (max 3 attempts) and proactive throttling when available points drop below 20%.

#### `bulkOperation(string $query): array`
Start a Shopify Bulk Operation. Returns `{id, status}`. Used automatically when syncing >1000 orders.

#### `bulkOperationStatus(): array`
Poll the current bulk operation. Returns `{id, status, errorCode, objectCount, url}`.

#### `bulkOperationResults(string $url): Generator`
Stream and parse JSONL results from a completed bulk operation. Yields rows one at a time to prevent memory exhaustion.

### Rate Limiting
- Shopify uses cost-based throttling (1000 points max, 50/sec restore)
- Client proactively sleeps when available points < 20% of max
- HTTP 429 responses trigger automatic retry with `Retry-After` header

---

## Odoo External API

### Connection
- **Endpoint:** `{ODOO_URL}/jsonrpc` (JSON-RPC 2.0)
- **Auth:** Authenticate with database + username + API key → returns `uid`
- **Client:** `App\Services\OdooClient`

### Methods

#### `authenticate(): int`
Authenticate with Odoo. Returns user ID (uid). Cached for instance lifetime.

#### `execute(string $model, string $method, array $args, array $kwargs): mixed`
Generic wrapper for Odoo's `execute_kw`. Authenticates automatically on first call.

#### `searchRead(string $model, array $domain, array $fields, int $limit, int $offset): array`
Convenience method for `search_read`. Returns array of records matching the domain filter.

#### `searchCount(string $model, array $domain): int`
Count records matching a domain filter.

### Error Handling
- JSON-RPC errors throw `RuntimeException` with Odoo error message
- HTTP 5xx triggers retry (max 3 attempts, exponential backoff)
- Connection failures logged with attempt context

### Relevant Odoo Models
- `product.product` — variants with `default_code` (SKU), `standard_price` (COGS), `qty_available` (stock)
- `product.template` — product templates (parent of variants)
- `stock.quant` — detailed stock per warehouse location

---

## Dashboard Controller

### `GET /dashboard`
Renders the main dashboard page via Inertia.

**Query Parameters:**
| Param | Default | Options | Description |
|-------|---------|---------|-------------|
| `period` | `mtd` | `mtd`, `qtd`, `ytd` | Period for KPI metrics comparison |

**Inertia Props (immediate):**
- `period` — Current period selection
- `kpi` — KPI metrics with comparison to previous period

**Inertia Props (deferred):**
- `acquisitionTrend` — New customers per month (12 months)
- `acquisitionByRegion` — Top 10 regions by customer count
- `regionGrowthRates` — Top regions with 6-month trend + avg MoM growth
- `orderTypeSplit` — First vs returning orders % per month
- `revenueSplit` — Net revenue new vs returning per month
- `cohortRetention` — Cumulative cohort retention matrix (12 months)
- `timeToSecondOrder` — Cumulative curve + milestones for time to second order
- `retentionByRegion` — Retention % per region
- `aovTrend` — AOV first order vs returning per month
- `topProductsFirst` — Top products in first purchases
- `topProductsReturning` — Top products in returning orders

---

## REST API (v1)

Base URL: `/api/v1/`
Auth: Currently unauthenticated (Sanctum planned for external access)

### `GET /api/v1/dashboard?period=mtd|qtd|ytd`
Returns all dashboard metrics as JSON. Same data as the Inertia dashboard but as a single JSON response.

### `GET /api/v1/orders`
Paginated order list with customer and line items included.

| Param | Type | Description |
|-------|------|-------------|
| `from` | date | Filter orders from this date |
| `to` | date | Filter orders until this date |
| `shipping_country` | string | Filter by shipping country (e.g. `US`) |
| `billing_country` | string | Filter by billing country (e.g. `DE`) |
| `financial_status` | string | Filter by status (e.g. `PAID`) |
| `per_page` | int | Items per page (default: 50) |

**Order Resource fields:**
- Financial: `total_price`, `subtotal`, `shipping`, `tax`, `discounts`, `refunded`, `net_revenue`, `currency`
- Address: `billing_country_code`, `billing_province_code`, `billing_postal_code`, `shipping_country_code`, `shipping_province_code`, `shipping_postal_code`
- Attribution: nested `attribution` object with `source_name`, `landing_page_url`, `referrer_url`, `first_touch` (source, source_type, utm_source/medium/campaign/content/term, landing_page, referrer_url), `last_touch` (same fields)

### `GET /api/v1/orders/{id}`
Single order with customer and line items.

### `GET /api/v1/customers`
Paginated customer list.

| Param | Type | Description |
|-------|------|-------------|
| `from` | date | Filter by first_order_at from |
| `to` | date | Filter by first_order_at until |
| `country_code` | string | Filter by country |
| `min_orders` | int | Minimum order count |
| `per_page` | int | Items per page (default: 50) |

### `GET /api/v1/customers/{id}`
Single customer.

### `GET /api/v1/products`
Paginated product list.

| Param | Type | Description |
|-------|------|-------------|
| `status` | string | Filter by status (e.g. `active`) |
| `product_type` | string | Filter by product type |
| `per_page` | int | Items per page (default: 50) |

### `GET /api/v1/products/{id}`
Single product.

---

## Artisan Commands

### `shopify:test`
Test the Shopify API connection. Displays store name, email, domain and plan.

### `shopify:sync-orders {--from=} {--to=}`
Sync orders from Shopify. Defaults to last 3 days.
- Uses cursor pagination for <1000 orders
- Uses Bulk Operations API for >1000 orders
- Upserts orders, line items and customers
- Fetches postal codes and resolves province codes for EU countries via `PostalProvinceResolver`
- Syncs first-touch/last-touch attribution from `customerJourneySummary`

### `shopify:auth`
One-time OAuth flow to obtain an access token. Opens authorize URL, user pastes callback URL, exchanges code for token.

### `odoo:test`
Test the Odoo API connection. Authenticates, fetches sample products with SKU/COGS/stock, and displays total product count.

### `odoo:sync-shipping-costs`
Sync carrier names and exact shipping costs from Odoo stock pickings. Links via sale.order.shopify_order_number.

### `odoo:audit-shipping`
Audit Odoo shipping cost data: coverage per carrier, timeline, and gaps. Use to investigate missing carrier costs.

### `odoo:sync-products`
Sync products from Odoo: COGS, stock quantities, categories, barcodes. Records daily stock snapshots. Enriches product_type from Shopify line items.

### `orders:compute-margins`
Post-sync computation: links line items to products via SKU, sets COGS snapshots, computes order-level margins (total_cost, gross_margin), classifies first orders, updates customer aggregates.

### `sync:all`
Full daily pipeline orchestrator. Runs in sequence: `shopify:sync-orders` → `odoo:sync-products` → `orders:compute-margins` → cache flush. Each step logs duration. Failures are logged but don't block subsequent steps.

**Scheduled:** Daily at 06:00 via `routes/console.php`
