/* Generated from packages/contracts/quotation.schema.json. */

export interface QuotationContract {}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuoteAddress".
 */
export interface QuoteAddress {
  address_line: string;
  suburb?: string | null;
  city: string;
  postal_code?: string | null;
  country_code: string;
  latitude?: number | string | null;
  longitude?: number | string | null;
  provider_place_id?: string | null;
  verification_proof?: string | null;
  instructions?: string | null;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "InventoryEntry".
 */
export interface InventoryEntry {
  item_key: string;
  catalogue_public_id?: string | null;
  name?: string | null;
  quantity: number;
  length_cm?: string | null;
  width_cm?: string | null;
  height_cm?: string | null;
  weight_kg?: string | null;
  fragile: boolean;
  oversized: boolean;
  stackable: boolean;
  rotation_allowed: boolean;
  instructions?: string | null;
  weight_certainty?: "known" | "estimated" | "unknown";
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "MoveDetails".
 */
export interface MoveDetails {
  pickup_stairs: number;
  delivery_stairs: number;
  pickup_lift: boolean;
  delivery_lift: boolean;
  pickup_carry_metres: number;
  delivery_carry_metres: number;
  parking_limited: boolean;
  difficult_access: boolean;
  special_equipment: boolean;
  packing: boolean;
  waiting_minutes: number;
  declared_goods_value_minor?: number | null;
  declared_goods_currency?: "ZAR" | "USD" | "EUR" | "GBP" | null;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuoteInput".
 */
export interface QuoteInput {
  service_public_id: string;
  preferred_date: string;
  preferred_time?: string | null;
  /**
   * @minItems 2
   * @maxItems 10
   */
  stops: QuoteAddress[];
  /**
   * @minItems 1
   * @maxItems 100
   */
  inventory: InventoryEntry[];
  details: MoveDetails;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuoteContact".
 */
export interface QuoteContact {
  name: string;
  email: string;
  phone?: string | null;
  consent: boolean;
  communication: "email" | "none";
  marketing_consent: boolean;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuoteLine".
 */
export interface QuoteLine {
  code: string;
  description: string;
  stage: string;
  quantity: string | number;
  rate_micros: number;
  amount_minor: number;
  rate_display: string;
  quantity_unit: string;
  rate_kind: string;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuoteInventoryRow".
 */
export interface QuoteInventoryRow {
  public_id: string;
  item_key: string;
  name: string;
  quantity: number;
  length_cm: string | null;
  width_cm: string | null;
  height_cm: string | null;
  weight_kg: string | null;
  volume_m3: string | null;
  measurement_source: "catalogue" | "customer" | "measured" | "unknown";
  fragile: boolean;
  oversized: boolean;
  stackable: boolean;
  rotation_allowed: boolean;
  instructions: string | null;
  photos: string[];
  [k: string]: unknown;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "InventorySummary".
 */
export interface InventorySummary {
  quantity: number;
  volume_m3: string;
  total_weight_kg: string | null;
  known_weight_kg: string;
  unknown_weight_quantity: number;
  unknown_volume_quantity: number;
  safety_margin_bps: number;
  loading_complexity: number;
  [k: string]: unknown;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuoteRevisionData".
 */
export interface QuoteRevisionData {
  public_id: string;
  number: number;
  approval: "automatic" | "pending" | "approved";
  currency: string | null;
  subtotal_minor: number | null;
  tax_minor: number | null;
  total_minor: number | null;
  terms: string;
  exclusions: string;
  expires_at: string;
  expired: boolean;
  contact_snapshot: {
    name?: string;
    email?: string;
    phone?: string | null;
    communication?: "email" | "none";
    [k: string]: unknown;
  };
  inventory_summary: InventorySummary;
  lines: QuoteLine[];
  inventory: QuoteInventoryRow[];
  stops: {
    address_line: string;
    city: string;
    postal_code?: string | null;
    kind: string;
    position: number;
    instructions?: string | null;
    verification?: string;
    [k: string]: unknown;
  }[];
  review_reasons: {
    code: string;
    customer_message: string;
    [k: string]: unknown;
  }[];
  pricing_method: string;
  service_name: string;
  move_details: MoveDetails;
  preferred_time: string | null;
  terms_version?: string;
  [k: string]: unknown;
}
/**
 * This interface was referenced by `QuotationContract`'s JSON-Schema
 * via the `definition` "QuotationData".
 */
export interface QuotationData {
  public_id: string;
  reference: string;
  status:
    | "draft"
    | "calculating"
    | "requires_review"
    | "quoted"
    | "accepted"
    | "expired"
    | "declined"
    | "cancelled";
  lock_version: number;
  preferred_date: string | null;
  submitted_at: string | null;
  accepted_at: string | null;
  revision: QuoteRevisionData | null;
  links: {
    view: string;
    calculate: string;
    submit: string;
    accept: string;
    respond: string;
    photo: string;
    pdf: string;
    revisions: string;
    save: string;
    decline: string;
    cancel: string;
  };
  booking_confirmed: false;
  information_request: string | null;
  attachments: {
    public_id: string;
    item_key: string;
    url: string;
  }[];
  [k: string]: unknown;
}
