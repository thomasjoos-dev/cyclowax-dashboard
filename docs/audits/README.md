# Audit & Protocol Framework — Cyclowax Dashboard

Dit document beschrijft hoe we code-kwaliteit structureel borgen: welke audits we draaien, welke protocollen we volgen, en hoe alles samenhangt.

---

## Filosofie

**Protocollen zijn de meetlat, audits zijn de meting.**

- **Protocollen** definiëren hoe we bouwen — doorlopende regels die tijdens elke sessie gelden
- **Audit templates** definiëren wat we checken — scorekaarten per domein
- **Audit prompts** maken audits herhaalbaar — instructies aan Claude om een audit te draaien
- **Audit resultaten** zijn de snapshots — bevindingen op een specifiek moment

De cyclus:

```
Protocollen opstellen (guard rails)
        ↓
Audits draaien (meten tegen protocollen)
        ↓
Fixes implementeren (bevindingen oplossen)
        ↓
Protocollen aanscherpen (learnings verwerken)
        ↓
Automatiseren (terugkerende checks → CI of health:check)
```

---

## Protocollen

Protocollen zijn **doorlopende regels** die gelden tijdens het bouwen. Ze voorkomen fouten in plaats van ze achteraf te vinden. Claude valt hier tijdens bouwplannen op terug.

| Protocol | Scope | Locatie | Status |
|----------|-------|---------|--------|
| **Test** | Hoe tests geschreven en onderhouden worden | `docs/protocollen/test-protocol.md` | Actief |
| **Bouwsessie** | Pre-flight en post-flight checks per sessie | `docs/protocollen/bouwsessie-protocol.md` | Actief |
| **Security** | Env management, input validatie, route bescherming, API keys | `docs/protocollen/security-protocol.md` | Actief |
| **API Design** | RESTful conventies, error format, versioning, response structuur | `docs/protocollen/api-design-protocol.md` | Actief |
| **Frontend/UI** | Component conventies, shadcn patterns, accessibility, state | `docs/protocollen/frontend-protocol.md` | Actief |
| **Sync Pipeline** | Monitoring, failure handling, cursor management, idempotency | `docs/protocollen/sync-pipeline-protocol.md` | Stub |

### Wanneer protocollen van toepassing zijn

- **Test protocol** — bij elke code-wijziging
- **Bouwsessie protocol** — aan het begin en einde van elke sessie
- **Security protocol** — bij elke code-wijziging die raakt aan auth, API's, env, of user input
- **API design protocol** — bij elke nieuwe of gewijzigde endpoint
- **Frontend/UI protocol** — bij elke UI-wijziging
- **Sync pipeline protocol** — bij wijzigingen aan sync commands of services

### Hoe Claude protocollen gebruikt

Claude leest het relevante protocol **voordat** er code geschreven wordt en toetst het resultaat ertegen **na** het bouwen. Bij een conflict tussen snelheid en protocol wint het protocol — technische schuld voorkomen is goedkoper dan het later oplossen.

---

## Audits

Audits zijn **periodieke evaluaties** van de codebase tegen de protocollen en best practices. Elke audit heeft drie componenten:

### Componenten per audit

| Component | Wat | Locatie |
|-----------|-----|---------|
| **Template** | Scorekaart — welke dimensies, welke checks, welk output format | `docs/audits/templates/` |
| **Prompt** | Instructie aan Claude — "draai audit X, gebruik template Y" | `docs/audits/prompts/` |
| **Resultaat** | Rapport met bevindingen, scores, fix-lijst | `docs/audits/resultaten/` |

### Audit types

| Audit | Wat wordt geëvalueerd | Frequentie | Template | Prompt |
|-------|----------------------|------------|----------|--------|
| **Architectuur** | Lagen, services, models, commands, naamgeving, consistentie (12 dimensies) | Per kwartaal of na grote refactor | `templates/architectuur.md` | `prompts/architectuur.md` |
| **Test** | Coverage, kwaliteit, anti-patterns, CI health, factory gebruik | Per kwartaal | `templates/test.md` | `prompts/test.md` |
| **Security** | Auth, route bescherming, credentials, PII, CORS, input validatie | Per kwartaal + bij nieuwe integratie | `templates/security.md` | `prompts/security.md` |
| **Performance** | Query snelheid, N+1, response times, cache gebruik, geheugen | Per kwartaal of bij klachten | `templates/performance.md` | `prompts/performance.md` |
| **Dependency** | Bekende kwetsbaarheden, outdated packages, breaking changes | Maandelijks (CI-geautomatiseerd) | `templates/dependency.md` | `prompts/dependency.md` |

### Data-integriteit (geautomatiseerd)

Data-integriteit wordt **niet** als handmatige audit gedraaid maar als uitbreiding van `health:check`. Checks:

- COGS coverage percentage per productcategorie
- Shipping cost coverage (geschat vs werkelijk)
- Kanaalclassificatie coverage (% unknown)
- Sync pipeline freshness (alle 15 stappen)
- Foreign key integriteit (orphaned records)
- SKU matching coverage (line items → products)
- Forecast snapshot consistentie

Dit draait automatisch na elke sync run en rapporteert afwijkingen.

---

## Relatie protocol ↔ audit

Elk audit type heeft een **bijbehorend protocol** dat de regels definieert waartegen gemeten wordt:

```
Protocol                    →  Audit die het toetst
─────────────────────────────────────────────────────
Security protocol           →  Security audit
API design protocol         →  Architectuur audit (D6)
Frontend/UI protocol        →  Architectuur audit (D8)
Test protocol               →  Test audit
Bouwsessie protocol         →  (geen eigen audit — checklist per sessie)
Sync pipeline protocol      →  Architectuur audit (D11, D12)
```

De architectuur audit is bewust breed — die raakt meerdere protocollen. De security en test audits zijn diep en gespecialiseerd.

---

## Mapstructuur

```
docs/
├── protocollen/
│   ├── test-protocol.md              ← actief
│   ├── bouwsessie-protocol.md        ← actief
│   ├── security-protocol.md          ← actief
│   ├── api-design-protocol.md        ← actief
│   ├── frontend-protocol.md          ← actief
│   └── sync-pipeline-protocol.md     ← stub
│
├── audits/
│   ├── README.md                     ← dit document
│   │
│   ├── templates/
│   │   ├── architectuur.md           ← actief (12 dimensies)
│   │   ├── test.md                   ← actief
│   │   ├── security.md               ← actief
│   │   ├── performance.md            ← actief
│   │   └── dependency.md             ← actief
│   │
│   ├── prompts/
│   │   ├── architectuur.md           ← actief
│   │   ├── test.md                   ← actief
│   │   ├── security.md               ← actief
│   │   ├── performance.md            ← actief
│   │   └── dependency.md             ← actief
│   │
│   └── resultaten/
│       └── test-audit-2026-04-12.md  ← eerste audit
│
├── architectuur/
│   ├── api.md
│   ├── architectuur.md
│   ├── styleguide.md
│   └── SETUP.md
│
└── bouwplannen/
```

---

## Naamconventies

- **Resultaten:** `{type}-audit-YYYY-MM-DD.md` (bijv. `security-audit-2026-04-15.md`)
- **Templates:** `{type}.md` (enkelvoud, zonder prefix)
- **Prompts:** `{type}.md` (zelfde naam als template)
- **Protocollen:** `{type}-protocol.md`

---

## Hoe een audit draaien

1. Open een nieuwe Claude Code sessie
2. Zeg: _"Draai de [type] audit"_
3. Claude leest de prompt uit `docs/audits/prompts/{type}.md`
4. Claude volgt de template uit `docs/audits/templates/{type}.md`
5. Claude slaat het resultaat op in `docs/audits/resultaten/{type}-audit-YYYY-MM-DD.md`
6. Bevindingen worden besproken en geprioriteerd
7. Fixes worden in een bouwplan verwerkt

### Na de audit

- Update het relevante protocol als er learnings zijn
- Markeer opgeloste bevindingen in het resultaat
- Verwijs vanuit het volgende audit-resultaat naar het vorige (delta tracking)

---

## Audit kalender

| Maand | Audit | Aanleiding |
|-------|-------|------------|
| Januari | Architectuur + Dependency | Kwartaalstart |
| Februari | — | — |
| Maart | Test + Security | Pre-Q2 check |
| April | Architectuur + Dependency | Kwartaalstart |
| Mei | — | — |
| Juni | Test + Security | Mid-year check |
| Juli | Architectuur + Dependency | Kwartaalstart |
| Augustus | Performance | Na verwachte groei data volume |
| September | Test + Security | Pre-Q4 check |
| Oktober | Architectuur + Dependency | Kwartaalstart |
| November | — | — |
| December | Test + Security + Performance | Jaarafsluiting |

**Extra audit-triggers:**
- Na elke nieuwe externe integratie → Security audit
- Na significante performance klachten → Performance audit
- Na grote refactor → Architectuur audit
- Dependency audit draait ook automatisch via CI bij elke push
