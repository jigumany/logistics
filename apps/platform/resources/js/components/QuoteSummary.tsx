import type { QuotationData } from "@logistics/types";
import { Card } from "@logistics/design-system";
import { money } from "../features/quotes/money";
export default function QuoteSummary({ quote }: { quote: QuotationData }) {
    const revision = quote.revision;
    if (!revision)
        return (
            <Card>
                <h2 className="text-xl font-bold">
                    Your request is ready to calculate
                </h2>
            </Card>
        );
    const pending =
        revision.approval === "pending" ||
        ["requires_review", "calculating"].includes(quote.status);
    const closed = ["declined", "cancelled"].includes(quote.status);
    const heading =
        quote.status === "accepted"
            ? "Quotation accepted"
            : quote.status === "declined"
              ? "Quotation declined"
              : quote.status === "cancelled"
                ? "Request cancelled"
                : quote.status === "expired" || revision.expired
                  ? "Offer expired"
                  : pending
                    ? "Your request needs a review"
                    : "Your itemised estimate";
    const activeReasons = revision.review_reasons.filter(
        (reason) => !reason.resolved,
    );
    return (
        <div className="grid gap-5">
            <Card>
                <p className="text-sm font-semibold text-teal-800">
                    {quote.reference} · Revision {revision.number}
                </p>
                <h2 className="mt-3 text-2xl font-bold">{heading}</h2>
                <p className="mt-3 text-slate-600">
                    {closed
                        ? "This request is closed. Contact the company if you would like a new offer."
                        : pending
                          ? "The operations team will confirm the route, load and price. Any amount shown is indicative."
                          : "Review the scope and assumptions below. This offer does not confirm a booking."}
                </p>
                {revision.expired && (
                    <p role="alert" className="mt-3 text-red-700">
                        This offer has expired and cannot be accepted.
                    </p>
                )}
                <p className="mt-5 text-3xl font-bold text-teal-900">
                    {money(revision.total_minor, revision.currency)}
                </p>
                <p className="mt-2 text-sm text-slate-500">
                    {revision.service_name} · {revision.pricing_method}
                </p>
            </Card>
            <Card>
                <h3 className="text-lg font-bold">
                    How the price is calculated
                </h3>
                {revision.lines.length ? (
                    <div className="overflow-x-auto">
                        <table className="mt-4 w-full text-left text-sm">
                            <thead>
                                <tr className="border-b">
                                    <th className="py-3">Charge</th>
                                    <th className="px-3">Quantity</th>
                                    <th>Rate</th>
                                    <th className="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {revision.lines.map((line, index) => (
                                    <tr
                                        className="border-b border-slate-100"
                                        key={line.code + index}
                                    >
                                        <td className="py-3">
                                            {line.description}
                                        </td>
                                        <td className="px-3">
                                            {line.rate_kind === "percent" ||
                                            line.stage === "minimum"
                                                ? "—"
                                                : `${Number(line.quantity)} ${line.quantity_unit}`}
                                        </td>
                                        <td className="px-3">
                                            {line.rate_display}
                                        </td>
                                        <td className="text-right">
                                            {money(
                                                line.amount_minor,
                                                revision.currency,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="mt-4 grid gap-2 text-right">
                            <p>
                                Subtotal{" "}
                                {money(
                                    revision.subtotal_minor,
                                    revision.currency,
                                )}
                            </p>
                            <p>
                                Tax{" "}
                                {money(revision.tax_minor, revision.currency)}
                            </p>
                            <p className="font-bold">
                                Total{" "}
                                {money(revision.total_minor, revision.currency)}
                            </p>
                        </div>
                    </div>
                ) : (
                    <p className="mt-3 text-slate-600">
                        The price will be prepared after review.
                    </p>
                )}
            </Card>
            <Card>
                <h3 className="text-lg font-bold">Journey and inventory</h3>
                <p className="mt-3 text-sm text-slate-500">
                    Preferred date: {quote.preferred_date?.slice(0, 10)}{" "}
                    {revision.preferred_time ?? ""} (indicative; availability is
                    not confirmed)
                </p>
                <ol className="mt-4 grid gap-3">
                    {revision.stops.map((stop) => (
                        <li key={stop.position}>
                            <strong className="capitalize">
                                {stop.kind}:{" "}
                            </strong>
                            {stop.address_line}, {stop.city}
                            {stop.instructions && (
                                <p className="text-sm text-slate-500">
                                    {stop.instructions}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
                <p className="mt-5">
                    {revision.inventory_summary.quantity}{" "}
                    {revision.inventory_summary.quantity === 1
                        ? "item"
                        : "items"}{" "}
                    · {Number(revision.inventory_summary.volume_m3)} m³{" "}
                    {revision.inventory_summary.unknown_volume_quantity > 0
                        ? "known volume; some dimensions need confirmation"
                        : "estimated"}{" "}
                    ·{" "}
                    {revision.inventory_summary.total_weight_kg === null
                        ? "Weight needs confirmation"
                        : Number(revision.inventory_summary.total_weight_kg) +
                          " kg estimated"}
                </p>
                <ul className="mt-3 grid gap-2 text-sm">
                    {revision.inventory.map((item) => (
                        <li key={item.public_id}>
                            {item.quantity} × {item.name}{" "}
                            <span className="text-slate-500">
                                ({item.measurement_source} measurements
                                {item.fragile ? ", fragile" : ""}
                                {item.oversized ? ", oversized" : ""})
                            </span>
                            <details className="mt-1 text-slate-600">
                                <summary className="cursor-pointer">
                                    Measurements and handling
                                </summary>
                                <p className="mt-2">
                                    {item.length_cm &&
                                    item.width_cm &&
                                    item.height_cm
                                        ? `${Number(item.length_cm)} × ${Number(item.width_cm)} × ${Number(item.height_cm)} cm`
                                        : "Dimensions need confirmation"}{" "}
                                    ·{" "}
                                    {item.weight_kg === null
                                        ? "Weight unknown"
                                        : Number(item.weight_kg) +
                                          " kg per item"}
                                </p>
                                <p>
                                    {item.stackable
                                        ? "Stackable"
                                        : "Do not stack"}{" "}
                                    ·{" "}
                                    {item.rotation_allowed
                                        ? "Rotation allowed"
                                        : "Keep the stated orientation"}
                                </p>
                                {item.instructions && (
                                    <p>{item.instructions}</p>
                                )}
                            </details>
                        </li>
                    ))}
                </ul>
                <p className="mt-4 text-sm text-slate-500">
                    Safety margin:{" "}
                    {revision.inventory_summary.safety_margin_bps / 100}%. A
                    volume and weight screen does not guarantee a packable load.
                </p>
            </Card>
            <Card>
                <h3 className="text-lg font-bold">
                    Access and service assumptions
                </h3>
                <ul className="mt-3 grid gap-2 text-sm">
                    <li>
                        Pickup: {revision.move_details.pickup_stairs} flights of
                        stairs;{" "}
                        {revision.move_details.pickup_lift
                            ? "lift declared available"
                            : "no suitable lift declared"}
                        ; {revision.move_details.pickup_carry_metres} m carrying
                        distance.
                    </li>
                    <li>
                        Delivery: {revision.move_details.delivery_stairs}{" "}
                        flights of stairs;{" "}
                        {revision.move_details.delivery_lift
                            ? "lift declared available"
                            : "no suitable lift declared"}
                        ; {revision.move_details.delivery_carry_metres} m
                        carrying distance.
                    </li>
                    <li>
                        {revision.move_details.packing
                            ? "Packing requested"
                            : "Packing not requested"}{" "}
                        · {revision.move_details.waiting_minutes} expected
                        waiting minutes.
                    </li>
                    {revision.move_details.declared_goods_value_minor !=
                        null && (
                        <li>
                            Declared goods value:{" "}
                            {money(
                                revision.move_details
                                    .declared_goods_value_minor,
                                revision.move_details.declared_goods_currency ??
                                    revision.currency,
                            )}
                            . This does not confirm insurance coverage.
                        </li>
                    )}
                    {revision.move_details.parking_limited && (
                        <li>Limited parking.</li>
                    )}
                    {revision.move_details.difficult_access && (
                        <li>Difficult access declared.</li>
                    )}
                    {revision.move_details.special_equipment && (
                        <li>Special equipment requested.</li>
                    )}
                </ul>
            </Card>
            {activeReasons.length > 0 && (
                <Card>
                    <h3 className="text-lg font-bold">
                        {revision.approval === "approved"
                            ? "Assumptions reviewed by operations"
                            : "What needs confirmation"}
                    </h3>
                    <ul className="mt-3 list-disc space-y-2 pl-5">
                        {activeReasons.map((reason, index) => (
                            <li key={reason.code + index}>
                                {reason.customer_message}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
            <Card>
                <h3 className="text-lg font-bold">Terms and exclusions</h3>
                {revision.terms_version && (
                    <p className="mt-2 text-xs text-slate-400">
                        Terms version: {revision.terms_version}
                    </p>
                )}
                <p className="mt-3 whitespace-pre-wrap">
                    {revision.terms ||
                        "Terms will be supplied with the approved quotation."}
                </p>
                {revision.exclusions && (
                    <p className="mt-3 whitespace-pre-wrap">
                        {revision.exclusions}
                    </p>
                )}
                <p className="mt-4 text-sm text-slate-500">
                    Valid until{" "}
                    {new Date(revision.expires_at).toLocaleString("en-ZA")}.
                </p>
            </Card>
        </div>
    );
}
