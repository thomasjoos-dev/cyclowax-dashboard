# Test Audit — 12 april 2026

## Samenvatting

| Categorie | Score | Status |
|-----------|-------|--------|
| CI Pipeline | KRITIEK | GEFIXT — PG service, timeout, migrate stap |
| Memory / Performance | KRITIEK | GEFIXT — 512M limiet, SyncAllCommand Process::fake |
| Subprocess Isolatie | KRITIEK | GEFIXT — SyncAllCommand spawnte echte subprocessen |
| PostgreSQL Compatibiliteit | HOOG | GEFIXT — boolean/HAVING syntax in raw SQL |
| Test Isolatie (HTTP) | GOED | GEFIXT — preventStrayRequests() globaal |
| Factory Kwaliteit | MATIG | Geleidelijk verbeteren |
| Coverage Gaps | MATIG | Prioriteren per risico |
| Test Kwaliteit | GOED | Kleine verbeteringen |
| Ontbrekende Test Types | LAAG | Overwegen |

### Eindresultaat na fixes
```
Tests:    348 passed (1074 assertions)
Duration: 93.80s
Failures: 0
```

---

## 1. CI Pipeline — KRITIEK

### Probleem
De GitHub Actions workflow (`tests.yml`) heeft **geen PostgreSQL service** geconfigureerd. Tests draaien via `vendor/bin/pest` zonder database, waardoor de hele suite faalt in CI.

### Bevindingen
- Geen `services:` sectie met PostgreSQL container
- Kopieert `.env.example` → `.env` (die `DB_CONNECTION=pgsql` bevat)
- Geen `createdb` of `migrate` stap
- Geen `timeout-minutes` op de job
- Draait `vendor/bin/pest` i.p.v. `php artisan test`
- PHP 8.5 in de matrix bestaat nog niet (zal falen)

### Fix nodig
- PostgreSQL service toevoegen aan CI workflow
- Database aanmaken + migraties draaien
- `timeout-minutes: 15` toevoegen
- PHP 8.5 verwijderen uit matrix (nog niet released)
- `php artisan test` gebruiken i.p.v. `vendor/bin/pest` direct

---

## 2. Memory / Performance — KRITIEK

### Probleem
De volledige test suite (348 tests) crasht op een **memory exhaustion** (128MB limiet) na ~175 tests. Daarnaast zijn er twee extreem trage tests.

### Bevindingen

**Memory crash:**
- Crash rond `LtvForecastValidationTest` (test ~175 in de volgorde)
- Niet veroorzaakt door 1 specifieke test — het is cumulatief geheugenverbruik
- `fortify/routes/routes.php:90` is waar PHP opgeeft (niet de oorzaak, wel het slachtoffer)

**Trage tests:**
| Test | Duur | Opmerking |
|------|------|-----------|
| KlaviyoCampaignSyncerTest | 62.4s (31s/test) | Slaagt in isolatie, faalt in suite |
| LtvForecastValidationTest | 28.6s (28s/test) | Slaagt in isolatie |

### Fix nodig
- `memory_limit` verhogen in `phpunit.xml`: `<ini name="memory_limit" value="512M"/>`
- Trage tests onderzoeken: waarom duurt KlaviyoCampaignSyncerTest 31s met `Http::fake()`?
- `KlaviyoCampaignSyncerTest` heeft een **state leak** — faalt in suite maar slaagt alleen
- LtvForecastValidationTest genereert waarschijnlijk enorm veel testdata

---

## 3. Test Isolatie (HTTP Mocking) — GOED

### Bevindingen
Alle 8 syncer/client tests zijn correct gemockt:

| Test | Methode | Status |
|------|---------|--------|
| KlaviyoClientTest | Http::fake() | OK |
| OdooClientTest | Http::fake() | OK |
| KlaviyoCampaignSyncerTest | Http::fake() | OK |
| KlaviyoEngagementSyncerTest | Http::fake() | OK |
| KlaviyoProfileSyncerTest | Http::fake() | OK |
| OdooBomSyncerTest | Http::fake() | OK |
| ShopifySegmentSyncerTest | Mockery | OK |
| KlaviyoSegmentSyncerTest | Mockery | OK |

**Ontbrekend:** `Http::preventStrayRequests()` is nergens globaal ingesteld. Dit betekent dat nieuwe tests die per ongeluk echte HTTP calls maken niet worden gevangen.

### Fix nodig
- `Http::preventStrayRequests()` globaal toevoegen in `tests/Pest.php`

---

## 4. Factory Kwaliteit — MATIG

### Bevindingen
- **28 factories** aanwezig
- **10 factories** (36%) hebben custom states
- **45 directe `Model::create()` aanroepen** in tests die factories hadden moeten gebruiken

**Meest voorkomende overtreders:**
| Model | ::create() calls | Bestanden |
|-------|-------------------|-----------|
| ScenarioProductMix | 16 | 8 testbestanden |
| SeasonalIndex | 16 | 8 testbestanden |
| DemandEvent | 5 | EventSkuTargetingTest |
| ForecastSnapshot | 2 | ForecastErrorDecompositionTest |
| PurchaseCalendarRun | 2 | PurchaseCalendarTrackingTest |

**ProductFactory** dekt slechts 7 van 23 fillable velden — mist o.a. `product_category`, `portfolio_role`, `journey_phase`.

### Fix nodig
- ProductFactory uitbreiden met states per producttype (waxKit, chain, heater, etc.)
- Geleidelijk `Model::create()` vervangen door factories in tests
- ScenarioProductMix en SeasonalIndex factories uitbreiden met relevante states

---

## 5. Coverage Gaps — MATIG

### Overzicht

| Categorie | Met test | Zonder test | Dekking |
|-----------|----------|-------------|---------|
| Services | 35 | 28 | 56% |
| Controllers | 1 | 12 | 8% |
| Commands | 7 | 36 | 16% |
| Models | 27 | 1 | 96% |

### Ongeteste services (hoogste prioriteit)
- **Analytics:** AcquisitionAnalyticsService, RevenueAnalyticsService, RetentionAnalyticsService, ProductAnalyticsService
- **Sync:** ShopifyOrderSyncer, OdooOpenPoSyncer, OdooProductSyncer, OdooShippingCostSyncer
- **Business logica:** RfmScoringService, OrderMarginCalculator, RepeatProbabilityService, ProductClassifier

### Ongeteste controllers (alle behalve DashboardController)
- AcquisitionAnalyticsController, CustomerController, DashboardApiController, OrderController, ProductAnalyticsController, ProductController, RetentionAnalyticsController, RevenueAnalyticsController, ScenarioController, SecurityController, SyncStatusController, ProfileController

### Ongerefereerd model
- ShopifyProduct — nergens gebruikt in tests

---

## 6. Test Kwaliteit — GOED

### Steekproef: 12 tests geanalyseerd

| Metric | Resultaat |
|--------|-----------|
| Gemiddelde assertions/test | 2.5 |
| Tests met edge cases | 92% |
| Tests met error scenarios | 42% |
| Tests die behavior testen (niet implementatie) | 100% |
| Tests met goede beschrijvingen | 92% |

### Goede patronen
- Helper functies (createComponent, createCohortCustomer, etc.)
- Behavior-gerichte tests
- Minimaal mocken — alleen externe dependencies
- Duidelijke test beschrijvingen

### Anti-patronen gevonden
- **18x `expect()->not->toBeNull()`** zonder vervolgassertie op de waarde
- **13x vage boolean assertions** (`->toBeTrue()`) zonder context
- **Geen error scenario tests** in de meeste service tests
- `SyncAllCommandTest` checkt `not->toBe('running')` i.p.v. verwachte status

---

## 7. Ontbrekende Test Types

| Type | Status | Waarde |
|------|--------|--------|
| Feature tests | 67 bestanden | Goed |
| Unit tests | 1 (placeholder) | Ontbreekt |
| Architecture tests | Niet aanwezig | Medium — enforcement van patronen |
| Smoke tests | Niet aanwezig | Hoog — alle pagina's op 200 response |
| Browser tests | Niet aanwezig | Laag — alleen voor kritieke flows |

---

## Prioriteiten

### Sprint 1 (nu)
1. CI workflow fixen (PostgreSQL service + timeout)
2. Memory limit in phpunit.xml
3. `Http::preventStrayRequests()` globaal
4. KlaviyoCampaignSyncerTest state leak fixen

### Sprint 2
5. Trage tests optimaliseren (KlaviyoCampaign, LtvForecast)
6. Smoke tests toevoegen voor alle pagina's
7. ProductFactory uitbreiden

### Sprint 3+
8. Controller tests toevoegen (per controller bij feature werk)
9. Factory ::create() calls migreren naar factories
10. Architecture tests overwegen
