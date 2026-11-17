import { Link } from "@inertiajs/react";
import type { OperationalRow } from "../features/operations/types";
export default function WeeklySchedule({
    rows,
    base,
}: {
    rows: OperationalRow[];
    base: string;
}) {
    const groups = new Map<string, OperationalRow[]>();
    for (const row of rows) {
        const key = String(row.resource_public_id ?? "company");
        groups.set(key, [...(groups.get(key) ?? []), row]);
    }
    const days = [1, 2, 3, 4, 5, 6, 0];
    const names = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    return (
        <section
            aria-label="Weekly working hours"
            className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
        >
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 className="text-sm font-semibold">Your working week</h2>
                    <p className="mt-1 text-xs text-slate-500">
                        Recurring hours by resource · each entry uses its own
                        time zone
                    </p>
                </div>
                <span className="status-badge status-neutral">
                    Weekly pattern
                </span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[960px] table-fixed border-collapse text-left">
                    <thead>
                        <tr className="bg-slate-50/80">
                            <th className="w-52 px-5 py-4 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                Team / resource
                            </th>
                            {days.map((d) => (
                                <th
                                    key={d}
                                    className={
                                        "border-l border-slate-200 px-3 py-4 text-xs font-medium " +
                                        (d === 0 || d === 6
                                            ? "text-slate-400"
                                            : "text-slate-600")
                                    }
                                >
                                    {names[d]}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from(groups.entries()).map(
                            ([key, entries], index) => (
                                <tr
                                    key={key}
                                    className="border-t border-slate-200"
                                >
                                    <th className="px-5 py-6 align-top">
                                        <div className="flex items-center gap-3">
                                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs text-slate-500">
                                                {String(
                                                    entries[0].resource_name ??
                                                        "Company",
                                                ).slice(0, 1)}
                                            </span>
                                            <div>
                                                <p className="text-xs font-semibold">
                                                    {String(
                                                        entries[0]
                                                            .resource_name ??
                                                            "Company hours",
                                                    )}
                                                </p>
                                                <p className="mt-1 text-[10px] font-normal text-slate-400">
                                                    {key === "company"
                                                        ? "Default working hours"
                                                        : "Resource schedule"}
                                                </p>
                                            </div>
                                        </div>
                                    </th>
                                    {days.map((d) => (
                                        <td
                                            key={d}
                                            className={
                                                "h-32 border-l border-slate-200 p-2 align-top " +
                                                (d === 0 || d === 6
                                                    ? "bg-slate-50/50"
                                                    : "")
                                            }
                                        >
                                            {entries
                                                .filter(
                                                    (r) =>
                                                        Number(r.weekday) === d,
                                                )
                                                .map((r) => (
                                                    <Link
                                                        key={r.public_id}
                                                        href={
                                                            base +
                                                            "/calendar/weekly/" +
                                                            r.public_id +
                                                            "/edit"
                                                        }
                                                        className={
                                                            "mb-2 block rounded-lg border-l-[3px] p-3 text-xs transition-shadow hover:shadow-md " +
                                                            (!r.active
                                                                ? "border-slate-300 bg-slate-100 text-slate-500"
                                                                : index % 2
                                                                  ? "border-indigo-400 bg-indigo-50 text-indigo-800"
                                                                  : "border-teal-500 bg-teal-50 text-teal-900")
                                                        }
                                                    >
                                                        <span className="block font-semibold">
                                                            {String(r.opens_at)}{" "}
                                                            –{" "}
                                                            {String(
                                                                r.closes_at,
                                                            )}
                                                        </span>
                                                        <span className="mt-1 block text-[10px] opacity-70">
                                                            {r.ends_next_day
                                                                ? "+1 day · "
                                                                : ""}
                                                            {String(r.timezone)}
                                                        </span>
                                                        <span className="mt-2 block text-[10px]">
                                                            {r.active
                                                                ? "Working hours"
                                                                : "Inactive"}
                                                        </span>
                                                    </Link>
                                                ))}
                                        </td>
                                    ))}
                                </tr>
                            ),
                        )}
                    </tbody>
                </table>
            </div>
            {!rows.length && (
                <div className="p-12 text-center">
                    <p className="text-sm font-semibold">
                        Build your working week
                    </p>
                    <p className="mt-2 text-xs text-slate-500">
                        Add company hours or a driver, mover or vehicle
                        schedule.
                    </p>
                </div>
            )}
            <p className="border-t border-slate-100 px-5 py-3 text-[11px] text-slate-400">
                Showing entries on this page. An empty day has no entry on this
                page; working hours do not confirm resource availability.
            </p>
        </section>
    );
}
