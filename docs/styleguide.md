# Styleguide

## Theme
- **Base:** ShadCN UI (New York style)
- **Theme:** tweakcn Lara (`cmm2769u2000004jobride6q4`)
- **Color system:** OKLCH via CSS custom properties
- **Dark mode:** Supported via `@custom-variant dark`

## Color tokens

### Chart colors
Gebruik de `--chart-1` t/m `--chart-5` CSS variabelen voor data visualisatie:

```tsx
const chartColors = {
    primary: 'var(--color-chart-1)',    // Primaire lijn/bar
    secondary: 'var(--color-chart-2)',  // Secundaire lijn/bar
    tertiary: 'var(--color-chart-3)',   // Area charts, tertiaire data
};
```

### Status colors
- Groei/positief: `text-emerald-600 dark:text-emerald-400`
- Krimp/negatief: `text-red-600 dark:text-red-400`
- Neutraal: `text-muted-foreground`

## Dashboard componenten

### `KpiCard`
KPI metric met waarde en delta indicator.
```tsx
<KpiCard title="Omzet" value="€ 242.525" change={12.5} changeLabel="vs vorige periode" />
```

### `ChartCard`
Wrapper voor grafieken met titel, beschrijving en loading state.
```tsx
<ChartCard title="Titel" description="Uitleg" loading={!data}>
    {data && <MyChart data={data} />}
</ChartCard>
```

### `PeriodSelector`
MTD / QTD / YTD toggle. Navigeert via Inertia router met `preserveState`.

### `CohortHeatmap`
Tabel met kleurgecodeerde retentiepercentages. Kleurschaal van `bg-emerald-100` (laag) tot `bg-emerald-600` (hoog).

### `RegionPerformance`
Regio tabel met sparklines, 6-maanden absolute aantallen, en gemiddelde MoM growth badge. Overige regio's (<20 klanten) in expandable sectie.

### `TimeToSecondOrderChart`
Cumulatieve area chart met milestone badges bovenaan, mediaan referentielijn, en 25/50/75% referentielijnen.

### `GrowthBadge`
Pill-shaped badge voor groeipercentages. Groen voor positief, rood voor negatief.

## Formatting conventies

### Valuta
```tsx
new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', minimumFractionDigits: 0 }).format(value)
```

### Getallen
```tsx
new Intl.NumberFormat('nl-NL').format(value)
```

### Maandnamen in tabellen
Nederlandse afkortingen in capitals: `JAN`, `FEB`, `MRT`, `APR`, `MEI`, `JUN`, `JUL`, `AUG`, `SEP`, `OKT`, `NOV`, `DEC`

## Layout

### Dashboard
- Sidebar links (ShadCN collapsible, icon variant)
- Content area rechts met `p-4 md:p-6` padding
- Zones gescheiden door `h2` section headers
- Grid: `lg:grid-cols-2` voor naast-elkaar charts, full width voor tabellen

### Responsive
- Mobile-first: single column op small screens
- KPI header: `sm:grid-cols-2 lg:grid-cols-4`
- Chart grids: `lg:grid-cols-2`
- Tabellen: `overflow-x-auto` wrapper

## UI Componenten (shadcn/ui)

Beschikbare componenten in `resources/js/components/ui/`:

### Data display
- `data-table` — sorteerbare, pagineerbare tabel (tanstack/react-table)
- `table` — basis HTML tabel wrapper
- `badge` — status labels en tags
- `card` — content container
- `skeleton` — loading placeholder

### Forms & Input
- `input` — tekst input
- `select` — dropdown select
- `checkbox` — checkbox
- `label` — form label
- `calendar` — datumkiezer kalender
- `date-picker` — datumkiezer met popover
- `command` — search/combobox (cmdk)

### Navigation & Layout
- `tabs` — tab navigatie
- `sidebar` — collapsible sidebar
- `breadcrumb` — breadcrumb navigatie
- `navigation-menu` — nav menu
- `collapsible` — inklapbare sectie
- `separator` — visuele scheiding

### Feedback & Overlay
- `dialog` — modale dialoog
- `sheet` — slide-over panel
- `popover` — floating content
- `tooltip` — hover tooltip
- `dropdown-menu` — context menu
- `sonner` — toast notificaties
- `alert` — inline waarschuwing
- `spinner` — loading indicator

## Error handling

### Error boundary
Globale `ErrorBoundary` in `app.tsx` vangt React render crashes op. Toont een reload-pagina met foutmelding.

### Error pages
- `pages/errors/404.tsx` — pagina niet gevonden
- `pages/errors/500.tsx` — server fout

### API errors
API responses volgen een consistente error envelope:
```json
{
    "error": {
        "message": "Beschrijving",
        "code": "validation",
        "fields": { "field": ["foutmelding"] }
    }
}
```

TypeScript type: `ApiError` in `types/api.ts`.

### Toast notificaties
Gebruik `sonner` voor gebruikersfeedback:
```tsx
import { toast } from 'sonner';
toast.success('Opgeslagen');
toast.error('Er ging iets mis');
```

## TypeScript types

API model types in `resources/js/types/api.ts`:
- `Customer`, `Order`, `LineItem`, `Product` — Shopify models
- `Scenario`, `ScenarioAssumption`, `ScenarioProductMix` — Forecast models
- `ForecastData`, `ForecastMonth` — Forecast output
- `PurchaseCalendarRun`, `PurchaseCalendarEvent` — Supply planning
- `SyncStep` — Sync pipeline status
- `ApiError` — Error envelope
- `PaginatedResponse<T>` — Gepagineerde API response

## Iconen
- Library: Lucide React
- Sidebar logo: Custom SVG wiel-icoon (cirkel met spaken)
- App naam: "Cyclowax Dashboard"
