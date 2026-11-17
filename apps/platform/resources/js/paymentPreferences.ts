export function depositBasisPoints(percent: number): number {
    if (!Number.isFinite(percent) || percent <= 0 || percent > 100)
        throw new RangeError(
            "Deposit must be greater than 0 and no more than 100 percent.",
        );
    return Math.round(percent * 100);
}
