export type {
    DispatchJobData as DispatchJob,
    PaymentSummaryData as PaymentSummary,
} from "@logistics/types";
export const statusLabel = (value: string) => value.replaceAll("_", " ");
