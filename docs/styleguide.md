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

## Iconen
- Library: Lucide React
- Sidebar logo: Custom SVG wiel-icoon (cirkel met spaken)
- App naam: "Cyclowax Dashboard"
