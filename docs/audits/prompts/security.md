# Audit Prompt — Security

> Kopieer dit als instructie naar Claude om de security audit te draaien.

---

## Instructie

Draai een volledige security audit van de Cyclowax Dashboard codebase.

**Template:** Volg `docs/audits/templates/security.md` — die bevat 10 dimensies met concrete checklists.

**Protocol om tegen te toetsen:** `docs/protocollen/security-protocol.md`

**Vorige audit:** Zoek het meest recente `security-audit-*.md` in `docs/audits/resultaten/`. Als dit de eerste security audit is, noteer dat.

**Aanpak:**
1. Draai de Snapshot commands en vul de tabel in
2. Check elke route op middleware — geen gaten in auth
3. Inventariseer alle modellen op mass assignment configuratie
4. Grep voor `env()` gebruik buiten config files
5. Inventariseer alle raw queries en controleer parameter binding
6. Check credential handling: `.env`, `.env.example`, git history, logging
7. Controleer error exposure: API responses, debug mode, exception handling
8. Inventariseer PII velden en check logging/export
9. Evalueer CORS configuratie
10. Draai `composer audit` en `npm audit`
11. Sla het rapport op als `docs/audits/resultaten/security-audit-YYYY-MM-DD.md`

**Extra context voor deze codebase:**
- Extern dashboard met 3 API integraties (Shopify, Klaviyo, Odoo) — elk met eigen credentials
- PII van ~11.600 klanten in de database (email, naam, adres)
- Sanctum token auth voor API, session auth voor web
- Intern team (4 gebruikers) — geen publieke registratie

**Belangrijk:** Dit is een evaluatie — constateer en onderbouw, maar schrijf geen code. Markeer kritieke issues die direct gefixt moeten worden.
