# Bouwsessie Protocol — Cyclowax Dashboard

Dit protocol definieert de checks aan het begin en einde van elke bouwsessie met Claude. Het doel is: nooit half werk committen, altijd weten waar je staat, en elke sessie vlot kunnen oppakken.

---

## Pre-flight (begin van de sessie)

Voer deze checks uit voordat er code geschreven wordt:

### 1. Codebase status

```bash
git status                    # Clean working tree?
git log --oneline -5          # Waar zijn we gebleven?
php artisan test --compact    # Tests groen?
```

- [ ] Working tree is clean (of bekende uncommitted changes besproken)
- [ ] Laatste commits begrijpen — context voor deze sessie
- [ ] Alle tests groen

### 2. Doel van de sessie

- [ ] Helder wat er gebouwd gaat worden
- [ ] Work mode bepaald (Begrijpen / Verkennen / Plannen / Bouwen / Evalueren)
- [ ] Bij Bouwen: plan goedgekeurd voordat code geschreven wordt

### 3. Relevante protocollen

Identificeer welke protocollen van toepassing zijn op het werk van deze sessie:

| Type werk | Protocollen |
|-----------|-------------|
| Backend service/command | Test, Security |
| API endpoint | Test, Security, API Design |
| UI component/pagina | Test, Frontend/UI |
| Sync pipeline | Test, Security, Sync Pipeline |
| Refactoring | Test |
| Audit | Audit template + prompt |

---

## Tijdens de sessie

### Bij elke commit

- [ ] `vendor/bin/pint --dirty --format agent` gedraaid (PHP formatting)
- [ ] `php artisan test --compact` groen (of minimaal de relevante tests)
- [ ] Geen credentials, `.env` waarden of PII in de diff
- [ ] Docs impact gecheckt — raakt deze wijziging `api.md`, `architectuur.md` of `styleguide.md`? Zo ja: in dezelfde commit updaten
- [ ] Conventional commit format: `feat:`, `fix:`, `refactor:`, `docs:`, `chore:`, `test:`

### Bij architectuurkeuzes

Drie verplichte vragen bij elke bouwfase (aanvulling op CLAUDE.md §2 Coaching-rol):

1. **Data model** — Hoe slaan we dit op? Past het in het bestaande schema?
2. **Security** — Wie heeft toegang? Is input gevalideerd? Zijn routes beschermd?
3. **Architectuur** — Past dit in de bestaande lagenstructuur? Hergebruiken we bestaande services?

### Bij UI werk (extra)

Volg de UI Development Workflow uit CLAUDE.md §4 — hier samengevat als checklist:

1. Meedenken (UX-gesprek)
2. Zone-beschrijving opstellen → goedkeuring
3. Componentplan maken → goedkeuring
4. Bouwen

**Nooit van Plannen naar Bouwen zonder expliciete bevestiging.**

---

## Post-flight (einde van de sessie)

### 1. Code kwaliteit

```bash
git status                        # Alles gecommit?
php artisan test --compact        # Tests nog steeds groen?
vendor/bin/pint --dirty --format agent  # Formatting clean?
```

- [ ] Geen uncommitted changes (tenzij bewust work-in-progress)
- [ ] Alle tests groen
- [ ] Formatting clean

### 2. Documentatie

- [ ] `docs/architectuur/api.md` bijgewerkt als er API wijzigingen zijn
- [ ] `docs/architectuur/architectuur.md` bijgewerkt als er architectuur wijzigingen zijn
- [ ] `docs/architectuur/styleguide.md` bijgewerkt als er UI wijzigingen zijn

### 3. Sessie-afsluiting

Geef de standaard handoff:

```
Gebouwd: [wat er staat]
Open: [wat nog niet af is of besloten moet worden]
Volgende stap: [concrete eerste actie voor de volgende sessie]
```

### 4. Technische schuld check

- [ ] Geen TODO comments achtergelaten in code
- [ ] Geen dode code gecommit
- [ ] Geen bekende security issues open gelaten
- [ ] Als er technische schuld bewust is geaccepteerd: benoemd en vastgelegd

---

## Work-in-progress regels

Soms is een sessie niet lang genoeg om iets af te ronden.

### Mag op main (half af maar veilig)

- Backend service zonder UI (functioneert, getest, maar UI volgt later)
- Nieuwe tests (meer dekking is altijd veilig)
- Documentatie updates

### Moet op feature branch

- Half-afgebouwde UI pagina's die de app breken
- Migraties die het schema wijzigen zonder dat de code erop is aangepast
- Experimentele aanpakken die mogelijk teruggedraaid worden

### Nooit committen

- Code met bekende security issues
- Broken tests
- Hardcoded credentials
- `console.log` of debug output
