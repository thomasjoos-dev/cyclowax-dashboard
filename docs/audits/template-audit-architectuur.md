# Template — Audit Codebase Architectuur & Consistentie

> **Hoe deze template gebruiken**
> Kopieer dit bestand bij elke nieuwe audit naar een nieuw rapport: `docs/audits/audit-rapport-YYYY-MM.md`. Vul eerst de **Snapshot** sectie in met actuele cijfers (zie instructies daar). Werk daarna de dimensies één voor één af. De template is bewust zonder hardgecodeerde aantallen of specifieke servicenamen — die veranderen tussen audits door.

---

## Doel

Voer een grondige architectuur-audit uit van de Cyclowax Dashboard codebase. Evalueer de code als een **senior full-stack web application architect** met 15+ jaar Laravel ervaring. Het doel is een gedetailleerd rapport dat als basis dient voor refactoring sprints.

**Belangrijk:** Dit is een evaluatie — geen refactoring. Constateer en onderbouw, maar schrijf geen code en maak geen wijzigingen.

---

## Context

### Wat is dit project?

Een intern data analytics & forecasting dashboard voor het fietsverzorgingsmerk Cyclowax. Het systeem:

- **Synct data** uit externe bronnen (Shopify, Klaviyo, Odoo ERP — en in de toekomst de Cyclowax App) naar een lokale database
- **Berekent scores en segmentaties** (RFM, lifecycle stages, follower engagement, product classificatie)
- **Genereert demand forecasts** en een purchase calendar (S&OP pipeline)
- **Biedt een REST API** voor interne apps
- **Toont een dashboard** met KPI's en analytics voor het Cyclowax team

### Stack (verifieer voor je begint)

Lees `composer.json` en `package.json` om de actuele versies vast te leggen. Te documenteren:

- **Backend:** Laravel versie, PHP versie, Sanctum, Fortify
- **Frontend:** React versie, Inertia.js versie, TypeScript, Tailwind versie, Wayfinder
- **Database:** Lokaal én staging (vermeld beide platformen)
- **Testing:** Pest versie, totaal aantal tests (Feature/Unit/Browser uitsplitsen)

---

## Snapshot (vul in bij start van de audit)

Gebruik onderstaande commands om de actuele cijfers op te halen voor je begint. Zonder deze snapshot weet je niet wat er sinds de vorige audit veranderd is.

```bash
# Models
ls app/Models/*.php | wc -l

# Services per domein
find app/Services -name "*.php" -type f | wc -l
ls app/Services/

# Artisan commands
php artisan list --raw | wc -l
ls app/Console/Commands/*.php | wc -l

# Enums
ls app/Enums/*.php | wc -l

# Migraties
ls database/migrations/*.php | wc -l

# API
ls app/Http/Controllers/Api/**/*.php
ls app/Http/Requests/**/*.php | wc -l
ls app/Http/Resources/**/*.php | wc -l

# Frontend
find resources/js/pages -name "*.tsx" | wc -l
find resources/js/components -name "*.tsx" | wc -l
find resources/js/hooks -name "*.ts" -o -name "*.tsx" | wc -l

# Tests
php artisan test --list-tests 2>/dev/null | tail -5
```

Vul daarna deze tabel in:

| Onderdeel | Aantal | Δ vs vorige audit |
|-----------|--------|-------------------|
| Models | | |
| Services (totaal) | | |
| Service-domeinen | | |
| Artisan Commands | | |
| Enums | | |
| Migrations | | |
| API Controllers | | |
| Form Requests | | |
| API Resources | | |
| React Pages | | |
| React Components | | |
| Custom Hooks | | |
| Tests (Feature) | | |
| Tests (Unit) | | |
| Tests (Browser) | | |

### Service-domeinen snapshot

```
app/Services/
├── [vul in]/   ([X] services — [korte beschrijving])
├── ...
```

### Huidige API endpoints

Run `php artisan route:list --path=api` en plak een samenvatting:

```
[methods]  /api/v1/[resource]   — [beschrijving]
...
```

### Scheduler

Documenteer hoe de scheduler werkt (welk bestand, welke commands, welke flags). Verifieer of `env()` direct gebruikt wordt of via `config()`.

### Vorige audit

Verwijs naar het vorige rapport (`docs/audits/audit-rapport-YYYY-MM.md`) en somm de **top 5 issues** op die toen openstonden. Markeer per issue: **opgelost / nog open / nieuw probleem ontstaan**.

---

## Audit Dimensies

Evalueer elke dimensie hieronder. Geef per dimensie:

1. **Score:** Groen (goed) / Oranje (concern) / Rood (probleem)
2. **Bevindingen:** Concrete voorbeelden met bestandsnaam en regelnummer
3. **Impact:** Wat is het gevolg als dit niet opgelost wordt?
4. **Prioriteit:** Hoog / Medium / Laag (voor de refactoring backlog)

**Wees specifiek.** "Sommige services missen return types" is nutteloos. "`OrderMarginCalculator:45` mist return type op `calculate()`" is bruikbaar.

---

### D1. Lagenstructuur & Verantwoordelijkheden

Evalueer of elke architectuurlaag alleen doet wat het hoort te doen.

**Check specifiek:**

- [ ] **Controllers** — Zijn ze thin? Alleen request validation dispatchen, service aanroepen, response returnen? Geen business logica?
- [ ] **Services** — Bevatten ze de business logica? Zijn ze focused (single responsibility)? Geen god-services?
- [ ] **Models** — Alleen data-gerelateerde logica (relaties, scopes, casts, accessors)? Geen business logica in models?
- [ ] **Commands** — Thin orchestrators die services aanroepen? Of bevatten ze business logica die in een service hoort?
- [ ] **Form Requests** — Alle validatie in Form Requests, niet inline in controllers?
- [ ] **API Resources** — Consistente response formatting? Geen business logica in transformers?
- [ ] **Config files** — Business rules en magic numbers in config, niet hardcoded in services?

**Kijk extra naar:**

- Commands die rechtstreeks Eloquent queries draaien i.p.v. een service aanroepen
- Services die andere services aanroepen in lange ketens (overmatige coupling)
- Business logica in models (bijv. berekeningen, externe API calls)
- Dubbele logica: dezelfde berekening op meerdere plekken

---

### D2. Service-consistentie

Alle services moeten dezelfde patronen volgen.

**Check specifiek:**

- [ ] **Constructor injection** — Worden dependencies via de constructor geïnjecteerd? Geen `app()` of `resolve()` calls?
- [ ] **Return types** — Hebben alle publieke methoden expliciete return types?
- [ ] **Method signatures** — Consistent gebruik van type hints op parameters?
- [ ] **Naamgeving** — Volgen services een consistent naamgevingspatroon? (bijv. `XxxService`, `XxxSyncer`, `XxxCalculator`)
- [ ] **Lengte** — Zijn er services die te groot zijn geworden (>300 regels)? Lijst ze op met regelaantal en suggestie voor splitsing.
- [ ] **Static methods/properties** — Wordt er onnodig static state gebruikt?
- [ ] **PHPDoc** — Zijn complexe methoden gedocumenteerd met array shapes en type info?

**Pak minimaal 5 services uit verschillende domeinen en vergelijk ze op deze punten.**

---

### D3. Command-patronen

Evalueer de consistentie van alle Artisan commands.

**Check specifiek:**

- [ ] **Thin orchestration** — Roepen commands services aan, of zitten er fat commands tussen met inline business logica?
- [ ] **Output & logging** — Consistent gebruik van `$this->info()`, `$this->error()`, progress bars?
- [ ] **Error handling** — Wat gebeurt er als een command faalt? Graceful degradation of crash?
- [ ] **Exit codes** — Returnen commands correcte exit codes (0 = succes, 1 = fout)?
- [ ] **Arguments & options** — Consistent patroon voor command signatures?
- [ ] **Testbaarheid** — Zijn commands testbaar? Geen hardcoded dependencies?

**Let op:**

- De `sync:all` command orkestreert meerdere sync stappen — evalueer of die orchestratie goed is opgezet
- Commands die rapporten genereren (PDF, forecast, exports) — zit de logica in de service of het command?

---

### D4. Model-hygiëne

Evalueer de consistentie van alle models (interne én externe data sources).

**Check specifiek:**

- [ ] **Mass assignment** — `$fillable` of `$guarded` consistent toegepast?
- [ ] **Casts** — Dates, booleans, decimals correct gecast? Geen handmatige conversies in code?
- [ ] **Relaties** — Return type hints op alle relaties? Correcte relatie-types?
- [ ] **Scopes** — Herbruikbare query-patronen als scopes gedefinieerd?
- [ ] **Accessors/Mutators** — Gebruikt waar het de code vereenvoudigt?
- [ ] **Factories** — Hebben alle models factories? Zijn ze up-to-date met de migraties? Lijst missende factories op.
- [ ] **Soft deletes** — Waar relevant toegepast?

---

### D5. Database & Multi-Platform Compatibiliteit

De app draait op verschillende database engines lokaal en op staging. Evalueer of de codebase correct omgaat met de verschillen.

**Check specifiek:**

- [ ] **DbDialect helper (of equivalent)** — Wordt deze consistent gebruikt voor database-specifieke SQL? Zijn er plekken waar nog raw platform-specifieke syntax staat?
- [ ] **Raw queries** — Waar worden `DB::raw()`, `DB::select()` of raw expressions gebruikt? Zijn deze platform-safe?
- [ ] **Migrations** — Gebruiken alle migraties platform-agnostische column types?
- [ ] **JSON columns** — Correct gehandeld voor beide platformen?
- [ ] **Date/time functies** — Consistent via DbDialect of Eloquent, niet via raw SQL?
- [ ] **Index strategieën** — Werken alle indexen op beide platformen?
- [ ] **Transacties** — Correct gebruik van database transacties waar nodig?
- [ ] **Week/date berekeningen** — Verschillende DB engines hanteren andere week-conventies (bv. ISO vs 0-based). Waar wordt dit gebruikt en is het veilig?

**Geef een advies over de database-strategie:**

Weeg af:
- **Pariteit:** Hoeveel risico levert het verschil tussen lokaal en staging op?
- **DbDialect overhead:** Lost de helper het probleem op of voegt het complexiteit toe die niet nodig is?
- **Ontwikkelgemak:** Zero-config vs draaiende server
- **Features:** Worden DB-specifieke features gebruikt die op het andere platform niet werken?
- **Analyse workflow:** Voor ad-hoc data analyse via chat sessies — welk platform heeft voordelen?

---

### D6. REST API Volledigheid & Kwaliteit

Evalueer wat er is en wat er mist in de huidige API.

**Check de bestaande API op:**

- [ ] **RESTful conventie** — Correcte HTTP methods, URL structuur, status codes?
- [ ] **Consistentie** — Volgen alle endpoints dezelfde patronen voor filtering, paginatie, sorting?
- [ ] **Named routes** — Hebben alle API routes namen?
- [ ] **Versioning** — Is de v1 prefix correct opgezet voor toekomstige versies?
- [ ] **Rate limiting** — Is er rate limiting op de API? Per route of globaal?
- [ ] **Response format** — Consistent gebruik van API Resources? Envelope pattern?
- [ ] **Error responses** — Consistent JSON error format bij validation errors, 404s, 500s?

**Evalueer wat er ONTBREEKT voor een volledige interne API:**

Welke data/functionaliteit is beschikbaar via services maar niet via de API? Denk aan:
- Forecast data (demand forecasts, purchase calendar)
- Sync status en monitoring
- Scoring data (RFM scores, segments, lifecycle stages)
- Analytics data (cohort analyse, retention, revenue trends)
- Product portfolio en classificatie
- Engagement data (Klaviyo, follower scores)
- ERP data (BOMs, open POs, stock)
- Demand events en scenario's

Geef een concrete lijst van endpoints die nodig zijn voor een volledige interne API.

---

### D7. Security & Data Protection

**Authenticatie & Autorisatie:**

- [ ] **Route protection** — Zijn ALLE routes beveiligd? Geen onbeschermde endpoints?
- [ ] **Sanctum configuratie** — Token abilities, expiration, correct opgezet?
- [ ] **2FA (Fortify)** — Correct geïmplementeerd? Recovery codes veilig?
- [ ] **CORS** — Correct geconfigureerd voor API access vanuit andere apps?
- [ ] **Middleware** — Auth middleware consistent toegepast? Geen gaten?

**Data protection:**

- [ ] **Gevoelige data** — Worden API keys, tokens, credentials alleen via `.env` geladen? Nergens hardcoded?
- [ ] **Encryptie** — Worden gevoelige velden (email, PII) waar nodig encrypted opgeslagen?
- [ ] **Mass assignment** — Geen overmatig gebruik van `$guarded = []` waardoor alle velden writable zijn?
- [ ] **SQL injection** — Alle user input via parameter binding? Geen onveilige raw queries?
- [ ] **Logging** — Wordt er geen gevoelige data (tokens, wachtwoorden, PII) gelogd?
- [ ] **Error exposure** — Worden er geen stack traces of interne details in API responses gelekt?

**Environment & configuratie:**

- [ ] **`.env` in `.gitignore`** — Bevestig
- [ ] **`env()` gebruik** — Alleen in config files, niet rechtstreeks in applicatiecode?
- [ ] **Debug mode** — `APP_DEBUG` correct per omgeving?
- [ ] **Session configuratie** — Secure cookies, correct domein, SameSite?

**Externe API credentials:**

- [ ] Alle externe API tokens (Shopify, Klaviyo, Odoo, etc.) — veilig opgeslagen?
- [ ] Worden credentials gelogd bij sync fouten?
- [ ] Is er een rotatie-strategie?

---

### D8. Frontend-Readiness & Design System

Evalueer of de frontend-basis solide is om op door te bouwen. De styling is gebaseerd op **shadcn/ui (New York style)** met **Tailwind CSS** en **OKLCH design tokens**.

**Check Inertia & TypeScript basis:**

- [ ] **Inertia setup** — Shared props correct geconfigureerd? Type-safe?
- [ ] **Wayfinder** — Route generation werkend? TypeScript types up-to-date?
- [ ] **Type definitions** — Zijn de TypeScript types voor backend data (orders, customers, products, forecasts) aanwezig en correct?
- [ ] **State management** — Is er een patroon voor client-side state? Of is Inertia props-only voldoende?
- [ ] **Error handling** — Is er een globale error boundary? Hoe worden API fouten in de UI getoond?
- [ ] **Loading states** — Is er een patroon voor loading/skeleton states (deferred props)?

**Check design system & styling architectuur:**

- [ ] **shadcn/ui component set** — Welke componenten zijn geïnstalleerd? Welke ontbreken voor een volledig dashboard? (data table, tabs, popover, command palette, toast/sonner, calendar, date picker, charts?)
- [ ] **CVA (Class Variance Authority) patronen** — Worden alle componenten consistent via CVA gebouwd? Zijn varianten voorspelbaar?
- [ ] **Design tokens in CSS** — Is het OKLCH kleurensysteem compleet? Zijn er semantische tokens voor alle UI states (success, warning, info naast destructive)?
- [ ] **Chart kleuren** — Zijn de `--chart-*` tokens voldoende voor data-rijke dashboards? Zijn ze onderscheidbaar in light én dark mode?
- [ ] **Spacing & typography schaal** — Is er een consistent systeem voor spacing en font sizes, of wordt het ad-hoc toegepast?
- [ ] **Dark mode** — Is het appearance systeem correct opgezet? Zijn alle custom tokens (niet-shadcn) ook dark mode-aware?
- [ ] **Mobile-first** — Is de bestaande CSS/layout mobile-responsive? Kloppen de breakpoint keuzes?

**Check layout & compositie:**

- [ ] **Layout systeem** — Zijn de layouts (sidebar, header, auth) solide genoeg om op voort te bouwen?
- [ ] **Sidebar** — Collapsible, responsive, icon-mode? Klaar voor navigatie uitbreiding?
- [ ] **Content area** — Is er een standaard content wrapper met consistente padding, max-width, scroll behavior?
- [ ] **Page template patroon** — Is er een herbruikbaar patroon voor dashboard pages (header + filters + content grid)?

**Check `docs/architectuur/styleguide.md`:**

- [ ] **Volledigheid** — Documenteert de styleguide alle patronen die nodig zijn om consistent te bouwen?
- [ ] **Formattering conventies** — Currency (EUR), getallen (NL locale), datums — consistent gedocumenteerd en toegepast?
- [ ] **Up-to-date** — Komt de styleguide overeen met wat er daadwerkelijk in de code staat?

**Evalueer wat er ONTBREEKT:**

- Welke TypeScript types/interfaces moeten aangemaakt worden?
- Welke shared props moeten via Inertia meegegeven worden?
- Is er een patroon voor data fetching vanuit React (polling, deferred props)?
- Welke shadcn/ui componenten moeten nog toegevoegd worden?
- Zijn er UI patterns die gestandaardiseerd moeten worden voor je begint? (data tables, filter bars, detail panels, empty states, error states)

---

### D9. Naamgeving & Navigeerbaarheid

Met veel services en commands moet de codebase zelfverklarend zijn.

**Check specifiek:**

- [ ] **Service naamgeving** — Kun je aan de naam aflezen wat een service doet? Zijn er misleidende namen?
- [ ] **Command naamgeving** — Volgen commands een consistent `domain:action` patroon?
- [ ] **Method naamgeving** — Beschrijvende method names? Geen generieke namen als `process()`, `handle()`, `run()`?
- [ ] **Folder structuur** — Is de mappenstructuur logisch en voorspelbaar?
- [ ] **Enum organisatie** — Logisch benoemd en georganiseerd?
- [ ] **Config keys** — Herkenbare naamgeving in config files?

**Verifieer eerdere bevindingen:**

Loop de "misleidende namen"-issues van het vorige audit rapport door en check of ze nog steeds bestaan, opgelost zijn, of nieuwe varianten ontstaan zijn.

---

### D10. Test Coverage & Kwaliteit

Evalueer dekking en kwaliteit.

**Check specifiek:**

- [ ] **Kritieke paden gedekt?** — Sync pipeline, forecast berekeningen, netting, BOM explosion
- [ ] **Test isolatie** — Zijn tests onafhankelijk? Geen volgorde-afhankelijkheid?
- [ ] **Factory gebruik** — Worden factories correct gebruikt? Up-to-date met migraties?
- [ ] **Edge cases** — Worden edge cases getest (lege data, null waarden, grensgevallen)?
- [ ] **API tests** — Zijn alle API endpoints getest? Response format, auth, validation?
- [ ] **Database tests** — Werken alle tests op zowel lokaal als staging platform?
- [ ] **Geen stubs voor kritieke logica** — Worden externe APIs correct gemocked?
- [ ] **Browser tests (Pest 4)** — Zijn er smoke tests voor de belangrijkste pages?

**Evalueer gaps:**

Welke delen van de codebase hebben GEEN test coverage en zouden dat wel moeten hebben?

---

### D11. Error Handling & Observability

**Check specifiek:**

- [ ] **Consistent error patroon** — Is er een standaard manier waarop fouten worden afgehandeld in services?
- [ ] **Logging strategie** — Wat wordt gelogd? Is er een consistent log level beleid?
- [ ] **Sync monitoring** — Hoe weet je of een sync gefaald is? `SyncState` correct gebruikt?
- [ ] **Geen stille failures** — Zijn er plekken waar fouten worden geslikt (lege catch blocks)?
- [ ] **Queue failures** — Hoe worden mislukte jobs afgehandeld?
- [ ] **Health checks** — Is er een manier om te controleren of het systeem gezond is?

---

### D12. Database Workflow: Lokaal → Staging

Evalueer de volledige workflow van verse omgeving naar gevulde lokale database naar gesynchroniseerde staging.

**Context (bevestig actueel):**

- **Lokaal:** [database engine] — wordt ook gebruikt voor ad-hoc data analyse via chat sessies, moet dus altijd up-to-date zijn
- **Staging:** [database engine + hosting] — moet dezelfde data en schema hebben als lokaal
- **Data komt binnen via sync pipeline** — niet via seeders
- **Seeders bevatten:** configuratie-data (supply profiles, scenarios, demand events, users) — geen transactiedata

**Check de seeding-strategie:**

- [ ] **DatabaseSeeder** — Roept die alle relevante seeders aan in correcte volgorde?
- [ ] **Seeder idempotentie** — Kunnen seeders veilig opnieuw gedraaid worden? (`updateOrCreate` vs `create`)
- [ ] **Seeder afhankelijkheden** — Zijn er seeders die van elkaar afhangen? Is de volgorde gedocumenteerd?
- [ ] **Factory completeness** — Hebben alle models factories? Zijn factories up-to-date met recente migratie-toevoegingen?
- [ ] **Factory cascading** — Maken factories gerelateerde models aan?

**Check de migratie-flow:**

- [ ] **Schema divergentie** — Kan het schema op lokaal en staging uit de pas lopen? Hoe detecteer je dat?
- [ ] **Rollback veiligheid** — Zijn alle migraties veilig rollback-baar op beide platformen?
- [ ] **Constraint handling** — Foreign keys, unique constraints, indexen — werken ze identiek op beide platformen?
- [ ] **`migrate:fresh` vs `migrate`** — Welke strategie wordt gebruikt op staging? Is er risico op dataverlies?

**Check de sync pipeline als data source:**

- [ ] **SyncState bij environment switch** — Wat gebeurt er als SyncState records van lokaal naar staging gekopieerd worden? Worden dan halve syncs hervat?
- [ ] **Credential isolatie** — Draaien lokaal en staging tegen dezelfde API credentials? Is dat een risico?
- [ ] **Data volume** — Kan de sync pipeline staging vullen binnen een redelijke tijd?
- [ ] **Sync onafhankelijkheid** — Kan staging zelfstandig syncen zonder afhankelijk te zijn van lokale data?

**Evalueer wat er ONTBREEKT voor een vlotte workflow:**

- Is er een gedocumenteerde procedure om van "verse database" naar "volledig gevuld" te komen? (zowel lokaal als staging)
- Is er een manier om de lokale database te resetten en opnieuw te vullen zonder alles handmatig te doen?
- Is er een `sync:status` command om te zien waar de sync pipeline staat?
- Is er een database backup/snapshot mechanisme?
- Zou een database dump/restore strategie (lokaal → staging) beter werken dan staging zelf laten syncen?
- Is er een smoke test die verifieert dat het schema + basisdata correct is na een fresh install?

**Geef een advies over de ideale workflow:**

Beschrijf hoe de flow **zou moeten werken** in een ideale situatie:
1. Nieuwe developer of verse omgeving opzetten
2. Lokale database vullen met data
3. Schema wijzigingen doorvoeren en testen
4. Staging up-to-date brengen
5. Verifiëren dat staging correct werkt

---

## Output Formaat

Lever het rapport op als Markdown bestand: `docs/audits/audit-rapport-YYYY-MM.md` met de volgende structuur:

```markdown
# Architectuur Audit Rapport — Cyclowax Dashboard
## Datum: YYYY-MM-DD

## Executive Summary
- Snapshot tabel (cijfers + Δ vs vorige audit)
- Totaal score per dimensie (tabel met groen/oranje/rood)
- Top 5 bevindingen die het eerst aangepakt moeten worden
- Status van top 5 issues uit vorige audit (opgelost / nog open / nieuw)
- Algemene architectuurkwaliteit in 3-4 zinnen

## Per Dimensie
### D1. [Naam] — [Score]
**Bevindingen:**
1. [Bevinding met bestandsnaam:regelnummer]
   - Impact: ...
   - Prioriteit: Hoog/Medium/Laag

**Wat goed gaat:**
- ...

## Bijlage A: API Ontbrekende Endpoints
[Gedetailleerde lijst van endpoints die nodig zijn voor een volledige interne API]

## Bijlage B: Ontbrekende shadcn/ui Componenten & Frontend Gaps
[Lijst van componenten, types en patterns die nodig zijn voor frontend uitbouw]

## Bijlage C: Database Advies
[Onderbouwd advies met voor/nadelen en aanbeveling]

## Bijlage D: Ideale Database Workflow
[Beschrijving van de ideale flow: verse omgeving → gevulde database → staging sync]

## Bijlage E: Refactoring Backlog
[Alle bevindingen als backlog items, gesorteerd op prioriteit (Hoog → Laag)]
```

---

## Aanwijzingen voor de auditor

1. **Vul eerst de Snapshot in** — zonder actuele cijfers heeft de audit geen referentiekader
2. **Lees daarna de volledige codebase** — begin met `routes/`, dan `app/Http/`, dan `app/Services/` per domein, dan `app/Models/`, dan `app/Console/Commands/`, dan `tests/`, dan `resources/js/`
3. **Vergelijk met het vorige rapport** — markeer per dimensie of de score is verbeterd, gelijk gebleven of verslechterd
4. **Vergelijk siblings** — pak bij elke dimensie minimaal 3-5 concrete bestanden en vergelijk ze op consistentie
5. **Wees specifiek** — bestand:regel notatie, geen vage uitspraken
6. **Onderbouw met impact** — waarom is het een probleem? Wat gaat er mis als we het niet fixen?
7. **Wees eerlijk** — benoem ook wat goed gaat. Dit is een evaluatie, geen afrekening
8. **Denk aan de toekomst** — evalueer of de huidige basis klaar is voor de volgende fase van de roadmap
9. **Multi-database** — test elke raw query claim tegen beide database platformen
