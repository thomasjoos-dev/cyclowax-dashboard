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

## Klaviyo API

### Connection
- **Base URL:** `https://a.klaviyo.com/api`
- **Auth:** Private API key via `Authorization: Klaviyo-API-Key {key}` header
- **Revision:** `2024-10-15` via `revision` header
- **Client:** `App\Services\KlaviyoClient`

### Methods

#### `get(string $endpoint, array $query = []): array`
Perform a GET request. Handles rate limiting with retry (max 3 attempts, respects `Retry-After` header).

#### `post(string $endpoint, array $data = []): array`
Perform a POST request. Used for the Reporting API.

#### `paginate(string $endpoint, array $query = []): Generator`
Cursor-based pagination through all results. Follows `links.next` until exhausted. Yields individual items.

### Endpoints Used

#### Profiles — `GET /api/profiles`
- Page size: 100
- Includes `predictive_analytics` (CLV, churn probability, order predictions)
- Rate limit: Burst 10/s, Steady 150/m (with predictive analytics)

#### Campaigns — `GET /api/campaigns`
- Filtered by `messages.channel = 'email'`
- Returns metadata only (name, status, tracking options, schedule)
- Rate limit: Burst 10/s, Steady 150/m

#### Campaign Metrics — `POST /api/campaign-values-reports`
- Fetches performance data per campaign (recipients, opens, clicks, conversions, revenue)
- Rate limit: Burst 1/s, Steady 2/m — syncer respects this with 1s delay between requests
- Statistics: recipients, delivered, bounced, opens, clicks, unsubscribes, conversions, conversion_value, revenue_per_recipient

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

### Authentication

All API v1 endpoints require authentication via **Laravel Sanctum** (`auth:sanctum` middleware).

Two authentication methods are supported:
1. **Session cookie** — automatic for the Inertia SPA (browser sends session cookie)
2. **API token** — for external clients (n8n, scripts, future apps). Send `Authorization: Bearer <token>` header.

**Creating tokens** (via tinker or future admin panel):
```php
$user->createToken('integration-name', ['orders:read', 'products:read']);
```

Unauthenticated requests receive `401 Unauthorized`.

### Input Validation

All list endpoints validate input via FormRequests. Invalid input returns `422 Unprocessable Entity` with validation errors.

Common rules:
- `per_page` is capped at **100** (prevents full table dumps)
- Date parameters must be valid dates; `to` must be `>=` `from`
- Enum parameters only accept known values

### `GET /api/v1/dashboard?period=mtd|qtd|ytd`
Returns all dashboard metrics as JSON. Same data as the Inertia dashboard but as a single JSON response.

**Validation:** `period` must be one of `mtd`, `qtd`, `ytd`.

### `GET /api/v1/orders`
Paginated order list with customer and line items included.

| Param | Type | Validation | Description |
|-------|------|------------|-------------|
| `from` | date | Valid date | Filter orders from this date |
| `to` | date | Valid date, `>= from` | Filter orders until this date |
| `shipping_country` | string | Exactly 2 chars | Filter by shipping country (e.g. `US`) |
| `billing_country` | string | Exactly 2 chars | Filter by billing country (e.g. `DE`) |
| `financial_status` | string | `PAID`, `PENDING`, `REFUNDED`, `PARTIALLY_REFUNDED` | Filter by status |
| `per_page` | int | 1–100 | Items per page (default: 50) |

**Order Resource fields:**
- Financial: `total_price`, `subtotal`, `shipping`, `tax`, `discounts`, `refunded`, `net_revenue`, `currency`
- Address: `billing_country_code`, `billing_province_code`, `billing_postal_code`, `shipping_country_code`, `shipping_province_code`, `shipping_postal_code`
- Attribution: nested `attribution` object with `source_name`, `landing_page_url`, `referrer_url`, `first_touch` (source, source_type, utm_source/medium/campaign/content/term, landing_page, referrer_url), `last_touch` (same fields)

### `GET /api/v1/orders/{id}`
Single order with customer and line items.

### `GET /api/v1/customers`
Paginated customer list.

| Param | Type | Validation | Description |
|-------|------|------------|-------------|
| `from` | date | Valid date | Filter by first_order_at from |
| `to` | date | Valid date, `>= from` | Filter by first_order_at until |
| `country_code` | string | Exactly 2 chars | Filter by country |
| `min_orders` | int | `>= 0` | Minimum order count |
| `per_page` | int | 1–100 | Items per page (default: 50) |

### `GET /api/v1/customers/{id}`
Single customer.

### `GET /api/v1/products`
Paginated product list.

| Param | Type | Validation | Description |
|-------|------|------------|-------------|
| `status` | string | `active`, `draft`, `archived` | Filter by status |
| `product_type` | string | Max 100 chars | Filter by product type |
| `per_page` | int | 1–100 | Items per page (default: 50) |

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

### `klaviyo:sync-profiles`
Sync all customer profiles from Klaviyo, including predictive analytics (CLV, churn probability). Upserts in batches of 50 via cursor-based pagination.

### `klaviyo:sync-campaigns`
Sync all email campaigns from Klaviyo, then enrich sent campaigns with performance metrics from the Reporting API. Metrics include opens, clicks, conversions and revenue.

### `klaviyo:sync-engagement`
Sync engagement and intent event counts per Klaviyo profile from the Events API. Tracks 7 event types: Received Email, Opened Email, Clicked Email, Active on Site, Viewed Product, Added to Cart, Checkout Started. Incremental: only profiles where `last_event_date > engagement_synced_at`. Only syncs followers (skips Shopify customers).

### `profiles:link`
Create and update unified `rider_profiles` by matching email addresses across Shopify and Klaviyo. Assigns `lifecycle_stage`: `customer` (has Shopify orders) or `follower` (Klaviyo-only subscriber). Idempotent.

### `klaviyo:sync-segments`
Sync rider segmentation data back to Klaviyo as custom profile properties (`cyclowax_lifecycle`, `cyclowax_segment`). Uses the Bulk Import API (`POST /api/profile-bulk-import-jobs`, max 10K profiles per batch). Default mode is incremental: only profiles where `updated_at > segment_synced_at`. Use `--full` flag for a complete resync. Marks `segment_synced_at` after successful push.

### `shopify:sync-segments`
Sync rider segment tags (`cw:*`) to Shopify customers via bulk mutations. Two-phase approach: first removes all existing `cw:` tags (`tagsRemove`), then adds the current segment tag (`tagsAdd`). Uses JSONL staged uploads for efficiency. Default mode is incremental: only customers where `updated_at > shopify_synced_at`. Use `--full` flag for a complete resync.

### `profiles:score-followers`
Calculate two scores per follower: engagement (1-5) and intent (0-4). Engagement is weighted: site visits (35%), email clicks (30%), opens (20%), recency (15%). Intent is highest funnel step: site visit (1), product view (2), cart add (3), checkout started (4) — halved if >30 days ago. Segments: `new`, `hot_lead`, `high_potential`, `engaged`, `fading` (30-90d), `inactive` (>90d).

### `sync:all`
Full daily pipeline orchestrator. Runs in sequence: `shopify:sync-orders` → `odoo:sync-products` → `odoo:sync-shipping-costs` → `klaviyo:sync-profiles` → `klaviyo:sync-campaigns` → `orders:compute-margins` → `customers:calculate-rfm` → `klaviyo:sync-engagement` → `profiles:flag-suspects` → `profiles:link` → `profiles:score-followers` → `klaviyo:sync-segments` → `shopify:sync-segments` → cache flush. Each step logs duration. Failures are logged but don't block subsequent steps.

**Scheduled:** Daily at 06:00 via `routes/console.php`
