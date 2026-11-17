import type {
    BookingData,
    BookingHoldData,
    QuotationData,
} from "@logistics/types";
export type BookingResource = {
    public_id: string;
    kind: string;
    label: string;
    registration?: string | null;
};
export type BookingState = {
    quote: QuotationData;
    booking:
        | (Omit<BookingData, "history"> & {
              history: {
                  to_status: string;
                  from_status?: string | null;
                  reason?: string;
                  created_at: string;
              }[];
              resources?: BookingResource[];
              changes?: { kind: string; reason: string; created_at: string }[];
              allocation_starts_at?: string;
              allocation_ends_at?: string;
          })
        | null;
    hold: (BookingHoldData & { resources?: BookingResource[] }) | null;
    timezone: string;
    server_time: string;
    links: Record<string, string>;
};
export function displayTime(value: string, timezone: string) {
    return new Intl.DateTimeFormat("en-ZA", {
        timeZone: timezone,
        hour: "2-digit",
        minute: "2-digit",
        hourCycle: "h23",
    }).format(new Date(value));
}
export function displayDate(value: string, timezone: string) {
    return new Intl.DateTimeFormat("en-ZA", {
        timeZone: timezone,
        day: "numeric",
        month: "short",
        year: "numeric",
    }).format(new Date(value));
}
