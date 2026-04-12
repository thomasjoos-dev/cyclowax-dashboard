# Frontend Protocol — Cyclowax Dashboard

Dit protocol definieert de conventies voor het bouwen van de React/Inertia frontend. Volg dit protocol bij elke UI-wijziging. Het doel is consistentie en kwaliteit vanaf het begin — vóór de grote dashboard UI build-out.

> **Status:** De frontend is momenteel minimaal (1 pagina). Dit protocol is forward-looking en definieert de conventies voor de aankomende dashboard UI build-out. Audit dit protocol pas na de eerste UI-sprint (≥ 3 pagina's).

---

## 1. Stack & Tooling

### Technologie

- **Framework:** React 19 + Inertia.js v2
- **Styling:** Tailwind CSS 4 + shadcn/ui (New York style)
- **Design tokens:** OKLCH kleurensysteem
- **Routing:** Wayfinder (TypeScript route generation vanuit Laravel)
- **Type safety:** TypeScript strict mode
- **Formatting:** Prettier + ESLint

### Bestanden

```
resources/js/
├── pages/              # Inertia pagina-componenten (1:1 met routes)
├── components/
│   ├── ui/             # shadcn/ui primitives (niet handmatig wijzigen)
│   └── [domein]/       # Dashboard-specifieke componenten
├── layouts/            # Layout wrappers (app, auth, settings)
├── hooks/              # Custom React hooks
├── lib/                # Utility functies
├── types/              # TypeScript type definities
├── actions/            # Wayfinder-generated controller routes
└── routes/             # Wayfinder-generated named routes
```

---

## 2. Component Conventies

### Naamgeving

- **Pages:** PascalCase, matchen met route — `pages/dashboard.tsx`, `pages/scenarios/index.tsx`
- **Components:** PascalCase — `KpiCard.tsx`, `CohortHeatmap.tsx`
- **Hooks:** camelCase met `use` prefix — `useForecasetData.ts`
- **Types:** PascalCase met beschrijvende naam — `ScenarioResponse`, `RevenueMetrics`

### Component structuur

```tsx
// 1. Imports (React, libraries, components, types)
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RevenueMetrics } from '@/types/api';

// 2. Props interface (als het component props ontvangt)
interface KpiCardProps {
    title: string;
    value: number;
    trend?: number;
    format?: 'currency' | 'percentage' | 'number';
}

// 3. Component (named export, niet default)
export function KpiCard({ title, value, trend, format = 'number' }: KpiCardProps) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {formatValue(value, format)}
            </CardContent>
        </Card>
    );
}

// 4. Helper functies (private, na het component)
function formatValue(value: number, format: string): string {
    // ...
}
```

### Regels

- **Named exports** — geen `export default` (behalve Inertia pages die dit vereisen)
- **Props via interface** — geen inline types, geen `any`
- **Geen business logica in componenten** — data transformatie in hooks of utilities
- **Componenten < 150 regels** — split als het groter wordt
- **Geen prop drilling > 2 niveaus** — gebruik composition of context

---

## 3. Inertia Patterns

### Pages

```tsx
import AppLayout from '@/layouts/app-layout';
import type { RevenueData } from '@/types/api';

interface Props {
    revenue: RevenueData;
    filters: {
        since: string;
        region?: string;
    };
}

export default function RevenuePage({ revenue, filters }: Props) {
    return (
        <AppLayout>
            {/* page content */}
        </AppLayout>
    );
}
```

### Deferred props met skeleton

Gebruik Inertia v2 deferred props voor data die niet direct nodig is:

```tsx
import { Deferred } from '@inertiajs/react';
import { Skeleton } from '@/components/ui/skeleton';

export default function DashboardPage({ kpis, ...props }: Props) {
    return (
        <AppLayout>
            <KpiGrid data={kpis} />

            <Deferred data="cohortData" fallback={<CohortSkeleton />}>
                <CohortHeatmap data={props.cohortData} />
            </Deferred>
        </AppLayout>
    );
}

function CohortSkeleton() {
    return (
        <Card>
            <CardHeader><Skeleton className="h-6 w-48" /></CardHeader>
            <CardContent><Skeleton className="h-64 w-full" /></CardContent>
        </Card>
    );
}
```

### Forms met useForm

```tsx
import { useForm } from '@inertiajs/react';
import store from '@/actions/App/Http/Controllers/Api/V1/ScenarioController';

function CreateScenarioForm() {
    const form = useForm({
        name: '',
        growth_rate: 0.15,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.submit(store());
    }

    return (
        <form onSubmit={handleSubmit}>
            {/* form fields */}
        </form>
    );
}
```

### Navigation met Wayfinder

```tsx
import { Link } from '@inertiajs/react';
import show from '@/actions/App/Http/Controllers/Api/V1/ScenarioController';

// In component
<Link href={show({ scenario: scenario.id })}>
    {scenario.name}
</Link>
```

---

## 4. Styling

### Tailwind conventies

- **Mobile-first** — schrijf base styles voor mobile, voeg breakpoints toe voor desktop
- **Geen custom CSS** — alles via Tailwind utilities
- **Geen `style` prop** — gebruik Tailwind classes
- **`cn()` utility voor conditional classes** — geen string concatenatie

```tsx
import { cn } from '@/lib/utils';

<div className={cn(
    'rounded-lg border p-4',
    isActive && 'border-primary bg-primary/5',
    isDisabled && 'opacity-50 cursor-not-allowed',
)} />
```

### shadcn/ui gebruik

- **Gebruik bestaande shadcn componenten** — check `components/ui/` voordat je iets custom bouwt
- **Niet handmatig wijzigen** — shadcn bestanden in `ui/` worden gegenereerd
- **Extend via composition** — wrap een shadcn component, wijzig het niet

```tsx
// Goed — wrap met eigen component
function MetricCard({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <Card>
            <CardHeader><CardTitle className="text-sm font-medium">{title}</CardTitle></CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

// Fout — shadcn Card.tsx aanpassen
```

### Responsive breakpoints

| Breakpoint | Gebruik |
|------------|---------|
| default | Mobile (320px+) |
| `sm:` | Tablet portrait (640px+) |
| `md:` | Tablet landscape (768px+) |
| `lg:` | Desktop (1024px+) |
| `xl:` | Wide desktop (1280px+) |

### Dashboard grid patroon

```tsx
// KPI cards — 1 kolom mobile, 2 tablet, 4 desktop
<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <KpiCard title="Revenue" value={revenue} />
    <KpiCard title="Orders" value={orders} />
    <KpiCard title="AOV" value={aov} />
    <KpiCard title="New Customers" value={newCustomers} />
</div>

// Charts — 1 kolom mobile, 2 desktop
<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <RevenueChart data={revenueData} />
    <AcquisitionChart data={acquisitionData} />
</div>
```

---

## 5. TypeScript Types

### API response types

Alle API response types staan in `resources/js/types/api.ts`. Houd dit bestand in sync met de backend API Resources.

```typescript
export interface Order {
    id: number;
    shopify_order_id: string;
    total_price: number;
    net_revenue: number;
    gross_margin: number;
    country: string;
    channel_type: ChannelType;
    created_at: string;
}

export type ChannelType = 'organic_search' | 'paid_search' | 'paid_social' | 'direct' | 'email';
```

### Regels

- **Geen `any`** — gebruik `unknown` als het type onbekend is, of definieer een interface
- **Geen type assertions (`as`)** — behalve bij Inertia `usePage<T>()`
- **Enum-achtige types als union types** — `type Status = 'active' | 'inactive'`
- **Optionele velden met `?`** — niet met `| undefined`

---

## 6. Data Formatting

### Conventies

| Type | Format | Voorbeeld | Hoe |
|------|--------|-----------|-----|
| Bedragen | EUR, 2 decimalen, punt als duizendtallen | €1.234,56 | `Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' })` |
| Percentages | 1 decimaal, % suffix | 12,3% | `Intl.NumberFormat('nl-NL', { style: 'percent', maximumFractionDigits: 1 })` |
| Aantallen | Punt als duizendtallen | 1.234 | `Intl.NumberFormat('nl-NL')` |
| Datums | `d MMM yyyy` | 12 apr 2026 | `Intl.DateTimeFormat('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' })` |
| Maanden | `MMM yyyy` | apr 2026 | `Intl.DateTimeFormat('nl-NL', { month: 'short', year: 'numeric' })` |

### Formatting utilities

Centraliseer formatting in `resources/js/lib/formatters.ts`:

```typescript
export function formatCurrency(value: number): string {
    return new Intl.NumberFormat('nl-NL', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
}

export function formatPercent(value: number): string {
    return new Intl.NumberFormat('nl-NL', {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(value);
}
```

---

## 7. States

Elk component dat data toont MOET deze states afhandelen:

### Loading

```tsx
<Skeleton className="h-8 w-24" />
```

### Empty

```tsx
<div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
    <p>Geen data beschikbaar voor deze periode.</p>
</div>
```

### Error

```tsx
<Alert variant="destructive">
    <AlertTitle>Laden mislukt</AlertTitle>
    <AlertDescription>Probeer het opnieuw of neem contact op.</AlertDescription>
</Alert>
```

### Regels

- **Geen lege schermen** — altijd feedback aan de gebruiker
- **Loading state bij deferred props** — gebruik Skeleton, geen spinner
- **Error state met actie** — vertel de gebruiker wat ze kunnen doen
- **Empty state met context** — leg uit waarom er geen data is

---

## 8. Accessibility

### Minimale vereisten

- **Alle interactieve elementen focusbaar** — tab-navigatie moet werken
- **Labels op form inputs** — gebruik `<Label htmlFor="">` van shadcn
- **Alt text op afbeeldingen** — beschrijvend, niet decoratief
- **Kleurcontrast** — minimaal WCAG AA (4.5:1 voor tekst)
- **Geen informatie alleen via kleur** — combineer met iconen of tekst

### shadcn doet het meeste

shadcn/ui componenten zijn standaard accessible (ARIA roles, keyboard navigation). Zolang je ze correct gebruikt, voldoe je aan de basis. Let extra op bij:

- Custom dropdowns of popovers — gebruik shadcn `Popover`, niet custom
- Data tables — gebruik shadcn `Table` met correcte `<th scope>`
- Charts — voeg een tekst-samenvatting toe naast de visuele chart
- Toast/notifications — shadcn `Sonner` is al ARIA-compatible

---

## 9. Performance

### Regels

- **Geen onnodige re-renders** — gebruik `useMemo`/`useCallback` alleen bij meetbare performance issues
- **Lazy load zware componenten** — charts, heatmaps etc. via `React.lazy()` als ze below-the-fold zijn
- **Deferred props voor secundaire data** — primaire KPI's direct laden, detail data deferred
- **Geen grote bundles** — controleer met `npm run build` of er geen onverwacht grote chunks zijn

---

## 10. Checklist bij UI wijzigingen

- [ ] Component volgt naamgevingsconventies
- [ ] Props via TypeScript interface (geen `any`)
- [ ] Mobile-first responsive (getest op 320px)
- [ ] Loading, empty en error states aanwezig
- [ ] shadcn/ui componenten gebruikt waar mogelijk
- [ ] Data formatting via centrale utilities
- [ ] Accessibility basics (focusbaar, labels, contrast)
- [ ] Dark mode werkt (als het project dark mode ondersteunt)
- [ ] Dev server gestart en feature handmatig getest in browser
- [ ] Geen `console.log` of debug code achtergelaten
