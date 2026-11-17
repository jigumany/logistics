import Icon, { type IconName } from "./Icon";
import type { OperationalSummary } from "../features/operations/types";
export default function OperationalOverview({
    items,
    icon,
}: {
    items: OperationalSummary;
    icon: IconName;
}) {
    return (
        <div className="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {items.map((item, i) => (
                <section
                    key={item.label}
                    className="rounded-xl border border-slate-200/80 bg-white px-5 py-5 shadow-sm shadow-slate-200/30"
                >
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-xs font-medium text-slate-500">
                            {item.label}
                        </p>
                        <span
                            className={
                                "rounded-lg p-2 " +
                                (i === 1
                                    ? "bg-teal-50 text-teal-700"
                                    : i === 2
                                      ? "bg-amber-50 text-amber-600"
                                      : "bg-slate-50 text-slate-400")
                            }
                        >
                            <Icon
                                name={
                                    i === 1 ? "check" : i === 2 ? "clock" : icon
                                }
                                width="17"
                                height="17"
                            />
                        </span>
                    </div>
                    <p className="mt-2 text-3xl font-semibold tracking-tight text-[#172b4d]">
                        {item.value.toLocaleString()}
                    </p>
                    <p className="mt-2 text-[11px] text-slate-400">
                        {item.hint}
                    </p>
                </section>
            ))}
        </div>
    );
}
