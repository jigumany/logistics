import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Button, Card } from "@logistics/design-system";
import type { SharedProps } from "@logistics/types";
import CompanyLayout from "../../layouts/CompanyLayout";
import LookupSelect from "../../components/LookupSelect";
import MapSurface, {
    type PolygonBoundary,
} from "../../components/maps/MapSurface";
import {
    modules,
    humanize,
    weekdayName,
} from "../../features/operations/config";
type RecordRow = Record<string, unknown> & { public_id: string };
export default function Index({
    module,
    records,
    filters,
}: {
    module: string;
    records: {
        data: RecordRow[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Record<string, string>;
}) {
    const config = modules[module];
    const { company, auth } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug;
    const canEdit = auth.role === "owner" || auth.role === "admin";
    const [search, setSearch] = useState(filters.search ?? "");
    const display = (row: RecordRow, column: string) => {
        const value = row[column];
        if (typeof value === "boolean")
            return (
                <span
                    className={
                        "rounded px-2 py-1 text-xs " +
                        (value
                            ? "bg-teal-50 text-teal-800"
                            : "bg-slate-100 text-slate-600")
                    }
                >
                    {value ? "Yes" : "No"}
                </span>
            );
        if (value === null || value === undefined)
            return column === "resource_name" ? "Company" : "—";
        return column === "weekday"
            ? weekdayName(String(value))
            : String(value);
    };
    return (
        <CompanyLayout>
            <Head title={config.title} />
            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold">{config.title}</h1>
                    <p className="mt-2 text-sm text-slate-500">
                        {records.total} records ·{" "}
                        {canEdit
                            ? "Manage your company configuration."
                            : "Read access to your company operation."}
                    </p>
                </div>
                {canEdit && config.creatable !== false && (
                    <Button asChild>
                        <Link href={base + "/" + module + "/create"}>
                            Add {config.singular.toLowerCase()}
                        </Link>
                    </Button>
                )}
            </div>
            {module.startsWith("calendar/") && (
                <nav
                    aria-label="Calendar sections"
                    className="mb-6 flex flex-wrap gap-4 text-sm"
                >
                    {["weekly", "overrides", "blocks"].map((kind) => (
                        <Link
                            className="rounded border bg-white px-4 py-2"
                            href={base + "/calendar/" + kind}
                            key={kind}
                        >
                            {modules["calendar/" + kind].title}
                        </Link>
                    ))}
                </nav>
            )}
            {module.startsWith("calendar/") && (
                <div className="mb-6 max-w-lg">
                    <LookupSelect
                        base={base}
                        kind="resources"
                        label="Resource filter"
                        emptyLabel="All company and resource calendars"
                        value={filters.resource ?? ""}
                        onChange={(resource) =>
                            router.get(
                                base + "/" + module,
                                { resource, page: 1 },
                                { preserveState: true },
                            )
                        }
                    />
                </div>
            )}
            {!module.startsWith("calendar/") && (
                <form
                    className="mb-5 flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            base + "/" + module,
                            { ...filters, search, page: 1 },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <label className="min-w-48 flex-1">
                        Search
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search records…"
                        />
                    </label>
                    {config.statuses && (
                        <label>
                            Filter
                            <select
                                value={filters.status ?? ""}
                                onChange={(event) =>
                                    router.get(
                                        base + "/" + module,
                                        {
                                            ...filters,
                                            status: event.target.value,
                                            page: 1,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            >
                                <option value="">All statuses</option>
                                {config.statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {humanize(status)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {config.archive && (
                        <label className="flex items-center gap-2 pb-3">
                            <input
                                className="w-auto"
                                type="checkbox"
                                checked={filters.archived === "1"}
                                onChange={(event) =>
                                    router.get(
                                        base + "/" + module,
                                        {
                                            ...filters,
                                            archived: event.target.checked
                                                ? "1"
                                                : "0",
                                            page: 1,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            />
                            Archived
                        </label>
                    )}
                    <Button type="submit">Search</Button>
                </form>
            )}
            {(module === "zones" || module === "depots") &&
                records.data.length > 0 && (
                    <Card className="mb-6">
                        <MapSurface
                            polygons={
                                module === "zones"
                                    ? records.data.map(
                                          (row) =>
                                              row.boundary as PolygonBoundary,
                                      )
                                    : []
                            }
                            points={
                                module === "depots"
                                    ? records.data.map((row) => ({
                                          latitude: Number(row.latitude),
                                          longitude: Number(row.longitude),
                                          label: String(row.name),
                                      }))
                                    : []
                            }
                        />
                        <p className="mt-3 text-xs text-slate-500">
                            Showing locations from this page. Example service
                            areas are not verified operating coverage.
                        </p>
                    </Card>
                )}
            <Card className="overflow-hidden p-0">
                {!records.data.length ? (
                    <div className="p-10 text-center">
                        <h2 className="font-semibold">No records found</h2>
                        <p className="mt-2 text-sm text-slate-500">
                            {search
                                ? "Try another search or clear the filters."
                                : canEdit
                                  ? "Add your first record to configure this part of your operation."
                                  : "A company administrator can add records here."}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    {config.columns.map((column) => (
                                        <th
                                            className="whitespace-nowrap px-5 py-4"
                                            key={column}
                                        >
                                            {humanize(column)}
                                        </th>
                                    ))}
                                    <th className="px-5 py-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {records.data.map((row) => (
                                    <tr
                                        className="border-t border-slate-100"
                                        key={row.public_id}
                                    >
                                        {config.columns.map((column) => (
                                            <td
                                                className="max-w-xs px-5 py-4"
                                                key={column}
                                            >
                                                {display(row, column)}
                                            </td>
                                        ))}
                                        <td className="whitespace-nowrap px-5 py-4">
                                            <Link
                                                className="font-medium text-teal-800 underline"
                                                href={
                                                    base +
                                                    "/" +
                                                    module +
                                                    "/" +
                                                    row.public_id +
                                                    "/edit"
                                                }
                                            >
                                                {canEdit ? "Edit" : "View"}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            <div className="mt-5 flex items-center justify-between text-sm text-slate-500">
                <p>
                    {records.from ?? 0}–{records.to ?? 0} of {records.total}
                </p>
                <nav aria-label="Pagination" className="flex gap-4">
                    {records.prev_page_url && (
                        <Link href={records.prev_page_url}>Previous</Link>
                    )}
                    {records.next_page_url && (
                        <Link href={records.next_page_url}>Next</Link>
                    )}
                </nav>
            </div>
            {module === "zones" && <CoverageCheck base={base} />}
        </CompanyLayout>
    );
}
function CoverageCheck({ base }: { base: string }) {
    const [latitude, setLatitude] = useState("");
    const [longitude, setLongitude] = useState("");
    const [result, setResult] = useState("");
    const [busy, setBusy] = useState(false);
    return (
        <Card className="mt-6">
            <h2 className="mb-4 font-semibold">Check configured coverage</h2>
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={async (event) => {
                    event.preventDefault();
                    setBusy(true);
                    try {
                        const response = await fetch(
                            base +
                                "/coverage?" +
                                new URLSearchParams({
                                    latitude,
                                    longitude,
                                    purpose: "pickup",
                                }),
                            { headers: { Accept: "application/json" } },
                        );
                        if (!response.ok)
                            throw new Error(
                                "Coverage check unavailable. Confirm that PostGIS is running.",
                            );
                        const data = await response.json();
                        setResult(
                            data.covered
                                ? "Covered by " +
                                      data.zone.name +
                                      (data.zone.manual_review
                                          ? " · manual review required"
                                          : "")
                                : "Outside active verified service areas.",
                        );
                    } catch (error) {
                        setResult(
                            error instanceof Error
                                ? error.message
                                : "Unable to check coverage.",
                        );
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <label>
                    Latitude
                    <input
                        required
                        type="number"
                        step="any"
                        min="-90"
                        max="90"
                        value={latitude}
                        onChange={(event) => setLatitude(event.target.value)}
                    />
                </label>
                <label>
                    Longitude
                    <input
                        required
                        type="number"
                        step="any"
                        min="-180"
                        max="180"
                        value={longitude}
                        onChange={(event) => setLongitude(event.target.value)}
                    />
                </label>
                <Button disabled={busy}>Check pickup coverage</Button>
            </form>
            {result && (
                <p role="status" className="mt-4 text-sm">
                    {result} Coordinates still require operator verification.
                </p>
            )}
        </Card>
    );
}
