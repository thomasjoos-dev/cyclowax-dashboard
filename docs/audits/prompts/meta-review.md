# Meta-Review Prompt — Protocollen & Audit Framework

> Gebruik deze prompt in een **schone sessie** (geen eerdere context) voor een onafhankelijke evaluatie van het audit & protocol framework.

---

## Instructie

Je bent een onafhankelijke reviewer. Evalueer het audit & protocol framework van het Cyclowax Dashboard project. Dit framework is in één sessie opgezet door een andere Claude-instantie samen met de projecteigenaar. Jouw rol is om het kritisch te beoordelen — zonder loyaliteit aan de auteur.

---

## Context over het project

Lees eerst deze bestanden om het project te begrijpen:

1. `CLAUDE.md` — projectregels en stack
2. `docs/audits/README.md` — het overkoepelende framework document
3. `docs/architectuur/architectuur.md` — huidige architectuur

Verdiep je daarna in de codebase:

4. `composer.json` + `package.json` — dependencies en versies
5. `routes/api.php` + `routes/web.php` — route structuur
6. `app/Services/` — scan de service-domeinen (ls per submap)
7. `app/Http/Controllers/` — controllers
8. `app/Console/Commands/` — commands
9. `tests/` — test structuur
10. `config/` — custom config files

### Kernfeiten

- **Wat:** Intern DTC analytics dashboard voor fietswaxmerk Cyclowax
- **Stack:** Laravel 13, React 19, Inertia v2, PostgreSQL, Tailwind 4, shadcn/ui
- **Data:** ~16k orders, ~11.6k klanten, 3 externe API's (Shopify, Klaviyo, Odoo)
- **Team:** Solo developer (COO/junior CTO) die met Claude bouwt
- **Fase:** Backend compleet (62 services, 43 commands), frontend nog vrijwel leeg (1 app-pagina)
- **Volgende stap:** Grote dashboard UI build-out

---

## Wat je moet evalueren

### Te reviewen bestanden

**Protocollen:**
- `docs/protocollen/security-protocol.md`
- `docs/protocollen/api-design-protocol.md`
- `docs/protocollen/frontend-protocol.md`
- `docs/protocollen/bouwsessie-protocol.md`
- `docs/protocollen/test-protocol.md` (al langer bestaand, ter vergelijking)

**Audit templates:**
- `docs/audits/templates/architectuur.md` (al langer bestaand)
- `docs/audits/templates/test.md`
- `docs/audits/templates/security.md`
- `docs/audits/templates/performance.md`
- `docs/audits/templates/dependency.md`

**Audit prompts:**
- `docs/audits/prompts/architectuur.md`
- `docs/audits/prompts/test.md`
- `docs/audits/prompts/security.md`
- `docs/audits/prompts/performance.md`
- `docs/audits/prompts/dependency.md`

**Framework:**
- `docs/audits/README.md`

---

## Evaluatiecriteria

Beoordeel elk document op deze assen:

### 1. Relevantie voor dit project
- Zijn de regels en checks specifiek genoeg voor de Cyclowax Dashboard codebase, of zijn het generieke best practices die overal gelden?
- Worden de unieke risico's van dit project afgedekt? (solo developer met AI, 3 externe API's, PII van klanten, forecast engine als business-kritieke logica)
- Is er iets project-specifiek dat ontbreekt?

### 2. Proportionaliteit
- Zijn de protocollen en audits proportioneel aan de omvang en het risico van het project? (intern dashboard, 4 gebruikers, geen publieke registratie)
- Wordt er over-engineering gedaan? Regels die niet nodig zijn voor een project van deze schaal?
- Wordt er onder-engineering gedaan? Risico's die onderschat worden?

### 3. Uitvoerbaarheid
- Zijn de audits realistisch uit te voeren door Claude in een enkele sessie?
- Zijn de prompts concreet genoeg om reproduceerbare resultaten op te leveren?
- Zijn de protocollen kort genoeg om tijdens het bouwen daadwerkelijk gelezen en gevolgd te worden? Of worden ze genegeerd omdat ze te lang zijn?

### 4. Consistentie
- Zijn de protocollen onderling consistent? Spreken ze elkaar niet tegen?
- Sluiten de audit templates aan op de protocollen die ze zouden moeten toetsen?
- Is het format/stijl consistent tussen documenten?

### 5. Blinde vlekken
- Welke risico's of domeinen worden niet afgedekt?
- Zijn er aannames in de documenten die niet kloppen? (check tegen de werkelijke codebase)
- Zijn er checks die klinken als ze belangrijk zijn maar in de praktijk weinig opleveren?

### 6. Bruikbaarheid voor de AI-developer workflow
- Dit framework wordt primair door Claude gebruikt (de developer geeft de instructie, Claude voert uit). Zijn de documenten geoptimaliseerd voor die workflow?
- Zijn de prompts zelf-contained genoeg dat een nieuwe Claude-sessie ze kan oppakken zonder extra context?
- Is er overlap of duplicatie tussen documenten die verwarring kan veroorzaken?

---

## Output formaat

Structureer je review als volgt:

```markdown
# Meta-Review — Audit & Protocol Framework

## Totaalbeeld
[3-5 zinnen: werkt dit framework, wat is de overall kwaliteit, hoofdconclusie]

## Sterke punten
[Wat is goed opgezet en waarom — wees specifiek, verwijs naar documenten]

## Proportionaliteit
[Is dit te veel, te weinig, of juist goed voor een intern dashboard met 4 gebruikers?]

## Kritische bevindingen
Per bevinding:
- **Wat:** Concreet probleem
- **Waar:** Bestand + sectie
- **Waarom:** Wat is het risico als dit niet gefixt wordt
- **Suggestie:** Concrete verbetering

## Blinde vlekken
[Wat ontbreekt volledig?]

## Overbodige elementen
[Wat kan geschrapt worden zonder verlies van waarde?]

## Conflicten & inconsistenties
[Waar spreken documenten elkaar of de werkelijke codebase tegen?]

## Aanbevelingen
[Top 5 concrete acties, geprioriteerd]
```

**Belangrijk:**
- Wees direct en kritisch. De eigenaar wil eerlijke feedback, geen bevestiging.
- Check claims tegen de werkelijke codebase — als een protocol zegt "we gebruiken X", verifieer of X daadwerkelijk bestaat.
- Denk vanuit de dagelijkse workflow: wordt dit framework daadwerkelijk gebruikt of wordt het een ongelezen document?
