import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import Pagination, { type Paged } from "../../../components/Pagination";
import { displayDate, displayTime } from "../../../features/bookings/types";
import { money } from "../../../features/quotes/money";
type Row = {
    public_id: string;
    reference: string;
    status: string;
    financial_state: string;
    service_starts_at: string;
    customer: string;
    service: string;
    total_minor: number;
    currency: string;
};
export default function Index({
    bookings,
    filters,
    services,
    timezone,
    holds,
}: {
    bookings: Paged<Row>;
    filters: Record<string, string>;
    services: { public_id: string; name: string }[];
    timezone: string;
    holds: {
        public_id: string;
        purpose: string;
        expires_at: string;
        service_starts_at: string;
        quote_public_id: string;
    }[];
}) {
    const { company, auth } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug;
    const [form, setForm] = useState({
        search: "",
        status: "",
        from: "",
        until: "",
        service: "",
        ...filters,
    });
    return (
        <CompanyLayout>
            <Head title="Bookings" />
            <div className="mb-7 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-bold uppercase tracking-widest text-teal-700">
                        Operations
                    </p>
                    <h1 className="mt-2 text-3xl font-bold">Bookings</h1>
                    <p className="mt-2 text-slate-500">
                        Manage confirmed moves, pending requirements and
                        temporary reservations.
                    </p>
                </div>
                <div className="flex flex-wrap gap-3">
                    <Button asChild variant="outline">
                        <Link href={base + "/planner"}>Open planner</Link>
                    </Button>
                    {["owner", "admin"].includes(auth.role ?? "") && (
                        <Button asChild>
                            <Link href={base + "/booking-policy"}>
                                Booking policy
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
            {holds.length > 0 && (
                <Card className="mb-6">
                    <h2 className="font-semibold">
                        Temporary holds · {holds.length}
                    </h2>
                    <div className="mt-4 grid gap-3 sm:grid-cols-3">
                        {holds.map((hold) => (
                            <Link
                                className="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm"
                                key={hold.public_id}
                                href={
                                    base +
                                    "/bookings/quote/" +
                                    hold.quote_public_id
                                }
                            >
                                <strong>
                                    {displayDate(
                                        hold.service_starts_at,
                                        timezone,
                                    )}{" "}
                                    ·{" "}
                                    {displayTime(
                                        hold.service_starts_at,
                                        timezone,
                                    )}
                                </strong>
                                <p className="mt-1 capitalize">
                                    {hold.purpose} hold · expires{" "}
                                    {displayTime(hold.expires_at, timezone)}
                                </p>
                            </Link>
                        ))}
                    </div>
                </Card>
            )}
            <Card>
                <form
                    className="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(base + "/bookings", form);
                    }}
                >
                    <label className="sm:col-span-2">
                        Search bookings
                        <input
                            value={form.search}
                            onChange={(e) =>
                                setForm({ ...form, search: e.target.value })
                            }
                            placeholder="Reference, customer or email"
                        />
                    </label>
                    <label>
                        Status
                        <select
                            aria-label="Booking status filter"
                            value={form.status}
                            onChange={(e) =>
                                setForm({ ...form, status: e.target.value })
                            }
                        >
                            <option value="">All statuses</option>
                            {[
                                "pending_confirmation",
                                "pending_payment",
                                "confirmed",
                                "cancelled",
                                "expired",
                            ].map((value) => (
                                <option key={value} value={value}>
                                    {value.replaceAll("_", " ")}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Service
                        <select
                            aria-label="Booking service filter"
                            value={form.service}
                            onChange={(e) =>
                                setForm({ ...form, service: e.target.value })
                            }
                        >
                            <option value="">All services</option>
                            {services.map((service) => (
                                <option
                                    key={service.public_id}
                                    value={service.public_id}
                                >
                                    {service.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        From date
                        <input
                            type="date"
                            value={form.from}
                            onChange={(e) =>
                                setForm({ ...form, from: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Until date
                        <input
                            type="date"
                            value={form.until}
                            onChange={(e) =>
                                setForm({ ...form, until: e.target.value })
                            }
                        />
                    </label>
                    <Button type="submit">Apply filters</Button>
                </form>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b text-xs uppercase text-slate-500">
                                {[
                                    "Booking",
                                    "Customer / service",
                                    "Pickup · " + timezone,
                                    "Accepted total",
                                    "Status",
                                    "Payment",
                                ].map((label) => (
                                    <th className="px-3 py-4" key={label}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {bookings.data.map((row) => (
                                <tr
                                    className="border-b border-slate-100"
                                    key={row.public_id}
                                >
                                    <td className="px-3 py-5">
                                        <Link
                                            className="font-semibold text-teal-800 underline"
                                            href={
                                                base +
                                                "/bookings/" +
                                                row.public_id
                                            }
                                        >
                                            {row.reference}
                                        </Link>
                                    </td>
                                    <td className="px-3">
                                        <strong>{row.customer}</strong>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {row.service}
                                        </p>
                                    </td>
                                    <td className="px-3 whitespace-nowrap">
                                        {displayDate(
                                            row.service_starts_at,
                                            timezone,
                                        )}
                                        <p className="mt-1 text-xs text-slate-500">
                                            {displayTime(
                                                row.service_starts_at,
                                                timezone,
                                            )}
                                        </p>
                                    </td>
                                    <td className="px-3 whitespace-nowrap">
                                        {money(row.total_minor, row.currency)}
                                    </td>
                                    <td className="px-3">
                                        <span
                                            className={
                                                "rounded-full px-3 py-1 text-xs capitalize " +
                                                (row.status === "confirmed"
                                                    ? "bg-teal-50 text-teal-800"
                                                    : "bg-amber-50 text-amber-800")
                                            }
                                        >
                                            {row.status.replaceAll("_", " ")}
                                        </span>
                                    </td>
                                    <td className="px-3 text-xs capitalize">
                                        {row.financial_state.replaceAll(
                                            "_",
                                            " ",
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {!bookings.data.length && (
                    <p className="py-10 text-center text-slate-500">
                        No bookings match these filters. Open an accepted
                        quotation to prepare a booking.
                    </p>
                )}
                <Pagination links={bookings.links} />
            </Card>
        </CompanyLayout>
    );
}
