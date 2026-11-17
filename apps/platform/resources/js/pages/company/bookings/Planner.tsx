import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import Pagination, { type Paged } from "../../../components/Pagination";
import { displayDate, displayTime } from "../../../features/bookings/types";
type Event = {
    public_id: string;
    resource_public_id: string;
    starts_at: string;
    ends_at: string;
    pickup_at?: string;
    service_minutes?: number;
    state: string;
    label: string;
    booking_public_id?: string;
    quote_public_id?: string;
};
type ResourceRow = {
    public_id: string;
    kind: string;
    label: string;
    active: boolean;
    utilisation_percent: number | null;
};
export default function Planner({
    resources,
    events,
    filters,
    date,
    days,
    timezone,
    conflicts,
}: {
    resources: Paged<ResourceRow>;
    events: Event[];
    filters: Record<string, string>;
    date: string;
    days: number;
    timezone: string;
    conflicts: {
        reason: string;
        created_at: string;
        details: { starts_at?: string };
    }[];
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug;
    const [form, setForm] = useState({
        date,
        view: days === 1 ? "day" : "week",
        kind: "vehicle",
        status: "",
        resource: "",
        ...filters,
    });
    const dates = Array.from({ length: days }, (_, index) => {
        const d = new Date(date + "T12:00:00Z");
        d.setUTCDate(d.getUTCDate() + index);
        return d.toISOString().slice(0, 10);
    });
    const eventDate = (value: string) =>
        new Date(value).toLocaleDateString("en-CA", { timeZone: timezone });
    function navigate(values: Partial<typeof form>) {
        const next = { ...form, ...values };
        setForm(next);
        router.get(base + "/planner", next);
    }
    function shift(delta: number) {
        const next = new Date(date + "T12:00:00Z");
        next.setUTCDate(next.getUTCDate() + delta);
        navigate({ date: next.toISOString().slice(0, 10) });
    }
    return (
        <CompanyLayout>
            <Head title="Operations planner" />
            <div className="mb-7 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-bold uppercase tracking-widest text-teal-700">
                        Scheduling workspace
                    </p>
                    <h1 className="mt-2 text-3xl font-bold">
                        Operations planner
                    </h1>
                    <p className="mt-2 text-slate-500">
                        Truck, driver and crew reservations · {timezone}
                    </p>
                </div>
                <Button asChild variant="outline">
                    <Link href={base + "/bookings"}>Booking list</Link>
                </Button>
            </div>
            <Card>
                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            aria-label="Previous scheduling period"
                            onClick={() => shift(-days)}
                        >
                            ←
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                navigate({
                                    date: new Date().toLocaleDateString(
                                        "en-CA",
                                        { timeZone: timezone },
                                    ),
                                })
                            }
                        >
                            Today
                        </Button>
                        <Button
                            variant="outline"
                            aria-label="Next scheduling period"
                            onClick={() => shift(days)}
                        >
                            →
                        </Button>
                    </div>
                    <label>
                        Planner date
                        <input
                            type="date"
                            value={form.date}
                            onChange={(e) => navigate({ date: e.target.value })}
                        />
                    </label>
                    <label>
                        View
                        <select
                            aria-label="Planner view"
                            value={form.view}
                            onChange={(e) => navigate({ view: e.target.value })}
                        >
                            <option value="day">Daily</option>
                            <option value="week">Weekly</option>
                        </select>
                    </label>
                    <label>
                        Resources
                        <select
                            aria-label="Planner resource kind"
                            value={form.kind}
                            onChange={(e) =>
                                navigate({ kind: e.target.value, resource: "" })
                            }
                        >
                            <option value="vehicle">Vehicles</option>
                            <option value="staff">Drivers and movers</option>
                        </select>
                    </label>
                    <label>
                        Status
                        <select
                            aria-label="Planner status"
                            value={form.status}
                            onChange={(e) =>
                                navigate({ status: e.target.value })
                            }
                        >
                            <option value="">All reservations</option>
                            <option value="held">Held</option>
                            <option value="confirmed">Confirmed</option>
                        </select>
                    </label>
                    <label>
                        Resource filter
                        <select
                            aria-label="Planner resource filter"
                            value={form.resource}
                            onChange={(e) =>
                                navigate({ resource: e.target.value })
                            }
                        >
                            <option value="">All shown resources</option>
                            {resources.data.map((resource) => (
                                <option
                                    key={resource.public_id}
                                    value={resource.public_id}
                                >
                                    {resource.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button variant="outline" onClick={() => router.reload()}>
                        Refresh
                    </Button>
                </div>
                <div className="mt-5 flex flex-wrap gap-4 text-xs">
                    <span className="rounded bg-teal-100 px-3 py-2 text-teal-900">
                        Confirmed reservation
                    </span>
                    <span className="rounded bg-blue-100 px-3 py-2 text-blue-900">
                        Temporary hold
                    </span>
                    <span className="rounded bg-slate-200 px-3 py-2 text-slate-700">
                        Maintenance / leave / blackout
                    </span>
                </div>
                <div className="mt-6 overflow-x-auto rounded-xl border border-slate-200">
                    <div style={{ minWidth: days === 1 ? 650 : 1100 }}>
                        <div
                            className="grid bg-slate-50"
                            style={{
                                gridTemplateColumns: `200px repeat(${days}, minmax(120px,1fr))`,
                            }}
                        >
                            <div className="p-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Resource
                            </div>
                            {dates.map((day) => (
                                <div
                                    className="border-l border-slate-200 p-4 text-center text-sm font-semibold"
                                    key={day}
                                >
                                    {displayDate(day + "T12:00:00Z", timezone)}
                                </div>
                            ))}
                        </div>
                        {resources.data.map((resource) => (
                            <div
                                className="grid border-t border-slate-200"
                                style={{
                                    gridTemplateColumns: `200px repeat(${days}, minmax(120px,1fr))`,
                                }}
                                key={resource.public_id}
                            >
                                <div className="p-4">
                                    <p className="font-semibold">
                                        {resource.label}
                                    </p>
                                    <p className="mt-1 text-xs capitalize text-slate-500">
                                        {resource.kind} ·{" "}
                                        {resource.active
                                            ? "Active"
                                            : "Unavailable"}
                                    </p>
                                    <p className="mt-3 text-xs text-teal-700">
                                        {resource.utilisation_percent === null
                                            ? "No working window"
                                            : resource.utilisation_percent +
                                              "% reserved"}
                                    </p>
                                </div>
                                {dates.map((day) => (
                                    <div
                                        className="min-h-28 space-y-2 border-l border-slate-100 p-2"
                                        key={day}
                                    >
                                        {events
                                            .filter(
                                                (event) =>
                                                    event.resource_public_id ===
                                                        resource.public_id &&
                                                    eventDate(
                                                        event.starts_at,
                                                    ) <= day &&
                                                    eventDate(event.ends_at) >=
                                                        day,
                                            )
                                            .map((event) => {
                                                const content = (
                                                    <>
                                                        <strong className="block text-xs">
                                                            {event.label}
                                                        </strong>
                                                        <span className="mt-1 block text-xs">
                                                            {displayTime(
                                                                event.starts_at,
                                                                timezone,
                                                            )}
                                                            –
                                                            {displayTime(
                                                                event.ends_at,
                                                                timezone,
                                                            )}
                                                        </span>
                                                        {event.pickup_at && (
                                                            <span className="mt-1 block text-xs opacity-80">
                                                                Pickup{" "}
                                                                {displayTime(
                                                                    event.pickup_at,
                                                                    timezone,
                                                                )}{" "}
                                                                · ~
                                                                {
                                                                    event.service_minutes
                                                                }{" "}
                                                                min
                                                            </span>
                                                        )}
                                                    </>
                                                );
                                                const cls =
                                                    "block rounded-lg border-l-4 p-3 " +
                                                    (event.state === "confirmed"
                                                        ? "border-teal-600 bg-teal-50 text-teal-900"
                                                        : event.state === "held"
                                                          ? "border-blue-500 bg-blue-50 text-blue-900"
                                                          : "border-slate-400 bg-slate-100 text-slate-700");
                                                return event.state ===
                                                    "blocked" ? (
                                                    <div
                                                        className={cls}
                                                        key={event.public_id}
                                                    >
                                                        {content}
                                                    </div>
                                                ) : (
                                                    <Link
                                                        className={cls}
                                                        key={event.public_id}
                                                        href={
                                                            event.booking_public_id
                                                                ? base +
                                                                  "/bookings/" +
                                                                  event.booking_public_id
                                                                : base +
                                                                  "/bookings/quote/" +
                                                                  event.quote_public_id
                                                        }
                                                    >
                                                        {content}
                                                    </Link>
                                                );
                                            })}
                                    </div>
                                ))}
                            </div>
                        ))}
                        {!resources.data.length && (
                            <p className="p-10 text-center text-slate-500">
                                No resources match this view.
                            </p>
                        )}
                    </div>
                </div>
                <Pagination links={resources.links} />
                <p className="mt-4 text-xs text-slate-500">
                    Internal allocation intervals include configured
                    repositioning and turnaround. Open a booking to make
                    validated changes; this view does not allow direct
                    drag-and-drop allocation.
                </p>
            </Card>
            {conflicts.length > 0 && (
                <Card className="mt-6">
                    <h2 className="text-lg font-semibold">
                        Recent scheduling conflicts
                    </h2>
                    <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                        {conflicts.map((conflict, index) => (
                            <li
                                className="rounded-lg border border-amber-100 bg-amber-50 p-4 text-sm"
                                key={index}
                            >
                                <p>{conflict.reason}</p>
                                <p className="mt-2 text-xs text-slate-500">
                                    {displayDate(conflict.created_at, timezone)}{" "}
                                    ·{" "}
                                    {displayTime(conflict.created_at, timezone)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </CompanyLayout>
    );
}
