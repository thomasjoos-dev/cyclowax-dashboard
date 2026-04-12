const currencyFormatter = new Intl.NumberFormat('nl-NL', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 0,
});

const numberFormatter = new Intl.NumberFormat('nl-NL');

const percentFormatter = new Intl.NumberFormat('nl-NL', {
    style: 'percent',
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

export function formatCurrency(value: number): string {
    return currencyFormatter.format(value);
}

export function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

export function formatPercent(value: number): string {
    return percentFormatter.format(value);
}
