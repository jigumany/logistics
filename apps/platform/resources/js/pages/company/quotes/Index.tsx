import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import Pagination, { type Paged } from "../../../components/Pagination";
import { money } from "../../../features/quotes/money";
interface Row {
    public_id: string;
    reference: string;
    status: string;
    customer: string;
    preferred_date: string | null;
    total_minor: number | null;
    currency: string | null;
}
export default function Quotes({
    quotes,
    filters,
}: {
    quotes: Paged<Row>;
    filters: { search?: string; status?: string };
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/quotes";
    const [search, setSearch] = useState(filters.search ?? "");
    const [status, setStatus] = useState(filters.status ?? "");
    return (
        <CompanyLayout>
            <Head title="Quote inbox" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">Quote inbox</h1>
                    <p className="mt-2 text-slate-500">
                        Review requests, issue offers and track customer
                        acceptance.
                    </p>
                </div>
                <Button variant="outline" asChild>
                    <Link href={base + "?status=requires_review"}>
                        Pending review
                    </Link>
                </Button>
            </div>
            <Card>
                <form
                    className="mb-6 flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            base,
                            { search, status },
                            { preserveState: true },
                        );
                    }}
                >
                    <label className="grow">
                        Search reference or customer
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                    <label>
                        Status
                        <select
                            aria-label="Status"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                        >
                            <option value="">All submitted requests</option>
                            {[
                                "requires_review",
                                "quoted",
                                "accepted",
                                "expired",
                                "declined",
                                "cancelled",
                            ].map((value) => (
                                <option key={value} value={value}>
                                    {value.replaceAll("_", " ")}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit">Filter quotes</Button>
                </form>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="py-3">Reference</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Preferred date</th>
                                <th className="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {quotes.data.map((quote) => (
                                <tr
                                    key={quote.public_id}
                                    className="border-b border-slate-100"
                                >
                                    <td className="py-4">
                                        <Link
                                            className="font-bold text-teal-800 underline"
                                            href={base + "/" + quote.public_id}
                                        >
                                            {quote.reference}
                                        </Link>
                                    </td>
                                    <td>{quote.customer}</td>
                                    <td className="capitalize">
                                        {quote.status.replaceAll("_", " ")}
                                    </td>
                                    <td>
                                        {quote.preferred_date
                                            ? new Date(
                                                  quote.preferred_date,
                                              ).toLocaleDateString("en-ZA")
                                            : "—"}
                                    </td>
                                    <td className="text-right">
                                        {money(
                                            quote.total_minor,
                                            quote.currency,
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {quotes.data.length === 0 && (
                    <p className="py-8 text-slate-500">
                        No quotation requests match these filters.
                    </p>
                )}
                <Pagination links={quotes.links} />
            </Card>
        </CompanyLayout>
    );
}
