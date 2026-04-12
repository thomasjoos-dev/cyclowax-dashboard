# Sync Pipeline Protocol — Cyclowax Dashboard

> **Status: Stub.** Dit protocol moet nog volledig worden uitgewerkt in een eigen verkenningssessie. De structuur hieronder definieert de secties die ingevuld moeten worden.

Dit protocol definieert de regels voor de sync pipeline: 15 stappen die data ophalen bij Shopify, Klaviyo en Odoo en verwerken naar de lokale database. De sync pipeline is het meest fragiele onderdeel van de applicatie — het raakt alle data en draait dagelijks.

---

## 1. Pipeline Overzicht

De volledige pipeline wordt georkestreerd door `SyncAllCommand` en omvat:

| # | Command | Service | Externe API |
|---|---------|---------|-------------|
| 1 | `shopify:sync-orders` | ShopifyOrderSyncer | Shopify |
| 2 | `shopify:sync-segments` | ShopifySegmentSyncer | Shopify |
| 3 | `klaviyo:sync-profiles` | KlaviyoProfileSyncer | Klaviyo |
| 4 | `klaviyo:sync-segments` | KlaviyoSegmentSyncer | Klaviyo |
| 5 | `klaviyo:sync-engagement` | KlaviyoEngagementSyncer | Klaviyo |
| 6 | `klaviyo:sync-campaigns` | KlaviyoCampaignSyncer | Klaviyo |
| 7 | `odoo:sync-products` | OdooProductSyncer | Odoo |
| 8 | `odoo:sync-boms` | OdooBomSyncer | Odoo |
| 9 | `odoo:sync-open-pos` | OdooOpenPoSyncer | Odoo |
| 10 | `odoo:sync-shipping-costs` | OdooShippingCostSyncer | Odoo |
| 11 | `link:line-items` | LineItemLinker | — (lokaal) |
| 12 | `link:rider-profiles` | RiderProfileLinker | — (lokaal) |
| 13 | `compute:order-margins` | OrderMarginCalculator | — (lokaal) |
| 14 | `calculate:rfm-scores` | RfmScoringService | — (lokaal) |
| 15 | `health:check` | — | — (lokaal) |

---

## 2. Failure Handling

> TODO: Uitwerken per stap-categorie (API sync, linking, berekeningen)

Vragen die beantwoord moeten worden:

- Wat gebeurt er als een individuele stap faalt — stopt de hele pipeline of gaat de rest door?
- Welke stappen zijn afhankelijk van eerdere stappen? (bijv. `link:line-items` na `shopify:sync-orders`)
- Hoe worden partial failures afgehandeld? (bijv. 80% van orders gesyncet, API timeout halverwege)
- Wat is het retry-beleid per stap?

---

## 3. Rollback & Recovery

> TODO: Uitwerken

Vragen die beantwoord moeten worden:

- Hoe herken je corrupte data na een sync? (health:check dekt dit deels)
- Kun je een specifieke stap opnieuw draaien zonder side effects?
- Is er een strategie voor "full resync" bij ernstige data-corruptie?
- Moeten we sync snapshots bijhouden voor rollback?

---

## 4. Cursor Management

> TODO: Uitwerken op basis van SyncState model

Vragen die beantwoord moeten worden:

- Hoe worden cursors (last synced ID/datum) opgeslagen? (`SyncState` model)
- Wat gebeurt er als een cursor corrupt raakt?
- Hoe reset je een cursor veilig? (`sync:reset-cursor` command)
- Worden cursors per stap of globaal beheerd?

---

## 5. Idempotency

> TODO: Uitwerken per syncer

Vragen die beantwoord moeten worden:

- Zijn alle syncers idempotent? (dezelfde data twee keer syncen = zelfde resultaat)
- Worden upserts gebruikt of insert-then-update?
- Hoe gaan linking stappen om met duplicate runs?
- Zijn berekeningen (margins, RFM) idempotent bij herhaalde uitvoering?

---

## 6. Time Budgets & Resource Limits

Bestaand patroon: `HasTimeBudget` trait (3 Klaviyo syncers).

- Default time budget: **210 seconden** per syncer
- Memory threshold: **80%** van PHP memory_limit
- Syncer stopt graceful als budget op is, pikt volgende run op waar gestopt

Vragen die beantwoord moeten worden:

- Moeten alle API syncers een time budget hebben?
- Wat zijn de juiste limieten per stap?
- Hoe rapporteer je als een stap structureel over budget gaat?

---

## 7. Monitoring & Alerting

> TODO: Uitwerken

Vragen die beantwoord moeten worden:

- Hoe detecteer je dat de sync pipeline stil staat? (freshness checks in `health:check`?)
- Moet er alerting zijn bij failures? (log, email, Slack?)
- Welke metrics zijn belangrijk? (duur per stap, records verwerkt, errors)
- Is `sync:status` command voldoende voor handmatige monitoring?

---

## 8. Checklist bij sync-gerelateerde wijzigingen

- [ ] Syncer is idempotent — dezelfde data twee keer verwerken geeft hetzelfde resultaat
- [ ] Cursor wordt correct bijgewerkt na succesvolle sync
- [ ] Failure in deze stap breekt niet de rest van de pipeline
- [ ] External API calls zijn gemockt in tests (`Http::fake()`)
- [ ] Time budget / memory threshold is passend voor verwacht volume
- [ ] `health:check` dekt de nieuwe/gewijzigde stap af
- [ ] `sync:status` toont correcte status voor de nieuwe/gewijzigde stap
