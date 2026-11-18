import { useRef, useState } from "react";
import { quoteRequest } from "../features/quotes/http";
import { money } from "../features/quotes/money";
import type { PaymentSummary } from "../features/dispatch/types";
export default function BookingPayments({
    summary,
    status,
    links,
    reservationActive,
}: {
    reservationActive: boolean;
    summary: PaymentSummary;
    status: string;
    links: Record<string, string>;
}) {
    const key = useRef(
        summary.checkout?.idempotency_key ?? crypto.randomUUID(),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    async function pay() {
        setBusy(true);
        setError("");
        try {
            const r = await quoteRequest<{
                redirect_url: string | null;
                status: string;
            }>(links.checkout, {
                idempotency_key: key.current,
                purpose:
                    summary.checkout?.purpose ??
                    (status === "confirmed" ? "balance" : "reservation"),
            });
            if (r.status === "succeeded") {
                window.location.reload();
                return;
            }
            if (r.status !== "ready" || !r.redirect_url)
                throw new Error(
                    "Payment status has changed. Refresh this booking to see the current result.",
                );
            window.location.assign(r.redirect_url);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : "Checkout could not be started.",
            );
            setBusy(false);
        }
    }
    return (
        <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
            <h2 className="text-lg font-bold">Payments and invoice</h2>
            <dl className="my-5 grid grid-cols-3 gap-3 text-sm">
                <div>
                    <dt className="text-slate-500">Quoted total</dt>
                    <dd className="mt-1 font-semibold">
                        {money(summary.total_minor, summary.currency)}
                    </dd>
                </div>
                <div>
                    <dt className="text-slate-500">Verified payments</dt>
                    <dd className="mt-1 font-semibold">
                        {money(summary.paid_minor, summary.currency)}
                    </dd>
                </div>
                <div>
                    <dt className="text-slate-500">Outstanding</dt>
                    <dd className="mt-1 font-semibold">
                        {money(summary.balance_minor, summary.currency)}
                    </dd>
                </div>
            </dl>
            {error && (
                <p role="alert" className="mb-4 text-sm text-red-800">
                    {error}
                </p>
            )}
            {summary.balance_minor >= 200 &&
                ["confirmed", "pending_payment"].includes(status) &&
                (status !== "pending_payment" || reservationActive) && (
                    <>
                        <button
                            disabled={busy}
                            onClick={() => void pay()}
                            className="rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white disabled:opacity-50"
                        >
                            {busy
                                ? "Opening Yoco…"
                                : summary.checkout
                                  ? "Resume Yoco payment"
                                  : status === "confirmed"
                                    ? "Pay outstanding balance with Yoco"
                                    : "Pay reservation amount with Yoco"}
                        </button>
                        <p className="mt-3 text-sm text-slate-500">
                            Card details are entered on Yoco’s secure checkout.
                            Returning here does not confirm payment. Refresh to
                            see verified payment status; your original hold
                            expiry still applies.
                        </p>
                    </>
                )}
            {status === "pending_payment" && !reservationActive && (
                <p className="mt-3 text-sm text-amber-800">
                    Your temporary hold has expired. Refresh availability before
                    making a payment.
                </p>
            )}
            {summary.invoice && (
                <a
                    href={links.invoice}
                    className="mt-5 block font-semibold text-teal-800 underline"
                >
                    Download invoice {summary.invoice.number}
                </a>
            )}
        </section>
    );
}
