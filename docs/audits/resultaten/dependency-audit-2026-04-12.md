# Dependency Audit Rapport — 2026-04-12

> Eerste dependency audit voor het Cyclowax Dashboard. Geen vorige audit om mee te vergelijken.

---

## Security Status

| Platform   | Critical | High | Medium | Low |
|------------|----------|------|--------|-----|
| PHP        | 0        | 0    | 0      | 0   |
| JavaScript | 1        | 3    | 1      | 0   |

**PHP:** `composer audit` — geen bekende kwetsbaarheden gevonden.

**JavaScript:** `npm audit` — 5 kwetsbare packages gevonden (details in D1).

---

## D1. Security Vulnerabilities

### PHP

Geen vulnerabilities gevonden.

### JavaScript

| Severity | Package | Versie | Advisory | Fix beschikbaar |
|----------|---------|--------|----------|-----------------|
| **Critical** | axios | <=1.14.0 | [GHSA-3p68-rc4w-qgx5](https://github.com/advisories/GHSA-3p68-rc4w-qgx5) — NO_PROXY Hostname Normalization Bypass (SSRF) | Ja |
| **Critical** | axios | <=1.14.0 | [GHSA-fvcv-3m26-pcqx](https://github.com/advisories/GHSA-fvcv-3m26-pcqx) — Cloud Metadata Exfiltration via Header Injection | Ja |
| **High** | lodash-es | <=4.17.23 | [GHSA-r5fr-rjxr-66jc](https://github.com/advisories/GHSA-r5fr-rjxr-66jc) — Code Injection via `_.template` | Ja |
| **High** | lodash-es | <=4.17.23 | [GHSA-f23m-r3pf-42rh](https://github.com/advisories/GHSA-f23m-r3pf-42rh) — Prototype Pollution via `_.unset`/`_.omit` | Ja |
| **High** | picomatch | <=2.3.1 | [GHSA-3v7f-55p6-f55p](https://github.com/advisories/GHSA-3v7f-55p6-f55p) — Method Injection in POSIX Character Classes | Ja |
| **High** | picomatch | <=2.3.1 | [GHSA-c2c7-rcm5-vvqj](https://github.com/advisories/GHSA-c2c7-rcm5-vvqj) — ReDoS via extglob quantifiers | Ja |
| **High** | vite | 8.0.0–8.0.4 | [GHSA-4w7w-66w2-5vf9](https://github.com/advisories/GHSA-4w7w-66w2-5vf9) — Path Traversal in Optimized Deps | Ja |
| **High** | vite | 8.0.0–8.0.4 | [GHSA-v2wj-q39q-566r](https://github.com/advisories/GHSA-v2wj-q39q-566r) — `server.fs.deny` bypass with queries | Ja |
| **High** | vite | 8.0.0–8.0.4 | [GHSA-p9ff-h696-f583](https://github.com/advisories/GHSA-p9ff-h696-f583) — Arbitrary File Read via WebSocket | Ja |
| **Moderate** | brace-expansion | <1.1.13 | [GHSA-f886-m6hf-6m8v](https://github.com/advisories/GHSA-f886-m6hf-6m8v) — Zero-step sequence hang/memory exhaustion | Ja |

---

## D2. Outdated Packages — PHP

| Package | Huidig | Laatst | Type update | Actie |
|---------|--------|--------|-------------|-------|
| **inertiajs/inertia-laravel** | 2.0.22 | 3.0.6 | **Major** | Evalueren — v3 bevat breaking changes, migratie guide nodig |
| **laravel/framework** | 13.1.1 | 13.4.0 | Minor/Patch | Upgraden — geen breaking changes verwacht |
| **laravel/boost** | 2.3.4 | 2.4.3 | Minor | Upgraden — tooling improvement |
| **laravel/fortify** | 1.36.1 | 1.36.2 | Patch | Upgraden — bugfix |
| **laravel/sail** | 1.54.0 | 1.56.0 | Minor | Upgraden — dev tooling |
| **laravel/wayfinder** | 0.1.14 | 0.1.16 | Patch | Upgraden — bugfixes |
| **nunomaduro/collision** | 8.9.1 | 8.9.3 | Patch | Upgraden — dev tooling |
| **pestphp/pest** | 4.4.3 | 4.5.0 | Minor | Upgraden — test framework |

---

## D3. Outdated Packages — JavaScript

| Package | Huidig | Laatst | Type update | Actie |
|---------|--------|--------|-------------|-------|
| **vite** | 8.0.1 | 8.0.8 | Patch | **Direct upgraden** — bevat security fixes |
| **react** | 19.2.4 | 19.2.5 | Patch | Upgraden |
| **react-dom** | 19.2.4 | 19.2.5 | Patch | Upgraden |
| **@inertiajs/react** | 2.3.18 | 3.0.3 (wanted: 2.3.21) | **Major** (latest) / Patch (wanted) | Patch naar 2.3.21; major v3 evalueren samen met server-side |
| **@headlessui/react** | 2.2.9 | 2.2.10 | Patch | Upgraden |
| **laravel-vite-plugin** | 3.0.0 | 3.0.1 | Patch | Upgraden |
| **recharts** | 3.8.0 | 3.8.1 | Patch | Upgraden |
| **prettier** | 3.8.1 | 3.8.2 | Patch | Upgraden |
| **typescript-eslint** | 8.57.1 | 8.58.1 | Minor | Upgraden |
| **@types/node** | 22.19.15 | 25.6.0 (wanted: 22.19.17) | **Major** (latest) / Patch (wanted) | Patch naar 22.19.17; major v25 evalueren |
| **lucide-react** | 0.475.0 | 1.8.0 | **Major** | Evalueren — icon library, mogelijk breaking imports |
| **@vitejs/plugin-react** | 5.2.0 | 6.0.1 | **Major** | Evalueren — afhankelijk van Vite compatibiliteit |
| **eslint** | 9.39.4 | 10.2.0 | **Major** | Evalueren — flat config changes |
| **@eslint/js** | 9.39.4 | 10.0.1 | **Major** | Samen met eslint evalueren |
| **globals** | 15.15.0 | 17.5.0 | **Major** | Samen met eslint evalueren |
| **typescript** | 5.9.3 | 6.0.2 | **Major** | Evalueren — TS 6 bevat waarschijnlijk breaking changes |
| **prettier-plugin-tailwindcss** | 0.6.14 | 0.7.2 | Minor | Evalueren — plugin compat check |

---

## D4. Breaking Changes Risico

### Inertia v2 naar v3 (PHP + JS)

- **Server:** `inertiajs/inertia-laravel` 2.0.22 -> 3.0.6
- **Client:** `@inertiajs/react` 2.3.18 -> 3.0.3
- **Risico:** Hoog. Server en client moeten samen geupgrade worden. Migratie guide vereist.
- **Aanbeveling:** Eerst migratie guide lezen, apart sprint-item plannen.

### ESLint 9 naar 10

- **Impact:** Flat config format wijzigingen mogelijk.
- **Risico:** Medium. Dev-only dependency.
- **Aanbeveling:** Samen met `@eslint/js` en `globals` upgraden.

### TypeScript 5 naar 6

- **Impact:** Mogelijke stricter type checking, nieuwe compiler opties.
- **Risico:** Medium. Kan type errors opleveren in bestaande code.
- **Aanbeveling:** Evalueren wanneer TS 6 stabiel is, apart testen.

### Lucide React 0.x naar 1.x

- **Impact:** Icon import paden en naamgeving mogelijk gewijzigd.
- **Risico:** Laag-Medium. Veel icons in gebruik, maar fixes zijn mechanisch.
- **Aanbeveling:** Changelog checken, batch find-and-replace.

### @vitejs/plugin-react 5 naar 6

- **Impact:** Mogelijk nieuwe Vite versie-eis.
- **Risico:** Laag. Dev-only dependency.
- **Aanbeveling:** Na vite patch upgrade evalueren.

---

## D5. Unused Dependencies

Resultaat van `npx depcheck`:

### Unused dependencies (productie)

| Package | Opmerking |
|---------|-----------|
| `concurrently` | Mogelijk alleen in npm scripts gebruikt — depcheck detecteert dat niet altijd |
| `tailwindcss` | False positive — v4 gebruikt PostCSS/Vite plugin, geen directe import |
| `tw-animate-css` | Controleren of dit daadwerkelijk in Tailwind config gebruikt wordt |

### Unused devDependencies

| Package | Opmerking |
|---------|-----------|
| `babel-plugin-react-compiler` | React Compiler plugin — mogelijk experimenteel toegevoegd, niet actief |
| `eslint-import-resolver-typescript` | Controleren of ESLint config dit nog referenceert |
| `prettier-plugin-tailwindcss` | False positive — wordt via prettier config geladen |

> **Let op:** depcheck geeft regelmatig false positives voor packages die via config files (niet imports) worden geladen. Handmatige verificatie nodig voor `tw-animate-css`, `babel-plugin-react-compiler` en `eslint-import-resolver-typescript`.

---

## Directe Acties

> Critical/High vulnerabilities die nu opgelost moeten worden.

### 1. Vite upgraden naar 8.0.8 (HOOG — 3 vulnerabilities)

```bash
npm install vite@8.0.8
```

Lost op: Path Traversal, `server.fs.deny` bypass, Arbitrary File Read via WebSocket. Dit zijn dev-server vulnerabilities, maar relevant voor lokale development.

### 2. Axios upgraden (CRITICAL — SSRF + Header Injection)

```bash
npm audit fix
```

Of handmatig: controleer of axios een directe of transitieve dependency is en upgrade naar >1.14.0.

### 3. lodash-es upgraden (HOOG — Prototype Pollution + Code Injection)

Controleer of `lodash-es` een directe dependency is. Zo niet, `npm audit fix` lost dit op via transitive updates.

### 4. picomatch upgraden (HOOG — ReDoS + Method Injection)

Wordt opgelost door vite upgrade naar 8.0.8 (picomatch is een vite dependency).

---

## Geplande Updates

> Medium vulnerabilities + outdated packages — volgende sprint.

### PHP batch upgrade

```bash
composer update laravel/framework laravel/boost laravel/fortify laravel/sail laravel/wayfinder nunomaduro/collision pestphp/pest
```

Allemaal minor/patch updates, geen breaking changes verwacht. Na upgrade tests draaien met `php artisan test --compact`.

### JavaScript batch upgrade (patch/minor)

```bash
npm install react@19.2.5 react-dom@19.2.5 @inertiajs/react@2.3.21 @headlessui/react@2.2.10 laravel-vite-plugin@3.0.1 recharts@3.8.1 prettier@3.8.2 typescript-eslint@8.58.1 @types/node@22.19.17
```

### brace-expansion (Moderate)

Wordt opgelost via `npm audit fix` of door update van parent packages.

---

## Notities

### Major upgrades — apart plannen

| Upgrade | Prioriteit | Reden |
|---------|-----------|-------|
| Inertia v3 (PHP + JS) | Hoog | Ecosystem-brede upgrade, breaking changes |
| Lucide React 1.x | Medium | Icon library, mechanische fixes |
| ESLint 10 + plugins | Laag | Dev-only, geen impact op productie |
| TypeScript 6 | Laag | Wachten op ecosysteem stabiliteit |
| @vitejs/plugin-react 6 | Laag | Na Vite compat check |

### Unused dependencies — opruimen

- `babel-plugin-react-compiler`: als React Compiler niet actief gebruikt wordt, verwijderen
- `eslint-import-resolver-typescript`: ESLint config controleren
- `tw-animate-css`: Tailwind config controleren, verwijderen als niet gebruikt
- `concurrently` en `prettier-plugin-tailwindcss`: waarschijnlijk false positives, verifieer via config/scripts
