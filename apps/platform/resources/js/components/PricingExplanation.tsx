import { Card } from "@logistics/design-system";
import type { InternalRevision } from "../features/quotes/adminTypes";
import { money } from "../features/quotes/money";
export default function PricingExplanation({
    revision,
}: {
    revision: InternalRevision;
}) {
    const snap = revision.pricing_snapshot;
    const costs = snap.internal_costs;
    return (
        <Card>
            <h2 className="text-xl font-semibold">Why this price?</h2>
            <p className="mt-2 text-xs text-slate-500">
                Internal commercial explanation · costs, margins and operational
                inputs are hidden from customers.
            </p>
            <dl className="mt-5 grid gap-4 text-xs sm:grid-cols-2">
                {[
                    ["Calculation", snap.calculation_id],
                    ["Calculated at", snap.calculated_at],
                    [
                        "Price book",
                        snap.price_book_name ??
                            snap.price_book_public_id ??
                            snap.source_price_book?.price_book_public_id ??
                            (snap.manual_override
                                ? "Authorised manual revision"
                                : "Not configured"),
                    ],
                    [
                        "Version",
                        snap.version ?? snap.source_price_book?.version,
                    ],
                    ["Calculation engine", snap.engine_version],
                    ["Service", revision.service_name],
                    ["Vehicle class", snap.screened_vehicle_type],
                    ["Journey basis", snap.distance_basis],
                    [
                        "Quantity confidence",
                        "Estimated; actual duration and loading remain subject to review",
                    ],
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-slate-400">{label}</dt>
                        <dd className="mt-1 break-all font-medium">
                            {value ?? "Not recorded on this legacy revision"}
                        </dd>
                    </div>
                ))}
            </dl>
            {snap.readiness && (
                <details
                    open
                    className="mt-5 rounded-lg bg-amber-50 p-4 text-sm"
                >
                    <summary className="font-semibold">
                        Readiness: {snap.readiness.state.replaceAll("_", " ")}
                    </summary>
                    <ul className="mt-3 list-disc pl-5">
                        {snap.readiness.checks
                            .filter((check) => !check.passed)
                            .map((check) => (
                                <li key={check.code}>{check.label}</li>
                            ))}
                    </ul>
                </details>
            )}
            {snap.surcharge_basis === "charges_subtotal" && (
                <p className="mt-4 text-sm">
                    Percentage surcharges independently use the same base,
                    distance and labour subtotal:{" "}
                    {money(snap.surcharge_base_minor ?? 0, revision.currency)}.
                </p>
            )}
            {snap.rules && (
                <details className="mt-4">
                    <summary className="cursor-pointer font-semibold">
                        Applied rule identifiers and quantities
                    </summary>
                    <ul className="mt-3 text-xs">
                        {snap.rules
                            .filter((rule) =>
                                revision.lines.some(
                                    (line) => line.code === rule.code,
                                ),
                            )
                            .map((rule) => (
                                <li className="py-1" key={rule.code}>
                                    {rule.code} · {rule.label} ·{" "}
                                    {rule.metric
                                        ? (snap.metrics?.[rule.metric] ??
                                          "Unknown quantity")
                                        : rule.stage}
                                </li>
                            ))}
                    </ul>
                </details>
            )}
            {snap.calculation_failures?.length ? (
                <ul className="mt-4 grid gap-2 rounded-lg bg-amber-50 p-4 text-xs text-amber-800">
                    {snap.calculation_failures.map((failure, i) => (
                        <li key={i}>
                            {failure.vehicle_type_key ?? "Configuration"}:{" "}
                            {failure.message}
                        </li>
                    ))}
                </ul>
            ) : null}
            {snap.route_snapshot && (
                <details className="mt-5 rounded-lg border border-slate-200 p-3">
                    <summary className="cursor-pointer text-xs font-semibold">
                        Recorded route quantities
                    </summary>
                    <dl className="mt-3 grid gap-3 text-xs sm:grid-cols-2">
                        {Object.entries(snap.route_snapshot).map(
                            ([key, value]) => (
                                <div key={key}>
                                    <dt className="text-slate-400">
                                        {key.replaceAll("_", " ")}
                                    </dt>
                                    <dd>{value ?? "Unconfirmed"}</dd>
                                </div>
                            ),
                        )}
                    </dl>
                </details>
            )}
            <div className="mt-6 border-t border-slate-100 pt-5">
                <h3 className="font-semibold">Estimated job profitability</h3>
                {costs?.status === "estimated" ? (
                    <>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {[
                                [
                                    "Revenue excluding tax",
                                    costs.revenue_ex_tax_minor,
                                ],
                                ["Direct job cost", costs.direct_cost_minor],
                                ["Overhead contribution", costs.overhead_minor],
                                [
                                    "Contribution after overhead",
                                    costs.contribution_minor,
                                ],
                            ].map(([label, value]) => (
                                <div key={String(label)}>
                                    <p className="text-xs text-slate-400">
                                        {label}
                                    </p>
                                    <p className="mt-1 font-semibold">
                                        {money(
                                            value as number,
                                            revision.currency,
                                        )}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <p
                            className={
                                "mt-4 rounded-lg p-3 text-sm " +
                                (costs.below_minimum
                                    ? "bg-amber-50 text-amber-800"
                                    : "bg-teal-50 text-teal-800")
                            }
                        >
                            Estimated margin:{" "}
                            {costs.margin_bps == null
                                ? "Undefined"
                                : (costs.margin_bps / 100).toFixed(2) +
                                  "%"}{" "}
                            · Minimum{" "}
                            {((costs.minimum_margin_bps ?? 0) / 100).toFixed(2)}
                            % · Target{" "}
                            {((costs.target_margin_bps ?? 0) / 100).toFixed(2)}%
                            {costs.below_minimum
                                ? " · Explicit manual approval required"
                                : costs.below_target
                                  ? " · Below target"
                                  : " · Meets target"}
                        </p>
                        <p className="mt-3 text-xs text-slate-500">
                            {costs.job_hours} estimated job hours ·{" "}
                            {costs.distance_km} operating km. Margin is
                            contribution / revenue, not markup.
                        </p>
                        <ul className="mt-3 grid gap-2 text-xs">
                            {costs.lines?.map((line) => (
                                <li
                                    key={line.code}
                                    className="flex justify-between border-b border-slate-100 py-2"
                                >
                                    <span className="capitalize">
                                        {line.code.replaceAll("_", " ")}
                                    </span>
                                    <span>
                                        {money(
                                            line.amount_minor,
                                            revision.currency,
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                        {costs?.reason ??
                            "No cost estimate was recorded on this revision. This does not establish profitability."}
                    </p>
                )}
            </div>
        </Card>
    );
}
