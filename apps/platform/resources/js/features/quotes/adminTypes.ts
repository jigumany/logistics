import type { QuotationData, QuoteRevisionData } from "@logistics/types";
import type { Paged } from "../../components/Pagination";
export interface InternalRevision extends QuoteRevisionData {
    coverage: { outcome: string; cross_zone: boolean };
    suitability: {
        vehicle_public_id: string;
        vehicle_name?: string;
        vehicle_registration?: string;
        vehicle_type_key: string;
        status: string;
        technically_fits: boolean;
        reasons: { code: string; explanation: string }[];
        limitation: string;
    }[];
    pricing_snapshot: {
        version?: number;
        engine_version?: string;
        calculation_failures?: { vehicle_type_key?: string; message: string }[];
        source_price_book?: { price_book_public_id?: string; version?: number };
        calculation_id?: string;
        calculated_at?: string;
        tenant_public_id?: string;
        service_public_id?: string;
        price_book_public_id?: string;
        price_book_name?: string;
        is_demonstration?: boolean;
        surcharge_basis?: string;
        surcharge_base_minor?: number | null;
        rules?: PriceRule[];
        screened_vehicle_type?: string;
        internal_costs?: InternalCostEstimate;
        readiness?: {
            state: string;
            automatic_ready: boolean;
            checks: { code: string; label: string; passed: boolean }[];
        };
        route_snapshot?: Record<string, string | number | null>;
        quantity_status?: string;
        distance_basis?: string;
        manual_override?: boolean;
        reason?: string;
        metrics?: Record<string, string | number | null>;
    };
}
export interface AdminQuote extends QuotationData {
    route?: {
        provider: string;
        status: string;
        failure_code: string | null;
        customer_metres: number | null;
        depot_metres: number | null;
        return_metres: number | null;
        duration_seconds: number | null;
    } | null;
    revision: InternalRevision | null;
    reviews: Paged<{
        public_id: string;
        decision: string;
        reason: string;
        created_at: string;
        adjustment: {
            previous_total_minor: number | null;
            previous_currency?: string | null;
            total_minor: number | null;
            currency: string;
        } | null;
    }>;
}
export interface InternalCostEstimate {
    status: string;
    reason?: string;
    lines?: { code: string; amount_minor: number }[];
    direct_cost_minor?: number;
    overhead_minor?: number;
    total_cost_minor?: number;
    revenue_ex_tax_minor?: number;
    contribution_minor?: number;
    margin_bps?: number | null;
    minimum_margin_bps?: number;
    target_margin_bps?: number;
    below_minimum?: boolean;
    below_target?: boolean;
    job_hours?: string;
    distance_km?: string;
}
export interface CommercialTerms {
    status: "draft" | "reviewed";
    insurance_status: "draft" | "reviewed";
    declared_value_required: boolean;
    insurance_information: string;
    special_handling: string;
    customer_responsibilities: string;
    access_parking: string;
    cancellation: string;
    rescheduling: string;
    damage_reporting: string;
    additional_charges: string;
}
export interface CommercialPricing {
    included_km: number;
    minimum_chargeable_km: number;
    minimum_labour_hours: number;
    standard_labour_hours: number;
    labour_model: "combined" | "separate";
    labour_distance_basis: "customer" | "operational";
    bill_waiting_in_labour: boolean;
    toll_policy: "included" | "actual" | "review";
    tax_reviewed: boolean;
    tax_mode: "not_registered" | "registered" | "exempt" | "demo_zero";
    tax_registration: string;
    tax_basis_points: number;
    costs_reviewed: boolean;
    cost_distance_basis: "customer" | "operational";
    target_margin_bps: number;
    minimum_margin_bps: number;
    override_roles: ("owner" | "admin")[];
    fuel_rate_micros: number;
    vehicle_rate_micros: number;
    driver_rate_micros: number;
    mover_rate_micros: number;
    driver_overtime_rate_micros: number;
    mover_overtime_rate_micros: number;
    fixed_cost_minor: number;
    overhead_minor: number;
    estimated_tolls_minor: number;
}
export interface Policy {
    commercial_terms?: CommercialTerms | null;
    terms_version?: string | null;
    automatic_estimates: boolean;
    allow_catalogue_estimates: boolean;
    allow_customer_measurements: boolean;
    allow_cross_zone: boolean;
    outside_zone: "review" | "reject";
    safety_margin_bps: number;
    borderline_bps: number;
    max_auto_stops: number;
    max_auto_stairs: number;
    validity_hours: number;
    notifications_enabled: boolean;
    terms: string;
    exclusions: string | null;
}
export interface CatalogueRecord {
    public_id: string;
    name: string;
    category: string;
    description: string | null;
    length_cm: string | null;
    width_cm: string | null;
    height_cm: string | null;
    weight_kg: string | null;
    volume_m3: string | null;
    handling: "standard" | "fragile" | "special";
    active: boolean;
    global?: boolean;
    has_image?: boolean;
}
export interface PriceRule {
    code: string;
    label: string;
    stage: "charge" | "surcharge" | "discount" | "minimum" | "tax";
    kind: "fixed" | "rate" | "percent";
    metric: string | null;
    rate_micros: number;
    position: number;
    vehicle_type_key: string | null;
}
export interface BookConfiguration {
    schema_version: 1 | 2 | 3;
    included_driver_hours?: number;
    labour_rounding_minutes?: number;
    long_carry_threshold_metres?: number;
    waiting_grace_minutes?: number;
    waiting_interval_minutes?: number;
    percentage_surcharge_basis?: "charges_subtotal";
    commercial?: CommercialPricing;
    included_metrics: string[];
    crew_size: number;
    loading_minutes_per_complexity: number;
    unloading_minutes_per_complexity: number;
    carry_included_metres: number;
    business_opens: string;
    business_closes: string;
    holiday_dates: string[];
    weekend_days?: number[];
    depot_public_id?: string | null;
}
export interface PriceBookRecord {
    is_demonstration?: boolean;
    public_id: string;
    name: string;
    version: number;
    status: "draft" | "published" | "retired";
    currency: string;
    effective_from: string;
    effective_until: string | null;
    distance_basis:
        "customer" | "customer_depot" | "customer_return" | "operational";
    configuration: BookConfiguration;
    service_public_id: string;
    rules: PriceRule[];
}
