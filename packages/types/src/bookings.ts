/* Generated from packages/contracts/booking.schema.json. */

export interface BookingContract {}
/**
 * This interface was referenced by `BookingContract`'s JSON-Schema
 * via the `definition` "BookingHoldData".
 */
export interface BookingHoldData {
  public_id: string;
  purpose: "initial" | "reschedule";
  status: "active" | "consumed" | "expired" | "released";
  expires_at: string;
  arrival_starts_at: string;
  arrival_ends_at: string;
  service_starts_at: string;
  service_ends_at: string;
  requirements: {
    vehicles: number;
    drivers: number;
    movers: number;
    vehicle_type_key: string;
  };
  duration_snapshot: {
    [k: string]: unknown;
  };
}
/**
 * This interface was referenced by `BookingContract`'s JSON-Schema
 * via the `definition` "BookingData".
 */
export interface BookingData {
  public_id: string;
  reference: string;
  status:
    | "pending_confirmation"
    | "pending_payment"
    | "confirmed"
    | "cancelled"
    | "expired";
  financial_state:
    "not_required" | "awaiting_verification" | "verified" | "manual_resolution";
  required_payment_minor: number;
  currency: string;
  dispatch_state: "blocked" | "awaiting_dispatch";
  refund_state: "none" | "review_required";
  arrival_starts_at: string;
  arrival_ends_at: string;
  service_starts_at: string;
  service_ends_at: string;
  lock_version: number;
  requirements: {
    vehicles: number;
    drivers: number;
    movers: number;
    vehicle_type_key: string;
  };
  duration_snapshot: {
    [k: string]: unknown;
  };
  confirmation_instructions: string;
  history: {
    to_status: string;
    created_at: string;
  }[];
  payments?: PaymentSummaryData;
  dispatch?: {
    status: string;
    completed_at: string | null;
  } | null;
}
/**
 * This interface was referenced by `BookingContract`'s JSON-Schema
 * via the `definition` "PaymentSummaryData".
 */
export interface PaymentSummaryData {
  total_minor: number;
  paid_minor: number;
  balance_minor: number;
  currency: string;
  checkout: {
    public_id: string;
    status: string;
    purpose: string;
    amount_minor: number;
    redirect_url: string | null;
    idempotency_key: string;
  } | null;
  invoice: {
    public_id: string;
    number: string;
    due_date: string;
    total_minor: number;
  } | null;
}
/**
 * This interface was referenced by `BookingContract`'s JSON-Schema
 * via the `definition` "AvailabilitySlot".
 */
export interface AvailabilitySlot {
  starts_at: string;
  ends_at: string;
  timezone: string;
  state: "available";
  estimated_service_minutes: number;
  vehicle_class: string;
}
/**
 * This interface was referenced by `BookingContract`'s JSON-Schema
 * via the `definition` "AvailabilityResult".
 */
export interface AvailabilityResult {
  slots: AvailabilitySlot[];
  reasons: {
    code: string;
    message: string;
  }[];
  timezone: string;
  hold_minutes: number;
  advisory: true;
}
