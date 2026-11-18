import YocoConfiguration from "../../../components/YocoConfiguration";
import { Head, Link, usePage, router } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import CompanyLayout from "../../../layouts/CompanyLayout";
import { quoteRequest } from "../../../features/quotes/http";
import { money } from "../../../features/quotes/money";
import {
    statusLabel,
    type PaymentSummary,
} from "../../../features/dispatch/types";
type Row = PaymentSummary & {
    public_id: string;
    reference: string;
    status: string;
    financial_state: string;
    refund_state: string;
};
export default function Index({
    bookings,
    filters,
    yoco,
    webhookUrl,
}: {
    yoco: { mode: string } | null;
    webhookUrl: string;
    bookings: {
        data: Row[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search?: string };
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug;
    const [error, setError] = useState("");
    const [billingAddress, setBillingAddress] = useState("");
    const [billingVat, setBillingVat] = useState("");
    const [busy, setBusy] = useState(false);
    const [due, setDue] = useState(new Date().toISOString().slice(0, 10));
    const [search, setSearch] = useState(filters.search ?? "");
    const [history, setHistory] = useState<
        {
            public_id: string;
            amount_minor: number;
            currency: string;
            mode: string;
            status: string;
            provider_payment_id: string | null;
        }[]
    >([]);
    async function issue(id: string) {
        setBusy(true);
        setError("");
        try {
            await quoteRequest(base + "/finance/" + id + "/invoice", {
                due_date: due,
                billing_address: billingAddress || null,
                vat_number: billingVat || null,
            });
            router.reload();
        } catch (e) {
            setError(
                e instanceof Error ? e.message : "Invoice could not be issued.",
            );
        } finally {
            setBusy(false);
        }
    }
    async function payments(id: string) {
        setError("");
        try {
            setHistory(
                await quoteRequest(base + "/finance/" + id + "/payments"),
            );
        } catch (e) {
            setError(e instanceof Error ? e.message : "Payments unavailable.");
        }
    }
    return (
        <CompanyLayout>
            <Head title="Payments and invoicing" />
            <p className="text-sm font-semibold uppercase tracking-widest text-teal-700">
                Finance
            </p>
            <h1 className="mt-2 text-3xl font-bold">Payments and invoicing</h1>
            <p className="mt-2 text-slate-500">
                Accepted quote totals, verified Yoco payments and retained
                invoice records.
            </p>
            <YocoConfiguration configured={yoco} webhookUrl={webhookUrl} />
            <div className="my-6 flex flex-wrap items-end gap-4">
                <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(base + "/finance", { search });
                    }}
                >
                    <label className="text-sm font-medium">
                        Booking reference
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="mt-1 block rounded-lg border-slate-300"
                        />
                    </label>
                    <button className="self-end rounded-lg border bg-white px-4 py-2">
                        Search
                    </button>
                </form>
                <label className="text-sm font-medium">
                    New invoice due date
                    <input
                        type="date"
                        value={due}
                        onChange={(e) => setDue(e.target.value)}
                        className="mt-1 block rounded-lg border-slate-300"
                    />
                </label>
            </div>
            <div className="mb-6 flex flex-wrap gap-4">
                <label className="text-sm font-medium">
                    Customer billing address
                    <input
                        value={billingAddress}
                        onChange={(e) => setBillingAddress(e.target.value)}
                        className="mt-1 block rounded-lg border-slate-300"
                        maxLength={2000}
                    />
                </label>
                <label className="text-sm font-medium">
                    Customer VAT number (if registered)
                    <input
                        value={billingVat}
                        onChange={(e) => setBillingVat(e.target.value)}
                        className="mt-1 block rounded-lg border-slate-300"
                        maxLength={40}
                    />
                </label>
            </div>
            {error && (
                <p
                    role="alert"
                    className="mb-5 rounded-xl bg-red-50 p-4 text-red-800"
                >
                    {error}
                </p>
            )}
            <div className="overflow-x-auto rounded-2xl border bg-white">
                <table className="w-full min-w-[850px] text-left text-sm">
                    <thead className="bg-slate-50 text-slate-500">
                        <tr>
                            {[
                                "Booking",
                                "Total",
                                "Verified paid",
                                "Outstanding",
                                "Invoice",
                                "Actions",
                            ].map((h) => (
                                <th key={h} className="p-4 font-semibold">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {bookings.data.map((b) => (
                            <tr key={b.public_id}>
                                <td className="p-4">
                                    <p className="font-semibold">
                                        {b.reference}
                                    </p>
                                    <p className="mt-1 capitalize text-slate-500">
                                        {statusLabel(b.status)}
                                    </p>
                                    {b.refund_state === "review_required" && (
                                        <p className="mt-1 font-medium text-amber-800">
                                            Payment/refund review required
                                        </p>
                                    )}
                                </td>
                                <td className="p-4">
                                    {money(b.total_minor, b.currency)}
                                </td>
                                <td className="p-4">
                                    {money(b.paid_minor, b.currency)}
                                </td>
                                <td className="p-4 font-semibold">
                                    {money(b.balance_minor, b.currency)}
                                </td>
                                <td className="p-4">
                                    {b.invoice ? (
                                        <a
                                            className="font-semibold text-teal-700 underline"
                                            href={
                                                base +
                                                "/invoices/" +
                                                b.invoice.public_id +
                                                "/pdf"
                                            }
                                        >
                                            {b.invoice.number}
                                        </a>
                                    ) : b.status === "confirmed" ? (
                                        <button
                                            disabled={busy}
                                            onClick={() =>
                                                void issue(b.public_id)
                                            }
                                            className="rounded-lg bg-teal-700 px-3 py-2 font-semibold text-white disabled:opacity-50"
                                        >
                                            Issue invoice
                                        </button>
                                    ) : (
                                        <span className="text-slate-400">
                                            Not issued
                                        </span>
                                    )}
                                </td>
                                <td className="p-4">
                                    <button
                                        onClick={() =>
                                            void payments(b.public_id)
                                        }
                                        className="font-semibold text-teal-700 underline"
                                    >
                                        Payment history
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!bookings.data.length && (
                    <p className="p-8 text-slate-500">
                        No bookings match this reference.
                    </p>
                )}
            </div>
            <div className="my-5 flex gap-5 text-sm font-semibold text-teal-700">
                {bookings.prev_page_url && (
                    <Link href={bookings.prev_page_url}>Previous</Link>
                )}
                {bookings.next_page_url && (
                    <Link href={bookings.next_page_url}>Next</Link>
                )}
            </div>
            {history.length > 0 && (
                <section className="mt-6 rounded-2xl border bg-white p-6">
                    <h2 className="text-lg font-bold">Payment history</h2>
                    <ul className="mt-4 divide-y">
                        {history.map((p) => (
                            <li
                                key={p.public_id}
                                className="flex flex-wrap justify-between gap-3 py-3"
                            >
                                <span>
                                    {p.provider_payment_id ??
                                        "Awaiting provider payment"}{" "}
                                    <span className="ml-2 rounded bg-slate-100 px-2 py-1 text-xs uppercase">
                                        {p.mode}
                                    </span>
                                </span>
                                <span>
                                    {money(p.amount_minor, p.currency)} ·{" "}
                                    {p.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            <p className="mt-6 text-sm text-slate-500">
                Issued invoices retain their original totals and identities.
                Refund review does not automatically return funds. Resolve
                refunds through the payment provider and retain financial
                corrections separately.
            </p>
        </CompanyLayout>
    );
}
