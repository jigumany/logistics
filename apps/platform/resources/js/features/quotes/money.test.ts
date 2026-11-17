import { describe, it, expect } from "vitest";
import { money, parseScaledDecimal, scaledDecimal } from "./money";
describe("quote money precision", () => {
    it("converts decimal rates to integer precision without binary rounding", () => {
        expect(parseScaledDecimal("0.29", 2)).toBe(29);
        expect(parseScaledDecimal("12.345678", 6)).toBe(12345678);
        expect(parseScaledDecimal("15.1250", 4)).toBe(151250);
    });
    it("rejects ambiguous and excessive decimal precision", () => {
        for (const value of ["1,20", "1.001", "NaN", "-1", "1e3", ""])
            expect(() => parseScaledDecimal(value, 2)).toThrow();
    });
    it("preserves minor units when editing manual offers", () => {
        expect(scaledDecimal(125099, 2)).toBe("1250.99");
        expect(scaledDecimal(-105, 2)).toBe("-1.05");
        expect(scaledDecimal(5000000, 6)).toBe("5.000000");
    });
    it("keeps an unpriced review visibly distinct from a zero-price offer", () => {
        expect(money(null, "ZAR")).toBe("Awaiting review");
        expect(money(0, "ZAR")).not.toBe("Awaiting review");
    });
});
