# Template — Test Audit

> **Hoe gebruiken:** Draai deze audit per kwartaal of na significante wijzigingen aan de test-infrastructuur. Gebruik `docs/audits/prompts/test.md` om de audit te starten. Resultaat opslaan als `docs/audits/resultaten/test-audit-YYYY-MM-DD.md`.

---

## Doel

Evalueer de test suite op coverage, kwaliteit, snelheid en naleving van het test protocol (`docs/protocollen/test-protocol.md`). Dit is geen code-audit — het gaat specifiek om de tests.

---

## Snapshot (vul in bij start)

```bash
# Totaal tests en assertions
php artisan test --compact 2>&1 | tail -5

# Test bestanden per type
find tests/Feature -name "*Test.php" | wc -l
find tests/Unit -name "*Test.php" | wc -l

# Wat wordt getest
ls tests/Feature/

# Suite snelheid
time php artisan test --compact
```

| Metric | Waarde | Δ vs vorige audit |
|--------|--------|-------------------|
| Feature tests (bestanden) | | |
| Unit tests (bestanden) | | |
| Totaal tests | | |
| Totaal assertions | | |
| Suite duur (seconden) | | |
| Langzaamste test (seconden) | | |

---

## Dimensies

### T1. Coverage Analyse

Breng in kaart wat WEL en wat NIET getest is.

**Check per laag:**

- [ ] **Services** — welke services hebben tests, welke niet? Sorteer op risico (forecast/sync/scoring = hoog risico)
- [ ] **Controllers** — welke API endpoints hebben HTTP-level tests?
- [ ] **Commands** — welke commands worden getest?
- [ ] **Models** — worden factories gebruikt? Zijn alle factory states up-to-date met migraties?

**Output:** Tabel met alle services/controllers/commands, gemarkeerd als getest/ongetest, met risico-prioriteit.

| Categorie | Totaal | Getest | Coverage % | Ongetest (hoog risico) |
|-----------|--------|--------|------------|----------------------|
| Services | | | | |
| Controllers | | | | |
| Commands | | | | |
| Models (factory) | | | | |

### T2. Test Kwaliteit

Evalueer de kwaliteit van bestaande tests tegen het test protocol.

**Check specifiek:**

- [ ] **Factory gebruik** — hoeveel tests gebruiken `Model::create()` i.p.v. factories?
- [ ] **Assertion specificiteit** — hoeveel vage assertions (`not->toBeNull()`, `->toBeTrue()` zonder context)?
- [ ] **Edge cases** — hebben test files minimaal happy path + edge case + error scenario?
- [ ] **Test beschrijvingen** — zijn `it()` beschrijvingen actief en beschrijvend?
- [ ] **Helper functies** — worden herhaalde setup-patronen geëxtraheerd?

**Kwantificeer:** Tel exacte aantallen per anti-pattern.

### T3. Test Isolatie & Betrouwbaarheid

- [ ] **Geen volgorde-afhankelijkheid** — kunnen tests in willekeurige volgorde draaien?
- [ ] **Geen gedeelde state** — geen tests die afhangen van data uit een andere test?
- [ ] **HTTP mocking** — is `Http::preventStrayRequests()` globaal actief?
- [ ] **Process mocking** — worden subprocess-calls (artisan binnen artisan) gemockt?
- [ ] **Flaky tests** — zijn er tests die soms falen? Identificeer ze.

### T4. Performance

- [ ] **Suite totaaltijd** — binnen de 60 seconden norm?
- [ ] **Individuele tests** — zijn er tests > 2 seconden?
- [ ] **Langzaamste tests top 10** — lijst met duur en verklaring
- [ ] **Database setup overhead** — is `RefreshDatabase` de bottleneck?

### T5. CI Pipeline

- [ ] **GitHub Actions workflow** — bestaat en draait correct?
- [ ] **PostgreSQL service container** — geconfigureerd?
- [ ] **PHP versie** — correct (8.4)?
- [ ] **Timeout** — 15 minuten ingesteld?
- [ ] **Memory limit** — 512M in phpunit.xml?
- [ ] **Laatste CI run** — groen?

### T6. Test Protocol Naleving

Toets de huidige tests tegen elke regel in `docs/protocollen/test-protocol.md`:

- [ ] Alle principes (3.1-3.7) nageleefd?
- [ ] Factory regels gevolgd (sectie 5)?
- [ ] Naamgeving correct (sectie 1)?
- [ ] Checklist items (sectie 8) afgevinkt per recent geschreven test?

### T7. Ontbrekende Test Types

- [ ] **Smoke tests** — laden alle pagina's zonder errors?
- [ ] **Architecture tests** — Pest Arch regels voor structurele enforcement?
- [ ] **Browser tests** — interactieve tests voor kritieke user flows?

---

## Output Formaat

```markdown
# Test Audit Rapport — YYYY-MM-DD

## Snapshot
[Tabel met metrics]

## Samenvatting
- Totaal score: [Groen/Oranje/Rood]
- Top 3 bevindingen
- Status issues vorige audit

## Per Dimensie
### T1. Coverage — [Score]
[Coverage tabel + risico-prioritering]

### T2. Kwaliteit — [Score]
[Anti-pattern tellingen + voorbeelden]

...

## Aanbevelingen
[Geprioriteerde fix-lijst, gegroepeerd in sprints]
```
