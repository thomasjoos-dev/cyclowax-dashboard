# Template — Performance Audit

> **Hoe gebruiken:** Draai deze audit per kwartaal of bij performance klachten. Gebruik `docs/audits/prompts/performance.md` om de audit te starten. Resultaat opslaan als `docs/audits/resultaten/performance-audit-YYYY-MM-DD.md`.

---

## Doel

Evalueer de performance van queries, services en response times. Focus op de specifieke risico's van dit project: complexe analytische queries (cohort analyses, BOM explosies, forecast berekeningen) die trager worden naarmate de dataset groeit.

---

## Snapshot (vul in bij start)

```bash
# Data volumes
php artisan tinker --execute "
echo 'Orders: ' . \App\Models\ShopifyOrder::count() . PHP_EOL;
echo 'Customers: ' . \App\Models\ShopifyCustomer::count() . PHP_EOL;
echo 'Line items: ' . \App\Models\ShopifyLineItem::count() . PHP_EOL;
echo 'Products: ' . \App\Models\Product::count() . PHP_EOL;
echo 'Rider profiles: ' . \App\Models\RiderProfile::count() . PHP_EOL;
echo 'Ad spends: ' . \App\Models\AdSpend::count() . PHP_EOL;
echo 'Forecast snapshots: ' . \App\Models\ForecastSnapshot::count() . PHP_EOL;
"

# Database grootte
psql -c "SELECT pg_size_pretty(pg_database_size('cyclowax_dashboard'));"

# Tabel groottes top 10
psql -d cyclowax_dashboard -c "SELECT relname, pg_size_pretty(pg_total_relation_size(relid)) FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10;"
```

| Metric | Waarde | Δ vs vorige audit |
|--------|--------|-------------------|
| Orders | | |
| Customers | | |
| Line items | | |
| Database grootte | | |

---

## Dimensies

### P1. N+1 Query Detection

Analyseer services die Eloquent relaties laden. Zoek naar patronen waar relaties in loops geladen worden zonder eager loading.

**Check specifiek:**

- [ ] **Analytics services** — laden die gerelateerde data via eager loading?
- [ ] **Sync services** — worden relaties geladen per item of in bulk?
- [ ] **Controller responses** — worden relaties meegeladen voor API Resources?
- [ ] **Commands** — chunked processing bij grote datasets?

**Hoe te detecteren:**

```php
// Zoek naar ->load() in loops
// Zoek naar relatie-access in Collection->map() zonder ->with()
// Zoek naar nested queries in foreach/each blocks
```

### P2. Zware Queries

Identificeer queries die potentieel traag zijn bij groeiende data.

**Check specifiek:**

- [ ] **Raw queries** — alle `DB::raw()`, `selectRaw()`, `whereRaw()` calls — zijn deze geïndexeerd?
- [ ] **Subqueries** — zijn er geneste subqueries die beter als joins geschreven kunnen worden?
- [ ] **GROUP BY op grote tabellen** — cohort analyses, revenue aggregaties
- [ ] **LIKE queries** — worden die gebruikt op grote tekstvelden?
- [ ] **Date range queries** — zijn `created_at` / `processed_at` kolommen geïndexeerd?

**Per gevonden zware query:**
- Geschatte complexiteit (O(n), O(n²), etc.)
- Huidige dataset grootte waar de query op draait
- Verwachte groei (12 maanden)
- Impact: wordt merkbaar traag bij welk volume?

### P3. Index Analyse

- [ ] **Ontbrekende indexen** — kolommen die in WHERE/JOIN/ORDER BY staan maar geen index hebben
- [ ] **Ongebruikte indexen** — indexen die nooit geraakt worden
- [ ] **Compound indexen** — waar multi-kolom queries baat zouden hebben bij een compound index
- [ ] **Foreign key indexen** — alle foreign keys geïndexeerd?

```sql
-- Ontbrekende indexen suggestie (PostgreSQL)
SELECT schemaname, relname, seq_scan, idx_scan
FROM pg_stat_user_tables
WHERE seq_scan > idx_scan AND seq_scan > 100
ORDER BY seq_scan DESC;
```

### P4. Caching

- [ ] **Welke data wordt gecached?** — inventariseer `Cache::remember()` calls
- [ ] **Cache invalidatie** — worden caches correct geïnvalideerd bij data-updates?
- [ ] **Cache TTL** — zijn TTL's redelijk? Te kort = geen nut, te lang = stale data
- [ ] **Waar MOET caching toegevoegd worden?** — analytics queries die zelden veranderen maar vaak opgevraagd worden

### P5. Sync Pipeline Performance

- [ ] **Totale sync duur** — hoe lang duurt `sync:all`?
- [ ] **Per stap duur** — welke sync stappen zijn het traagst?
- [ ] **Batch sizes** — worden externe API's in optimale batch sizes aangesproken?
- [ ] **Memory usage** — peak memory tijdens sync? Chunking correct?
- [ ] **Time budgets** — zijn de `HasTimeBudget` limieten correct ingesteld?

### P6. Frontend Performance

> **Activeer na eerste UI-sprint.** Overslaan zolang de frontend < 3 pagina's heeft.

- [ ] **Bundle size** — `npm run build` output analyseren, onverwacht grote chunks?
- [ ] **Deferred props** — wordt secundaire data deferred geladen?
- [ ] **Unnecessary re-renders** — React DevTools profiler check op kritieke pagina's

### P7. Memory & Resource Usage

- [ ] **Peak memory per command** — welke commands zijn memory-intensief?
- [ ] **Forecast berekeningen** — BOM explosie, netting, seasonal indices — memory footprint?
- [ ] **PDF generatie** — DomPDF memory usage bij grote rapporten?
- [ ] **Collection vs lazy collection** — worden grote resultsets als lazy collection verwerkt?

---

## Output Formaat

```markdown
# Performance Audit Rapport — YYYY-MM-DD

## Snapshot
[Data volumes + database grootte]

## Performance Score
| Dimensie | Score | Kritieke bevindingen |
|----------|-------|---------------------|
| N+1 queries | | |
| Zware queries | | |
| Indexen | | |
| Caching | | |
| Sync pipeline | | |
| Frontend | | |
| Memory | | |

## Top 5 Performance Risico's
[Gesorteerd op impact × waarschijnlijkheid]

## Per Dimensie
### P1. N+1 Queries — [Score]
...

## Quick Wins (< 1 uur per fix)
[Laaghangend fruit]

## Grotere Optimalisaties
[Meer werk, maar significant impact]
```
