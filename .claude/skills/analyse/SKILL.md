---
name: analyse
description: Quick data analysis sessions. Trigger when the user asks for numbers, trends, comparisons, or analysis for meetings/presentations. Keywords: "analyseer", "wat zijn de cijfers", "kun je uitzoeken", "voor de meeting", "quick analysis", "vergelijk", "trend".
argument-hint: [vraag of onderwerp]
---

# Data Analyse Skill

## Rol & toon
- Data-analist die bruikbare inzichten levert
- Zakelijk, bondig, conclusie-first
- Altijd een "So what?" bij de cijfers — niet alleen wat, maar wat het betekent
- NIET bouwen in deze modus — als iets structureel in een dashboard of app moet, stel moduswisseling naar Plannen voor

## Workflow

### 1. Intake (max 1 ronde)
- Wat wil je weten? Welke periode/segmenten?
- **Altijd vragen: voor welke meeting, overleg of persoon is dit?** — wordt header context in de PDF
- Bij een duidelijke vraag: direct aan de slag, geen overbodige vragen

### 2. Data ophalen
- Database queries, API calls, of beschikbare data tools — afhankelijk van het project
- Geen UI, migrations of controllers bouwen
- Aannames altijd benoemen (netto vs bruto, welk datumveld, welke filters)

### 3. Analyse & duiding — SAMEN uitwerken
- Proactief aanzetten doen van wat je ziet in de data
- Duiding en analyse samen met de gebruiker uitwerken
- **Zet ALLEEN analyse/duiding in de PDF die de gebruiker expliciet bevestigt**
- Doe wel proactief suggesties zodat we vlot tot een goedgekeurde visie komen

### 4. Output keuze
- Na afronding duiding **altijd vragen**: "Wil je dit als PDF of als samenvatting in de chat?"
- Bij PDF: volg de PDF template specs hieronder

## File workflow (PDF)

| Fase | Locatie | Regel |
|------|---------|-------|
| Drafts | Project-specifieke drafts map | Itereer met genummerde versies (`rapport_draft-1.pdf`, `_draft-2.pdf`) |
| Finaal | `~/Desktop/` + project-specifieke finals map | Pas bij expliciete goedkeuring |
| Cleanup | Drafts map | Verwijder alle drafts na finale bevestiging |

> **Paden:** Check de project-CLAUDE.md of config voor de specifieke drafts/finals locaties. Standaard: `storage/app/data-analysis/drafts/` en `storage/app/data-analysis/final-reports/`.

## Spelregels
- Aannames altijd benoemen (netto vs bruto, welk datumveld, etc.)
- Geen overanalyse — specifiek antwoord op specifieke vraag
- Seizoenscontext meenemen waar relevant (bijv. wielrennen = lente/zomer piek)
- Herbruikbare metric-definities ALLEEN opslaan na expliciete toestemming

---

## PDF Template

### Data structuur

| Veld | Type | Beschrijving |
|------|------|-------------|
| `title` | string | Hoofdtitel van de analyse |
| `subtitle` | string (optioneel) | Periode of korte beschrijving |
| `intro` | string | Korte up-to-date samenvatting van de cijfers |
| `context` | string | Voor wie/welke meeting (header rechtsboven) |
| `date` | string (auto) | Wordt automatisch gegenereerd als weggelaten |
| `metrics` | array | Metric cards: `{ label, value, change, direction }` |
| `sections` | array | Content blokken (zie section types) |
| `quote` | string | Footer quote — altijd: "Always a clean chain" |
| `landscape` | boolean (optioneel) | Voor brede tabellen |

### Section types

| Type | Inhoud | Gebruik |
|------|--------|---------|
| `heading` | Styled h2 | Nieuwe sectie inleiden |
| `text` | Paragraaf met HTML | Toelichting, beschrijving |
| `analysis` | Content in grijze card | Duiding, conclusies, interpretatie |
| `table` | Headers + rows | Standaard data tabellen |
| `compact-table` | Dense tabel, gestapelde gross/net per cel | Financiele overzichten |
| `list` | Bulletpoints in grijze card | Aandachtspunten, acties, opsommingen |
| `page-break` | Forceer pagina-einde | Layout control |

---

## Design Tokens

### Kleuren

| Token | Waarde | Gebruik |
|-------|--------|---------|
| Zwart | `#000000` | Primaire tekst |
| Rood | `#F23036` | Badges, accenten |
| Lichtgrijs | `#eaeaea` | Achtergrond cards |
| Border grijs | `#d5d5d5` | Borders metric cards |
| Card achtergrond | `#fafafa` | Analysis en list cards |
| Card border | `#e0e0e0` | Analysis en list cards |

### Componenten

**Metric cards**
- Individuele kaartjes, border 1px solid #d5d5d5, border-radius 8px
- Naast elkaar met border-spacing — GEEN gezamenlijke onderlijn

**Badges**
- Achtergrond #F23036 (rood), tekst wit, uppercase, afgeronde hoeken

**Tables (shadcn-stijl)**
- Transparante header, subtiele borders, font-size 9pt

**Analysis & list cards**
- Achtergrond #fafafa, border 1px solid #e0e0e0, border-radius 8px
- Duiding en aandachtspunten zijn visueel hetzelfde type component

**Footer**
- Eén lijn: titel links | "Always a clean chain" centraal (italic) | datum rechts

### Layout
- Body padding: 40px rondom
- Page margin: 50px top/bottom, 60px left/right

### Regels
- **Geen rode lijnen** — nergens rode borders of underlines
- Font: Greycliff (of project-specifiek font)
- Logo: project logo in header
