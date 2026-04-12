# Audit Prompt — Performance

> Kopieer dit als instructie naar Claude om de performance audit te draaien.

---

## Instructie

Draai een volledige performance audit van de Cyclowax Dashboard codebase.

**Template:** Volg `docs/audits/templates/performance.md` — die bevat 7 dimensies met concrete checklists.

**Vorige audit:** Zoek het meest recente `performance-audit-*.md` in `docs/audits/resultaten/`. Als dit de eerste performance audit is, noteer dat.

**Aanpak:**
1. Vul de Snapshot in met actuele data volumes (via tinker)
2. Zoek naar N+1 patronen in services die Eloquent relaties laden
3. Inventariseer alle raw/complex queries en beoordeel hun complexiteit bij groeiende data
4. Check indexen op tabellen die in WHERE/JOIN/ORDER BY voorkomen
5. Inventariseer caching (Cache::remember, config cache, query cache)
6. Meet sync pipeline performance als de pipeline beschikbaar is
7. Analyseer frontend bundle size via `npm run build`
8. Check memory-intensieve operaties (BOM explosie, forecast, PDF)
9. Sla het rapport op als `docs/audits/resultaten/performance-audit-YYYY-MM-DD.md`

**Extra context voor deze codebase:**
- Dataset: ~16.000 orders, ~11.600 klanten, ~260 producten — groeit ~200 orders/maand
- Analytische queries: cohort analyses, LTV berekeningen, BOM explosies, seasonal indices
- Sync pipeline: 15 stappen, dagelijks incrementeel + wekelijks full
- Forecast engine: demand → SKU mix → BOM explosie → netting → purchase calendar

**Belangrijk:** Dit is een evaluatie — constateer en onderbouw, maar schrijf geen code. Focus op risico's die bij 2-3x data volume merkbaar worden.
