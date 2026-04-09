# Architectuur Audit Rapport — Cyclowax Dashboard

## Datum: 9 april 2026

---

## Executive Summary

| Dimensie | Score |
|----------|-------|
| D1. Lagenstructuur & Verantwoordelijkheden | **GROEN** |
| D2. Service-consistentie | **ORANJE** |
| D3. Command-patronen | **GROEN** |
| D4. Model-hygiëne | **GROEN** |
| D5. Database & Multi-Platform Compatibiliteit | **ORANJE** |
| D6. REST API Volledigheid & Kwaliteit | **ROOD** |
| D7. Security & Data Protection | **ORANJE** |
| D8. Frontend-Readiness & Design System | **ORANJE** |
| D9. Naamgeving & Navigeerbaarheid | **GROEN** |
| D10. Test Coverage & Kwaliteit | **ORANJE** |
| D11. Error Handling & Observability | **GROEN** |
| D12. Database Workflow: Lokaal → Staging | **ORANJE** |

### Top 5 bevindingen (hoogste prioriteit)

1. **API dekking is minimaal** — Slechts 3 van 28 models zijn via de API ontsloten. Forecast, supply chain, analytics en scoring data is onbereikbaar voor interne apps.
2. **Sanctum tokens verlopen nooit** — `expiration: null` in config/sanctum.php. Gecompromitteerde tokens blijven onbeperkt geldig.
3. **12 van 28 models missen factories** — Belemmert testbaarheid van kritieke paden (BOM, netting, purchase calendar, demand events).
4. **13 services overschrijden 300 regels** — DemandForecastService (867), SalesBaselineService (730), ShopifyOrderSyncer (493). Verlaagt leesbaarheid en testbaarheid.
5. **Geen health-check command** — Er is geen manier om snel te controleren of sync pipeline, API credentials en database in orde zijn.

### Algemene architectuurkwaliteit

De Cyclowax Dashboard codebase toont **sterke architecturale discipline** voor een project van deze omvang. De lagenstructuur is consequent: controllers zijn thin, services bevatten de business logica, models zijn puur data/relaties. De 61 services zijn verdeeld over 7 logische domeinen met consistente naamgeving. De sync pipeline (SyncAllCommand) is expert-level met cursor-gebaseerde hervatting, parallellisatie en crash recovery. De grootste risico's zitten niet in de codekwaliteit maar in de **onvolledigheid**: de API dekt slechts een fractie van de beschikbare data, en de test-infrastructuur (factories, seeders) is niet compleet genoeg voor snelle iteratie op de forecast- en supply-chain pijplijn.

---

## Per Dimensie

### D1. Lagenstructuur & Verantwoordelijkheden — GROEN

**Bevindingen:**

1. **Controllers zijn thin** — Alle 4 API controllers dispatchen validatie via FormRequests, roepen een service aan, en returnen via Resources. Geen business logica gedetecteerd.
   - `DashboardApiController` delegeert volledig aan `DashboardService`
   - `OrderController`, `CustomerController`, `ProductController` bouwen queries op met gevalideerde input en returnen Resources
   - Prioriteit: n.v.t.

2. **Services bevatten de juiste logica** — Business logica zit in services, niet in controllers of models. Dependency injection is 100% via constructors (geen `app()` of `resolve()` calls).
   - Prioriteit: n.v.t.

3. **Models zijn puur data** — Alle 28 models bevatten alleen relaties, scopes, casts en accessors. Geen business logica, geen externe API calls.
   - Prioriteit: n.v.t.

4. **~40% van commands bevat te veel logica** — Report generation commands (met name `GenerateProductOverviewCommand` — 416 regels) bevatten inline PDF-opbouw en berekeningen die in een service horen.
   - Impact: Moeilijk testbaar, logica niet herbruikbaar
   - Prioriteit: Medium

5. **FormRequests consequent** — Alle 8 FormRequests bevatten alleen validatie en autorisatie. Geen inline validatie in controllers.
   - Prioriteit: n.v.t.

6. **API Resources consistent** — Alle 4 Resources gebruiken `toArray()` met `whenLoaded()` voor relaties. Geen business logica in transformers.
   - Prioriteit: n.v.t.

7. **Hardcoded magic numbers** — Cache TTL `3600` staat hardcoded in 7+ analysis services (RevenueAnalyticsService, RetentionAnalyticsService, etc.) i.p.v. in config. ShopifyClient hardcodet rate limiting threshold `0.2`.
   - Impact: Niet centraal aanpasbaar, inconsistentie bij wijziging
   - Prioriteit: Laag

**Wat goed gaat:**
- Controllers, models, FormRequests en Resources volgen allemaal hun verantwoordelijkheid
- 100% constructor injection in services
- Config files bevatten het merendeel van de business rules (scoring, products, shipping-rates, sku-aliases)

---

### D2. Service-consistentie — ORANJE

**Bevindingen:**

1. **13 services overschrijden 300 regels (elephant services)**
   - `DemandForecastService`: 867 regels — orkestreert 5 dependencies en bevat te veel verantwoordelijkheden
   - `SalesBaselineService`: 730 regels — bevat AOV-berekeningen die een eigen service verdienen
   - `ShopifyOrderSyncer`: 493 regels — mixed public/protected concerns
   - `CohortProjectionService`: 431 regels
   - `PurchaseCalendarPdfService`: 420 regels
   - `BomExplosionService`: 404 regels — gerechtvaardigd door recursieve operaties met interne cache
   - `PurchaseCalendarService`: 400 regels
   - Impact: Moeilijk te lezen, testen en onderhouden
   - Prioriteit: Hoog (DemandForecastService, SalesBaselineService), Medium (overige)

2. **Constructor injection: 100%** — Alle 59 services gebruiken dependency injection via constructor. Geen `app()` of `resolve()` calls.
   - Prioriteit: n.v.t.

3. **Return types: 95%+** — Vrijwel alle publieke methoden hebben expliciete return types. PHPDoc met array shapes op complexe returns (BomExplosionService, RfmScoringService).
   - Prioriteit: n.v.t.

4. **Naamgeving consistent** — `XxxService`, `XxxSyncer`, `XxxCalculator`, `XxxResolver`, `XxxAnalyzer` patronen worden consequent toegepast.
   - Prioriteit: n.v.t.

5. **Geen static methods** — Nul static methods in alle 59 services. Alleen constants waar nodig (bijv. `CohortProjectionService::MIN_COHORTS_FOR_OWN_CURVE`).
   - Prioriteit: n.v.t.

6. **Private methods onder-gedocumenteerd** — PHPDoc is excellent op publieke methoden, maar complexe private helper methods missen documentatie in de elephant services.
   - Impact: Kennisoverdracht en onboarding moeilijker
   - Prioriteit: Laag

**Wat goed gaat:**
- Dependency injection is vlekkeloos
- Naamgeving en typing zijn consequent
- Geen anti-patterns (static state, service locator)

---

### D3. Command-patronen — GROEN

**Bevindingen:**

1. **SyncAllCommand is expert-level** — Orkestreert 13+ sync stappen met cursor-gebaseerde hervatting, intelligente parallellisatie (`canRunParallel()`), memory management (`gc_collect_cycles()`), en stale-run recovery.
   - Prioriteit: n.v.t.

2. **Alle 41 commands gebruiken attribute-based signatures** — `#[Signature]` en `#[Description]` consequent toegepast. Correct gebruik van `self::SUCCESS` / `self::FAILURE` exit codes.
   - Prioriteit: n.v.t.

3. **Inconsistente exception handling** — 5 commands catchen `\Exception`, 11 catchen `Throwable`. 12 commands missen try-catch volledig (report generation, calculation commands).
   - Impact: Bij een crash in een report command krijg je een onafgehandelde exception i.p.v. graceful failure
   - Prioriteit: Medium

4. **Logging is te beperkt** — Slechts 4 van 41 commands gebruiken de `Log` facade. Report generation is volledig stil (geen file logging). Bij productie-issues is er geen trail.
   - Impact: Debugging van nachtelijke runs lastig
   - Prioriteit: Medium

5. **Geen progress bars** — Slechts 1 command (BackfillVariantSkusCommand) toont voortgang. Lange sync- en report-operaties geven geen feedback.
   - Impact: UX bij handmatig draaien van commands
   - Prioriteit: Laag

**Wat goed gaat:**
- Thin orchestrators die delegeren naar services
- Consistente signatures en exit codes
- SyncAllCommand is een uitstekend voorbeeld van pipeline orchestratie

---

### D4. Model-hygiëne — GROEN

**Bevindingen:**

1. **Mass assignment: 100% consistent** — Alle 28 models gebruiken `protected $guarded = []`. User model gebruikt `#[Fillable]` attribute.
   - Prioriteit: n.v.t. (zie D7 voor security-implicatie)

2. **Casts: excellent** — 14+ models met decimal precision (financiële data), 10+ met native PHP enums, immutable datetimes. ShopifyOrder heeft 14 casts, KlaviyoProfile heeft 23 casts.
   - Prioriteit: n.v.t.

3. **Relaties: 100% getypt** — Alle 39 relatie-declaraties hebben return type hints (`BelongsTo`, `HasMany`, `HasOne`).
   - Prioriteit: n.v.t.

4. **12 van 28 models missen factories** — DemandEvent, ProductBom, ProductBomLine, OpenPurchaseOrder, PurchaseCalendarRun, PurchaseCalendarEvent, ScenarioProductMix, ForecastSnapshot, SegmentTransition, SupplyProfile, DemandEventCategory, SyncState.
   - Impact: Tests maken deze models inline aan met hardcoded data en static counters. Belemmert snelle test-iteratie.
   - Prioriteit: Hoog

5. **Scopes op 64% van models** — 18 van 28 models hebben query scopes. Goede voorbeelden: `DemandEvent->historical()`, `SeasonalIndex->forRegion()`, `PurchaseCalendarEvent->purchases()`. Maar 10 models missen scopes waar ze waarde zouden toevoegen (SyncState, SupplyProfile, OpenPurchaseOrder).
   - Impact: Query logica wordt herhaald in services
   - Prioriteit: Laag

6. **Accessors onder-benut** — Slechts 1 accessor in de hele codebase (`RiderProfile::typedSegment`). `KeyResult::progress()` is een method die een accessor zou moeten zijn.
   - Impact: Gering, maar gemiste kans voor cleaner API
   - Prioriteit: Laag

7. **Geen soft deletes: correcte keuze** — Data komt van externe API's (Shopify, Klaviyo, Odoo). De externe bron is de source of truth. Soft deletes zouden conflicteren met sync-logica.
   - Prioriteit: n.v.t.

**Wat goed gaat:**
- Casting is uitstekend (precision, enums, immutable dates)
- Relatie-typing is 100%
- Bewuste keuze voor geen soft deletes

---

### D5. Database & Multi-Platform Compatibiliteit — ORANJE

**Bevindingen:**

1. **DbDialect helper: goed geïmplementeerd** — `app/Support/DbDialect.php` abstraheert date/time functies correct voor SQLite, PostgreSQL en MySQL. Methods: `yearMonthExpr()`, `monthExpr()`, `yearExpr()`, `yearWeekExpr()`, `weekExpr()`, `daysDiffExpr()`, `daysSinceExpr()`.
   - Prioriteit: n.v.t.

2. **13 services gebruiken DB::select() met raw SQL** — Alle gebruiken parameter binding (`?`). Window functions (ROW_NUMBER, SUM OVER, LAG) worden gebruikt en werken op SQLite 3.25+ en PostgreSQL.
   - Prioriteit: n.v.t.

3. **1 medium-risk query** — `RetentionAnalyticsService:116` passeert een `LAG()` window function als parameter aan `DbDialect::daysDiffExpr()`. Werkt op SQLite en PostgreSQL, maar MySQL-compatibiliteit is onzeker.
   - Impact: Laag (MySQL niet in gebruik), maar technische schuld
   - Prioriteit: Laag

4. **72 migraties: allemaal platform-agnostisch** — Standaard Laravel column types (id, string, decimal, boolean, date, json). Foreign keys met `cascadeOnDelete()`. Geen platform-specifieke syntax.
   - Prioriteit: n.v.t.

5. **JSON columns correct afgehandeld** — PurchaseCalendarRun en KlaviyoProfile gebruiken Eloquent `'array'` cast. Geen raw `json_extract()` of `jsonb` operators.
   - Prioriteit: n.v.t.

6. **Week-nummering verschil** — SQLite `strftime('%W')` (0-53, maandag-start) vs PostgreSQL `EXTRACT(WEEK)` (ISO 1-53). Afgehandeld via DbDialect, maar semantisch verschil kan subtiele bugs veroorzaken bij week-grensgevallen.
   - Impact: Potentieel verkeerde weekindeling bij rapportages rond jaarwisseling
   - Prioriteit: Medium

7. **Transacties correct** — ShopifyOrderSyncer, OdooOpenPoSyncer, KlaviyoProfileSyncer gebruiken `DB::transaction()` waar nodig.
   - Prioriteit: n.v.t.

**Wat goed gaat:**
- DbDialect is een effectieve abstractielaag
- Migraties zijn 100% platform-agnostisch
- JSON en transacties correct afgehandeld

**Advies SQLite vs PostgreSQL lokaal:** Zie Bijlage C.

---

### D6. REST API Volledigheid & Kwaliteit — ROOD

**Bevindingen:**

1. **Slechts 3 data models ontsloten** — ShopifyOrder, ShopifyCustomer, ShopifyProduct. De overige 25 models (inclusief alle forecast, supply chain, scoring en analytics data) zijn niet via de API bereikbaar.
   - Impact: Interne apps en de toekomstige frontend kunnen alleen basisdata opvragen. Forecast, planning en analytics vereisen directe database toegang of Inertia props.
   - Prioriteit: Hoog

2. **Read-only API** — Alleen GET endpoints. Geen POST/PUT/DELETE voor CRUD-operaties. Geen mutations mogelijk.
   - Impact: Externe apps kunnen geen data wijzigen (scenarios aanpassen, demand events toevoegen)
   - Prioriteit: Medium

3. **API routes niet benaamd** — Geen `->name()` op API routes. `route()` helper werkt niet voor API endpoints.
   - Impact: Wayfinder kan geen TypeScript route functions genereren voor API
   - Prioriteit: Medium

4. **Geen rate limiting op API** — Auth endpoints zijn gerafilimiteerd (5/min login, 6/min password reset), maar `/api/v1/*` endpoints hebben geen throttle.
   - Impact: API kan zonder beperking gehammerd worden
   - Prioriteit: Hoog

5. **Geen custom error envelope** — Standaard Laravel JSON errors. Geen gestandaardiseerd format voor validation errors, 404s en 500s.
   - Impact: Frontend moet diverse error formats parsen
   - Prioriteit: Medium

6. **Geen dynamische sorting** — Sorting is impliciet per endpoint (`-ordered_at`, `title`). Geen query parameter support voor `?sort=field&direction=asc`.
   - Impact: Beperkte flexibiliteit voor data tables
   - Prioriteit: Laag

7. **Pagination en filtering: goed** — Alle index endpoints pagineren (1-100 per page, default 50). Filters worden gevalideerd via FormRequests. Proper gebruik van `->has()` voor conditionele filters.
   - Prioriteit: n.v.t.

**Wat goed gaat:**
- Bestaande endpoints zijn RESTful (correcte HTTP methods, URL structuur)
- Consistente Resources met `whenLoaded()` voor relaties
- Filters via FormRequests

**Ontbrekende endpoints:** Zie Bijlage A.

---

### D7. Security & Data Protection — ORANJE

**Bevindingen:**

1. **Sanctum tokens verlopen nooit** — `config/sanctum.php` regel 53: `'expiration' => null`. Geen token prefix, geen token abilities (scoped permissions).
   - Impact: Een gecompromitteerd token geeft onbeperkte, eeuwige toegang
   - Prioriteit: Hoog

2. **20 models gebruiken `$guarded = []`** — Architecturaal risico: elk veld is mass-assignable. Praktisch risico is laag (huidige controllers accepteren geen directe model input via FormRequests), maar bij API uitbreiding met POST/PUT endpoints wordt dit een kwetsbaarheid.
   - Impact: Hoog bij toekomstige write-endpoints
   - Prioriteit: Hoog

3. **Geen expliciete CORS config** — Geen `config/cors.php` gevonden. Sanctum stateful domains zijn geconfigureerd, maar cross-origin API requests zijn niet expliciet gecontroleerd.
   - Impact: Onvoorspelbaar gedrag bij API access vanuit andere domeinen
   - Prioriteit: Medium

4. **Route protection: volledig** — Alle web routes achter `['auth', 'verified']`. Alle API routes achter `auth:sanctum`. Geen publieke endpoints behalve de homepage.
   - Prioriteit: n.v.t.

5. **2FA correct geïmplementeerd** — Fortify met confirmation requirement, rate limiting (5/min), recovery codes. `two_factor_secret` en `two_factor_recovery_codes` correct als `#[Hidden]` op User model.
   - Prioriteit: n.v.t.

6. **Geen hardcoded credentials** — Alle API clients laden tokens via `config()`. ShopifyClient, KlaviyoClient en OdooClient loggen geen credentials. `.env` staat in `.gitignore`.
   - Prioriteit: n.v.t.

7. **SQL injection: geen risico** — Alle queries gebruiken parameter binding. User input komt altijd via FormRequest validatie.
   - Prioriteit: n.v.t.

8. **`env()` alleen in config files** — Geen `env()` calls in `/app/` directory. Alle configuratie via `config()`.
   - Prioriteit: n.v.t.

**Wat goed gaat:**
- Route protection is volledig
- 2FA correct met rate limiting
- Credential management via .env/config is consequent
- Geen SQL injection risico's

---

### D8. Frontend-Readiness & Design System — ORANJE

**Bevindingen:**

1. **Inertia setup: solide** — Shared props (auth, sidebarOpen) zijn type-safe. SSR correct geconfigureerd met TooltipProvider. Wayfinder route generation werkt.
   - Prioriteit: n.v.t.

2. **TypeScript types: onvolledig** — Dashboard types zijn compleet (19 interfaces). Maar types voor API response models (Customer, Order, Product), error responses en form submissions ontbreken.
   - Impact: Frontend development vereist handmatig type-inferentie
   - Prioriteit: Medium

3. **25 shadcn/ui componenten geïnstalleerd, 11+ ontbreken** — Missend voor dashboard: **data-table** (cruciaal), **tabs**, **toast/sonner** (user feedback), **calendar/date-picker** (date-range filtering), **command** (search), **popover**, **form** (React Hook Form integratie).
   - Impact: Blokkerend voor volledige dashboard build-out
   - Prioriteit: Hoog

4. **Geen global error boundary** — Alleen form-level error handling (InputError component). Geen Inertia ErrorBoundary, geen 404/500 pagina's, geen runtime exception handling.
   - Impact: Onafgevangen fouten crashen de hele pagina
   - Prioriteit: Hoog

5. **Design tokens: compleet** — OKLCH kleurensysteem met light/dark mode. 5 chart kleuren, 8 shadow levels, 3 font stacks. Sidebar tokens. Semantische tokens voor states.
   - Prioriteit: n.v.t.

6. **Dark mode: volledig** — Inline script voorkomt flash, `useAppearance` hook met localStorage + cookie persistentie, alle custom tokens dark mode-aware.
   - Prioriteit: n.v.t.

7. **Mobile-first: goed** — Dashboard grid responsief (1→2→4 kolommen), sidebar collapsible naar icon-mode, auth layouts responsief. `useIsMobile()` hook op 768px breakpoint.
   - Prioriteit: n.v.t.

8. **Layout systeem: solide** — AppLayout → AppSidebarLayout → AppShell compositie. Sidebar met `collapsible="icon"`, breadcrumbs, persisted state via cookie. Herbruikbaar page template patroon.
   - Prioriteit: n.v.t.

9. **Styleguide 80% compleet** — Thema, chart kleuren, status kleuren, formatting conventies, breakpoints gedocumenteerd. Mist: form patterns, error handling patterns, button varianten, typography schaal. Nav items verouderd.
   - Impact: Inconsistentie bij frontend uitbouw
   - Prioriteit: Laag

**Wat goed gaat:**
- Design tokens en dark mode zijn productie-klaar
- Layout systeem is solide en uitbreidbaar
- Deferred props met skeleton loading correct geïmplementeerd
- CVA patronen consequent

**Ontbrekende componenten en patterns:** Zie Bijlage B.

---

### D9. Naamgeving & Navigeerbaarheid — GROEN

**Bevindingen:**

1. **Service naamgeving: uitstekend** — Alle 61 services zijn zelfverklarend. Patronen: `XxxService`, `XxxSyncer`, `XxxCalculator`, `XxxResolver`, `XxxAnalyzer`. Domein-prefix via mappenstructuur (`Forecast/Demand/`, `Sync/`, `Analysis/`).
   - Prioriteit: n.v.t.

2. **Command naamgeving: consequent** — Alle 41 commands volgen `domain:action` patroon: `forecast:generate`, `klaviyo:sync-profiles`, `orders:compute-margins`, `sync:all`.
   - Prioriteit: n.v.t.

3. **Vorige audit-bevindingen geverifieerd:**
   - `ForecastTrackingService` — correct benoemd, handelt snapshots + actuals af (gedocumenteerd in architectuur.md)
   - `StockForecastService` — bestaat niet meer; stock wordt afgehandeld door `ProductionTimelineService` + `InventoryHealthService`
   - `ProductionTimelineService` — heeft single responsibility (`timeline()` methode)
   - **Alle drie eerdere concerns zijn opgelost.**

4. **Geen generieke method names** — Geen `process()`, `handle()` of `run()` in services. Domein-specifieke verbs: `explode()`, `net()`, `stockFreshness()`, `kpiMetrics()`, `acquisitionTrend()`.
   - Prioriteit: n.v.t.

5. **Enum organisatie: excellent** — 13 enums logisch gegroepeerd per domein (Customer/Lifecycle, Forecast, Product). Alle met `label()` helper voor UI. Gespecialiseerde methods: `FollowerSegment::isDisengaged()`, `CustomerSegment::isAtRisk()`.
   - Prioriteit: n.v.t.

**Wat goed gaat:**
- De codebase is navigeerbaar zonder documentatie: namen vertellen wat de code doet
- Alle eerder geconstateerde naamgevingsproblemen zijn opgelost

---

### D10. Test Coverage & Kwaliteit — ORANJE

**Bevindingen:**

1. **67 tests, kritieke paden gedekt** — Sync pipeline, demand forecast, BOM explosion, component netting, seasonal indices, RFM scoring, purchase calendar, retention analytics — allemaal met feature tests.
   - Prioriteit: n.v.t.

2. **12 models missen factories** — DemandEvent, ProductBom, ProductBomLine, OpenPurchaseOrder, ScenarioProductMix, PurchaseCalendarRun, PurchaseCalendarEvent, ForecastSnapshot, SegmentTransition, SupplyProfile, DemandEventCategory, SyncState. Tests maken deze inline aan met hardcoded data en static counters (`BomExplosionServiceTest:24`).
   - Impact: Tests zijn fragiel en moeilijk uit te breiden
   - Prioriteit: Hoog

3. **Edge cases goed getest** — Phantom BOM traversal, 3-level deep nesting, Q1 incompleteness, anomaly detection (>30% deviation), event uplift >50%, return rate correcties, discount-corrected AOV, dynamic acquisition AOV, retention curve fallbacks.
   - Prioriteit: n.v.t.

4. **Test isolatie: goed** — Alle tests gebruiken `RefreshDatabase`. Factory gebruik correct. Geen volgorde-afhankelijkheden gedetecteerd.
   - Prioriteit: n.v.t.

5. **Gaps in test coverage:**
   - Geen API rate limiting tests
   - Geen API payload validation tests (alleen `ApiSecurityTest` voor auth)
   - Geen tests voor sync cursor recovery na crash
   - Geen concurrent sync safety tests
   - Geen tests voor lege database scenario's (nieuw product category)
   - Geen performance tests (>10K orders)
   - Prioriteit: Medium

**Wat goed gaat:**
- Kritieke paden zijn grondig getest inclusief edge cases
- Test isolatie is correct
- Bestaande factories zijn up-to-date met migraties

---

### D11. Error Handling & Observability — GROEN

**Bevindingen:**

1. **Consistent error patroon** — Services gebruiken try-catch met logging en re-throw. API clients (ShopifyClient, KlaviyoClient, OdooClient) loggen context bij fouten en implementeren retry met backoff.
   - Prioriteit: n.v.t.

2. **Geen silent failures** — Nul lege catch blocks in de hele codebase. Alle exceptions worden gelogd met context (error message, step name, attempt count) voor ze worden afgehandeld.
   - Prioriteit: n.v.t.

3. **2 custom exceptions: doelgericht** — `InsufficientBaselineException` (Q1 data ontbreekt) en `InvalidProductMixException` (shares tellen niet op tot ~1.0). Named constructors met duidelijke messages.
   - Prioriteit: n.v.t.

4. **SyncState monitoring: excellent** — Elke sync stap wordt getrackt met status, started_at, last_synced_at, cursor, records_synced, duration_seconds. `isStale()` detecteert gecrashte runs (>6 min). `resetStaleSyncStates()` herstelt automatisch.
   - Prioriteit: n.v.t.

5. **Logging levels correct** — `Log::error()` voor RPC/GraphQL failures, `Log::warning()` voor anomalieën en rate limits, `Log::info()` voor sync completions en fallbacks. Geen credentials gelogd.
   - Prioriteit: n.v.t.

6. **Geen health-check command** — Er is geen `php artisan health:check` dat SyncState, API credentials en database status controleert.
   - Impact: Handmatige inspectie nodig om systeemgezondheid te verifiëren
   - Prioriteit: Medium

7. **Geen queue systeem** — Syncs zijn synchrone CLI commands. SyncState cursor biedt hervatting maar geen atomaire job failure handling.
   - Impact: Acceptabel voor huidige schaal, maar schaalt niet naar background processing
   - Prioriteit: Laag

**Wat goed gaat:**
- Error handling is consequent en informatief
- SyncState is een uitstekend monitoring-mechanisme
- Logging is op het juiste niveau zonder gevoelige data

---

### D12. Database Workflow: Lokaal → Staging — ORANJE

**Bevindingen:**

1. **DatabaseSeeder roept geen sub-seeders aan** — `DatabaseSeeder::run()` maakt alleen een test user. De 6 andere seeders moeten handmatig in de juiste volgorde gedraaid worden. Er is geen gedocumenteerde volgorde.
   - Impact: Nieuwe developer of verse omgeving opzetten is error-prone
   - Prioriteit: Hoog

2. **Seeder idempotentie: gemengd** — `UserSeeder` en `SupplyProfileSeeder` zijn idempotent (check/updateOrCreate). `ScenarioSeeder` en `DemandEventSeeder` gebruiken `create()` zonder check — falen bij re-run als er unique constraints zijn.
   - Impact: `php artisan db:seed` is niet veilig herhaaldbaar
   - Prioriteit: Hoog

3. **Factory completeness: 57%** — 16 van 28 models hebben factories. De 12 ontbrekende factories (zie D4/D10) zorgen voor inline test data en belemmeren seeding.
   - Impact: Gecombineerd met punt 1 en 2 maakt dit de hele setup workflow fragiel
   - Prioriteit: Hoog

4. **72 migraties: rollback veilig** — Alle migraties hebben `down()` methods. Platform-agnostische column types. Foreign keys met cascade deletes.
   - Prioriteit: n.v.t.

5. **Geen `sync:status` command** — `SyncResetCursorCommand` toont individuele stappen maar er is geen globaal overzicht van alle sync stappen + leeftijden.
   - Impact: Geen snel inzicht in sync pipeline status
   - Prioriteit: Medium

6. **Architectuurdocumentatie: goed maar setup mist** — `docs/architectuur.md` beschrijft stack, lagen, pipeline en forecast systeem. Maar setup-instructies (migraties, seeding volgorde, .env configuratie, API credentials) ontbreken.
   - Impact: Onboarding en environment setup kost onnodig veel tijd
   - Prioriteit: Medium

**Wat goed gaat:**
- Migraties zijn platform-agnostisch en rollback veilig
- SyncState biedt cursor-gebaseerde hervatting
- Architectuurdocumentatie is gedetailleerd

**Ideale workflow:** Zie Bijlage D.

---

## Bijlage A: API Ontbrekende Endpoints

### Forecast & Planning

| Endpoint | Method | Beschrijving |
|----------|--------|-------------|
| `/api/v1/scenarios` | GET | Lijst van forecast scenario's |
| `/api/v1/scenarios/{scenario}` | GET | Scenario detail met assumptions en product mix |
| `/api/v1/scenarios/{scenario}/forecast` | GET | Demand forecast voor een scenario |
| `/api/v1/scenarios/{scenario}/purchase-calendar` | GET | Purchase calendar voor een scenario |
| `/api/v1/forecast-snapshots` | GET | Historische forecast snapshots |
| `/api/v1/demand-events` | GET | Lijst van demand events (historisch + gepland) |

### Supply Chain

| Endpoint | Method | Beschrijving |
|----------|--------|-------------|
| `/api/v1/supply-profiles` | GET | Supply profiles per product category |
| `/api/v1/open-purchase-orders` | GET | Open PO's uit Odoo |
| `/api/v1/stock-snapshots` | GET | Product stock snapshots |
| `/api/v1/inventory-health` | GET | Inventory health dashboard (runways, freshness) |
| `/api/v1/boms` | GET | Bill of Materials met explosion |

### Analytics & Scoring

| Endpoint | Method | Beschrijving |
|----------|--------|-------------|
| `/api/v1/analytics/revenue` | GET | Revenue trends en split |
| `/api/v1/analytics/acquisition` | GET | Acquisitie trends |
| `/api/v1/analytics/retention` | GET | Cohort retention data |
| `/api/v1/analytics/products` | GET | Product performance |
| `/api/v1/analytics/channels` | GET | Channel performance |
| `/api/v1/analytics/segments` | GET | Segment movement |
| `/api/v1/analytics/customer-value` | GET | Customer value analyse |

### Klaviyo & Engagement

| Endpoint | Method | Beschrijving |
|----------|--------|-------------|
| `/api/v1/klaviyo/profiles` | GET | Klaviyo profielen met engagement scores |
| `/api/v1/klaviyo/campaigns` | GET | Campagne performance |

### Sync & Monitoring

| Endpoint | Method | Beschrijving |
|----------|--------|-------------|
| `/api/v1/sync/status` | GET | Status van alle sync stappen |
| `/api/v1/health` | GET | System health check |

---

## Bijlage B: Ontbrekende shadcn/ui Componenten & Frontend Gaps

### Ontbrekende Componenten (prioriteit)

| Component | Reden | Prioriteit |
|-----------|-------|-----------|
| **data-table** | Cruciaal voor product, order en customer tabellen | Hoog |
| **toast / sonner** | User feedback bij acties (sync gestart, settings opgeslagen) | Hoog |
| **tabs** | Dashboard secties, settings pagina's | Hoog |
| **calendar + date-picker** | Date-range filtering op dashboards | Hoog |
| **command** | Search/command palette | Medium |
| **popover** | Tooltips, filter dropdowns | Medium |
| **form** | React Hook Form integratie voor settings en mutations | Medium |
| **progress** | Sync voortgang, loading states | Laag |
| **slider** | Scenario parameter aanpassing | Laag |
| **switch** | Toggle settings | Laag |
| **textarea** | Notities, feedback | Laag |

### Ontbrekende TypeScript Types

| Type | Beschrijving |
|------|-------------|
| `Customer` | Shopify customer met rider profile, RFM scores |
| `Order` | Shopify order met line items, marges |
| `Product` | Product met BOM, portfolio role, category |
| `Scenario` | Forecast scenario met assumptions en product mix |
| `ForecastData` | Demand forecast output per maand/regio |
| `PurchaseCalendar` | Purchase calendar events en runs |
| `SyncStatus` | Sync pipeline status per stap |
| `ApiError` | Gestandaardiseerd error response format |
| `PaginatedResponse<T>` | Generic paginated API response |

### Ontbrekende UI Patterns

| Pattern | Beschrijving |
|---------|-------------|
| Global error boundary | Inertia ErrorBoundary met fallback UI |
| Error pages | 404, 500, 503 pagina's |
| Empty states | Patroon voor "geen data" met actie-suggestie |
| Filter bar | Herbruikbaar filter component voor data tables |
| Detail panel | Slide-over of modal voor record details |
| Confirmation dialog | "Weet je het zeker?" voor destructieve acties |
| Toast notifications | Systeem-breed feedback mechanisme |

---

## Bijlage C: Database Advies — SQLite vs PostgreSQL Lokaal

### Huidige Situatie

- **Lokaal:** SQLite via Laravel Herd (zero-config)
- **Staging:** PostgreSQL via Laravel Cloud
- **DbDialect:** Abstractielaag voor database-specifieke SQL

### Analyse

| Factor | SQLite | PostgreSQL Lokaal |
|--------|--------|-------------------|
| **Setup** | Zero-config, al werkend | Vereist Herd Pro of Homebrew install |
| **Pariteit met staging** | Verschil in week-nummering, JSON handling, window function nuances | 100% pariteit |
| **DbDialect overhead** | Nodig, voegt complexiteit toe | Kan vereenvoudigd/verwijderd worden |
| **Ad-hoc analyse** | Beperkte query planner, geen CTEs (pre-3.35) | Superieure query planner, window functions, CTEs |
| **Bugs door verschil** | Week-nummering verschil kan subtiele bugs veroorzaken | Geen risico |
| **Migratie-effort** | n.v.t. | Eenmalig: database aanmaken, migraties draaien, sync pipeline runnen |

### Aanbeveling: **Migreer lokaal naar PostgreSQL**

**Waarom:**

1. **Pariteit elimineert een hele klasse bugs.** Het week-nummering verschil (SQLite 0-based vs PostgreSQL ISO) is een reëel risico bij rapportages. Eén database platform = geen DbDialect nodig = minder code = minder bugs.

2. **Ad-hoc analyse wordt krachtiger.** PostgreSQL's query planner, window functions, CTEs en native JSON queries maken chat-gebaseerde data analyse significant effectiever.

3. **DbDialect kan op termijn verwijderd worden.** Als beide omgevingen PostgreSQL draaien, wordt de abstractielaag overbodig. Dat vereenvoudigt alle 13 services die raw SQL gebruiken.

4. **Setup-kosten zijn eenmalig en laag.** Laravel Herd Pro biedt PostgreSQL support. Alternatief: `brew install postgresql@17`. Eenmalig migraties draaien + sync pipeline starten.

**Migratieplan:**
1. PostgreSQL installeren via Herd Pro of Homebrew
2. `.env` aanpassen: `DB_CONNECTION=pgsql`
3. `php artisan migrate`
4. `php artisan db:seed` (alle seeders)
5. `php artisan sync:all --full`
6. Verifieer: alle tests draaien op PostgreSQL
7. Op termijn: DbDialect uitfaseren, raw SQL vereenvoudigen

---

## Bijlage D: Ideale Database Workflow

### 1. Nieuwe developer of verse omgeving opzetten

```bash
# 1. Clone en install
git clone <repo>
cd cyclowax-dashboard
composer install && npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
# Configureer: DB_CONNECTION, Shopify/Klaviyo/Odoo credentials

# 3. Database opzetten
php artisan migrate
php artisan db:seed          # DatabaseSeeder roept ALLE seeders aan in juiste volgorde

# 4. Data vullen via sync
php artisan sync:all --full  # Volledige initiële sync

# 5. Scores en forecasts berekenen
php artisan customers:calculate-rfm
php artisan forecast:generate --year=2026

# 6. Verifieer
php artisan health:check     # Controleert sync status, API credentials, data integriteit
php artisan test --compact   # Alle tests groen
```

### 2. Schema wijzigingen doorvoeren

```bash
# 1. Maak migratie
php artisan make:migration add_column_to_table

# 2. Test lokaal
php artisan migrate
php artisan test --compact --filter=RelevantTest

# 3. Commit en push
# Staging pikt de migratie op via deploy pipeline
```

### 3. Staging up-to-date brengen

```bash
# Optie A: Staging synct zelfstandig (aanbevolen)
# - Scheduler draait dagelijks
# - Migraties draaien automatisch bij deploy

# Optie B: Verse staging database
php artisan migrate:fresh     # Alleen als data verlies acceptabel is
php artisan db:seed
php artisan sync:all --full
```

### 4. Verifiëren dat staging correct werkt

```bash
php artisan health:check      # Sync status, API credentials, data integriteit
php artisan sync:status       # Overzicht van alle sync stappen + leeftijden
```

### Wat er moet veranderen om dit te bereiken

| Actie | Prioriteit |
|-------|-----------|
| `DatabaseSeeder` orchestreert alle seeders in juiste volgorde | Hoog |
| Alle seeders idempotent maken (`updateOrCreate`) | Hoog |
| 12 ontbrekende factories aanmaken | Hoog |
| `php artisan health:check` command bouwen | Medium |
| `php artisan sync:status` command bouwen | Medium |
| Setup documentatie schrijven (SETUP.md of README uitbreiden) | Medium |

---

## Bijlage E: Refactoring Backlog

### Hoog Prioriteit

| # | Item | Dimensie | Impact |
|---|------|----------|--------|
| 1 | Sanctum token expiration instellen (60-480 min) | D7 | Security: tokens verlopen nooit |
| 2 | Rate limiting toevoegen op API endpoints (`throttle:60,1`) | D6/D7 | Security: API onbeschermd tegen abuse |
| 3 | 12 ontbrekende factories aanmaken | D4/D10/D12 | Testbaarheid en setup workflow |
| 4 | DatabaseSeeder orchestreren (alle seeders in juiste volgorde) | D12 | Fresh install workflow |
| 5 | Alle seeders idempotent maken | D12 | Veilig herhaaldbaar seeden |
| 6 | DemandForecastService opsplitsen (867 regels) | D2 | Leesbaarheid en testbaarheid |
| 7 | SalesBaselineService opsplitsen (730 regels) | D2 | Leesbaarheid en testbaarheid |
| 8 | `$guarded = []` vervangen door `$fillable` op alle models | D7 | Security bij toekomstige write-endpoints |
| 9 | Global error boundary toevoegen (Inertia ErrorBoundary) | D8 | Frontend stabiliteit |
| 10 | Ontbrekende shadcn/ui componenten installeren (data-table, toast, tabs, date-picker) | D8 | Blokkerend voor dashboard build-out |

### Medium Prioriteit

| # | Item | Dimensie | Impact |
|---|------|----------|--------|
| 11 | API uitbreiden: forecast, analytics, sync status endpoints | D6 | Interne apps kunnen data opvragen |
| 12 | API route names toevoegen | D6 | Wayfinder TypeScript generation |
| 13 | Custom API error envelope definiëren | D6 | Consistente error handling in frontend |
| 14 | Exception handling standaardiseren op `Throwable` in alle commands | D3 | Consistentie, geen silent crashes |
| 15 | Logging toevoegen aan report generation commands | D3 | Debugging van batch operaties |
| 16 | `php artisan health:check` command bouwen | D11 | Snel systeem-status inzicht |
| 17 | `php artisan sync:status` command bouwen | D12 | Sync pipeline monitoring |
| 18 | TypeScript types aanmaken voor API models | D8 | Type-safe frontend development |
| 19 | CORS configuratie expliciet maken | D7 | Voorspelbaar cross-origin gedrag |
| 20 | Setup documentatie schrijven | D12 | Onboarding en environment setup |
| 21 | Week-nummering verschil onderzoeken en documenteren | D5 | Correcte rapportages bij jaargrens |
| 22 | Token abilities implementeren (scoped API permissions) | D7 | Least-privilege API access |

### Laag Prioriteit

| # | Item | Dimensie | Impact |
|---|------|----------|--------|
| 23 | Cache TTL centraliseren in config | D1 | Centraal aanpasbaar |
| 24 | Fat commands refactoren naar services | D1/D3 | Herbruikbaarheid |
| 25 | Query scopes toevoegen aan 10 models (SyncState, SupplyProfile, etc.) | D4 | DRY queries |
| 26 | Private method documentatie in elephant services | D2 | Kennisoverdracht |
| 27 | Progress bars toevoegen aan lange commands | D3 | CLI UX |
| 28 | `KeyResult::progress()` converteren naar accessor | D4 | Cleaner API |
| 29 | Styleguide aanvullen (form patterns, error handling, button varianten) | D8 | Consistente frontend build-out |
| 30 | Performance tests toevoegen (>10K orders, grote BOMs) | D10 | Schaalzekerheid |
