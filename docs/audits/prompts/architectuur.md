# Audit Prompt — Architectuur

> Kopieer dit als instructie naar Claude om de architectuur audit te draaien.

---

## Instructie

Draai een volledige architectuur audit van de Cyclowax Dashboard codebase.

**Template:** Volg `docs/audits/templates/architectuur.md` — die bevat 12 dimensies met concrete checklists.

**Protocollen om tegen te toetsen:**
- `docs/protocollen/security-protocol.md` (voor D7)
- `docs/protocollen/api-design-protocol.md` (voor D6)
- `docs/protocollen/frontend-protocol.md` (voor D8)
- `docs/protocollen/test-protocol.md` (voor D10)

**Vorige audit:** Zoek het meest recente `architectuur-audit-*.md` of `audit-rapport-*.md` in `docs/audits/resultaten/`. Vergelijk de top 5 issues van die audit met de huidige situatie.

**Aanpak:**
1. Vul eerst de Snapshot sectie in met actuele cijfers
2. Lees de codebase systematisch: routes → controllers → services per domein → models → commands → tests → frontend
3. Evalueer elke dimensie (D1-D12) met concrete bestand:regel referenties
4. Scoor elke dimensie als Groen / Oranje / Rood
5. Schrijf een executive summary met top 5 bevindingen
6. Sla het rapport op als `docs/audits/resultaten/architectuur-audit-YYYY-MM-DD.md`

**Belangrijk:** Dit is een evaluatie — constateer en onderbouw, maar schrijf geen code en maak geen wijzigingen.
