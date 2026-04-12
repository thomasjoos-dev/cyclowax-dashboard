# Architectuur Audit Rapport — Cyclowax Dashboard
## Datum: 2026-04-12

---

## Executive Summary

### Stack

| Component | Versie |
|-----------|--------|
| PHP | ^8.3 (target 8.4) |
| Laravel | ^13.0 |
| Inertia.js (Laravel) | ^2.0 |
| Inertia.js (React) | ^2.3.7 |
| React | ^19.2.0 |
| TypeScript | ^5.7.2 |
| Tailwind CSS | ^4.0.0 |
| Wayfinder | ^0.1.14 |
| Pest | ^4.4 |
| Sanctum | ^4.0 |
| Fortify | ^1.34 |
| Database lokaal | PostgreSQL |
| Database staging | PostgreSQL (Cloud) |

### Snapshot

| Onderdeel | Aantal | Opmerking |
|-----------|--------|-----------|
| Models | 28 | Compleet met factories |
| Services (totaal) | 64 | Verdeeld over 7 domeinen |
| Service-domeinen | 7 | Analysis, Api, Forecast, Report, Scoring, Support, Sync |
| Artisan Commands | 43 | Incl. sync, scoring, forecast, report |
| Enums | 13 | Consistent gebruik |
| Migrations | 72 | |
| API Controllers | 10 | 4 analytics + 6 resource |
| Form Requests | 7 | 4 API + 3 settings |
| API Resources | 4 | Order, Customer, Product, LineItem |
| React Pages | 17 | Auth (7), settings (3), docs (3), dashboard (1), errors (2), welcome (1) |
| React Components | 62 | 33 ui/ + 6 dashboard/ + 23 layout/shared |
| Custom Hooks | 7 | |
| Tests (Feature) | 64 bestanden | 348 tests, 1074 assertions |
| Tests (Unit) | 1 bestand | Placeholder |
| Tests (Browser) | 0 | Niet aanwezig |
| Factories | 28 | 1:1 met models |

### Service-domeinen snapshot

```
app/Services/
├── Analysis/   (14 services — DTC sales, analytics, retention, product, portfolio)
├── Api/        (3 services — Shopify, Klaviyo, Odoo clients)
├── Forecast/   (22 services — demand, supply, tracking, SKU mix)
│   ├── Demand/     (11 services)
│   ├── Supply/     (7 services)
│   └── Tracking/   (4 services)
├── Report/     (1 service — product portfolio report)
├── Scoring/    (7 services — RFM, margin, channel, follower, product classifier)
├── Support/    (3 services — postal resolver, shipping estimator, PDF)
└── Sync/       (14 services — Shopify, Klaviyo, Odoo syncers + linkers)
```

### Huidige API endpoints (15)

```
GET  /api/v1/dashboard              — KPI metrics per periode
GET  /api/v1/orders                 — Orders (paginated, filtered)
GET  /api/v1/orders/{order}         — Order detail
GET  /api/v1/customers              — Customers (paginated, filtered)
GET  /api/v1/customers/{customer}   — Customer detail
GET  /api/v1/products               — Products (paginated, filtered)
GET  /api/v1/products/{product}     — Product detail
GET  /api/v1/scenarios              — Active scenarios
GET  /api/v1/scenarios/{scenario}   — Scenario detail + assumptions
GET  /api/v1/scenarios/{scenario}/forecast — Demand forecast
GET  /api/v1/sync/status            — Sync pipeline status
GET  /api/v1/analytics/revenue      — Revenue analytics
GET  /api/v1/analytics/acquisition  — Acquisition analytics
GET  /api/v1/analytics/retention    — Retention analytics
GET  /api/v1/analytics/products     — Product analytics
```

### Scheduler

- **Bestand:** `routes/console.php`
- **Issue:** Gebruikt `env()` direct (regel 12-13) i.p.v. `config()`. Dit is een protocol-overtreding.
- **Commands:** `sync:all --skip-enrichment` (dagelijks), `sync:all --full --skip-enrichment` (wekelijks zondag), `klaviyo:enrich-campaigns --limit=20` (dagelijks)

### Vorige audit

Er is geen vorige architectuur audit — dit is de eerste. De test audit van 12 april 2026 (docs/audits/resultaten/test-audit-2026-04-12.md) wordt gebruikt als referentie voor D10.

---

### Score per dimensie

| Dimensie | Score | Trend |
|----------|-------|-------|
| D1. Lagenstructuur & Verantwoordelijkheden | **Groen** | n.v.t. |
| D2. Service-consistentie | **Groen** | n.v.t. |
| D3. Command-patronen | **Groen** | n.v.t. |
| D4. Model-hygiëne | **Groen** | n.v.t. |
| D5. Database & Multi-Platform Compatibiliteit | **Groen** | n.v.t. |
| D6. REST API Volledigheid & Kwaliteit | **Oranje** | n.v.t. |
| D7. Security & Data Protection | **Groen** | n.v.t. |
| D8. Frontend-Readiness & Design System | **Oranje** | n.v.t. |
| D9. Naamgeving & Navigeerbaarheid | **Groen** | n.v.t. |
| D10. Test Coverage & Kwaliteit | **Oranje** | n.v.t. |
| D11. Error Handling & Observability | **Groen** | n.v.t. |
| D12. Database Workflow: Lokaal → Staging | **Groen** | n.v.t. |

### Top 5 bevindingen

1. **API is read-only — geen write endpoints** (D6, Hoog). Alle 15 API endpoints zijn GET. Geen POST/PUT/DELETE voor scenarios, demand events of product mixes. Het frontend protocol verwacht forms met Wayfinder, maar er zijn geen endpoints om naar te submitten.
2. **Controller inconsistentie: 2 controllers gebruiken raw Request i.p.v. Form Request** (D1/D6, Medium). `ScenarioController::forecast()` en `RevenueAnalyticsController` accepteren `Illuminate\Http\Request` zonder validatie — overtreedt zowel het API design protocol als het security protocol.
3. **Formatting utilities niet gecentraliseerd** (D8, Medium). `formatCurrency()` en `formatNumber()` zijn inline gedefinieerd in `dashboard.tsx:34-39` i.p.v. in `resources/js/lib/formatters.ts` zoals het frontend protocol voorschrijft.
4. **Test coverage gaps: 8% controllers, 16% commands** (D10, Hoog). Slechts 1 van 13 controllers en 7 van 43 commands zijn getest. Zie test-audit-2026-04-12.md voor details.
5. **`env()` in routes/console.php** (D7, Medium). Scheduler gebruikt `env('SYNC_SCHEDULE_ENABLED')` en `env('SYNC_DAILY_AT')` direct in `routes/console.php:12-13` i.p.v. via config. Dit is fragiel na config caching.

### Algemene architectuurkwaliteit

De codebase is architecturaal solide voor een project van deze complexiteit. De lagenstructuur is helder — controllers zijn thin, services bevatten de business logica, models zijn clean. Het DbDialect-patroon voor database-abstractie werkt goed en wordt consistent gebruikt. De zwakste punten zitten in API-volledigheid (alleen read, geen write) en test coverage van controllers en commands. De frontend-basis (dashboard met 12 charts, deferred props, TypeScript types) is een sterk fundament maar mist gecentraliseerde formatting en heeft nog maar 1 applicatiepagina.

---

## Per Dimensie

### D1. Lagenstructuur & Verantwoordelijkheden — Groen

**Bevindingen:**

1. **Controllers zijn thin** — geverifieerd over alle 10 API controllers. Ze doen precies wat ze moeten doen: request valideren, service aanroepen, response formatteren.
   - `DashboardApiController.php:12-32` — model thin controller, delegeert naar `DashboardService`
   - `OrderController.php:13-48` — filtering in controller is acceptabel (Eloquent query building)
   - `ComputeOrderMarginsCommand.php:16-64` — thin orchestrator, alle logica in `LineItemLinker`, `OrderMarginCalculator`, `ChannelClassificationService`

2. **OrderController bevat inline query building** — `OrderController.php:19-31` bouwt Eloquent queries direct in de controller i.p.v. via een service. Dit is acceptabel voor simpele filtering maar inconsistent: `DashboardApiController` delegeert alles naar een service, terwijl `OrderController`, `CustomerController` en `ProductController` queries inline bouwen.
   - Impact: Bij meer complexe filtering (sorting, search) wordt de controller fat.
   - Prioriteit: Laag — refactor wanneer filtering complexer wordt.

3. **DashboardService is correct opgezet als facade-service** — `DashboardService.php:1-81` delegeert naar 4 sub-services. Dit is een goed pattern voor het aggregeren van dashboard data.

4. **Twee controllers gebruiken raw `Request` i.p.v. Form Request**:
   - `ScenarioController.php:29` — `forecast(Request $request, ...)` valideert `year` parameter niet via Form Request
   - `RevenueAnalyticsController.php:12` — `__invoke(Request $request, ...)` valideert `period` parameter niet
   - Impact: Inconsistentie met API design protocol sectie 6. Input wordt niet gevalideerd.
   - Prioriteit: Medium

**Wat goed gaat:**
- Duidelijke scheiding: controllers → services → models
- Commands zijn thin orchestrators (bijv. `ComputeOrderMarginsCommand`, `GenerateDemandForecastCommand`)
- Form Requests aanwezig voor CRUD endpoints (`ListOrdersRequest`, `ListCustomersRequest`, `ListProductsRequest`, `DashboardApiRequest`)
- API Resources voor response formatting op CRUD endpoints
- Config files voor business rules (`config/scoring.php`, `config/fees.php`, `config/shipping-rates.php`)

---

### D2. Service-consistentie — Groen

**Bevindingen:**

Vergelijking van 5 services uit verschillende domeinen:

| Service | Regels | Constructor DI | Return types | Type hints | PHPDoc |
|---------|--------|---------------|-------------|-----------|--------|
| `RevenueAnalyticsService` | 179 | n.v.t. (geen deps) | Ja | Ja | Ja (array shapes) |
| `RfmScoringService` | 304 | Ja (SegmentTransitionLogger) | Ja | Ja | Ja (array shapes) |
| `DemandForecastService` | 593 | Ja (7 deps) | Ja | Ja | Ja (array shapes) |
| `ShopifyOrderSyncer` | 493 | Ja (ShopifyClient, PostalProvinceResolver) | Ja | Ja | Ja |
| `OrderMarginCalculator` | 144 | n.v.t. | Ja | Ja | Beperkt |

1. **DemandForecastService is te groot** — 593 regels met 7 constructor dependencies. Dit is het enige echt "fat" service bestand.
   - `DemandForecastService.php:17-25` — 7 geïnjecteerde dependencies
   - Impact: Moeilijker te testen en begrijpen. De `calculateCohortRepeatRevenue` (regel 482-521) en `calculateEventBoost`/`calculatePullForward` (regels 526-592) zouden in eigen services kunnen.
   - Prioriteit: Medium — functionaliteit is correct, maar splitsen verbetert onderhoudbaarheid.

2. **ShopifyOrderSyncer bevat duplicatie in GraphQL queries** — De GraphQL query voor orders staat zowel in `syncViaPagination()` (regel 78-154) als `syncViaBulkOperation()` (regels 173-240). Bijna identieke query, 2x onderhouden.
   - Impact: Query-wijzigingen moeten op 2 plekken doorgevoerd worden.
   - Prioriteit: Medium

3. **Geen `app()` of `resolve()` calls gevonden** — alle dependency injection gaat via constructors. Correct.

4. **Geen static state** — services gebruiken geen static properties.

**Wat goed gaat:**
- Constructor injection consistent toegepast
- Return types op alle publieke methoden
- Array shape PHPDoc op kritieke services (`RevenueAnalyticsService:16`, `RfmScoringService:30`, `DemandForecastService:32`)
- Services groeperen logisch in domeinen
- Geen onnodig static state

---

### D3. Command-patronen — Groen

**Bevindingen:**

Vergelijking van 5 commands:

| Command | Thin? | Error handling | Exit codes | Logging |
|---------|-------|---------------|------------|---------|
| `SyncAllCommand` | Ja (orchestrator) | try/catch + Log::error | Correct | Ja |
| `ComputeOrderMarginsCommand` | Ja | try/catch + Log::error | Correct | Ja |
| `GenerateDemandForecastCommand` | Ja | try/catch + Log::error | Correct | Ja |
| `HealthCheckCommand` | Ja | try/catch + Log::error | Correct | Ja |
| `CalculateRfmScoresCommand` | Ja | try/catch + Log::error | Correct (vermoedelijk) | Ja |

1. **SyncAllCommand is een goed opgezette orchestrator** — `SyncAllCommand.php:44-151`. Gebruikt subprocess-isolatie via `Process::run()` (geheugen-veilig), stale state detection, parallel groups, credential validatie. Dit is een van de sterkste onderdelen van de codebase.

2. **BackfillVariantSkusCommand bevat inline DB query** — `BackfillVariantSkusCommand.php:30` gebruikt `DB::table('shopify_line_items')` direct i.p.v. een service. Dit is een eenmalig backfill-command, dus acceptabel.
   - Impact: Laag — eenmalig gebruik
   - Prioriteit: Laag

3. **Consistent error handling patroon** — Alle commands volgen hetzelfde try/catch patroon met `Log::error()` en `self::FAILURE` return. Dit is goed gestandaardiseerd.

4. **Command naamgeving volgt `domain:action` patroon** — `sync:all`, `shopify:sync-orders`, `klaviyo:sync-profiles`, `forecast:generate`, `orders:compute-margins`, `health:check`. Consistent en voorspelbaar.

**Wat goed gaat:**
- Uniform try/catch + logging patroon
- Subprocess-isolatie in `SyncAllCommand` voorkomt memory leaks
- Credential validatie voor pipeline start (`SyncAllCommand:294-320`)
- Stale state detection en recovery (`SyncAllCommand:326-335`)
- Consistent `domain:action` naamgeving

---

### D4. Model-hygiëne — Groen

**Bevindingen:**

Vergelijking van 5 models:

| Model | $fillable | Casts | Relaties (typed) | Scopes | Factory |
|-------|-----------|-------|-----------------|--------|---------|
| `ShopifyOrder` | Ja (56 velden) | Ja (datetime, decimal, boolean) | Ja (2) | Ja (2: valid, paid) | Ja |
| `ShopifyCustomer` | Ja (24 velden) | Ja (datetime, decimal, int, enum) | Ja (2) | Ja (1: returning) | Ja |
| `Product` | Ja (20 velden) | Ja (decimal, boolean, date, 5 enums) | Ja (4) | Ja (3: active, discontinued, byCategory) | Ja |
| `Scenario` | Ja (6 velden) | Ja (int, boolean, decimal) | Ja (2) | Ja (2: active, forYear) | Ja |
| `SyncState` | Ja (8 velden) | Ja (datetime, boolean, array) | Geen | Geen | Ja |

1. **Geen `$guarded = []` gevonden** — alle 28 models gebruiken `$fillable`. Correct per security protocol.

2. **ShopifyOrder.$fillable is zeer uitgebreid (56 velden)** — `ShopifyOrder.php:18-73`. Dit omvat zowel sync-data als berekende velden (net_revenue, gross_margin, channel_type). Per het security protocol (sectie 4) zouden velden die alleen programmatisch gezet worden niet in `$fillable` hoeven. Echter, dit model wordt alleen via `updateOrCreate` in de syncer gevuld, niet via user input, dus het risico is beperkt.
   - Impact: Laag — geen user-facing mass assignment op dit model
   - Prioriteit: Laag

3. **SyncState heeft static helper methods** — `SyncState.php:38-115` bevat 6 static methods (`lastSyncedAt`, `getCursor`, `markRunning`, etc.). Dit is een bewuste keuze — het model fungeert als een repository-achtige helper. Acceptabel voor dit use case.

4. **Alle models hebben factories** — 28 factories voor 28 models. Compleet.

5. **Relatie return types zijn correct** — alle relaties gebruiken generics (`BelongsTo<ShopifyCustomer, $this>`).

**Wat goed gaat:**
- Consistent `$fillable` gebruik
- Enum casts voor business-domein waarden (ProductCategory, CustomerSegment, etc.)
- Typed relaties met PHPDoc generics
- Complete factory coverage (28/28)
- Scopes voor veelgebruikte filters

---

### D5. Database & Multi-Platform Compatibiliteit — Groen

**Bevindingen:**

1. **DbDialect helper wordt consistent gebruikt** — 14 services importeren `DbDialect`. De helper ondersteunt PostgreSQL, MySQL/MariaDB en SQLite. Dit is een robuust patroon.
   - `DbDialect.php:1-92` — 6 methoden: `yearMonthExpr`, `monthExpr`, `yearExpr`, `yearWeekExpr`, `weekExpr`, `daysDiffExpr`, `daysSinceExpr`
   - Alle date/time extracties gaan via DbDialect

2. **Twee commands bevatten raw queries zonder DbDialect** — maar deze gebruiken standaard SQL:
   - `ClassifyProductPortfolioCommand.php:36` — `selectRaw('product_category, portfolio_role, count(*) as cnt')` — platform-agnostisch
   - `ScoreFollowersCommand.php:48` — `DB::raw('COUNT(*) as count')` — platform-agnostisch
   - Impact: Geen — standaard SQL werkt op alle platformen
   - Prioriteit: n.v.t.

3. **71 raw query occurrences** — verspreid over 20+ bestanden. Steekproef van 10 toont correct gebruik: standaard SQL functies (SUM, COUNT, CASE, ROUND) of via DbDialect.

4. **Database is nu uniform PostgreSQL** — zowel lokaal als staging. De SQLite-PG migratie is afgerond (zie migratie-geschiedenis). DbDialect wordt bewaard als safety net en voor eventuele toekomstige platform-switches.

5. **Migraties zijn platform-agnostisch** — geen PostgreSQL-specifieke column types gevonden in de 72 migraties.

**Wat goed gaat:**
- DbDialect als centraal abstractie-punt
- Uniform database platform (PG/PG) elimineert pariteitsrisico
- Standaard SQL in raw queries
- No-risk column types in migraties

---

### D6. REST API Volledigheid & Kwaliteit — Oranje

**Bevindingen:**

#### Wat er is (goed):

1. **RESTful conventies correct** — meervoud resources, v1 prefix, correcte HTTP methods voor reads. Alle API routes hebben namen met `api.v1.` prefix (`api.php:15-39`).

2. **Auth + rate limiting consistent** — `api.php:15`: `middleware(['auth:sanctum', 'throttle:api'])` op alle routes.

3. **API Resources op CRUD endpoints** — `ShopifyOrderResource`, `ShopifyCustomerResource`, `ShopifyProductResource` worden correct gebruikt.

4. **Consistent envelope pattern** — responses volgen `{ "data": ... }` format.

5. **Error responses gestandaardiseerd** — `bootstrap/app.php:29-60` definieert validation (422) en HTTP exception handlers met consistent JSON format.

#### Wat ontbreekt (problematisch):

1. **Geen write endpoints (POST/PUT/DELETE)** — Alle 15 endpoints zijn GET. Er zijn geen endpoints om scenarios aan te maken, demand events te beheren, of product mixes bij te werken. Dit blokkeert frontend-interactiviteit.
   - Impact: Hoog — het frontend kan geen data wijzigen via de API
   - Prioriteit: Hoog

2. **Twee controllers missen Form Requests**:
   - `ScenarioController.php:29` — `forecast()` accepteert raw `Request`, valideert `year` niet
   - `RevenueAnalyticsController.php:12` — accepteert raw `Request`, valideert `period` niet
   - Impact: Input wordt niet gevalideerd. `period` kan elke willekeurige string zijn.
   - Prioriteit: Medium

3. **Analytics controllers gebruiken geen API Resources** — `AcquisitionAnalyticsController`, `RetentionAnalyticsController`, `ProductAnalyticsController` retourneren raw arrays via `response()->json()`. Dit is conform het API design protocol (sectie 3: analytics/aggregatie endpoints mogen een plat object zonder `data` wrapper gebruiken), maar is inconsistent met de CRUD endpoints.
   - Impact: Laag — gedocumenteerd in protocol
   - Prioriteit: Laag

4. **Geen sorting/paginatie op analytics endpoints** — analytics endpoints retourneren volledige datasets. Acceptabel bij huidige schaal maar niet schaalbaar.
   - Impact: Laag nu, groeit mee met data volume
   - Prioriteit: Laag

5. **ScenarioController retourneert raw Eloquent models** — `ScenarioController.php:15-25` retourneert `response()->json(['data' => $scenarios])` zonder API Resource. Model-structuur lekt naar de API.
   - Impact: Breaking changes wanneer model-structuur wijzigt
   - Prioriteit: Medium

Zie **Bijlage A** voor ontbrekende endpoints.

---

### D7. Security & Data Protection — Groen

**Bevindingen:**

1. **Route protection correct** — alle web routes achter `auth` + `verified` middleware (`web.php:11`), alle API routes achter `auth:sanctum` + `throttle:api` (`api.php:15`). Welcome page (`/`) en auth routes zijn de enige publieke routes. Correct.

2. **Sanctum correct geconfigureerd** — token expiry 8 uur (`sanctum.php:53`), prefix `cwx_` (`sanctum.php:68`).

3. **Geen `env()` in applicatiecode** — grep bevestigt 0 hits in `app/`. Alle env-waarden gaan via config files. Correct.

4. **`env()` WEL in routes/console.php** — `routes/console.php:12-13` gebruikt `env('SYNC_SCHEDULE_ENABLED')` en `env('SYNC_DAILY_AT')` direct. Na `config:cache` worden deze waarden `null`.
   - Impact: Scheduler werkt niet correct na config caching op staging/productie
   - Prioriteit: Medium — verplaats naar `config/sync.php` of `config/dashboard.php`

5. **`.env` in `.gitignore`** — bevestigd (`.gitignore:16`).

6. **Geen credentials hardcoded** — alle API tokens via config files (`config/shopify.php`, `config/klaviyo.php`, `config/odoo.php`). Credential validatie in `SyncAllCommand:294-320` checkt correcte config keys.

7. **CORS correct** — `cors.php:20`: configurable via `CORS_ALLOWED_ORIGINS`, standaard `https://cyclowax-dashboard.test`. Geen wildcard.

8. **Error exposure beveiligd** — `bootstrap/app.php:30-59` toont geen stack traces in API responses. Validation errors retourneren alleen field-level messages.

9. **Mass assignment veilig** — alle models gebruiken `$fillable`, geen `$guarded = []` gevonden.

10. **2FA via Fortify beschikbaar** — UI componenten voor setup en recovery codes aanwezig (`two-factor-setup-modal.tsx`, `two-factor-recovery-codes.tsx`).

**Wat goed gaat:**
- Volledig conform het security protocol op alle 10 checkpunten
- Credential validatie voor pipeline start
- Geen PII in logging (steekproef van sync services bevestigt dit)
- Correct error envelope pattern

---

### D8. Frontend-Readiness & Design System — Oranje

**Bevindingen:**

#### Basis (goed):

1. **Inertia v2 setup correct** — deferred props werkend in `DashboardController.php:19-29`. 11 van 12 dashboard data-props zijn deferred — goed voor performance.

2. **TypeScript types aanwezig en compleet** — `types/api.ts` (177 regels), `types/dashboard.ts` (105 regels), `types/auth.ts`, `types/navigation.ts`, `types/ui.ts`. Alle backend data-structuren zijn getypt.

3. **shadcn/ui component set is uitgebreid** — 33 componenten geinstalleerd, waaronder de essentials: card, table, dialog, select, command (palette), sonner (toast), calendar, date-picker, data-table, tabs, popover, skeleton, badge, alert.

4. **OKLCH design tokens correct opgezet** — `app.css` definieert complete token set voor light en dark mode. 5 chart kleuren beschikbaar.

5. **Dashboard componenten zijn goed gestructureerd** — 6 dashboard-specifieke componenten in `components/dashboard/`: `kpi-card`, `chart-card`, `cohort-heatmap`, `region-performance`, `period-selector`, `time-to-second-order`.

6. **Error boundary aanwezig** — `pages/errors/404.tsx` en `pages/errors/500.tsx` bestaan. `alert-error.tsx` component voor inline errors.

#### Gaps (verbeterpunten):

1. **Formatting utilities niet gecentraliseerd** — `formatCurrency()` en `formatNumber()` zijn inline gedefinieerd in `dashboard.tsx:34-39`. Het frontend protocol (sectie 6) schrijft voor dat deze in `resources/js/lib/formatters.ts` staan. Er bestaat geen `formatters.ts`.
   - Impact: Elke nieuwe pagina moet deze functies opnieuw definieren of importeren uit dashboard.tsx
   - Prioriteit: Medium

2. **Slechts 1 applicatiepagina** — dashboard.tsx is de enige "echte" pagina. Docs (3 pages), auth (7 pages), settings (3 pages) en errors (2 pages) tellen niet als business-functionaliteit. Er zijn geen pagina's voor orders, customers, products, scenarios, forecast, sync status.
   - Impact: Alle API data is alleen via API calls beschikbaar, niet in de UI
   - Prioriteit: Hoog — maar verwacht (frontend build-out is de volgende fase)

3. **Dashboard.tsx is 265 regels** — overschrijdt de 150-regel richtlijn uit het frontend protocol (sectie 2). Charts zijn inline i.p.v. geextraheerd naar eigen componenten.
   - Impact: Moeilijker te onderhouden bij toevoeging van meer visualisaties
   - Prioriteit: Laag — functioneel correct, refactor bij uitbreiding

4. **`WhenVisible` import niet gebruikt** — `dashboard.tsx:1` importeert `WhenVisible` van Inertia maar gebruikt het niet.
   - Impact: Minimaal — unused import
   - Prioriteit: Laag

5. **Geen `formatters.ts` utility** — het frontend protocol definieert een centraal formatteringspatroon maar het bestand bestaat nog niet.
   - Impact: Inconsistente formatting bij uitbreiding naar meerdere pagina's
   - Prioriteit: Medium

**Wat goed gaat:**
- Deferred props correct toegepast
- shadcn/ui component set is compleet genoeg voor dashboard build-out
- TypeScript types zijn volledig en correct
- OKLCH design tokens met dark mode support
- Dashboard componenten volgen compositie-patroon (ChartCard als wrapper)

---

### D9. Naamgeving & Navigeerbaarheid — Groen

**Bevindingen:**

1. **Service naamgeving is beschrijvend en consistent**:
   - Syncers: `ShopifyOrderSyncer`, `KlaviyoCampaignSyncer`, `OdooBomSyncer` — duidelijk `{Platform}{Resource}Syncer` patroon
   - Calculators: `OrderMarginCalculator`, `QuarterlyAovCalculator`, `CategorySeasonalCalculator`
   - Services: `DemandForecastService`, `RfmScoringService`, `DashboardService`
   - Clients: `ShopifyClient`, `KlaviyoClient`, `OdooClient`

2. **Command naamgeving volgt consistent `domain:action` patroon**:
   - `sync:all`, `sync:status`, `sync:reset-cursor`
   - `shopify:sync-orders`, `shopify:sync-segments`
   - `klaviyo:sync-profiles`, `klaviyo:sync-campaigns`, `klaviyo:enrich-campaigns`
   - `forecast:generate`, `forecast:update-actuals`
   - `orders:compute-margins`, `customers:calculate-rfm`

3. **Enum organisatie is logisch** — 13 enums die het businessdomein modelleren: `ProductCategory`, `CustomerSegment`, `ForecastRegion`, `ForecastGroup`, `PortfolioRole`, `JourneyPhase`, etc.

4. **Folder structuur is voorspelbaar** — `Services/Analysis/`, `Services/Sync/`, `Services/Forecast/Demand/`, `Services/Forecast/Supply/`. Hiërarchie weerspiegelt het domein.

5. **Config keys zijn herkenbaar** — `config/scoring.php` voor RFM breakpoints, `config/fees.php` voor payment fees, `config/shipping-rates.php` voor verzendkosten.

**Wat goed gaat:**
- Consistent naamgevingspatroon per service-type
- Intuïtieve folder-structuur
- Beschrijvende command signatures
- Enum naamgeving matcht het businessdomein

---

### D10. Test Coverage & Kwaliteit — Oranje

Referentie: [test-audit-2026-04-12.md](test-audit-2026-04-12.md) voor gedetailleerde bevindingen.

**Samenvatting:**

| Categorie | Gedekt | Totaal | Dekking |
|-----------|--------|--------|---------|
| Services | 35 | 63 | 56% |
| Controllers | 1 | 13 | 8% |
| Commands | 7 | 43 | 16% |
| Models (via tests) | 27 | 28 | 96% |

1. **348 tests, 1074 assertions, 0 failures** — suite is groen en stabiel na CI-fixes.

2. **Controller test coverage is kritiek laag** — alleen `DashboardController` is getest. Alle 10 API controllers, 2 settings controllers ongetest.
   - Impact: API regressions worden niet gevangen
   - Prioriteit: Hoog

3. **Command test coverage laag** — 7 van 43 commands getest (16%). De sync pipeline (`sync:all`) is wel getest.
   - Prioriteit: Medium — commands zijn thin orchestrators, dus risico is beperkt

4. **Factory kwaliteit matig** — `ProductFactory` dekt 7 van 23 fillable velden. 45 directe `Model::create()` aanroepen in tests.
   - Prioriteit: Medium

5. **CI pipeline is gefixt** — PostgreSQL service, timeout, memory limit, `Http::preventStrayRequests()` globaal.

6. **Geen architecture tests, geen smoke tests, geen browser tests**.

**Wat goed gaat:**
- Test kwaliteit is goed: behavior-gericht, minimaal mocken
- HTTP mocking correct op alle 8 syncer/client tests
- Globale `Http::preventStrayRequests()` als vangnet
- CI pipeline werkend met PostgreSQL

---

### D11. Error Handling & Observability — Groen

**Bevindingen:**

1. **Consistent error patroon in commands** — alle onderzochte commands (5/5 steekproef) volgen het try/catch + `Log::error()` + `self::FAILURE` patroon. Geen stille failures.

2. **API error envelope gestandaardiseerd** — `bootstrap/app.php:29-59` definieert handlers voor `ValidationException` (422) en `HttpExceptionInterface` (4xx/5xx). Consistent JSON format.

3. **SyncState monitoring** — het `SyncState` model (`SyncState.php`) trackt status, duur, records, en detecteert stale runs. `HealthCheckCommand` checkt sync freshness (<25h).

4. **Logging in sync pipeline** — `ShopifyOrderSyncer.php:34-46` logt start en completion met counts. `SyncAllCommand` logt failures met exit codes.

5. **AdSpendImporter heeft bewuste lege catches** — `AdSpendImporter.php:186,193` catcht `\Exception` zonder te loggen, maar retourneert `null`. Dit is acceptabel voor date parsing fallbacks (probeert meerdere formaten).

6. **Custom exceptions voor business logica** — `InsufficientBaselineException` en `InvalidProductMixException` geven duidelijke foutmeldingen voor forecast-validatie.

7. **Health check command aanwezig** — `HealthCheckCommand.php` controleert database connectiviteit, API credentials en sync freshness.

**Wat goed gaat:**
- Uniform error handling patroon
- Geen stille failures in business-kritieke code
- SyncState als monitoring mechanisme
- Health check command voor system verification
- Custom exceptions voor domein-specifieke fouten

---

### D12. Database Workflow: Lokaal → Staging — Groen

**Context:**
- **Lokaal:** PostgreSQL (via Herd / Docker)
- **Staging:** PostgreSQL (Cloud)
- **Data komt binnen via sync pipeline** — niet via seeders
- **Seeders bevatten:** configuratie-data (users, scenarios, product mixes, supply profiles, demand events)

**Bevindingen:**

1. **DatabaseSeeder is compleet en geordend** — `DatabaseSeeder.php` roept 6 seeders aan in correcte volgorde. Documentatie-comment bevestigt volgorde-afhankelijkheid.

2. **Seeder idempotentie** — seeders gebruiken `updateOrCreate` (geverifieerd in `ScenarioSeeder`, `SupplyProfileSeeder`). Veilig om opnieuw te draaien.

3. **Factory completeness** — 28 factories voor 28 models. Alle models hebben factories.

4. **Migratie-flow** — 72 migraties, platform-agnostisch. Rollback is mogelijk via standaard Laravel migratie-commando's.

5. **Sync pipeline als data source** — `SyncAllCommand` kan een verse database vullen. `SyncState` trackt voortgang en kan hervatten na crash.

6. **`sync:status` command beschikbaar** — `SyncStatusCommand` toont de status van alle sync stappen.

7. **SqliteToPostgresSeeder aanwezig** — voor de eenmalige migratie van SQLite naar PostgreSQL. Kan worden opgeruimd.

**Wat goed gaat:**
- Gedocumenteerde seeder-volgorde
- Idempotente seeders
- Complete factory set
- Sync pipeline met crash recovery
- Health check voor database verificatie

---

## Bijlage A: API Ontbrekende Endpoints

De volgende endpoints zijn nodig voor een volledige interne API (gebaseerd op beschikbare services):

### CRUD — Hoge prioriteit (nodig voor frontend interactiviteit)

```
POST   /api/v1/scenarios                    — Scenario aanmaken
PUT    /api/v1/scenarios/{scenario}         — Scenario bijwerken
DELETE /api/v1/scenarios/{scenario}         — Scenario verwijderen
POST   /api/v1/scenarios/{scenario}/assumptions   — Assumptions toevoegen/bijwerken
POST   /api/v1/scenarios/{scenario}/product-mixes — Product mixes bijwerken
```

### Forecast & Supply — Hoge prioriteit

```
GET    /api/v1/scenarios/{scenario}/forecast/regional — Regionale forecast
GET    /api/v1/purchase-calendar/{scenario}           — Purchase calendar
GET    /api/v1/purchase-calendar/{scenario}/pdf       — PDF export
GET    /api/v1/forecast/tracking/{scenario}           — Forecast vs actuals tracking
GET    /api/v1/demand-events                          — Demand events index
POST   /api/v1/demand-events                          — Demand event aanmaken
PUT    /api/v1/demand-events/{event}                  — Demand event bijwerken
DELETE /api/v1/demand-events/{event}                  — Demand event verwijderen
```

### Scoring & Segmentatie — Medium prioriteit

```
GET    /api/v1/customers/{customer}/rfm     — RFM scores voor klant
GET    /api/v1/segments                     — Segment overzicht met counts
GET    /api/v1/segments/{segment}/customers — Klanten per segment
GET    /api/v1/segment-transitions          — Segment bewegingen
```

### Analytics — Medium prioriteit

```
GET    /api/v1/analytics/channels           — Channel performance
GET    /api/v1/analytics/cohorts            — Cohort analyse (detail)
GET    /api/v1/analytics/ltv                — LTV by cohort
GET    /api/v1/analytics/purchase-ladder    — Purchase ladder analyse
GET    /api/v1/analytics/product-pathway    — Product pathway analyse
```

### Supply & Stock — Lage prioriteit

```
GET    /api/v1/supply-profiles              — Supply profiles
PUT    /api/v1/supply-profiles/{profile}    — Supply profile bijwerken
GET    /api/v1/stock                        — Current stock snapshots
GET    /api/v1/boms                         — BOM overzicht
GET    /api/v1/open-pos                     — Open purchase orders
```

### Sync Management — Lage prioriteit

```
POST   /api/v1/sync/trigger                 — Handmatig sync starten
POST   /api/v1/sync/reset/{step}            — Sync cursor resetten
```

---

## Bijlage B: Ontbrekende shadcn/ui Componenten & Frontend Gaps

### Geinstalleerde shadcn/ui componenten (33)

alert, avatar, badge, breadcrumb, button, calendar, card, checkbox, collapsible, command, data-table, date-picker, dialog, dropdown-menu, icon, input, input-otp, label, navigation-menu, placeholder-pattern, popover, select, separator, sheet, sidebar, skeleton, sonner, spinner, table, tabs, toggle, toggle-group, tooltip

### Ontbrekende componenten voor dashboard build-out

| Component | Waarom nodig |
|-----------|-------------|
| `progress` | Sync status progress bars, forecast completion |
| `scroll-area` | Lange data tabellen, sidebar content overflow |
| `accordion` | FAQ, collapsible filter sections |
| `switch` | Toggle settings, feature flags |
| `textarea` | Scenario descriptions, notes |
| `slider` | Growth rate input, percentage sliders |
| `hover-card` | Klant preview, product details on hover |
| `form` | React Hook Form integratie voor scenario CRUD |

### Frontend patterns die gestandaardiseerd moeten worden

1. **Data table pattern** — `data-table.tsx` bestaat maar wordt nog niet gebruikt in een pagina. Standaardiseer filtering, sorting, paginatie pattern.
2. **Filter bar pattern** — consistente filter UI bovenaan data pages (datum range, land, status).
3. **Detail panel pattern** — slide-over of dialog voor resource details (order detail, customer detail).
4. **Empty state pattern** — wat tonen als er geen data is per component.
5. **Loading state pattern** — skeleton vs spinner keuze per context.

### Ontbrekende TypeScript types

De `types/api.ts` is compleet voor huidige endpoints. Bij toevoeging van write endpoints zijn nodig:
- `CreateScenarioRequest`, `UpdateScenarioRequest`
- `CreateDemandEventRequest`, `UpdateDemandEventRequest`
- `ScenarioAssumptionInput`, `ProductMixInput`

### Ontbrekende utilities

- `resources/js/lib/formatters.ts` — currency, percentage, number formatting (nu inline in dashboard.tsx)
- `resources/js/lib/dates.ts` — date formatting utilities

---

## Bijlage C: Database Advies

### Huidige situatie

- Lokaal: PostgreSQL (via Herd)
- Staging: PostgreSQL (Cloud)
- Pariteit: 100% — zelfde engine op beide omgevingen

### Advies

De huidige opzet (PG/PG) is ideaal:
- **Geen pariteitsrisico** — code werkt identiek op beide omgevingen
- **DbDialect als safety net** — bij eventuele toekomstige platformwijzigingen is de abstractie al aanwezig
- **PostgreSQL-specifieke features** — JSONB, window functions, materialized views zijn beschikbaar voor toekomstige optimalisaties
- **Ad-hoc analyse** — PG is krachtiger voor analytische queries dan SQLite

**Aanbeveling:** Geen wijzigingen nodig. Overweeg om `SqliteToPostgresSeeder` op te ruimen — dit was een eenmalig migratie-hulpmiddel.

---

## Bijlage D: Ideale Database Workflow

### 1. Nieuwe developer / verse omgeving

```bash
# Clone + dependencies
git clone ... && cd Cyclowax-Dashboard
composer install && npm install

# Environment
cp .env.example .env
php artisan key:generate
# Configureer PG credentials in .env

# Database setup
createdb cyclowax_dashboard
php artisan migrate
php artisan db:seed

# Verify
php artisan health:check
```

### 2. Lokale database vullen met data

```bash
# Configureer API credentials in .env (Shopify, Klaviyo, Odoo)
php artisan sync:all --full
# Eerste sync duurt ~15-30 minuten
php artisan sync:status
```

### 3. Schema wijzigingen doorvoeren

```bash
php artisan make:migration add_column_to_table
# Edit migration
php artisan migrate
php artisan test --compact
# Bij success: commit migration + code samen
```

### 4. Staging up-to-date brengen

```bash
# Op staging:
git pull
php artisan migrate
php artisan db:seed  # Idempotent — veilig om opnieuw te draaien
# Sync draait automatisch via scheduler
```

### 5. Verificatie

```bash
php artisan health:check
php artisan sync:status
php artisan test --compact
```

---

## Bijlage E: Refactoring Backlog

### Hoog

| # | Item | Dimensie | Bestand |
|---|------|----------|---------|
| 1 | Write endpoints toevoegen voor scenarios, demand events, product mixes | D6 | `routes/api.php` |
| 2 | Form Requests toevoegen voor `ScenarioController::forecast()` en `RevenueAnalyticsController` | D1/D6 | `ScenarioController.php:29`, `RevenueAnalyticsController.php:12` |
| 3 | Controller tests toevoegen (alle 10 API controllers) | D10 | `tests/Feature/` |
| 4 | API Resource voor scenarios (geen raw Eloquent in response) | D6 | `ScenarioController.php:15-25` |

### Medium

| # | Item | Dimensie | Bestand |
|---|------|----------|---------|
| 5 | `env()` in console.php verplaatsen naar config | D7 | `routes/console.php:12-13` |
| 6 | `formatters.ts` utility aanmaken | D8 | `resources/js/lib/formatters.ts` (nieuw) |
| 7 | DemandForecastService splitsen (extract event/cohort calculators) | D2 | `DemandForecastService.php` (593 regels) |
| 8 | GraphQL query duplicatie in ShopifyOrderSyncer oplossen | D2 | `ShopifyOrderSyncer.php:78-154` vs `173-240` |
| 9 | ProductFactory uitbreiden met meer states en velden | D10 | `database/factories/ProductFactory.php` |
| 10 | Command test coverage verhogen (sync, scoring, forecast commands) | D10 | `tests/Feature/` |

### Laag

| # | Item | Dimensie | Bestand |
|---|------|----------|---------|
| 11 | `WhenVisible` unused import verwijderen | D8 | `dashboard.tsx:1` |
| 12 | `SqliteToPostgresSeeder` opruimen | D12 | `database/seeders/SqliteToPostgresSeeder.php` |
| 13 | ShopifyOrder.$fillable opsplitsen (sync vs computed velden documenteren) | D4 | `ShopifyOrder.php:18-73` |
| 14 | Dashboard.tsx refactoren naar kleinere componenten (<150 regels) | D8 | `dashboard.tsx` (265 regels) |
| 15 | OrderController filtering verplaatsen naar service | D1 | `OrderController.php:19-31` |
| 16 | Smoke tests toevoegen voor alle pagina's | D10 | `tests/Feature/` |
| 17 | Architecture tests toevoegen (Pest arch) | D10 | `tests/` |
