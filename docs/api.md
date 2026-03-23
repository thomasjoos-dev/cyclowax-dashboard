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

#### `bulkOperationResults(string $url): array`
Download and parse JSONL results from a completed bulk operation.

### Rate Limiting
- Shopify uses cost-based throttling (1000 points max, 50/sec restore)
- Client proactively sleeps when available points < 20% of max
- HTTP 429 responses trigger automatic retry with `Retry-After` header

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

## Artisan Commands

### `shopify:test`
Test the Shopify API connection. Displays store name, email, domain and plan.

### `shopify:sync-orders {--from=} {--to=}`
Sync orders from Shopify. Defaults to last 3 days.
- Uses cursor pagination for <1000 orders
- Uses Bulk Operations API for >1000 orders
- Upserts orders, line items and customers
- Flushes dashboard cache after sync

**Scheduled:** Daily at 06:00 via `routes/console.php`

### `shopify:auth`
One-time OAuth flow to obtain an access token. Opens authorize URL, user pastes callback URL, exchanges code for token.
