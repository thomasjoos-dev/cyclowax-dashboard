# Sync Pipeline Protocol — Cyclowax Dashboard

Dit protocol definieert de regels voor de sync pipeline: 12 stappen die data ophalen bij Shopify, Klaviyo en Odoo, verrijken en verwerken naar de lokale database. De sync pipeline is het meest fragiele onderdeel van de applicatie — het raakt alle data en draait dagelijks. Volg dit protocol bij elke wijziging aan sync commands, syncers, API clients of gerelateerde modellen.

---

## 1. Pipeline Overzicht

### Stappen & volgorde

De pipeline wordt georkestreerd door `SyncAllCommand` (`sync:all`) en draait stappen sequentieel, met uitzondering van de Odoo-groep die parallel kan draaien.

| # | Command | Service | Bron | Categorie |
|---|---------|---------|------|-----------|
| 1 | `shopify:sync-orders` | ShopifyOrderSyncer | Shopify API | API sync |
| 2 | `odoo:sync-products` | OdooProductSyncer | Odoo API | API sync (parallel) |
| 3 | `odoo:sync-shipping-costs` | OdooShippingCostSyncer | Odoo API | API sync (parallel) |
| 4 | `odoo:sync-boms` | OdooBomSyncer | Odoo API | API sync (parallel) |
| 5 | `odoo:sync-open-pos` | OdooOpenPoSyncer | Odoo API | API sync (parallel) |
| 6 | `klaviyo:sync-profiles` | KlaviyoProfileSyncer | Klaviyo API | API sync (cursor) |
| 7 | `klaviyo:sync-campaigns` | KlaviyoCampaignSyncer | Klaviyo API | API sync (cursor) |
| 8 | `orders:compute-margins` | OrderMarginCalculator | Lokaal | Berekening |
| 9 | `customers:calculate-rfm` | RfmScoringService | Lokaal | Berekening |
| 10 | `klaviyo:sync-engagement` | KlaviyoEngagementSyncer | Klaviyo API | API sync (cursor) |
| 11 | `profiles:flag-suspects` | — | Lokaal | Verrijking |
| 12 | `profiles:link` | RiderProfileLinker | Lokaal | Linking |
| 13 | `profiles:score-followers` | — | Lokaal | Berekening |
| 14 | `klaviyo:sync-segments` | KlaviyoSegmentSyncer | Klaviyo API | API push |
| 15 | `shopify:sync-segments` | ShopifySegmentSyncer | Shopify API | API push |

### Afhankelijkheden

Stappen mogen niet van volgorde verwisseld worden zonder de afhankelijkheden te controleren:

```
shopify:sync-orders
    ↓ (orders + line items moeten bestaan)
    ├── orders:compute-margins (berekent marges op basis van orders + COGS)
    └── profiles:link (koppelt Klaviyo profielen aan Shopify klanten)

odoo:sync-products
    ↓ (COGS + producten moeten bestaan)
    └── orders:compute-margins

klaviyo:sync-profiles
    ↓ (profielen moeten bestaan)
    ├── klaviyo:sync-engagement (koppelt events aan profielen)
    ├── profiles:flag-suspects (markeert bot/spam profielen)
    ├── profiles:link (koppelt aan Shopify customers)
    └── profiles:score-followers (scoort follower engagement)

orders:compute-margins + customers:calculate-rfm + profiles:*
    ↓ (verrijkte data beschikbaar)
    ├── klaviyo:sync-segments (pusht segmenten naar Klaviyo)
    └── shopify:sync-segments (pusht tags naar Shopify)
```

**Regel:** Segment push-stappen (14, 15) draaien altijd als laatste — ze zijn afhankelijk van alle voorgaande verrijkingen.

### Scheduling

| Schedule | Commando | Doel |
|----------|----------|------|
| Dagelijks (`SYNC_DAILY_AT`, default 06:00) | `sync:all --skip-enrichment` | Incrementele sync |
| Wekelijks (zondag 04:00) | `sync:all --full --skip-enrichment` | Volledige resync |
| Dagelijks (1 uur na sync) | `klaviyo:enrich-campaigns --limit=20` | Campaign metrics verrijking |

Scheduling is alleen actief als `SYNC_SCHEDULE_ENABLED=true` in `.env`. Elke scheduled taak heeft `withoutOverlapping()` met een passende timeout.

---

## 2. Patronen & Conventies

Deze patronen zijn de standaard. Elke nieuwe syncer of wijziging moet ze volgen.

### 2.1 Process Isolation

Elke pipeline stap draait als **apart PHP process** via `Process::timeout(900)->run()`. Dit voorkomt memory accumulation over de hele pipeline.

**Regels:**
- Nooit een syncer direct aanroepen vanuit `SyncAllCommand` — altijd via `Process::run('php artisan ...')`
- Parent process roept `DB::connection()->flushQueryLog()` en `gc_collect_cycles()` aan tussen stappen
- Timeout per stap: **900 seconden** (15 minuten)
- Parallel groups gebruiken `Process::pool()` wanneer het systeem 2GB+ geheugen heeft

### 2.2 Transaction Wrapping

Alle database writes die meerdere tabellen of meerdere records raken, worden in een `DB::transaction()` gewrapped.

**Regels:**
- Upserts van gerelateerde data (order + line items, BOM + BOM lines) altijd atomair
- Geen partial state mogelijk — als een write faalt, faalt de hele transactie
- Binnen een transactie: nooit externe API calls doen (transaction kan timeout)

### 2.3 Upsert voor Idempotency

Syncers gebruiken `updateOrCreate()` of `upsert()` met een unieke externe key als match-kolom.

**Regels:**
- Elke syncer is idempotent — dezelfde data twee keer verwerken geeft hetzelfde resultaat
- Match altijd op de externe identifier: `shopify_id`, `klaviyo_id`, `odoo_id`
- Gebruik `upsert()` voor bulk operations (Klaviyo profielen), `updateOrCreate()` voor individuele records
- Snapshot-modellen (stock, open POs) worden per run volledig vervangen — geen incrementele updates

### 2.4 Incremental Sync via Timestamps

API syncers halen alleen gewijzigde data op sinds de laatste sync, met een veiligheidsbuffer.

**Regels:**
- Gebruik `SyncState::lastSyncedAt($step)` als startpunt voor incrementele queries
- Pas een **5 minuten buffer** toe op de `since` timestamp om edge cases bij gelijktijdige writes op te vangen
- Bij `--full` flag: negeer de timestamp en sync alles
- Wekelijkse full sync vangt eventuele gemiste records op

### 2.5 Credential Validation

API clients valideren credentials bij instantiatie. De pipeline valideert alle credentials voordat de eerste stap start.

**Regels:**
- API client constructors gooien `RuntimeException` als verplichte config ontbreekt
- `SyncAllCommand::validateCredentials()` checkt alle services voor de pipeline start
- Credentials alleen via `config()` — nooit `env()` in services of commands

### 2.6 Memory Management

Syncers voorkomen memory exhaustion door query logs uit te schakelen, grote resultaten te streamen, en geheugengebruik te monitoren.

**Regels:**
- `DB::disableQueryLog()` aan het begin van elke syncer
- Grote API responses streamen (Shopify bulk ops → JSONL streaming)
- Unset grote variabelen na verwerking
- `gc_collect_cycles()` aanroepen na batch-verwerking
- Memory threshold: **80% van `memory_limit`** — syncer stopt graceful als dit overschreden wordt

---

## 3. Cursor Management

Cursor-aware stappen (Klaviyo profiles, campaigns, engagement) kunnen hun werk verdelen over meerdere pipeline runs via het cursor protocol.

### SyncState Model

De `sync_states` tabel houdt per stap de status bij:

| Kolom | Type | Doel |
|-------|------|------|
| `step` | string (unique) | Command naam (bijv. `klaviyo:sync-profiles`) |
| `status` | string | `idle`, `running`, of `completed` |
| `last_synced_at` | timestamp | Laatste succesvolle completion |
| `duration_seconds` | float | Duur van de laatste run |
| `records_synced` | integer | Aantal verwerkte records |
| `was_full_sync` | boolean | Of de laatste run een full sync was |
| `cursor` | json | Resumption state (API cursor, since timestamp, etc.) |
| `started_at` | timestamp | Start van de huidige run |

### Cursor Flow

```
1. Command start
   → SyncState::getCursor($step)
   → Als cursor bestaat en status != 'completed': hervat
   → Anders: start nieuw met SyncState::lastSyncedAt($step)

2. Syncer draait
   → Verwerkt records in batches
   → Checkt hasTimeRemaining() en hasMemoryRemaining() per batch

3a. Budget op (niet alles verwerkt)
    → Syncer returnt { complete: false, cursor: { next_url, since, was_full } }
    → Command roept SyncState::saveCursor($step, $cursor, $count) aan
    → Status wordt 'idle' — scheduler pikt het op bij de volgende run

3b. Alles verwerkt
    → Syncer returnt { complete: true, count: N }
    → Command roept SyncState::markCompleted($step, $duration, $records, $wasFull) aan
    → Cursor wordt gewist, status wordt 'completed'
```

### Cursor Structuur

De cursor is een JSON object dat per syncer verschilt, maar altijd deze velden bevat:

```json
{
    "next_url": "https://api.example.com/next-page?cursor=...",
    "since": "2026-04-10T12:30:00+00:00",
    "was_full": false
}
```

Engagement syncer heeft extra velden voor multi-metric tracking:

```json
{
    "metric_ids": { "Received Email": "abc123", "Opened Email": "def456" },
    "completed_metrics": ["Received Email"],
    "current_metric": "Opened Email",
    "next_url": "...",
    "since": "...",
    "was_full": false
}
```

### Regels

- Cursor wordt **alleen opgeslagen bij succesvolle verwerking** — nooit bij een error
- Bij `was_full: true` wordt de `since` timestamp genegeerd bij resumption
- Cursor-aware commands beheren hun eigen SyncState — `SyncAllCommand` roept niet `markCompleted()` aan voor deze stappen
- Een corrupt of inconsistente cursor kan veilig gereset worden via `sync:reset-cursor {step}`

### Stale Detection

Als een stap langer dan **360 seconden** in `running` status staat, wordt die als stale beschouwd:

- `SyncState::isStale($step, 360)` checkt dit
- `SyncAllCommand` reset stale states automatisch bij de start van elke pipeline run
- `sync:reset-cursor` kan handmatig een specifieke stap of alle stappen (`--all`) resetten
- Na een reset: de stap start opnieuw als incrementele sync (cursor is gewist)

---

## 4. Failure Handling

### Pipeline-niveau

De pipeline stopt bij de eerste failure — volgende stappen worden niet uitgevoerd.

**Rationale:** stappen zijn afhankelijk van eerdere data. Orders syncen die later margins berekenen zonder actuele COGS leidt tot incorrecte resultaten.

**Uitzondering:** cursor-aware stappen die pauzeren (budget op) zijn geen failure — de pipeline stopt wél, maar met SUCCESS exit code. De scheduler hervat bij de volgende run.

### Per categorie

| Categorie | Bij failure | Effect op pipeline |
|-----------|------------|-------------------|
| **API sync (Shopify)** | Throw bij bulk operation failure of timeout | Pipeline stopt |
| **API sync (Odoo)** | Try-catch rond API call, returnt lege resultaten | Pipeline gaat door met 0 records |
| **API sync (Klaviyo)** | Try-catch met cursor save bij partial success | Pipeline pauzeert, hervat volgende run |
| **Linking** | Per-item try-catch, logt warnings | Pipeline gaat door |
| **Berekeningen** | Idempotent herberekening, geen side effects | Pipeline gaat door |
| **Segment push** | Bulk mutations met error reporting | Pipeline gaat door |

### Per-item error handling

**Regel:** bij het verwerken van meerdere records, vang fouten per record op zodat één slecht record niet de hele batch blokkeert.

```php
// Goed: per-item error handling
foreach ($records as $record) {
    try {
        $this->upsertRecord($record);
    } catch (\Throwable $e) {
        Log::warning('Failed to upsert record', [
            'id' => $record['id'],
            'error' => $e->getMessage(),
        ]);
    }
}

// Fout: hele batch faalt bij één slecht record
foreach ($records as $record) {
    $this->upsertRecord($record); // één exception breekt de loop
}
```

### Retry-beleid

- **API clients** (Klaviyo, Shopify) hebben ingebouwde retry: 3 attempts bij connection errors, exponential backoff bij rate limits
- **Syncers zelf** hebben geen retry — een gefaalde run wordt opgepakt door de scheduler bij de volgende dagelijkse run
- **Handmatige retry:** `php artisan sync:all` of individueel `php artisan shopify:sync-orders`

---

## 5. Time Budgets & Resource Limits

### HasTimeBudget Trait

Cursor-aware Klaviyo syncers gebruiken de `HasTimeBudget` trait voor gecontroleerde executie:

| Property | Default | Doel |
|----------|---------|------|
| `timeBudgetSeconds` | 210s (profiel/engagement), 900s (campaigns) | Max executietijd per run |
| `memoryThreshold` | 0.80 (80% van `memory_limit`) | Max geheugengebruik |

**Methoden:**
- `startTimeBudget()` — start de timer
- `hasTimeRemaining()` — check of er nog budget is
- `hasMemoryRemaining()` — check of geheugen onder de threshold is
- `elapsedSeconds()` — verstreken tijd sinds start

### Regels

- **Alle API syncers die pagineren** moeten een time budget hebben — voorkomt dat een enkele stap de hele pipeline blokkeert
- Campaign enrichment heeft 900s budget vanwege rate limiting (31s sleep per enrichment request)
- Time budget check wordt gedaan **per batch/page**, niet per record
- Bij budget exhaustion: syncer stopt graceful, slaat cursor op, logt een info-bericht

### Wanneer een time budget verplicht is

| Situatie | Time budget vereist? |
|----------|---------------------|
| API syncer met paginatie | Ja |
| API syncer met vaste dataset (bijv. alle BOMs) | Nee (dataset is begrensd) |
| Lokale berekening | Nee |
| Bulk push naar externe API | Nee (bounded door lokale dataset) |

---

## 6. Rollback & Recovery

### Incrementele correctie

De standaard recovery-strategie is **opnieuw syncen**, niet rollback:

- Incrementele sync haalt gewijzigde data opnieuw op
- Full sync (`--full`) haalt alles opnieuw op
- Wekelijkse full sync op zondag vangt structureel gemiste records op

### Wanneer full resync nodig is

| Situatie | Actie |
|----------|-------|
| Cursor corrupt / stuck | `php artisan sync:reset-cursor {step}` → normale run pikt het op |
| Verdacht gat in data | `php artisan sync:all --full` |
| Schema-wijziging in externe API | Full resync van betreffende stap |
| Lokale database wipe | `php artisan sync:all --full` (hele pipeline) |

### Wat je NIET moet doen

- **Geen handmatige database edits** om sync-problemen op te lossen — de volgende sync overschrijft ze
- **Geen cursors handmatig aanpassen** in de database — gebruik `sync:reset-cursor`
- **Geen individuele stappen overslaan** door ze uit `SyncAllCommand` te halen — fix het probleem

---

## 7. Monitoring & Diagnostiek

### Beschikbare tools

| Command | Doel | Wanneer gebruiken |
|---------|------|-------------------|
| `php artisan sync:status` | Toont status van alle stappen: laatste sync, duur, records, staleness | Na elke pipeline run, bij debugging |
| `php artisan health:check` | Valideert database, credentials, sync freshness | Bij deployment, bij vermoedelijke problemen |
| `php artisan sync:reset-cursor {step}` | Reset stuck/stale cursor | Bij stuck pipeline stap |

### Freshness thresholds

- `sync:status` markeert stappen als stale na **25 uur** zonder succesvolle sync
- `health:check` rapporteert stale stappen als warning
- Dagelijkse sync draait om 06:00 — een stap die om 08:00 de volgende dag nog niet gesynced is, is stale

### Logging

Alle sync-activiteit wordt gelogd naar het standaard Laravel log kanaal:

| Level | Wanneer |
|-------|---------|
| `info` | Succesvolle sync completion, pipeline start/stop, budget pause |
| `warning` | Per-item failures, stale state resets, partial completions |
| `error` | Stap failures, credential problemen, API errors |

**Regel:** log altijd het `step` en relevante context (records verwerkt, duur, error message). Log nooit credentials of volledige API responses.

---

## 8. Checklist bij Sync-gerelateerde Wijzigingen

Gebruik deze checklist bij elke wijziging aan sync commands, syncers, API clients of gerelateerde modellen.

### Nieuwe syncer toevoegen

- [ ] Syncer is idempotent — dezelfde data twee keer verwerken geeft hetzelfde resultaat
- [ ] Upsert op externe identifier (`shopify_id`, `klaviyo_id`, `odoo_id`)
- [ ] Database writes in `DB::transaction()` gewrapped
- [ ] Per-item error handling met `try-catch` en logging
- [ ] `DB::disableQueryLog()` aan het begin
- [ ] Grote variabelen unset na verwerking
- [ ] Time budget via `HasTimeBudget` als de syncer pagineert
- [ ] Cursor protocol geïmplementeerd als de syncer meerdere runs nodig kan hebben
- [ ] Stap toegevoegd aan `SyncAllCommand` op de juiste positie (check afhankelijkheden)
- [ ] `health:check` dekt de nieuwe stap af (freshness check)
- [ ] `sync:status` toont correcte status
- [ ] Tests met `Http::fake()` voor externe API calls
- [ ] Credentials via `config()`, gevalideerd in API client constructor
- [ ] Process timeout van 900s is voldoende voor verwacht volume

### Bestaande syncer wijzigen

- [ ] Idempotency niet gebroken door de wijziging
- [ ] Cursor structuur backward-compatible (bestaande cursors moeten nog werken)
- [ ] Time budget nog passend voor het nieuwe volume
- [ ] Afhankelijke stappen nog correct (bijv. als je velden toevoegt die margins beïnvloeden)
- [ ] Bestaande tests bijgewerkt

### API client wijzigen

- [ ] Retry-logica intact
- [ ] Rate limit handling intact
- [ ] Error responses correct afgevangen (geen stille failures)
- [ ] Credential validatie in constructor

### Model wijzigen dat door sync gevuld wordt

- [ ] Migratie geschreven voor schema-wijziging
- [ ] Syncer bijgewerkt om nieuwe/gewijzigde kolommen te vullen
- [ ] Factory bijgewerkt voor tests
- [ ] Afhankelijke berekeningen (margins, RFM, scores) gecheckt op impact
