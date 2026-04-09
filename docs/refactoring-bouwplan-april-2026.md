# Refactoring Bouwplan — Post-Audit April 2026

## Context

De architectuur-audit van 9 april 2026 heeft 30 refactoring items opgeleverd (10 hoog, 12 medium, 8 laag). De codebase is architecturaal sterk maar heeft gaps in **completeness**: API dekking, test-infrastructuur (factories/seeders), security configuratie en frontend componenten. Thomas wil daarnaast **lokaal migreren naar PostgreSQL** — dit is een prioriteit en enabler voor verdere vereenvoudiging (DbDialect uitfaseren).

**Doel:** In 6 sprints de codebase refactoring-klaar maken voor de dashboard UI build-out (fase 1-4 van de roadmap). Na dit plan is de technische schuld opgelost en kan er gefocust gebouwd worden.

**Audit rapport:** `docs/audit-rapport-april-2026.md`

---

## Sprint 1: PostgreSQL Migratie Lokaal + Database Workflow

**Wat:** Lokale database overzetten van SQLite naar PostgreSQL. Seeders fixen. Setup documenteren.
**Waarom:** Elimineert database-pariteit risico's, maakt DbDialect op termijn overbodig, en lost de fragiele setup workflow op.

### Stap 1.1 — PostgreSQL installeren en configureren
- PostgreSQL 17 installeren via Homebrew (`brew install postgresql@17`)
- Service starten (`brew services start postgresql@17`)
- Database aanmaken: `createdb cyclowax_dashboard`
- `.env` aanpassen: `DB_CONNECTION=pgsql`, `DB_DATABASE=cyclowax_dashboard`, `DB_USERNAME=<user>`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`
- `.env.example` updaten met PostgreSQL als default

**Bestanden:** `.env`, `.env.example`

### Stap 1.2 — DatabaseSeeder orchestreren
- `DatabaseSeeder::run()` laten aanroepen: UserSeeder → SupplyProfileSeeder → ScenarioSeeder → ScenarioProductMixSeeder → RegionalScenarioSeeder → DemandEventSeeder
- Volgorde is kritiek: Scenarios voor ProductMix en RegionalScenario

**Bestanden:** `database/seeders/DatabaseSeeder.php`

### Stap 1.3 — Seeders idempotent maken
- `ScenarioSeeder`: `updateOrCreate` op scenario name
- `ScenarioProductMixSeeder`: `updateOrCreate` op scenario_id + product_category
- `RegionalScenarioSeeder`: `updateOrCreate` op scenario_id + region
- `DemandEventSeeder`: `updateOrCreate` op name + start_date (of vergelijkbare unique combo)

**Bestanden:** `database/seeders/Scenario*.php`, `database/seeders/DemandEventSeeder.php`, `database/seeders/RegionalScenarioSeeder.php`

### Stap 1.4 — Migraties draaien en valideren
- `php artisan migrate` op PostgreSQL
- `php artisan db:seed` (volledige DatabaseSeeder)
- `php artisan test --compact` — alle tests groen op PostgreSQL
- Fix eventuele SQLite-specifieke issues in tests

### Stap 1.5 — Sync pipeline draaien
- `php artisan sync:all --full` op PostgreSQL
- Verifieer dat alle sync stappen completen
- Spot-check: order counts, customer counts, Klaviyo profielen

### Stap 1.6 — Setup documentatie
- `docs/SETUP.md` schrijven met stap-voor-stap instructies (clone → install → env → migrate → seed → sync → verify)

**Bestanden:** `docs/SETUP.md`

---

## Sprint 2: Security Hardening

**Wat:** De 4 security-bevindingen uit de audit fixen.
**Waarom:** Sanctum tokens verlopen nooit, API heeft geen rate limiting, 20 models zijn volledig mass-assignable. Dit moet opgelost zijn voor de API uitgebreid wordt.

### Stap 2.1 — Sanctum token expiration
- `config/sanctum.php`: `expiration` instellen op `480` (8 uur)
- Token prefix instellen voor detectie

**Bestanden:** `config/sanctum.php`

### Stap 2.2 — API rate limiting
- `throttle:api` rate limiter definiëren in `bootstrap/app.php` of `AppServiceProvider`
- Toepassen op API route group in `routes/api.php`

**Bestanden:** `bootstrap/app.php` of `app/Providers/AppServiceProvider.php`, `routes/api.php`

### Stap 2.3 — Mass assignment: $guarded → $fillable
- Alle 20 models met `$guarded = []` omzetten naar expliciete `$fillable` arrays
- Per model: fillable lijst afleiden uit migratie-kolommen en sync-logica
- Tests draaien na elke batch (sync models, forecast models, scoring models)

**Bestanden:** Alle 20 models in `app/Models/`

### Stap 2.4 — CORS configuratie
- `config/cors.php` aanmaken met expliciete allowed origins
- Configureerbaar via `.env` voor lokaal vs staging

**Bestanden:** `config/cors.php`, `.env.example`

---

## Sprint 3: Test-infrastructuur

**Wat:** 12 ontbrekende factories aanmaken. Commands standaardiseren.
**Waarom:** Factories zijn nodig voor betrouwbare tests en seeders. Command-standaardisatie voorkomt silent failures.

### Stap 3.1 — Ontbrekende factories aanmaken (12 stuks)
Per factory: `php artisan make:factory`, kolommen uit migratie overnemen, relaties via factory states.

Volgorde (afhankelijkheden):
1. `DemandEventCategoryFactory`
2. `DemandEventFactory` (→ category)
3. `SupplyProfileFactory`
4. `ProductBomFactory` (→ product)
5. `ProductBomLineFactory` (→ bom, product)
6. `OpenPurchaseOrderFactory` (→ product)
7. `ScenarioProductMixFactory` (→ scenario)
8. `ForecastSnapshotFactory` (→ scenario)
9. `PurchaseCalendarRunFactory` (→ scenario)
10. `PurchaseCalendarEventFactory` (→ run)
11. `SegmentTransitionFactory` (→ rider_profile)
12. `SyncStateFactory`

**Bestanden:** `database/factories/*.php`

### Stap 3.2 — Inline test data vervangen door factories
- `BomExplosionServiceTest`: static $bomCounter vervangen door ProductBomFactory
- `DemandForecastServiceTest`: hardcoded ScenarioProductMix vervangen
- `ComponentNettingServiceTest`: inline ProductBom/Line vervangen

**Bestanden:** `tests/Feature/Bom*.php`, `tests/Feature/Demand*.php`, `tests/Feature/Component*.php`

### Stap 3.3 — Command exception handling standaardiseren
- Alle commands: `catch (Throwable $e)` (niet `\Exception`)
- 12 commands zonder try-catch: toevoegen met `Log::error()` + `self::FAILURE`
- Report generation commands: try-catch + logging toevoegen

**Bestanden:** `app/Console/Commands/*.php` (12-15 bestanden)

---

## Sprint 4: Service Refactoring

**Wat:** De 2 grootste elephant services opsplitsen. Magic numbers naar config.
**Waarom:** DemandForecastService (867r) en SalesBaselineService (730r) zijn te groot om veilig te onderhouden.

### Stap 4.1 — DemandForecastService opsplitsen (867 → ~4 services)
- `DemandForecastService` wordt thin orchestrator (~150r)
- Extractie: `BaselineForecastCalculator` (baseline x growth x mix)
- Extractie: `SeasonalAdjustmentService` (seasonal indices toepassen)
- Extractie: `ForecastValidationService` (anomaly detection, Q1 checks)
- Bestaande tests moeten blijven slagen (public API wijzigt niet)

**Bestanden:** `app/Services/Forecast/Demand/DemandForecastService.php` + 3 nieuwe services

### Stap 4.2 — SalesBaselineService opsplitsen (730 → ~2 services)
- Extractie: `QuarterlyAovCalculator` (AOV per kwartaal berekeningen)
- `SalesBaselineService` behoudt baseline berekening (~400r)

**Bestanden:** `app/Services/Forecast/Demand/SalesBaselineService.php` + 1 nieuwe service

### Stap 4.3 — GenerateProductOverviewCommand → service
- PDF-opbouw logica (416r) verplaatsen naar `ProductPortfolioReportService`
- Command wordt thin orchestrator

**Bestanden:** `app/Console/Commands/GenerateProductOverviewCommand.php`, nieuwe service

### Stap 4.4 — Magic numbers naar config
- Cache TTL `3600` → `config/dashboard.php` key `cache_ttl`
- Rate limiting threshold `0.2` → `config/shopify.php`
- Toepassen in alle 7+ analysis services

**Bestanden:** `config/dashboard.php` (nieuw), `config/shopify.php`, 7 analysis services

---

## Sprint 5: API Uitbreiding

**Wat:** API route names, error envelope, en de belangrijkste ontbrekende endpoints.
**Waarom:** De frontend en toekomstige interne apps hebben toegang nodig tot forecast, analytics en sync data.

### Stap 5.1 — API route names toevoegen
- Alle bestaande routes benoemen (`->name('api.v1.orders.index')` etc.)
- Wayfinder regenereren

**Bestanden:** `routes/api.php`

### Stap 5.2 — Custom API error envelope
- Exception handler configureren in `bootstrap/app.php` voor JSON responses
- Standaard format: `{ error: { message, code, details } }`
- Validation errors: `{ error: { message, code: 'validation', fields: {...} } }`

**Bestanden:** `bootstrap/app.php`

### Stap 5.3 — Sync status endpoint
- `GET /api/v1/sync/status` — overzicht van alle sync stappen + leeftijden
- Controller + Resource

**Bestanden:** nieuw controller + resource

### Stap 5.4 — Analytics endpoints (batch)
- `GET /api/v1/analytics/revenue` — RevenueAnalyticsService
- `GET /api/v1/analytics/acquisition` — AcquisitionAnalyticsService
- `GET /api/v1/analytics/retention` — RetentionAnalyticsService
- `GET /api/v1/analytics/products` — ProductAnalyticsService
- Hergebruik bestaande services, alleen controllers + resources nodig

**Bestanden:** nieuwe controllers + resources in `app/Http/Controllers/Api/V1/Analytics/`

### Stap 5.5 — Forecast endpoints
- `GET /api/v1/scenarios` — lijst
- `GET /api/v1/scenarios/{scenario}` — detail met assumptions + mix
- `GET /api/v1/scenarios/{scenario}/forecast` — demand forecast
- Controllers + FormRequests + Resources

**Bestanden:** nieuwe controllers + resources

---

## Sprint 6: Frontend Readiness

**Wat:** Ontbrekende shadcn/ui componenten, error boundary, TypeScript types.
**Waarom:** Blokkerend voor de dashboard UI build-out (fase 1-4 van de roadmap).

### Stap 6.1 — shadcn/ui componenten installeren
- `data-table` (incl. tanstack/react-table)
- `tabs`
- `sonner` (toast notifications)
- `calendar` + `date-picker` (date-range filtering)
- `command` (search/combobox)
- `popover`

### Stap 6.2 — Global error boundary
- Inertia ErrorBoundary implementeren in `app.tsx`
- 404 en 500 error pagina's aanmaken

**Bestanden:** `resources/js/app.tsx`, `resources/js/pages/errors/`

### Stap 6.3 — TypeScript types voor API models
- Types aanmaken: Customer, Order, Product, Scenario, ForecastData, PurchaseCalendar, SyncStatus, ApiError, PaginatedResponse<T>

**Bestanden:** `resources/js/types/*.ts`

### Stap 6.4 — health:check en sync:status commands
- `php artisan health:check`: SyncState leeftijden, API credential validatie, database connectivity
- `php artisan sync:status`: tabel met alle sync stappen + last_synced_at + leeftijd

**Bestanden:** 2 nieuwe commands

### Stap 6.5 — Styleguide updaten
- Form patterns, error handling patterns, button varianten documenteren
- Verouderde nav items verwijderen

**Bestanden:** `docs/styleguide.md`

---

## Volgorde en Afhankelijkheden

```
Sprint 1 (PostgreSQL + DB workflow)
    |
    +---> Sprint 2 (Security) --+
    |                           |
    +---> Sprint 3 (Test-infra) +---> Sprint 4 (Service refactoring)
                                |          |
                                +----------+---> Sprint 5 (API)
                                                      |
                                                      +---> Sprint 6 (Frontend)
```

Sprint 2 en 3 kunnen parallel.

---

## Verificatie per Sprint

| Sprint | Verificatie |
|--------|------------|
| S1 | `php artisan test --compact` groen op PostgreSQL, `php artisan db:seed` idempotent, sync pipeline compleet |
| S2 | Token expiration test, rate limiting test, mass assignment test, CORS test |
| S3 | Alle 67+ tests groen, geen inline model creation in tests, commands loggen bij failure |
| S4 | Alle tests groen, DemandForecastService <200r, SalesBaselineService <450r |
| S5 | Nieuwe endpoints returnen correcte data, Wayfinder genereert routes, error envelope consistent |
| S6 | shadcn componenten renderen, error boundary vangt crashes, TypeScript compileert zonder errors |

---

## Docs updates (mee in elke sprint-commit)

- Sprint 1: `docs/SETUP.md` (nieuw), `docs/architectuur.md` (database sectie)
- Sprint 2: `docs/api.md` (rate limiting, auth sectie)
- Sprint 3: geen docs impact
- Sprint 4: `docs/architectuur.md` (service structuur update)
- Sprint 5: `docs/api.md` (nieuwe endpoints)
- Sprint 6: `docs/styleguide.md` (componenten, patterns)
