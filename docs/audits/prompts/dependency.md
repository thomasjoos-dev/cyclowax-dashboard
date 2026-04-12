# Audit Prompt — Dependency

> Kopieer dit als instructie naar Claude om de dependency audit te draaien.

---

## Instructie

Draai een dependency audit van de Cyclowax Dashboard.

**Template:** Volg `docs/audits/templates/dependency.md` — die bevat 5 dimensies.

**Vorige audit:** Zoek het meest recente `dependency-audit-*.md` in `docs/audits/resultaten/`. Als dit de eerste dependency audit is, noteer dat.

**Aanpak:**
1. Draai `composer audit` en documenteer elke vulnerability
2. Draai `npm audit` en documenteer elke vulnerability
3. Draai `composer outdated --direct` en beoordeel welke updates prioriteit hebben
4. Draai `npm outdated` en beoordeel welke updates prioriteit hebben
5. Check changelogs van major updates op breaking changes
6. Optioneel: draai `npx depcheck` voor unused JS dependencies
7. Sla het rapport op als `docs/audits/resultaten/dependency-audit-YYYY-MM-DD.md`

**Prioritering:**
- Critical/High security vulnerabilities → direct upgraden
- Outdated core packages (Laravel, React, Inertia, Sanctum) → plan maken
- Minor/patch updates → batch upgraden

**Belangrijk:** Dit is een evaluatie. Voer geen upgrades uit tenzij expliciet gevraagd.
