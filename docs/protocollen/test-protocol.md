# Test Protocol — Cyclowax Dashboard

Dit protocol definieert de principes, patronen en regels voor het schrijven en onderhouden van tests in dit project. Volg dit protocol bij elke test die wordt geschreven, aangepast of gereviewd.

---

## 1. Fundamenten

### Stack
- **Framework:** Pest PHP 4.x met Laravel Plugin
- **Database:** PostgreSQL (zowel lokaal als CI)
- **Isolatie:** `RefreshDatabase` trait (globaal voor alle Feature tests via `Pest.php`)
- **Runner:** `php artisan test` (nooit `vendor/bin/pest` direct)

### Teststructuur
```
tests/
  Feature/           # Integratie- en feature tests (database, HTTP, services)
  Unit/              # Pure logica zonder framework dependencies
  Pest.php           # Globale configuratie, traits, helpers
  TestCase.php       # Base test case met custom helpers
```

### Naamgeving
- Bestandsnaam: `{Onderwerp}Test.php` — bijv. `BomExplosionServiceTest.php`
- Test beschrijving: actieve zin die het gedrag beschrijft
  - Goed: `it('calculates predicted LTV from retention curve')`
  - Fout: `it('test LTV')`, `it('works')`

---

## 2. Test Types & Wanneer Te Gebruiken

### Feature Tests (standaard)
Gebruik voor: services, commands, controllers, API endpoints, integraties.
```php
it('creates a scenario with assumptions', function () {
    $scenario = Scenario::factory()->create();
    $service = app(ScenarioService::class);
    $result = $service->createWithAssumptions($scenario->id, [...]);
    expect($result)->toHaveKey('assumptions');
});
```

### Unit Tests
Gebruik voor: pure functies, value objects, berekeningen zonder database/framework.
```php
it('rounds margin to two decimals', function () {
    expect(MarginCalculator::round(0.33456))->toBe(0.33);
});
```

### Smoke Tests
Gebruik voor: alle pagina's retourneren 200 en geen JS errors.
```php
it('loads the dashboard without errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();
});
```

### Architecture Tests (Pest Arch)
Gebruik voor: enforcement van structurele regels.
```php
arch('controllers should not use DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');
```

---

## 3. Principes

### 3.1 Test gedrag, niet implementatie
Test WAT code doet, niet HOE het werkt. Assertions moeten gaan over outputs, side effects en state changes — niet over interne method calls.

```php
// Goed — test het resultaat
expect($forecast->totalUnits())->toBe(1200);

// Fout — test implementatiedetails
expect($service)->toReceive('calculateBaseline')->once();
```

### 3.2 Factories boven Model::create()
Gebruik ALTIJD factories voor test data. Directe `Model::create()` is niet toegestaan in tests.

```php
// Goed
$product = Product::factory()->waxKit()->create();

// Fout
$product = Product::create(['product_category' => 'wax_kit', ...]);
```

**Waarom:** Factories centraliseren testdata, maken tests korter en leesbaarder, en voorkomen dat tests breken als vereiste velden veranderen.

### 3.3 Specifieke assertions
Vermijd vage assertions. Elke assertion moet een concreet verwacht resultaat checken.

```php
// Goed
expect($transition->type)->toBe('upgrade');
expect($transition->from_segment)->toBe('bronze');

// Fout
expect($transition)->not->toBeNull();
expect($result)->toBeTrue();
```

### 3.4 Edge cases en error scenarios
Elke test file moet minimaal bevatten:
- **Happy path** — het standaard succesvol pad
- **Edge case** — lege data, grenzen, null waarden
- **Error scenario** — ongeldige input, ontbrekende data, exceptions

```php
it('returns zero LTV when no orders exist', function () { ... });
it('throws when product category is invalid', function () { ... });
```

### 3.5 Geen externe calls in tests
Alle HTTP, queue, mail en notification calls moeten gemockt zijn. Gebruik `Http::preventStrayRequests()` globaal als vangnet.

```php
// In tests/Pest.php — al geconfigureerd
beforeEach(function () {
    Http::preventStrayRequests();
})->in('Feature');
```

### 3.6 Tests moeten snel zijn
- Individuele tests: **< 2 seconden**
- Volledige suite: **< 60 seconden**
- Als een test > 5 seconden duurt: onderzoek waarom (te veel data? echte HTTP calls? onnodige berekeningen?)

### 3.7 Tests moeten onafhankelijk zijn
- Geen afhankelijkheid van testvolgorde
- Geen gedeelde state tussen tests
- Geen afhankelijkheid van seeders (gebruik factories)
- Elke test draait zelfstandig met `php artisan test --filter="testname"`

---

## 4. Patronen

### 4.1 Helper functies voor complexe setup
Extraheer herhaalde setup naar helper functies bovenaan het testbestand.

```php
function createCostOrder(string $country, float $revenue, int $quantity = 1): ShopifyOrder
{
    $customer = ShopifyCustomer::factory()->create(['country' => $country]);
    return ShopifyOrder::factory()->create([
        'shopify_customer_id' => $customer->shopify_customer_id,
        'total_price' => $revenue,
        'line_items_count' => $quantity,
    ]);
}
```

### 4.2 Chained assertions met Pest
Gebruik Pest's chaining voor gerelateerde assertions.

```php
expect($result)
    ->toHaveKey('monthly')
    ->monthly->toHaveCount(12)
    ->and($result['total'])->toBeGreaterThan(0);
```

### 4.3 HTTP mocking — API client strategie

Dit project heeft drie externe API clients (Shopify, Klaviyo, Odoo). Alle tests die syncers of API clients raken moeten HTTP calls mocken.

**Vangnet:** `Http::preventStrayRequests()` is globaal actief in `tests/Pest.php` — elke ongemockte HTTP call faalt direct. Dit voorkomt dat tests per ongeluk echte API's aanroepen.

**Patroon per API:**

```php
// Shopify — paginated responses met Link header
Http::fake([
    'your-shop.myshopify.com/admin/api/*' => Http::sequence()
        ->push(['orders' => [...]], 200, ['Link' => '<...>; rel="next"'])
        ->push(['orders' => []], 200),
]);

// Klaviyo — cursor-based pagination
Http::fake([
    'a.]klaviyo.com/api/*' => Http::response([
        'data' => [...],
        'links' => ['next' => null],
    ]),
]);

// Odoo — JSON-RPC
Http::fake([
    'odoo.example.com/jsonrpc' => Http::response([
        'result' => [...],
    ]),
]);
```

**Assertions:**

```php
it('syncs profiles from API', function () {
    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::response([
            'data' => [['id' => '1', 'attributes' => ['email' => 'test@example.com']]],
            'links' => ['next' => null],
        ]),
    ]);

    $syncer = app(KlaviyoProfileSyncer::class);
    $syncer->sync();

    expect(KlaviyoProfile::count())->toBe(1);
    Http::assertSentCount(1);
});
```

### 4.4 Command testing patroon
```php
it('syncs all data sources', function () {
    Http::fake(['*' => Http::response([])]);

    $this->artisan('sync:all')
        ->assertExitCode(0);

    expect(SyncState::where('status', 'completed')->count())->toBe(3);
});
```

### 4.5 Controller/pagina testing patroon
```php
it('shows the product detail page', function () {
    $user = User::factory()->create();
    $product = Product::factory()->waxKit()->create();

    $this->actingAs($user)
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Show')
            ->has('product')
        );
});
```

---

## 5. Factories

### Regels
- Elke factory MOET alle vereiste (non-nullable) velden van het model dekken
- Factories MOETEN custom states hebben voor veelgebruikte varianten
- Gebruik `for()` / `has()` voor relaties, niet handmatige ID toewijzing

### Vereiste states per factory (minimum)

| Factory | Vereiste States |
|---------|----------------|
| ProductFactory | waxKit(), chain(), heater(), starterKit() |
| UserFactory | unverified(), withTwoFactor() (bestaan al) |
| ScenarioFactory | active(), inactive() |
| DemandEventFactory | historical(), planned() |
| ShopifyOrderFactory | refunded(), firstOrder() |
| RiderProfileFactory | follower(), customer() (bestaan al) |

### Voorbeeld factory met states
```php
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'sku' => fake()->unique()->bothify('CW-###-??'),
            'product_category' => ProductCategory::WaxKit->value,
            'price' => fake()->randomFloat(2, 10, 100),
        ];
    }

    public function waxKit(): static
    {
        return $this->state(['product_category' => ProductCategory::WaxKit->value]);
    }

    public function chain(): static
    {
        return $this->state(['product_category' => ProductCategory::Chain->value]);
    }
}
```

---

## 6. CI Pipeline

### Vereisten
- PostgreSQL service container in GitHub Actions
- `createdb` + `migrate` stappen voor tests
- `timeout-minutes: 15` op de job
- `php artisan test` als runner (respecteert phpunit.xml)
- Memory limit in phpunit.xml: `512M`

### phpunit.xml checklist
- [x] `timeoutForSmallTests="10"`
- [x] `timeoutForMediumTests="30"`
- [x] `timeoutForLargeTests="60"`
- [x] `<ini name="memory_limit" value="512M"/>`
- [x] `DB_CONNECTION=pgsql`
- [x] `DB_DATABASE=cyclowax_dashboard_testing`

---

## 7. Wanneer tests schrijven

### Altijd testen
- Nieuwe services, commands, controllers
- Bugfixes (schrijf eerst de falende test, dan de fix)
- Business logica (berekeningen, transformaties, validaties)
- API endpoints
- Externe integraties (met mocking)

### Niet apart testen
- Eloquent scopes (getest via de service die ze gebruikt)
- Simple getters/setters
- Framework functionaliteit (Laravel test zichzelf)
- Migraties (getest via de factories die de tabellen gebruiken)

### Bij elke PR
- Alle bestaande tests moeten slagen
- Nieuwe/gewijzigde code moet getest zijn
- `php artisan test --compact` moet groen zijn
- `vendor/bin/pint --dirty --format agent` moet clean zijn

---

## 8. Checklist nieuwe test

Bij het schrijven van een nieuwe test, loop deze checklist af:

- [ ] Factory gebruikt (geen `Model::create()`)
- [ ] Happy path getest
- [ ] Minimaal 1 edge case
- [ ] Error scenario waar relevant
- [ ] Assertions zijn specifiek (geen `not->toBeNull()` alleen)
- [ ] Test draait < 2 seconden in isolatie
- [ ] Test draait onafhankelijk (geen volgorde-afhankelijkheid)
- [ ] Externe calls gemockt (HTTP, queue, mail)
- [ ] Test beschrijving is duidelijk en actief geformuleerd
- [ ] Draait succesvol met `php artisan test --filter="testnaam"`
