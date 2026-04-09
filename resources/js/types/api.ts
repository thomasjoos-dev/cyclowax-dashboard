// Generic API types

export interface ApiError {
    error: {
        message: string;
        code: string | number;
        fields?: Record<string, string[]>;
    };
}

export interface PaginatedResponse<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
    };
}

// Shopify models

export interface Customer {
    id: number;
    shopify_id: number;
    email: string;
    first_name: string | null;
    last_name: string | null;
    country_code: string | null;
    orders_count: number;
    total_spent: number;
    first_order_at: string | null;
    last_order_at: string | null;
    rfm_segment: string | null;
    gender: string | null;
}

export interface Order {
    id: number;
    shopify_id: number;
    name: string;
    ordered_at: string;
    total_price: number;
    subtotal: number;
    shipping: number;
    tax: number;
    discounts: number;
    refunded: number;
    net_revenue: number;
    currency: string;
    financial_status: string;
    fulfillment_status: string | null;
    is_first_order: boolean;
    billing_country_code: string | null;
    shipping_country_code: string | null;
    channel_type: string | null;
    refined_channel: string | null;
    customer?: Customer;
    line_items?: LineItem[];
}

export interface LineItem {
    id: number;
    product_title: string;
    product_type: string | null;
    sku: string;
    quantity: number;
    price: number;
    cost_price: number | null;
}

export interface Product {
    id: number;
    sku: string;
    name: string;
    product_type: string | null;
    product_category: string | null;
    cost_price: number | null;
    list_price: number | null;
    is_active: boolean;
    portfolio_role: string | null;
    journey_phase: string | null;
}

// Forecast models

export interface Scenario {
    id: number;
    name: string;
    label: string;
    year: number;
    description: string | null;
    is_active: boolean;
    assumptions?: ScenarioAssumption[];
    product_mixes?: ScenarioProductMix[];
}

export interface ScenarioAssumption {
    id: number;
    scenario_id: number;
    quarter: string;
    region: string | null;
    acq_rate: number;
    repeat_rate: number;
    repeat_aov: number;
}

export interface ScenarioProductMix {
    id: number;
    scenario_id: number;
    product_category: string;
    region: string | null;
    acq_share: number;
    repeat_share: number;
    avg_unit_price: number;
}

export interface ForecastMonth {
    units: number;
    revenue: number;
    acq_revenue: number;
    rep_revenue: number;
}

export interface ForecastData {
    total: {
        months: Record<number, ForecastMonth>;
        year_total: ForecastMonth;
    };
    categories: Record<number, Record<string, ForecastMonth>>;
}

// Purchase calendar

export interface PurchaseCalendarRun {
    id: number;
    scenario_id: number;
    year: number;
    warehouse: string;
    generated_at: string;
}

export interface PurchaseCalendarEvent {
    id: number;
    run_id: number;
    date: string;
    event_type: string;
    sku: string;
    name: string;
    quantity: number;
    gross_quantity: number;
    net_quantity: number;
    supplier: string | null;
    product_category: string;
    month_label: string;
}

// Sync status

export interface SyncStep {
    step: string;
    status: string;
    last_synced_at: string | null;
    age: string;
    duration_seconds: number | null;
    records_synced: number | null;
    was_full_sync: boolean;
}
