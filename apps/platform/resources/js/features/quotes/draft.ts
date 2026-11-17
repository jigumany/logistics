import type { QuoteInput, QuotationData } from "@logistics/types";
export interface WizardDraft {
    version: 1;
    savedAt: number;
    step: number;
    key: string;
    input: QuoteInput;
    quote: QuotationData | null;
}
export function readDraft(company: string): WizardDraft | null {
    try {
        const raw = sessionStorage.getItem("quotation:" + company);
        if (!raw) return null;
        const value = JSON.parse(raw) as WizardDraft;
        if (
            value.version !== 1 ||
            Date.now() - value.savedAt > 86400000 ||
            !value.input ||
            !Array.isArray(value.input.inventory) ||
            !Array.isArray(value.input.stops) ||
            typeof value.key !== "string"
        )
            return null;
        return value;
    } catch {
        return null;
    }
}
export function saveDraft(company: string, draft: WizardDraft): boolean {
    try {
        sessionStorage.setItem("quotation:" + company, JSON.stringify(draft));
        return true;
    } catch {
        return false;
    }
}
export function clearDraft(company: string): void {
    try {
        sessionStorage.removeItem("quotation:" + company);
    } catch {
        /* Browsers may disable local storage. */
    }
}
export function emptyQuoteInput(service: string): QuoteInput {
    return {
        service_public_id: service,
        preferred_date: "",
        preferred_time: null,
        stops: [
            { address_line: "", city: "", country_code: "ZA" },
            { address_line: "", city: "", country_code: "ZA" },
        ],
        inventory: [],
        details: {
            pickup_stairs: 0,
            delivery_stairs: 0,
            pickup_lift: false,
            delivery_lift: false,
            pickup_carry_metres: 0,
            delivery_carry_metres: 0,
            parking_limited: false,
            difficult_access: false,
            special_equipment: false,
            packing: false,
            waiting_minutes: 0,
        },
    };
}
