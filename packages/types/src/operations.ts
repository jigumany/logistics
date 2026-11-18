/* Generated from packages/contracts/operations.schema.json. */

export interface OperationsContract {
  job?: DispatchJobData;
}
/**
 * This interface was referenced by `OperationsContract`'s JSON-Schema
 * via the `definition` "DispatchJobData".
 */
export interface DispatchJobData {
  public_id: string;
  status:
    | "assigned"
    | "accepted"
    | "en_route"
    | "arrived"
    | "loading"
    | "in_transit"
    | "delivered"
    | "completed"
    | "recalled";
  lock_version: number;
  next_status: string | null;
  completed_at: string | null;
  resource_snapshot: {
    resource_public_id: string;
    kind: string;
    label: string;
    staff_public_id: string | null;
  }[];
  work_snapshot: {
    reference: string;
    starts_at: string;
    ends_at: string;
    contact: {
      name?: string;
      phone?: string;
    };
    stops: {
      position: number;
      kind: string;
      address_line: string;
      city: string;
      instructions?: string | null;
    }[];
    inventory: {
      name: string;
      quantity: number;
      weight_kg?: string | null;
      instructions?: string | null;
      fragile?: boolean;
    }[];
    access: {
      [k: string]: unknown;
    };
  };
  proof: {
    public_id: string;
    recipient_name: string;
    recipient_acknowledged: boolean;
    notes: string | null;
    captured_at: string;
    sha256: string;
  } | null;
  events: {
    from_status?: string | null;
    to_status: string;
    note?: string | null;
    created_at: string;
  }[];
}
