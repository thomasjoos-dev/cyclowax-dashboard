# Audit Prompt — Test

> Kopieer dit als instructie naar Claude om de test audit te draaien.

---

## Instructie

Draai een volledige test audit van de Cyclowax Dashboard test suite.

**Template:** Volg `docs/audits/templates/test.md` — die bevat 7 dimensies met concrete checklists.

**Protocol om tegen te toetsen:** `docs/protocollen/test-protocol.md`

**Vorige audit:** Zoek het meest recente `test-audit-*.md` in `docs/audits/resultaten/`. Vergelijk de bevindingen en sprint-prioriteiten met de huidige situatie.

**Aanpak:**
1. Draai `php artisan test --compact` en noteer het resultaat
2. Vul de Snapshot tabel in
3. Inventariseer coverage: maak een volledige tabel van alle services, controllers en commands met test-status
4. Kwantificeer anti-patterns: tel exacte aantallen `Model::create()`, `not->toBeNull()`, vage assertions
5. Check CI pipeline configuratie (`.github/workflows/`)
6. Toets tegen elke regel in het test protocol
7. Evalueer elke dimensie (T1-T7) met concrete voorbeelden
8. Sla het rapport op als `docs/audits/resultaten/test-audit-YYYY-MM-DD.md`

**Belangrijk:** Dit is een evaluatie — constateer en onderbouw, maar schrijf geen code. Geef wel concrete fix-suggesties per bevinding.
