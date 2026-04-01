# Architectuur

## Stack

| Laag | Technologie |
|------|-------------|
| Backend | Laravel 13, PHP 8.4 |
| Frontend | React 19, TypeScript, Inertia v2 |
| Styling | Tailwind CSS v4, ShadCN UI, tweakcn Lara theme |
| Charts | Recharts |
| Database | SQLite (local), migreerbaar naar MySQL/PostgreSQL |
| Auth | Fortify (session/login), Sanctum (API tokens) |
| Shopify | GraphQL Admin API (2025-04), custom client |
| Odoo | JSON-RPC External API, custom client |
| Klaviyo | REST API v2024-10-15, custom client |

## Lagen

```
Browser
  └── React (Inertia pages)
        └── Dashboard components (KPI, charts, tables)
              └── Recharts / ShadCN UI

Laravel
  └── DashboardController (Inertia render)
        └── DashboardService (delegator)
              ├── RevenueAnalyticsService (KPIs, revenue split, AOV trend)
              ├── AcquisitionAnalyticsService (trends, regions, growth rates)
              ├── RetentionAnalyticsService (cohorts, time-to-second, order type split)
              └── ProductAnalyticsService (top products first/returning)
                    └── Eloquent Models (ShopifyOrder, ShopifyCustomer, etc.)
                          └── App\Support\DbDialect (database-agnostic SQL expressions)

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

Klaviyo Sync
  ├── KlaviyoSyncProfilesCommand (artisan)
  │     └── KlaviyoProfileSyncer (profiles + predictive analytics)
  │           └── KlaviyoClient (REST HTTP client)
  │                 └── Klaviyo API (GET /api/profiles)
  └── KlaviyoSyncCampaignsCommand (artisan)
        └── KlaviyoCampaignSyncer (campaigns + reporting metrics)
              └── KlaviyoClient (REST HTTP client)
                    ├── Klaviyo API (GET /api/campaigns)
                    └── Klaviyo API (POST /api/campaign-values-reports)

Klaviyo Engagement
  └── KlaviyoSyncEngagementCommand (artisan)
        └── KlaviyoEngagementSyncer (per-profile event counts)
              └── KlaviyoClient (REST HTTP client)
                    └── Klaviyo API (GET /api/events per profile)

Rider Profiles & Segmentation
  ├── LinkRiderProfilesCommand (artisan)
  │     └── RiderProfileLinker (email matching → unified profiles)
  │           ├── ShopifyCustomer (lifecycle_stage: customer)
  │           ├── KlaviyoProfile (lifecycle_stage: follower)
  │           └── SegmentTransitionLogger (lifecycle transitions: follower → customer)
  ├── ScoreFollowersCommand (artisan)
  │     └── FollowerScorer (engagement + intent scoring → RiderProfile.segment)
  │           ├── Engagement (1-5): site visits 35%, clicks 30%, opens 20%, recency 15%
  │           ├── Intent (0-4): site(1) → product view(2) → cart(3) → checkout(4)
  │           └── SegmentTransitionLogger (segment transitions)
  ├── CalculateRfmScoresCommand (artisan)
  │     └── RfmScoringService (R/F/M 1-5) → ShopifyCustomer.rfm_segment + RiderProfile.segment
  │           └── SegmentTransitionLogger (injected, segment transitions)
  ├── KlaviyoSyncSegmentsCommand (artisan)
  │     └── KlaviyoSegmentSyncer (write-back to Klaviyo)
  │           ├── Bulk Import API (POST /api/profile-bulk-import-jobs, max 10K/batch)
  │           ├── Properties: cyclowax_lifecycle, cyclowax_segment
  │           ├── Incremental (default): only profiles where updated_at > klaviyo_synced_at
  │           └── Full (--full flag): all profiles with a segment
  ├── ShopifySyncSegmentsCommand (artisan)
  │     └── ShopifySegmentSyncer (write-back to Shopify)
  │           ├── Bulk mutations via JSONL upload (stagedUpload → bulkOperationRunMutation)
  │           ├── Tags: cw:{segment} (e.g. cw:champion, cw:at_risk)
  │           ├── Two-phase: tagsRemove all old cw:* → tagsAdd new cw:{segment}
  │           ├── Incremental (default): only customers where updated_at > shopify_synced_at
  │           └── Full (--full flag): all customers with a segment
  └── Enums
        ├── LifecycleStage: follower | customer
        ├── FollowerSegment: new | engaged | high_potential | hot_lead | fading | inactive
        └── CustomerSegment: champion | at_risk | rising | loyal | hunters | promising_first | one_timer | new_customer

Product Classification
  └── ClassifyProductPortfolioCommand (artisan)
        └── ProductClassifier (SKU-based rule tree → category, role, phase, recipe)

Suspect Profile Flagging
  └── FlagSuspectProfilesCommand (artisan)
        └── SuspectProfileFlagger (disposable emails, ghost checkouts, bot opens)

Seasonal Indices
  └── CalculateSeasonalIndicesCommand (artisan)
        └── SeasonalIndexCalculator (monthly normalisatie → avg = 1.0)

Demand Forecast System
  ├── CalculateCategorySeasonalIndicesCommand (forecast:calculate-seasonal)
  │     └── CategorySeasonalCalculator
  │           ├── Seizoensindexen per ProductCategory, geschoond voor DemandEvents
  │           ├── Gewogen groepsgemiddelde per ForecastGroup
  │           ├── Maturity-based fallback: Launch→groep, Ramping→mix, Mature→eigen
  │           └── DemandEventService (historische events als exclusie-filter)
  ├── GenerateDemandForecastCommand (forecast:generate {scenario})
  │     ├── DemandForecastService (kernberekening)
  │     │     ├── validateProductMixes() — shares in [0,1], som per type 0.95–1.05
  │     │     ├── Q1 completeness check — exception bij 0 maanden, warning bij <3
  │     │     ├── baseline (vorig jaar) × scenario growth × product mix × seasonal index
  │     │     ├── + DemandEvent boost (geplande campagnes/launches)
  │     │     ├── - Pull-forward deductie (alleen Getting Started categorieën)
  │     │     └── → units + revenue per ProductCategory per maand
  │     └── ForecastTrackingService (snapshot opslag)
  │           ├── recordSnapshot() — forecast opslaan als ForecastSnapshot rijen
  │           ├── updateActuals() — werkelijke cijfers invullen na afloop maand
  │           ├── monthlyVariance() — forecast vs actuals + variance%
  │           └── paceProjection() — bijgestelde jaarprojectie op basis van YTD
  ├── UpdateForecastActualsCommand (forecast:update-actuals {YYYY-MM})
  │     └── ForecastTrackingService.updateActuals()
  └── GeneratePurchaseScheduleCommand (forecast:purchase-schedule {scenario})
        └── StockPlanningService
              ├── demand forecast + huidige voorraad + SupplyProfile (lead time, MOQ, buffer)
              ├── purchaseSchedule() → bestelmoment + hoeveelheid per categorie
              ├── categoryRunway() → forward-looking runway per categorie
              └── reorderTimeline() → chronologisch overzicht alle bestelacties

  Input Validation
  ├── InvalidProductMixException — shares buiten bereik of som buiten 0.95–1.05
  ├── InsufficientBaselineException — geen Q1 actuals beschikbaar
  ├── Stock freshness check — ProductionScheduleService::stockFreshness() > 48u = stale
  ├── Supply profile validation — validated_at/validated_by velden, warning bij unvalidated
  └── ValidateBomCommand (forecast:validate-bom)
        └── Controleert: orphan BOMs, lege explosies, ontbrekende lead times, producten zonder BOM

  Supply Profile Analysis
  └── SyncSupplyProfilesCommand (forecast:sync-supply-profiles)
        └── SupplyProfileAnalyzer
              ├── Fetches purchase.order.line + stock.picking (incoming, done) via OdooClient
              ├── Matches Odoo product IDs → ProductCategory via products tabel
              ├── Lead time: mediaan van (date_done - date_order) per categorie
              ├── MOQ: 10e percentiel van historische bestelhoeveelheden
              ├── Bestelfrequentie: gemiddeld aantal dagen tussen bestellingen
              ├── Updatet SupplyProfile records met berekende waarden
              └── Reset validated_at bij automatische LT/MOQ wijzigingen

  Forecast Groups (ForecastGroup enum):
  ├── Ride Activity: WaxTablet, PocketWax — km-gedreven verbruik
  ├── Getting Started: StarterKit, WaxKit, Bundle — acquisitie-producten
  ├── Chain Wear: Chain, ChainConsumable, ChainTool — slijtage-vervanging
  └── Companion: Heater, HeaterAccessory, Cleaning, MultiTool, Accessory — add-ons

Margin Computation
  └── ComputeOrderMarginsCommand (artisan)
        ├── LineItemLinker (4-staps product matching: SKU → barcode → alias → title)
        ├── OrderMarginCalculator (net revenue, COGS, fees, margins, first-order, aggregates)
        └── ChannelClassificationService (channel_type + refined_channel)

PDF Report Generation
  ├── GenerateProductOverviewCommand → DtcSalesQueryService + OdooB2bSalesService + AnalysisPdfService
  ├── GenerateMarchDtcReportCommand → DtcSalesQueryService + AnalysisPdfService
  └── GenerateMarchRecordReportCommand → DtcSalesQueryService + AnalysisPdfService

Shared Query Layer
  ├── DtcSalesQueryService (orderTotals, productSales, categorySales, countrySales, monthlySales, weeklySales, ...)
  └── OdooB2bSalesService (B2B sales ophaling via Odoo JSON-RPC, SKU parsing, batch aggregatie)
```

## Database Portabiliteit

Alle raw SQL queries gebruiken `App\Support\DbDialect` voor database-agnostische expressies. De applicatie draait op SQLite (lokaal) en MySQL/PostgreSQL (staging/productie).

| Helper | SQLite | MySQL | PostgreSQL |
|--------|--------|-------|------------|
| `yearMonthExpr()` | `strftime('%Y-%m', col)` | `DATE_FORMAT(col, '%Y-%m')` | `to_char(col, 'YYYY-MM')` |
| `monthExpr()` | `CAST(strftime('%m', col) AS INTEGER)` | `MONTH(col)` | `EXTRACT(MONTH FROM col)` |
| `yearExpr()` | `strftime('%Y', col)` | `YEAR(col)` | `EXTRACT(YEAR FROM col)` |
| `yearWeekExpr()` | `strftime('%Y-%W', col)` | `DATE_FORMAT(col, '%Y-%v')` | `to_char(col, 'IYYY-IW')` |
| `daysDiffExpr()` | `julianday(a) - julianday(b)` | `DATEDIFF(a, b)` | `EXTRACT(EPOCH FROM (a-b))/86400` |
| `daysSinceExpr()` | `julianday(date) - julianday(col)` | `DATEDIFF(date, col)` | `EXTRACT(EPOCH FROM (date-col))/86400` |

## Services Mappenstructuur

```
app/Services/
├── Api/           ShopifyClient, KlaviyoClient, OdooClient
├── Sync/          *Syncer (6x), RiderProfileLinker, LineItemLinker, AdSpendImporter
├── Analysis/      DashboardService (delegator), *AnalyticsService (4x),
│                  DtcSalesQueryService, OdooB2bSalesService, Channel*, Customer*,
│                  Product*, PurchaseLadder*, RepeatProbability*, SegmentMovement*
├── Forecast/      ForecastService, ScenarioService, CohortProjectionService,
│                  GoalService, StockForecastService, SeasonalIndexCalculator,
│                  CategorySeasonalCalculator, DemandForecastService,
│                  DemandEventService, ForecastTrackingService, StockPlanningService,
│                  SupplyProfileAnalyzer
├── Scoring/       RfmScoringService, FollowerScorer, ChannelClassificationService,
│                  ProductClassifier, SuspectProfileFlagger, SegmentTransitionLogger,
│                  OrderMarginCalculator
└── Support/       AnalysisPdfService, PostalProvinceResolver, ShippingCostEstimator
```

## Authenticatie & Autorisatie

### Auth stack
- **Fortify** — headless auth backend: login, registratie, wachtwoord reset, 2FA, e-mailverificatie
- **Sanctum** — hybride guard: session-based auth (SPA/browser) + token-based auth (externe clients)

### Route beveiliging
| Route groep | Middleware | Doel |
|-------------|-----------|------|
| `/` (welcome) | geen | Publiek |
| `/dashboard`, `/docs/*` | `auth`, `verified` | Ingelogde, geverifieerde gebruikers |
| `/settings/*` | `auth` (+`verified` voor destructieve acties) | Account instellingen |
| `/api/v1/*` | `auth:sanctum` | API — session cookie of Bearer token |

### API input validatie
Alle API list-endpoints gebruiken FormRequests (`App\Http\Requests\Api\V1\*`):
- `per_page` gekapt op max 100
- Datums gevalideerd als date format
- Enum velden alleen bekende waarden toegestaan
- Ongeldige input → `422 Unprocessable Entity` met validatiefouten

## Dataflow

### Sync pipeline (dagelijks 06:00 via `sync:all`)

**Process-geïsoleerde pipeline:** Elke sync-stap draait als apart PHP-proces via
`Process::run()`. Hierdoor wordt geheugen na elke stap volledig vrijgegeven door het OS,
wat de pipeline geschikt maakt voor geheugen-gelimiteerde omgevingen (staging: 1 GB).

**Resumable sync architectuur:** Elke sync-stap heeft een time- én memory budget
(via `HasTimeBudget` trait: 3,5 min / 80% memory_limit). Stappen die niet binnen het
budget passen slaan een cursor op in `sync_states` en hervatten bij de volgende run.
`SyncAllCommand` skipt afgeronde stappen en stopt bij de eerste incomplete stap.
Alle writes zijn idempotent (upsert). Query logging is uitgeschakeld in alle syncers.

0. **Klaviyo profiles** (`klaviyo:sync-profiles`)
   - Cursor-based pagination door alle profielen (page size 50)
   - Inclusief predictive analytics (CLV, churn, predicted orders)
   - Batch upsert per 50 profielen, cursor opgeslagen per pagina
0. **Klaviyo campaigns** (`klaviyo:sync-campaigns`)
   - Alle email campaigns ophalen en upserten
   - Sent campaigns verrijken met metrics via Reporting API (1 req/sec rate limit)
   - Enrichment stopt bij time budget; campaigns met `recipients = 0` zijn impliciete cursor
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
  ├── r_score, f_score, m_score (RFM 1-5)
  ├── rfm_segment (CustomerSegment enum), previous_rfm_segment
  ├── rfm_scored_at, segment_synced_at
  └── hasMany → ShopifyOrder

ShopifyOrder
  ├── id, shopify_id, name, ordered_at
  ├── total_price, subtotal, shipping, tax, discounts, refunded
  ├── financial_status, fulfillment_status, currency
  ├── net_revenue (total_price - tax - refunded)
  ├── total_cost (COGS), payment_fee, shipping_cost, shipping_margin, shipping_carrier
  ├── gross_margin (net_revenue - COGS - fee - shipping_cost), is_first_order
  ├── channel_type (afgeleid: organic_search, paid_search, direct, email, etc.)
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

KlaviyoProfile
  ├── klaviyo_id, email, phone_number, external_id
  ├── first_name, last_name, organization
  ├── city, region, country, zip, timezone
  ├── properties (JSON — custom Klaviyo properties)
  ├── historic_clv, predicted_clv, total_clv
  ├── historic_number_of_orders, predicted_number_of_orders
  ├── average_order_value, churn_probability, average_days_between_orders
  ├── expected_date_of_next_order, last_event_date
  ├── emails_received, emails_opened, emails_clicked, engagement_synced_at
  ├── site_visits, product_views, cart_adds, checkouts_started
  ├── klaviyo_created_at, klaviyo_updated_at
  └── hasOne → RiderProfile

KlaviyoCampaign
  ├── klaviyo_id, name, channel, status, archived
  ├── send_strategy, is_tracking_opens, is_tracking_clicks
  ├── recipients, delivered, bounced
  ├── opens, opens_unique, clicks, clicks_unique
  ├── unsubscribes, conversions, conversion_value, revenue_per_recipient
  ├── scheduled_at, send_time
  └── klaviyo_created_at, klaviyo_updated_at

RiderProfile (unified view — koppelt Shopify + Klaviyo)
  ├── email (unique, lowercase — de matching key)
  ├── lifecycle_stage (LifecycleStage enum): follower | customer
  ├── shopify_customer_id (FK, nullable), klaviyo_profile_id (FK, nullable)
  ├── segment (string): FollowerSegment of CustomerSegment value — single source of truth
  ├── previous_segment (string, nullable)
  ├── segment_changed_at (timestamp, nullable)
  ├── engagement_score (1-5, gewogen: site visits 35%, clicks 30%, opens 20%, recency 15%)
  ├── intent_score (0-4: site→product view→cart→checkout, halveert na 30d)
  ├── linked_at, klaviyo_synced_at, shopify_synced_at
  ├── belongsTo → ShopifyCustomer, belongsTo → KlaviyoProfile
  └── hasMany → SegmentTransition

SegmentTransition (transition history log)
  ├── rider_profile_id (FK)
  ├── type: lifecycle_change | segment_change
  ├── from_lifecycle, to_lifecycle (nullable)
  ├── from_segment, to_segment (nullable)
  ├── occurred_at (indexed)
  └── belongsTo → RiderProfile

AdSpendRecord (toekomstig — tabel klaar, import command volgt)
  ├── period, channel, country_code, campaign_name
  ├── spend, impressions, clicks, conversions, notes
  └── imported_at

DemandEvent
  ├── name, type (DemandEventType: promo_campaign | product_launch)
  ├── start_date, end_date, description, is_historical
  └── hasMany → DemandEventCategory

DemandEventCategory
  ├── demand_event_id (FK), product_category (ProductCategory enum)
  ├── expected_uplift_units (nullable), pull_forward_pct (default 0)
  └── belongsTo → DemandEvent

ScenarioProductMix
  ├── scenario_id (FK), product_category (ProductCategory enum)
  ├── acq_share, repeat_share, avg_unit_price
  └── belongsTo → Scenario

SupplyProfile
  ├── product_category (unique), lead_time_days, moq, buffer_days
  └── supplier_name, notes

ForecastSnapshot
  ├── scenario_id (FK), year_month, product_category (nullable = totaal)
  ├── forecasted_units, forecasted_revenue
  ├── actual_units (nullable), actual_revenue (nullable)
  └── belongsTo → Scenario
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

- **Net revenue** = `total_price - tax - refunded` (opgeslagen als `net_revenue` kolom)
- **CM1 (gross margin)** = `net_revenue - total_cost - payment_fee - shipping_cost`
- **Payment fee** = `total_price × 1.9% + €0.25` (configureerbaar via `config/fees.php`)
- **COGS** = som van line item `cost_price × quantity` (frozen snapshot uit Odoo, 97,8% coverage)
- Line items worden gematcht via: SKU → barcode → SKU alias (`config/sku-aliases.php`) → product title (`config/title-product-map.php`)
- **Shipping cost** = exacte carrier_price uit Odoo, of geschatte kost via `config/shipping-rates.php`
- **Shipping margin** = `shipping` (klant betaalt) - `shipping_cost` (onze kost)

## Revenue berekening
Alle omzetcijfers zijn **netto** (excl. BTW): `total_price - tax`. Dit geldt voor KPI omzet, revenue split, en AOV trend.

## Caching strategie
- Elke analytics service cached apart met unieke keys (`dashboard:{domain}:{params}`)
- TTL: 3600 seconden (1 uur)
- Cache flush: `DashboardService::flushCache()` delegeert naar alle 4 analytics services
- Cache flush: automatisch na `sync:all` pipeline
- Cache driver: database (configureerbaar via `CACHE_STORE`)

## Configuratie (custom)
- `config/analytics.php` — `data_since` startdatum voor analyse queries (default: 2024-01-01)
- `config/analysis.php` — `output_path` voor PDF rapport output (default: ~/Desktop)
- `config/scoring.php` — RFM segment regels, frequency breakpoints, suspect profiel thresholds
- `config/products.php` — discontinued dates, product configuratie
- `config/fees.php` — payment fee percentages
- `config/shipping-rates.php` — geschatte shipping kosten per carrier/land

## Shopify authenticatie
- Custom App via Shopify Partners dev dashboard
- OAuth 2.0 one-time token exchange → offline `shpat_*` token
- Token opgeslagen in `.env` (nooit in git)
- Client ID/Secret alleen nodig voor initiële token exchange

## Dashboard authenticatie
- Fortify regelt login flow (session-based, met optionele 2FA)
- Sanctum `auth:sanctum` guard op alle API v1 routes
- SPA (Inertia) gebruikt automatisch session cookie — geen extra config nodig
- Externe clients gebruiken Bearer token: `$user->createToken('naam')->plainTextToken`
