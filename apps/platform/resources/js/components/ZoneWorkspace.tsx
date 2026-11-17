import { Link } from "@inertiajs/react";
import { useState } from "react";
import MapSurface, { type PolygonBoundary } from "./maps/MapSurface";
import Icon from "./Icon";
import type { OperationalRow } from "../features/operations/types";
export default function ZoneWorkspace({
    rows,
    base,
    canEdit,
}: {
    rows: OperationalRow[];
    base: string;
    canEdit: boolean;
}) {
    const [selection, setSelected] = useState<string | null>(null);
    const [hidden, setHidden] = useState<string[]>([]);
    const selected = rows.some((row) => row.public_id === selection)
        ? selection
        : null;
    const current = rows.find((r) => r.public_id === selected);
    const colors = ["#0f766e", "#6366f1", "#d97706", "#0284c7", "#be185d"];
    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="grid lg:grid-cols-[310px_minmax(0,1fr)]">
                <div className="border-b border-slate-200 lg:border-b-0 lg:border-r">
                    <div className="border-b border-slate-100 p-5">
                        <h2 className="font-semibold">Service territories</h2>
                        <p className="mt-1 text-xs leading-5 text-slate-500">
                            Select an area to inspect its boundary and approval
                            rules.
                        </p>
                    </div>
                    <div className="max-h-[500px] overflow-y-auto">
                        {!rows.length && (
                            <p className="p-6 text-sm text-slate-500">
                                No service areas match these filters.
                            </p>
                        )}
                        {rows.map((row, i) => (
                            <div
                                key={row.public_id}
                                className={
                                    "border-b border-slate-100 p-4 transition-colors " +
                                    (selected === row.public_id
                                        ? "border-l-2 border-l-teal-600 bg-teal-50/60"
                                        : "border-l-2 border-l-transparent")
                                }
                            >
                                <div className="flex items-start gap-3">
                                    <span
                                        style={{
                                            background:
                                                colors[i % colors.length],
                                        }}
                                        className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                                    />
                                    <button
                                        className="min-w-0 flex-1 text-left"
                                        onClick={() => {
                                            setSelected(
                                                selected === row.public_id
                                                    ? null
                                                    : row.public_id,
                                            );
                                            setHidden((h) =>
                                                h.filter(
                                                    (id) =>
                                                        id !== row.public_id,
                                                ),
                                            );
                                        }}
                                        aria-pressed={
                                            selected === row.public_id
                                        }
                                    >
                                        <span className="block truncate text-sm font-semibold">
                                            {String(row.name)}
                                        </span>
                                        <span className="mt-1 block text-[11px] capitalize text-slate-500">
                                            {String(row.zone_type)} · Priority{" "}
                                            {String(row.priority)}
                                        </span>
                                    </button>
                                    <button
                                        onClick={() =>
                                            setHidden((h) =>
                                                h.includes(row.public_id)
                                                    ? h.filter(
                                                          (id) =>
                                                              id !==
                                                              row.public_id,
                                                      )
                                                    : [...h, row.public_id],
                                            )
                                        }
                                        aria-label={
                                            (hidden.includes(row.public_id)
                                                ? "Show "
                                                : "Hide ") + row.name
                                        }
                                        aria-pressed={
                                            !hidden.includes(row.public_id)
                                        }
                                        className={
                                            "rounded p-1 " +
                                            (hidden.includes(row.public_id)
                                                ? "text-slate-300"
                                                : "text-slate-500 hover:bg-slate-100")
                                        }
                                    >
                                        <Icon
                                            name="eye"
                                            width="17"
                                            height="17"
                                        />
                                    </button>
                                </div>
                                <div className="ml-5 mt-3 flex flex-wrap gap-1.5">
                                    <span
                                        className={
                                            "status-badge " +
                                            (row.active && !row.example_only
                                                ? "status-good"
                                                : "status-neutral")
                                        }
                                    >
                                        {row.example_only
                                            ? "Example only"
                                            : row.active
                                              ? "Active"
                                              : "Inactive"}
                                    </span>
                                    {Boolean(row.manual_review) && (
                                        <span className="status-badge status-warning">
                                            Manual approval
                                        </span>
                                    )}
                                </div>
                                {selected === row.public_id && (
                                    <div className="ml-5 mt-4 border-t border-teal-100 pt-3">
                                        <p className="mb-3 text-xs leading-5 text-slate-500">
                                            {String(
                                                row.description ||
                                                    "Review this area’s boundary, coverage and restrictions.",
                                            )}
                                        </p>
                                        <Link
                                            className="text-xs font-semibold text-teal-800"
                                            href={
                                                base +
                                                "/zones/" +
                                                row.public_id +
                                                "/edit"
                                            }
                                        >
                                            {canEdit
                                                ? "Edit service area"
                                                : "View service area"}{" "}
                                            ↗
                                        </Link>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
                <div className="relative min-w-0 bg-slate-100 p-3 sm:p-4">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <span className="rounded-md bg-white px-3 py-1.5 text-xs font-medium shadow-sm">
                            {current
                                ? String(current.name)
                                : "Cape Town & surrounding areas"}
                        </span>
                        {selected && (
                            <button
                                className="text-xs font-medium text-teal-800"
                                onClick={() => setSelected(null)}
                            >
                                Show all areas
                            </button>
                        )}
                    </div>
                    <MapSurface
                        heightClassName="h-[420px] xl:h-[500px]"
                        polygons={rows
                            .filter(
                                (r) =>
                                    !hidden.includes(r.public_id) &&
                                    (!selected || r.public_id === selected),
                            )
                            .map((r) => r.boundary as PolygonBoundary)}
                        polygonColors={rows
                            .filter(
                                (r) =>
                                    !hidden.includes(r.public_id) &&
                                    (!selected || r.public_id === selected),
                            )
                            .map(
                                (r) => colors[rows.indexOf(r) % colors.length],
                            )}
                    />
                    <div className="mt-3 flex items-start gap-2 text-[11px] leading-5 text-slate-500">
                        <Icon name="map" className="mt-0.5 h-4 w-4 shrink-0" />
                        <p>
                            Areas on this page only. Inactive and example areas
                            do not establish coverage. Overlapping active areas
                            use the highest priority, then the earliest created
                            area.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    );
}
