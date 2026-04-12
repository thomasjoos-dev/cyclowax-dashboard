# Template — Dependency Audit

> **Hoe gebruiken:** Draai maandelijks of wanneer getriggerd door CI. De meeste checks zijn geautomatiseerd — deze template documenteert wat handmatig gereviewd moet worden. Resultaat opslaan als `docs/audits/resultaten/dependency-audit-YYYY-MM-DD.md`.

---

## Doel

Inventariseer bekende kwetsbaarheden, outdated packages en breaking changes in dependencies. Dit is grotendeels een geautomatiseerde audit — het handmatige deel is de risicobeoordeling en upgrade-beslissing.

---

## Geautomatiseerde Checks

```bash
# PHP security vulnerabilities
composer audit

# JavaScript security vulnerabilities
npm audit

# Outdated PHP packages
composer outdated --direct

# Outdated JavaScript packages
npm outdated
```

---

## Dimensies

### D1. Security Vulnerabilities

- [ ] **composer audit** — resultaat opnemen. Per vulnerability: severity, package, advisory URL
- [ ] **npm audit** — resultaat opnemen. Per vulnerability: severity, package, advisory URL
- [ ] **Actie per vulnerability:**
  - Critical/High: **direct upgraden**, tenzij breaking change → plan maken
  - Medium: upgraden in volgende sprint
  - Low: noteren, upgraden bij gelegenheid

### D2. Outdated Packages — PHP

Focus op packages die security- of functionaliteit-kritiek zijn:

| Package | Huidig | Laatst | Type update | Actie |
|---------|--------|--------|-------------|-------|
| laravel/framework | | | major/minor/patch | |
| laravel/sanctum | | | | |
| laravel/fortify | | | | |
| inertiajs/inertia-laravel | | | | |
| pestphp/pest | | | | |

### D3. Outdated Packages — JavaScript

| Package | Huidig | Laatst | Type update | Actie |
|---------|--------|--------|-------------|-------|
| react | | | | |
| @inertiajs/react | | | | |
| tailwindcss | | | | |
| typescript | | | | |

### D4. Breaking Changes Risico

Bij major version updates: check changelogs voor breaking changes die impact hebben op dit project.

- [ ] **Laravel** — migratie guide gelezen?
- [ ] **React** — breaking changes impact op bestaande componenten?
- [ ] **Inertia** — v1 → v2 patronen allemaal bijgewerkt?
- [ ] **Tailwind** — config format wijzigingen?

### D5. Unused Dependencies

```bash
# PHP — check of packages daadwerkelijk gebruikt worden
# (handmatig: grep voor namespace/class usage per package)

# JavaScript — check unused imports
npx depcheck
```

---

## Output Formaat

```markdown
# Dependency Audit Rapport — YYYY-MM-DD

## Security Status
| Platform | Critical | High | Medium | Low |
|----------|----------|------|--------|-----|
| PHP | | | | |
| JavaScript | | | | |

## Directe Acties
[Critical/High vulnerabilities met upgrade instructies]

## Geplande Updates
[Medium vulnerabilities + outdated packages]

## Notities
[Low priority items, unused dependencies]
```
