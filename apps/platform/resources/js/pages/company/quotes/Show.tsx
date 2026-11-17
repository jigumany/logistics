import { Head, useForm, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import PricingExplanation from "../../../components/PricingExplanation";
import CompanyLayout from "../../../layouts/CompanyLayout";
import QuoteSummary from "../../../components/QuoteSummary";
import Pagination, { type Paged } from "../../../components/Pagination";
import type {
    AdminQuote,
    InternalRevision,
} from "../../../features/quotes/adminTypes";
import { localDateTime } from "../../../features/quotes/date";
import {
    money,
    parseScaledDecimal,
    scaledDecimal,
} from "../../../features/quotes/money";
import { downloadQuotePdf } from "../../../features/quotes/http";
function ReviewPanel({ quote, base }: { quote: AdminQuote; base: string }) {
    const revision = quote.revision;
    const [error, setError] = useState("");
    const form = useForm({
        decision: "note",
        reason: "",
        currency: revision?.currency ?? "ZAR",
        lines: revision?.lines.some((line) => line.stage !== "tax")
            ? revision.lines
                  .filter((line) => line.stage !== "tax")
                  .map((line) => ({
                      description: line.description,
                      amount: scaledDecimal(line.amount_minor, 2),
                  }))
            : [{ description: "Reviewed transport service", amount: "" }],
        tax_percent: revision?.subtotal_minor
            ? (
                  ((revision.tax_minor ?? 0) * 100) /
                  revision.subtotal_minor
              ).toFixed(2)
            : "0",
        expires_at: localDateTime(Date.now() + 72 * 3600000),
        terms: revision?.terms ?? "",
        exclusions: revision?.exclusions ?? "",
    });
    const manual = ["approve", "revise"].includes(form.data.decision);
    function submit(event: React.FormEvent) {
        event.preventDefault();
        setError("");
        try {
            const common = {
                decision: form.data.decision,
                reason: form.data.reason,
                lock_version: quote.lock_version,
            };
            if (manual) {
                const lines = form.data.lines.map((line) => ({
                    description: line.description,
                    amount_minor:
                        parseScaledDecimal(line.amount.replace(/^\-/, ""), 2) *
                        (line.amount.startsWith("-") ? -1 : 1),
                }));
                const payload = {
                    ...common,
                    currency: form.data.currency,
                    lines,
                    tax_basis_points: parseScaledDecimal(
                        form.data.tax_percent,
                        2,
                    ),
                    expires_at: new Date(form.data.expires_at).toISOString(),
                    terms: form.data.terms,
                    exclusions: form.data.exclusions,
                };
                form.transform(() => payload);
            } else {
                form.transform(() => common);
            }
            form.post(base + "/review");
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : "Check the price amounts.",
            );
        }
    }
    return (
        <Card>
            <h2 className="text-xl font-bold">Operational review</h2>
            <form className="mt-5 grid gap-5" onSubmit={submit}>
                <label>
                    Decision
                    <select
                        aria-label="Decision"
                        value={form.data.decision}
                        onChange={(event) =>
                            form.setData("decision", event.target.value)
                        }
                    >
                        <option value="note">Add an internal note</option>
                        <option value="approve">
                            Approve and issue a priced revision
                        </option>
                        <option value="revise">Revise the current offer</option>
                        <option value="request_information">
                            Request more information
                        </option>
                        <option value="reject">Reject this request</option>
                    </select>
                </label>
                <label>
                    {form.data.decision === "request_information"
                        ? "Message for the customer"
                        : "Internal reason / review note"}
                    <textarea
                        required
                        minLength={3}
                        maxLength={5000}
                        className="rounded-lg border border-slate-300 p-3"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData("reason", event.target.value)
                        }
                    />
                </label>
                {manual && (
                    <>
                        <p className="rounded-lg bg-amber-50 p-4 text-sm">
                            Issuing this offer creates a new immutable revision.
                            Enter the full itemised price below. A negative
                            amount is an explicit discount; tax is applied after
                            discounts. The reason is recorded in the audit
                            trail.
                        </p>
                        <label>
                            Currency
                            <select
                                aria-label="Currency"
                                value={form.data.currency}
                                onChange={(event) =>
                                    form.setData("currency", event.target.value)
                                }
                            >
                                {["ZAR", "USD", "EUR", "GBP"].map((value) => (
                                    <option key={value}>{value}</option>
                                ))}
                            </select>
                        </label>
                        {form.data.lines.map((line, index) => (
                            <div
                                className="grid items-end gap-3 sm:grid-cols-[1fr_160px_auto]"
                                key={index}
                            >
                                <label>
                                    Description
                                    <input
                                        required
                                        value={line.description}
                                        maxLength={160}
                                        onChange={(event) =>
                                            form.setData(
                                                "lines",
                                                form.data.lines.map((row, i) =>
                                                    i === index
                                                        ? {
                                                              ...row,
                                                              description:
                                                                  event.target
                                                                      .value,
                                                          }
                                                        : row,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                                <label>
                                    Amount ({form.data.currency})
                                    <input
                                        required
                                        inputMode="decimal"
                                        value={line.amount}
                                        onChange={(event) =>
                                            form.setData(
                                                "lines",
                                                form.data.lines.map((row, i) =>
                                                    i === index
                                                        ? {
                                                              ...row,
                                                              amount: event
                                                                  .target.value,
                                                          }
                                                        : row,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                                <Button
                                    variant="outline"
                                    type="button"
                                    disabled={form.data.lines.length === 1}
                                    onClick={() =>
                                        form.setData(
                                            "lines",
                                            form.data.lines.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData("lines", [
                                    ...form.data.lines,
                                    { description: "", amount: "" },
                                ])
                            }
                        >
                            Add charge or discount
                        </Button>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label>
                                Tax percentage (0 if not applicable)
                                <input
                                    required
                                    inputMode="decimal"
                                    value={form.data.tax_percent}
                                    onChange={(event) =>
                                        form.setData(
                                            "tax_percent",
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Valid until
                                <input
                                    required
                                    type="datetime-local"
                                    value={form.data.expires_at}
                                    onChange={(event) =>
                                        form.setData(
                                            "expires_at",
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        <label>
                            Terms
                            <textarea
                                required
                                maxLength={10000}
                                className="rounded-lg border border-slate-300 p-3"
                                aria-label="Terms"
                                value={form.data.terms}
                                onChange={(event) =>
                                    form.setData("terms", event.target.value)
                                }
                            />
                        </label>
                        <label>
                            Exclusions
                            <textarea
                                maxLength={10000}
                                className="rounded-lg border border-slate-300 p-3"
                                aria-label="Exclusions"
                                value={form.data.exclusions}
                                onChange={(event) =>
                                    form.setData(
                                        "exclusions",
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                    </>
                )}
                {(error || Object.keys(form.errors).length > 0) && (
                    <p role="alert" className="text-red-700">
                        {error || Object.values(form.errors).join(" ")}
                    </p>
                )}
                <Button disabled={form.processing} type="submit">
                    {manual ? "Issue approved revision" : "Record review"}
                </Button>
            </form>
        </Card>
    );
}
export default function Show({
    quote,
    history,
    revisions,
}: {
    quote: AdminQuote;
    history: Paged<{
        from_status: string | null;
        to_status: string;
        created_at: string;
    }>;
    revisions: Paged<InternalRevision>;
}) {
    const { company, auth } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/quotes/" + quote.public_id;
    const [notice, setNotice] = useState("");
    const canManage = ["owner", "admin"].includes(auth.role ?? "");
    return (
        <CompanyLayout>
            <Head title={quote.reference} />
            <div className="mb-7 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">{quote.reference}</h1>
                    <p className="mt-2 capitalize text-slate-500">
                        {quote.status.replaceAll("_", " ")} ·{" "}
                        {quote.revision?.contact_snapshot.name ??
                            "Unsubmitted guest"}
                    </p>
                </div>
                <Button
                    variant="outline"
                    onClick={async () => {
                        try {
                            setNotice(
                                (await downloadQuotePdf(base + "/pdf")) ??
                                    "PDF downloaded.",
                            );
                        } catch (error) {
                            setNotice(
                                error instanceof Error
                                    ? error.message
                                    : "PDF unavailable.",
                            );
                        }
                    }}
                >
                    Download PDF
                </Button>
            </div>
            {notice && (
                <p role="status" className="mb-5 rounded-lg bg-teal-50 p-4">
                    {notice}
                </p>
            )}
            <div className="grid items-start gap-6 lg:grid-cols-[1.15fr_1fr]">
                <div>
                    <QuoteSummary quote={quote} />
                    <Card className="mt-6">
                        <h2 className="text-xl font-bold">
                            Technical assessment
                        </h2>
                        <p className="mt-3">
                            Coverage:{" "}
                            {quote.revision?.coverage.outcome.replaceAll(
                                "_",
                                " ",
                            )}
                        </p>
                        <p className="mt-2 text-sm text-slate-500">
                            This is dimensional screening. No vehicle is
                            allocated or reserved.
                        </p>
                        {quote.revision?.suitability.map((vehicle) => (
                            <details
                                className="mt-3 rounded-lg border p-3"
                                key={vehicle.vehicle_public_id}
                            >
                                <summary className="cursor-pointer">
                                    {vehicle.vehicle_name ??
                                        vehicle.vehicle_type_key}{" "}
                                    {vehicle.vehicle_registration ?? ""} ·{" "}
                                    {vehicle.status.replaceAll("_", " ")}
                                </summary>
                                <ul className="mt-3 list-disc pl-5 text-sm">
                                    {vehicle.reasons.map((reason, index) => (
                                        <li key={reason.code + index}>
                                            {reason.explanation}
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-3 text-sm text-slate-500">
                                    {vehicle.limitation}
                                </p>
                            </details>
                        ))}
                        {quote.revision?.pricing_snapshot.metrics && (
                            <dl className="mt-5 grid grid-cols-2 gap-2 text-sm">
                                {Object.entries(
                                    quote.revision.pricing_snapshot.metrics,
                                ).map(([key, value]) => (
                                    <div key={key}>
                                        <dt className="text-slate-500">
                                            {key.replaceAll("_", " ")}
                                        </dt>
                                        <dd>{value ?? "Unknown"}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </Card>
                </div>
                <div className="grid gap-6">
                    {quote.revision && (
                        <PricingExplanation revision={quote.revision} />
                    )}
                    {canManage &&
                        ![
                            "accepted",
                            "declined",
                            "cancelled",
                            "calculating",
                        ].includes(quote.status) && (
                            <ReviewPanel
                                key={quote.lock_version}
                                quote={quote}
                                base={base}
                            />
                        )}
                    <Card>
                        <h2 className="text-xl font-bold">
                            Contact and photos
                        </h2>
                        <p className="mt-3">
                            {quote.revision?.contact_snapshot.email}
                        </p>
                        <p>{quote.revision?.contact_snapshot.phone}</p>
                        <ul className="mt-3 grid gap-2">
                            {quote.attachments.map((file, index) => (
                                <li key={file.public_id}>
                                    <a
                                        className="text-teal-800 underline"
                                        href={file.url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Inventory photo {index + 1}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card>
                        <h2 className="text-xl font-bold">
                            Review and audit history
                        </h2>
                        {quote.reviews.data.map((review) => (
                            <article
                                key={review.public_id}
                                className="mt-4 border-b pb-4"
                            >
                                <p className="font-bold capitalize">
                                    {review.decision.replaceAll("_", " ")}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-sm">
                                    {review.reason}
                                </p>
                                {review.adjustment && (
                                    <p className="mt-2 text-sm">
                                        Previous{" "}
                                        {money(
                                            review.adjustment
                                                .previous_total_minor,
                                            review.adjustment
                                                .previous_currency ??
                                                review.adjustment.currency,
                                        )}{" "}
                                        →{" "}
                                        {money(
                                            review.adjustment.total_minor,
                                            review.adjustment.currency,
                                        )}
                                    </p>
                                )}
                                <p className="mt-2 text-xs text-slate-500">
                                    {new Date(review.created_at).toLocaleString(
                                        "en-ZA",
                                    )}
                                </p>
                            </article>
                        ))}
                        <Pagination links={quote.reviews.links} />
                    </Card>
                    <Card>
                        <h2 className="text-xl font-bold">Revision history</h2>
                        {revisions.data.map((revision) => (
                            <details
                                className="mt-3 rounded-lg border p-3"
                                key={revision.public_id}
                            >
                                <summary className="cursor-pointer">
                                    Revision {revision.number} ·{" "}
                                    {revision.approval} ·{" "}
                                    {money(
                                        revision.total_minor,
                                        revision.currency,
                                    )}
                                </summary>
                                <ul className="mt-3 text-sm">
                                    {revision.lines.map((line) => (
                                        <li key={line.code}>
                                            {line.description}:{" "}
                                            {money(
                                                line.amount_minor,
                                                revision.currency,
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        ))}
                        <Pagination links={revisions.links} />
                    </Card>
                    <Card>
                        <h2 className="text-xl font-bold">Status history</h2>
                        <ol className="mt-3 grid gap-3 text-sm">
                            {history.data.map((row, index) => (
                                <li key={index}>
                                    {row.from_status ?? "New request"} →{" "}
                                    {row.to_status}
                                    <span className="ml-2 text-slate-500">
                                        {new Date(
                                            row.created_at,
                                        ).toLocaleString("en-ZA")}
                                    </span>
                                </li>
                            ))}
                        </ol>
                        <Pagination links={history.links} />
                    </Card>
                </div>
            </div>
        </CompanyLayout>
    );
}
