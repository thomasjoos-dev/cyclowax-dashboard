# Template — Security Audit

> **Hoe gebruiken:** Draai deze audit per kwartaal en bij elke nieuwe externe integratie. Gebruik `docs/audits/prompts/security.md` om de audit te starten. Resultaat opslaan als `docs/audits/resultaten/security-audit-YYYY-MM-DD.md`.

---

## Doel

Evalueer de beveiliging van de applicatie tegen het security protocol (`docs/protocollen/security-protocol.md`) en algemene OWASP best practices. Focus op de specifieke risico's van dit project: externe API integraties, PII opslag, en interne authenticatie.

---

## Snapshot (vul in bij start)

```bash
# Routes zonder auth middleware
php artisan route:list --columns=method,uri,middleware | grep -v "auth"

# Modellen met $guarded
grep -rn "guarded" app/Models/ --include="*.php"

# env() buiten config/
grep -rn "env(" app/ --include="*.php" | grep -v "config/"

# Form Requests
ls app/Http/Requests/**/*.php 2>/dev/null | wc -l

# Hardcoded credentials scan
grep -rn "sk_live\|sk_test\|password\s*=\s*['\"]" app/ config/ --include="*.php" | grep -v "config('" | grep -v ".env" | grep -v "validation"
```

| Metric | Waarde |
|--------|--------|
| Totaal routes | |
| Routes zonder auth | |
| Models met $fillable | |
| Models met $guarded = [] | |
| Form Requests | |
| env() calls buiten config/ | |
| Hardcoded credential indicaties | |

---

## Dimensies

### S1. Authentication & Session Management

- [ ] **Sanctum configuratie** — token expiry ingesteld? Token prefix? Correcte guard?
- [ ] **Session driver** — database (niet file)?
- [ ] **Cookie settings** — secure, httpOnly, sameSite correct?
- [ ] **2FA (Fortify)** — correct geïmplementeerd? Recovery codes veilig opgeslagen?
- [ ] **Password hashing** — bcrypt met default cost factor?
- [ ] **Login rate limiting** — actief op login/register endpoints?
- [ ] **User enumeration** — zelfde response bij bestaand/niet-bestaand account?

### S2. Route Protection

- [ ] **Alle routes beveiligd** — draai `route:list` en verifieer middleware per route
- [ ] **API routes** — `auth:sanctum` + `throttle:api` op alle endpoints?
- [ ] **Web routes** — `auth` + `verified` op alle app routes?
- [ ] **Publieke routes** — alleen welcome + auth routes publiek?
- [ ] **Rate limiting** — configuratie kloppen? 60/min API, 6/min password?

### S3. Input Validatie

- [ ] **Form Requests** — worden alle endpoints die input accepteren beschermd door Form Requests?
- [ ] **$request->validated()** — wordt overal `validated()` gebruikt i.p.v. `all()` of `input()`?
- [ ] **File upload validatie** — XLSX import: bestandstype, grootte, content validatie?
- [ ] **Command arguments** — worden command parameters gevalideerd (dates, enum values)?
- [ ] **Query parameter injection** — worden API filter parameters gewhitelist?

### S4. Mass Assignment

- [ ] **Alle models checken** — elk model heeft `$fillable` (nooit `$guarded = []`)
- [ ] **$fillable minimaal** — alleen velden die via user input gezet worden
- [ ] **Sync-data velden** — staan NIET in $fillable (worden programmatisch gezet)

### S5. SQL Injection & Query Safety

- [ ] **Raw queries inventariseren** — alle `DB::raw()`, `DB::select()`, `selectRaw()`, `whereRaw()` calls
- [ ] **Parameter binding** — alle raw queries gebruiken parameter binding?
- [ ] **DbDialect** — platform-specifieke SQL gaat via DbDialect?
- [ ] **Geen string concatenatie** — geen user input in query strings?

### S6. Credential Management

- [ ] **`.env` in `.gitignore`** — bevestig
- [ ] **`.env.example` veilig** — geen echte waarden, alleen placeholders?
- [ ] **env() isolatie** — `env()` alleen in config files?
- [ ] **Credentials in logs** — grep door logging calls voor token/key/password patronen
- [ ] **Git history** — zijn er ooit credentials gecommit? (`git log --all -p | grep -i "api_key\|password\|secret"`)

### S7. Error Exposure

- [ ] **APP_DEBUG** — `false` op staging/productie?
- [ ] **API error format** — consistent envelope, geen stack traces?
- [ ] **Exception handler** — vangt alle exceptions op voor API routes?
- [ ] **Geen model IDs in user-facing errors** — generieke foutmeldingen?
- [ ] **Validation errors** — geen overmatige detail die interne structuur onthult?

### S8. PII & Data Protection

- [ ] **PII velden geïnventariseerd** — welke modellen bevatten PII?
- [ ] **PII niet gelogd** — geen email/naam/adres in log berichten?
- [ ] **PII niet in URLs** — geen query parameters met persoonsgegevens?
- [ ] **Exports/PDFs** — bevatten die PII? Wie heeft er toegang?
- [ ] **Data minimalisatie** — wordt alleen de noodzakelijke PII opgeslagen?

### S9. CORS & Cross-Origin

- [ ] **Allowed origins** — specifiek geconfigureerd (geen wildcard op productie)?
- [ ] **Credentials** — `supports_credentials` correct per use case?
- [ ] **Methods** — alleen benodigde methods toegestaan?
- [ ] **Headers** — exposed headers bewust gekozen?

### S10. Dependency Security

- [ ] **composer audit** — bekende kwetsbaarheden in PHP packages?
- [ ] **npm audit** — bekende kwetsbaarheden in JS packages?
- [ ] **Outdated packages** — kritieke packages (Laravel, Sanctum, Fortify) up-to-date?

---

## Output Formaat

```markdown
# Security Audit Rapport — YYYY-MM-DD

## Risico Samenvatting
| Risico Level | Aantal bevindingen |
|---|---|
| Kritiek | |
| Hoog | |
| Medium | |
| Laag | |

## Per Dimensie
### S1. Authentication — [Score]
[Bevindingen met bestand:regel]

...

## Directe Acties (fix nu)
[Kritieke en hoge risico's met concrete fix-instructies]

## Geplande Verbeteringen
[Medium en lage risico's voor de backlog]
```
