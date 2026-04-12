# Security Protocol — Cyclowax Dashboard

Dit protocol definieert de security-regels die gelden bij elke code-wijziging. Claude toetst hier actief tegen tijdens het bouwen. Volg dit protocol bij elke wijziging die raakt aan auth, API's, env, user input of externe integraties.

---

## 1. Credentials & Environment

### Regels

- **Credentials alleen via `.env`** — API keys, tokens, wachtwoorden nooit hardcoden, ook niet in config files
- **`.env` staat in `.gitignore`** — verifieer dit bij elke nieuwe env variabele
- **`env()` alleen in config files** — applicatiecode gebruikt `config('shopify.api_key')`, nooit `env('SHOPIFY_API_KEY')`
- **Geen credentials in logs** — bij sync fouten: log de foutmelding, niet de token/key
- **Geen credentials in chat** — gevoelige waarden altijd via apart terminal venster invoeren

### Checks voor elke commit

```bash
# Scan op hardcoded credentials
grep -rn "sk_live\|sk_test\|pk_live\|pk_test\|Bearer \|password\s*=" app/ config/ --include="*.php" | grep -v "config('" | grep -v ".env"
```

### Externe API credentials in dit project

| Service | Config file | Env variabelen |
|---------|------------|----------------|
| Shopify | `config/shopify.php` | `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SHOPIFY_ACCESS_TOKEN`, `SHOPIFY_SHOP_DOMAIN` |
| Klaviyo | `config/klaviyo.php` | `KLAVIYO_API_KEY` |
| Odoo | `config/odoo.php` | `ODOO_URL`, `ODOO_DB`, `ODOO_USERNAME`, `ODOO_PASSWORD` |

### Key rotation

- Geen geautomatiseerde rotation op dit moment (intern dashboard, beperkt team)
- Bij vermoeden van compromise: direct roteren via het betreffende platform
- Bij teamwijziging: alle API keys roteren

---

## 2. Route Protection

### Regels

- **Alle routes beschermd** — elke route heeft `auth` middleware, behalve:
  - `GET /` (welcome page)
  - Auth-gerelateerde routes (login, register, password reset)
- **API routes:** `auth:sanctum` + `throttle:api` middleware
- **Web routes:** `auth` + `verified` middleware
- **Geen nieuwe publieke routes** zonder expliciete goedkeuring

### Sanctum configuratie

- Token expiry: **8 uur** (`config/sanctum.php`)
- Token prefix: `cwx_`
- Rate limiting: **60 requests/minuut** op API routes

### Verificatie bij nieuwe route

```php
// Elke nieuwe route MOET een van deze patronen volgen:

// Web route
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/new-page', NewPageController::class)->name('new-page');
});

// API route
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/new-resource', NewResourceController::class)->name('new-resource');
});
```

---

## 3. Input Validatie

### Regels

- **Alle user input via Form Requests** — nooit inline validatie in controllers
- **Geen `$request->all()`** — gebruik `$request->validated()` na Form Request validatie
- **Geen trust op client-side validatie** — server-side validatie is de waarheid
- **Sanitize output** — React doet dit standaard via JSX, maar let op `dangerouslySetInnerHTML` (nooit gebruiken)

### Form Request patroon

```php
class StoreScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth via middleware, niet per request
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'growth_rate' => ['required', 'numeric', 'between:0,5'],
            'region' => ['required', Rule::in(ForecastRegion::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'growth_rate.between' => 'Growth rate must be between 0% and 500%.',
        ];
    }
}
```

### Specifieke risico's in dit project

- **Forecast parameters** — growth rates, seasonal indices: valideer bereiken (geen negatieve waarden, geen extreme multipliers)
- **Sync commands met `--from`/`--to` parameters** — date parsing valideren
- **File uploads** (`ads:import`) — alleen XLSX, maximale bestandsgrootte, content validatie

---

## 4. Mass Assignment

### Regels

- **Alle models gebruiken `$fillable`** — nooit `$guarded = []`
- **`$fillable` is expliciet en minimaal** — alleen velden die daadwerkelijk mass-assigned worden
- **Nieuwe kolommen:** voeg ze pas toe aan `$fillable` als ze via user input gezet worden. Kolommen die alleen programmatisch gezet worden (sync, berekeningen) horen niet in `$fillable`

### Verschil sync vs user input

```php
// Sync data (NIET in $fillable — wordt programmatisch gezet)
$order->shopify_id = $data['id'];
$order->total_price = $data['total_price'];

// User input (WEL in $fillable — komt via Form Request)
Scenario::create($request->validated());
```

---

## 5. SQL Injection & Query Safety

### Regels

- **Eloquent of Query Builder voor queries** — geen raw SQL tenzij strikt noodzakelijk
- **Parameter binding bij raw queries** — nooit string concatenatie met user input
- **DbDialect helper voor platform-specifieke functies** — gebruik `DbDialect` voor `DATE_TRUNC`, `EXTRACT`, `YEAR()` en vergelijkbare dialect-afhankelijke functies. Standaard SQL in `DB::raw` (bijv. `SUM`, `COUNT`, `CASE`) is acceptabel

### Patroon voor veilige raw queries

```php
// Goed — parameter binding
$results = DB::select('SELECT * FROM orders WHERE country = ?', [$country]);

// Goed — DbDialect voor platform-specifieke functies
$yearMonth = DbDialect::yearMonth('created_at');
Product::query()->selectRaw("{$yearMonth} as period")->groupByRaw($yearMonth);

// FOUT — string concatenatie
$results = DB::select("SELECT * FROM orders WHERE country = '{$country}'");
```

---

## 6. CORS

### Regels

- CORS configuratie via `config/cors.php`
- Allowed origins: configureerbaar via `CORS_ALLOWED_ORIGINS` env variabele
- **Geen wildcard (`*`) op productie** — alleen specifieke origins
- Credentials (cookies) worden meegestuurd — `supports_credentials: true`

---

## 7. Error Exposure

### Regels

- **`APP_DEBUG=false` op staging en productie** — geen stack traces in responses
- **API error envelope** — gestandaardiseerd JSON format, geen interne details
- **Geen model IDs in foutmeldingen naar de gebruiker** — gebruik generieke berichten
- **Log volledige errors server-side** — de gebruiker krijgt een referentie, niet de stack trace

### API error format (bestaand patroon)

```json
{
    "error": {
        "message": "The given data was invalid.",
        "code": "validation_error",
        "status": 422
    }
}
```

---

## 8. Session & Authentication

### Regels

- Session driver: `database` (niet `file` op multi-server)
- Cookie settings: `secure: true`, `same_site: lax`, `http_only: true`
- **2FA via Fortify** — beschikbaar voor alle users, niet verplicht (intern team)
- Password hashing: bcrypt (Laravel default)

### Autorisatie

Huidige autorisatie werkt via middleware (`auth`, `auth:sanctum`). Er zijn geen Laravel Policies — bij een intern team met gelijke rechten volstaat middleware-based auth. Policies toevoegen zodra role-based access nodig wordt (bijv. bij externe API consumers of meer gebruikersrollen).

### Bij nieuwe auth-gerelateerde code

- [ ] Gebruikt bestaande Fortify/Sanctum functionaliteit (geen custom auth)
- [ ] Rate limiting op login/register endpoints
- [ ] Geen user enumeration (zelfde response bij bestaand/niet-bestaand account)
- [ ] Recovery codes voor 2FA veilig opgeslagen

---

## 9. PII & Data Protection

### Regels

- **PII in database:** email, naam, adres, telefoonnummer — opgeslagen in plain text (acceptabel voor intern dashboard met beperkte toegang)
- **PII niet loggen** — geen email/naam in log berichten
- **PII niet in URL parameters** — gebruik POST of request body
- **Exports/PDF's met PII** — bewust van wie er toegang heeft

### PII velden in dit project

| Model | PII velden |
|-------|-----------|
| ShopifyCustomer | email, first_name, last_name, phone, default_address |
| RiderProfile | email, first_name, last_name |
| KlaviyoProfile | email, first_name, last_name, phone_number |
| User | name, email |

---

## 10. Checklist bij security-relevante wijzigingen

Bij elke wijziging die raakt aan auth, API's, routes, user input of externe integraties:

- [ ] Geen credentials hardcoded of gelogd
- [ ] Route heeft correcte middleware (auth + throttle)
- [ ] User input via Form Request met expliciete regels
- [ ] `$request->validated()` gebruikt, niet `$request->all()`
- [ ] Raw queries gebruiken parameter binding
- [ ] Model gebruikt `$fillable`, niet `$guarded = []`
- [ ] Error responses lekken geen interne details
- [ ] `.env` variabelen alleen via config files benaderd
- [ ] Geen PII in logs of URL parameters
- [ ] Test geschreven voor auth enforcement op nieuwe routes
