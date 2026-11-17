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
export interface Policy {
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
    schema_version: 1;
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
