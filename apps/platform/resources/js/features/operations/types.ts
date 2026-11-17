export type OperationalRow = Record<string, unknown> & { public_id: string };
export type OperationalSummary = {
    label: string;
    value: number;
    hint: string;
}[];
