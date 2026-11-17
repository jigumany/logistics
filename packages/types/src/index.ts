/* Generated from packages/contracts/platform.schema.json. */

/**
 * This interface was referenced by `PlatformContract`'s JSON-Schema
 * via the `definition` "Role".
 */
export type Role = "owner" | "admin" | "dispatcher" | "driver" | "viewer";

export interface PlatformContract {}
/**
 * This interface was referenced by `PlatformContract`'s JSON-Schema
 * via the `definition` "Company".
 */
export interface Company {
  public_id: string;
  name: string;
  slug: string;
}
/**
 * This interface was referenced by `PlatformContract`'s JSON-Schema
 * via the `definition` "CompanySettings".
 */
export interface CompanySettings {
  public_id: string;
  display_name: string;
  contact_email: string;
  contact_phone: string | null;
  payment_terms: "deposit" | "full" | "invoice";
  deposit_basis_points: number;
  currency: "ZAR";
  timezone: string;
  registered_name: string | null;
  registration_number: string | null;
  vat_number: string | null;
  primary_contact_name: string | null;
  website: string | null;
  description: string | null;
  operating_address: string | null;
  billing_address: string | null;
  operational_email: string | null;
  operational_phone: string | null;
  operational_status: "active" | "paused" | "closed";
  measurement_units: "metric" | "imperial";
  booking_notice_minutes: number;
  job_buffer_minutes: number;
  public_contact_visible: boolean;
  booking_enabled: boolean;
}
/**
 * This interface was referenced by `PlatformContract`'s JSON-Schema
 * via the `definition` "ServiceType".
 */
export interface ServiceType {
  public_id: string;
  code: "household" | "office" | "general";
  name: string;
  enabled: boolean;
}
/**
 * This interface was referenced by `PlatformContract`'s JSON-Schema
 * via the `definition` "SharedProps".
 */
export interface SharedProps {
  auth: {
    user: {
      name: string;
      email: string;
    } | null;
    role: Role | null;
  };
  company: Company | null;
  flash: {
    success: string | null;
  };
  [k: string]: unknown;
}
/**
 * This interface was referenced by `PlatformContract`'s JSON-Schema
 * via the `definition` "OperationalResource".
 */
export interface OperationalResource {
  public_id: string;
  kind: "vehicle" | "driver" | "mover";
  active: boolean;
}

export type * from "./quotations";
export type * from "./bookings";
