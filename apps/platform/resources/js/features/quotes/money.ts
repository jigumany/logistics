export function parseScaledDecimal(value: string, scale: number): number {
    const match = /^(\d+)(?:\.(\d+))?$/.exec(value.trim());
    if (!match || (match[2]?.length ?? 0) > scale)
        throw new Error(
            `Enter a positive decimal with at most ${scale} decimal places.`,
        );
    const result =
        BigInt(match[1]) * 10n ** BigInt(scale) +
        BigInt((match[2] ?? "").padEnd(scale, "0") || "0");
    if (result > 1000000000000000n) throw new Error("The amount is too large.");
    return Number(result);
}
export function scaledDecimal(value: number, scale: number): string {
    const digits = String(Math.abs(value)).padStart(scale + 1, "0");
    return (
        (value < 0 ? "-" : "") +
        digits.slice(0, -scale) +
        "." +
        digits.slice(-scale)
    );
}
export function money(minor: number | null, currency: string | null): string {
    if (minor === null || !currency) return "Awaiting review";
    return new Intl.NumberFormat("en-ZA", {
        style: "currency",
        currency,
    }).format(minor / 100);
}
