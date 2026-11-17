import { afterEach, it, expect, vi } from "vitest";
import { emptyQuoteInput, readDraft, saveDraft, clearDraft } from "./draft";
afterEach(() => vi.unstubAllGlobals());
function storage() {
    const entries = new Map<string, string>();
    vi.stubGlobal("sessionStorage", {
        getItem: (key: string) => entries.get(key) ?? null,
        setItem: (key: string, value: string) => entries.set(key, value),
        removeItem: (key: string) => entries.delete(key),
    });
    return entries;
}
it("recovers an isolated company draft without saving contact details", () => {
    storage();
    const draft = {
        version: 1 as const,
        savedAt: Date.now(),
        step: 2,
        key: "00000000-0000-4000-8000-000000000001",
        input: emptyQuoteInput("service"),
        quote: null,
    };
    expect(saveDraft("company-a", draft)).toBe(true);
    expect(readDraft("company-a")?.step).toBe(2);
    expect(readDraft("company-b")).toBeNull();
    expect(readDraft("company-a")).not.toHaveProperty("contact");
    clearDraft("company-a");
    expect(readDraft("company-a")).toBeNull();
});
it("ignores expired or corrupt drafts", () => {
    const entries = storage();
    entries.set("quotation:test", "{bad");
    expect(readDraft("test")).toBeNull();
    saveDraft("test", {
        version: 1,
        savedAt: Date.now() - 86400001,
        step: 0,
        key: "test",
        input: emptyQuoteInput("service"),
        quote: null,
    });
    expect(readDraft("test")).toBeNull();
});
it("handles browsers that prohibit saving drafts", () => {
    vi.stubGlobal("sessionStorage", {
        setItem: () => {
            throw new Error("Storage disabled");
        },
        getItem: () => {
            throw new Error("Storage disabled");
        },
    });
    expect(
        saveDraft("test", {
            version: 1,
            savedAt: Date.now(),
            step: 0,
            key: "test",
            input: emptyQuoteInput("service"),
            quote: null,
        }),
    ).toBe(false);
    expect(readDraft("test")).toBeNull();
});
