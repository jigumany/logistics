import { describe, it, expect } from "vitest";
import { depositBasisPoints } from "./paymentPreferences";
describe("deposit precision", () => {
    it("converts fractional percentages to integer basis points", () => {
        expect(depositBasisPoints(12.35)).toBe(1235);
        expect(depositBasisPoints(100)).toBe(10000);
    });
    it.each([0, -1, 101, NaN, Infinity])(
        "rejects invalid percentage %s",
        (value) => {
            expect(() => depositBasisPoints(value)).toThrow(RangeError);
        },
    );
});
