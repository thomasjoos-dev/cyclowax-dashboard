# API Design Protocol — Cyclowax Dashboard

Dit protocol definieert de conventies voor het ontwerpen en bouwen van REST API endpoints. Volg dit protocol bij elke nieuwe of gewijzigde endpoint. Het doel is consistentie — zodat de API voorspelbaar is voor elke consumer.

---

## 1. URL Structuur

### Regels

- **Prefix:** `/api/v1/` — alle endpoints onder versioned prefix
- **Resources in meervoud:** `/api/v1/orders`, niet `/api/v1/order`
- **Nesting maximaal 1 niveau diep:** `/api/v1/scenarios/{scenario}/forecast`, niet `/api/v1/scenarios/{scenario}/assumptions/{assumption}/values`
- **Acties als sub-resource:** `/api/v1/sync/status`, niet `/api/v1/getSyncStatus`
- **Kebab-case voor multi-word resources:** `/api/v1/purchase-calendar`, niet `/api/v1/purchaseCalendar`

### Route naming

Alle API routes krijgen een naam met `api.v1.` prefix:

```php
Route::get('/orders', OrderController::class)->name('api.v1.orders.index');
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('api.v1.orders.show');
Route::get('/analytics/revenue', RevenueAnalyticsController::class)->name('api.v1.analytics.revenue');
```

### Invokable controllers voor single-action endpoints

```php
// Analytics endpoints die één ding doen
class RevenueAnalyticsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // ...
    }
}
```

---

## 2. HTTP Methods

| Method | Gebruik | Voorbeeld |
|--------|---------|-----------|
| `GET` | Ophalen van data (idempotent, geen side effects) | `GET /api/v1/orders` |
| `POST` | Aanmaken van nieuwe resource | `POST /api/v1/scenarios` |
| `PUT` | Volledig vervangen van resource | `PUT /api/v1/scenarios/{id}` |
| `PATCH` | Gedeeltelijk updaten van resource | `PATCH /api/v1/scenarios/{id}` |
| `DELETE` | Verwijderen van resource | `DELETE /api/v1/scenarios/{id}` |

### Regels

- **GET requests wijzigen nooit data**
- **POST is niet idempotent** — dezelfde request kan meerdere resources aanmaken
- **PUT vs PATCH:** gebruik PUT als alle velden verplicht zijn, PATCH als je een subset kunt updaten

---

## 3. Response Format

### Succes response — enkele resource

```json
{
    "data": {
        "id": 1,
        "name": "Base scenario",
        "growth_rate": 0.15,
        "created_at": "2026-04-12T10:30:00Z"
    }
}
```

### Succes response — collectie

```json
{
    "data": [
        { "id": 1, "name": "Base scenario" },
        { "id": 2, "name": "Growth scenario" }
    ],
    "meta": {
        "total": 42,
        "per_page": 15,
        "current_page": 1,
        "last_page": 3
    }
}
```

### Succes response — analytics/aggregatie

Analytics endpoints die geen CRUD resource teruggeven gebruiken een plat object zonder `data` wrapper:

```json
{
    "period": { "from": "2026-01-01", "to": "2026-03-31" },
    "revenue": { "total": 125000, "trend": 0.12 },
    "orders": { "total": 1850, "average_value": 67.57 }
}
```

### Error response

Alle errors volgen het bestaande envelope format in `bootstrap/app.php`:

```json
{
    "error": {
        "message": "The given data was invalid.",
        "code": "validation_error",
        "status": 422,
        "errors": {
            "name": ["The name field is required."]
        }
    }
}
```

### Status codes

| Code | Wanneer |
|------|---------|
| `200` | Succesvolle GET, PUT, PATCH |
| `201` | Succesvolle POST (resource aangemaakt) |
| `204` | Succesvolle DELETE (geen content) |
| `400` | Ongeldige request (malformed JSON, ontbrekende parameters) |
| `401` | Niet geauthenticeerd |
| `403` | Niet geautoriseerd |
| `404` | Resource niet gevonden |
| `422` | Validatie error |
| `429` | Rate limit bereikt |
| `500` | Server error |

---

## 4. Filtering, Sorting & Paginatie

### Filtering via query parameters

```
GET /api/v1/orders?country=DE&channel=paid_google&since=2026-01-01
```

- Parameter namen matchen database kolom namen (of duidelijke aliassen)
- Date parameters: ISO 8601 format (`YYYY-MM-DD`)
- Enum parameters: exact match met enum values

### Sorting

```
GET /api/v1/orders?sort=-created_at,total_price
```

- `-` prefix voor descending
- Komma-gescheiden voor meerdere kolommen
- Default sort: meest recente eerst (`-created_at`)

### Paginatie

```
GET /api/v1/orders?page=2&per_page=25
```

- Default `per_page`: 15
- Maximum `per_page`: 100
- Response bevat `meta` object met paginatie-info (zie sectie 3)
- Gebruik Laravel's `->paginate()` — geen handmatige paginatie

---

## 5. API Resources

### Regels

- **Gebruik Eloquent API Resources** voor response formatting — geen raw arrays/models
- **Eén resource per model** — `OrderResource`, `CustomerResource`, etc.
- **Geen business logica in Resources** — alleen data transformatie en formatting
- **Consistent date format:** ISO 8601 (`toISOString()`)
- **Consistent money format:** cents als integer of decimaal met 2 plaatsen (kies één, documenteer)

### Patroon

```php
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shopify_order_id' => $this->shopify_order_id,
            'total_price' => $this->total_price,
            'net_revenue' => $this->net_revenue,
            'gross_margin' => $this->gross_margin,
            'country' => $this->country,
            'channel_type' => $this->channel_type,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
```

---

## 6. Controller Structuur

### Regels

- **Thin controllers** — alleen request validatie, service aanroep, response formatting
- **Geen Eloquent queries in controllers** — delegeer naar services
- **Form Requests voor alle input** — ook voor GET requests met filters
- **Consistent return types** — altijd `JsonResponse` of `AnonymousResourceCollection`

### Patroon

```php
class OrderController extends Controller
{
    public function __construct(
        private DtcSalesQueryService $salesQuery,
    ) {}

    public function index(OrderIndexRequest $request): AnonymousResourceCollection
    {
        $orders = $this->salesQuery->filteredOrders($request->validated());

        return OrderResource::collection($orders);
    }

    public function show(ShopifyOrder $order): OrderResource
    {
        return new OrderResource($order->load(['lineItems', 'customer']));
    }
}
```

---

## 7. Versioning

### Regels

- **Huidige versie:** v1
- **Versie in URL** — `/api/v1/`
- **Geen breaking changes in v1** — nieuwe velden toevoegen mag, velden verwijderen of hernoemen niet
- **Breaking change = nieuwe versie** — v2 prefix, oude versie blijft beschikbaar

### Wat is een breaking change?

- Veld verwijderd of hernoemd
- Veld type gewijzigd (string → integer)
- Response structuur gewijzigd (nesting, wrapper)
- Verplicht request parameter toegevoegd
- HTTP method gewijzigd
- URL pad gewijzigd

### Wat is GEEN breaking change?

- Nieuw optioneel veld in response
- Nieuw optioneel query parameter
- Nieuwe endpoint

---

## 8. Rate Limiting

### Huidige configuratie

- API routes: **60 requests/minuut** via `throttle:api` middleware
- Password endpoint: **6 requests/minuut**

### Regels

- Alle API endpoints hebben rate limiting
- Rate limit headers in response: `X-RateLimit-Limit`, `X-RateLimit-Remaining`
- Bij `429`: `Retry-After` header met seconden tot reset

---

## 9. Documentatie

### Regels

- Elke nieuwe endpoint wordt gedocumenteerd in `docs/architectuur/api.md`
- Documentatie in dezelfde commit als de code (zie CLAUDE.md)
- Format per endpoint:

```markdown
### GET /api/v1/resource

**Beschrijving:** Wat de endpoint doet.

**Parameters:**
| Parameter | Type | Verplicht | Beschrijving |
|-----------|------|-----------|--------------|
| since | date | nee | Filter op datum (ISO 8601) |

**Response:** `200 OK`
```json
{
    "data": [...]
}
```
```

---

## 10. Checklist nieuwe endpoint

- [ ] URL volgt RESTful conventies (meervoud, kebab-case, max 1 nesting)
- [ ] Route heeft naam met `api.v1.` prefix
- [ ] `auth:sanctum` + `throttle:api` middleware
- [ ] Form Request voor input validatie (ook GET filters)
- [ ] API Resource voor response formatting
- [ ] Controller is thin — logica in service
- [ ] Consistent error format (envelope pattern)
- [ ] Correct HTTP status code per scenario
- [ ] Test geschreven (auth, happy path, validation, 404)
- [ ] Gedocumenteerd in `docs/architectuur/api.md`
