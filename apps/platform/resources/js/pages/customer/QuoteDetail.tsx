import { useRef, useState } from "react";
import type { QuotationData, QuoteRevisionData } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import QuotationLayout from "../../layouts/QuotationLayout";
import QuoteSummary from "../../components/QuoteSummary";
import { downloadQuotePdf, quoteRequest } from "../../features/quotes/http";
export default function QuoteDetail({
    business,
    quote: initial,
}: {
    business: { name: string; slug: string };
    quote: QuotationData;
}) {
    const [quote, setQuote] = useState(initial);
    const [agree, setAgree] = useState(false);
    const [message, setMessage] = useState("");
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState("");
    const [error, setError] = useState("");
    const [revisions, setRevisions] = useState<QuoteRevisionData[]>([]);
    const [nextPage, setNextPage] = useState<string | null>(null);
    const [responseKey, setResponseKey] = useState(() => crypto.randomUUID());
    const pending = useRef(false);
    async function action(operation: () => Promise<void>) {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError("");
        try {
            await operation();
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : "The request could not be completed.",
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    async function accept() {
        await action(async () => {
            if (!quote.revision || !agree)
                throw new Error("Confirm the terms before accepting.");
            setQuote(
                await quoteRequest<QuotationData>(quote.links.accept, {
                    revision_public_id: quote.revision.public_id,
                    agree: true,
                }),
            );
            setNotice(
                "Your quotation has been accepted. A booking and vehicle allocation are still to be confirmed separately.",
            );
        });
    }
    async function respond(event: React.FormEvent) {
        event.preventDefault();
        await action(async () => {
            setQuote(
                await quoteRequest<QuotationData>(quote.links.respond, {
                    message,
                    request_key: responseKey,
                    lock_version: quote.lock_version,
                }),
            );
            setMessage("");
            setResponseKey(crypto.randomUUID());
            setNotice("Your information was sent to the operations team.");
        });
    }
    async function history(url = quote.links.revisions) {
        await action(async () => {
            const data = await quoteRequest<{
                data: QuoteRevisionData[];
                next_page_url: string | null;
            }>(url);
            setRevisions(
                url === quote.links.revisions
                    ? data.data
                    : [...revisions, ...data.data],
            );
            setNextPage(data.next_page_url);
        });
    }
    const canAccept =
        quote.status === "quoted" &&
        !!quote.submitted_at &&
        quote.revision?.approval !== "pending" &&
        !quote.revision?.expired;
    return (
        <QuotationLayout
            business={business}
            title={"Quotation " + quote.reference}
        >
            <div className="mb-7">
                <p className="text-sm font-bold text-teal-800">
                    YOUR QUOTATION
                </p>
                <h1 className="mt-3 text-3xl font-bold">
                    {quote.status === "accepted"
                        ? "Thank you. Your offer is accepted."
                        : quote.status === "requires_review"
                          ? "We’re reviewing your request."
                          : quote.status === "declined"
                            ? "You declined this offer."
                            : quote.status === "cancelled"
                              ? "This request was cancelled."
                              : quote.status === "expired" ||
                                  quote.revision?.expired
                                ? "This offer has expired."
                                : "Review your move."}
                </h1>
                <p className="mt-3 text-slate-600">
                    Keep this private link safe. It expires and gives access to
                    this quotation.
                </p>
            </div>
            {notice && (
                <p role="status" className="mb-5 rounded-lg bg-teal-100 p-4">
                    {notice}
                </p>
            )}
            {error && (
                <p
                    role="alert"
                    className="mb-5 rounded-lg bg-red-50 p-4 text-red-800"
                >
                    {error}
                </p>
            )}
            <QuoteSummary quote={quote} />
            <div className="mt-6 grid gap-5">
                {canAccept && (
                    <Card>
                        <h2 className="text-xl font-bold">Accept this offer</h2>
                        <label className="my-5 flex items-start gap-3">
                            <input
                                className="!w-auto"
                                type="checkbox"
                                checked={agree}
                                onChange={(event) =>
                                    setAgree(event.target.checked)
                                }
                            />
                            I accept the terms, exclusions and current quotation
                            amount. I understand this does not reserve a vehicle
                            or confirm a booking.
                        </label>
                        <Button disabled={busy || !agree} onClick={accept}>
                            Accept quotation
                        </Button>
                    </Card>
                )}
                {quote.status === "requires_review" && quote.submitted_at && (
                    <Card>
                        <h2 className="text-xl font-bold">
                            Send more information
                        </h2>
                        {quote.information_request && (
                            <p className="mt-3 whitespace-pre-wrap">
                                {quote.information_request}
                            </p>
                        )}
                        <form className="mt-4 grid gap-4" onSubmit={respond}>
                            <label>
                                Additional information
                                <textarea
                                    className="rounded-lg border border-slate-300 p-3"
                                    required
                                    maxLength={5000}
                                    value={message}
                                    onChange={(event) =>
                                        setMessage(event.target.value)
                                    }
                                />
                            </label>
                            <Button type="submit" disabled={busy}>
                                Send information
                            </Button>
                        </form>
                    </Card>
                )}
                {quote.attachments.length > 0 && (
                    <Card>
                        <h2 className="text-xl font-bold">Inventory photos</h2>
                        <ul className="mt-3 space-y-2">
                            {quote.attachments.map((file, index) => (
                                <li key={file.public_id}>
                                    <a
                                        className="text-teal-800 underline"
                                        href={file.url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Download inventory photo {index + 1}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                <Card>
                    <div className="flex flex-wrap gap-3">
                        {quote.submitted_at && (
                            <Button
                                disabled={busy}
                                variant="outline"
                                onClick={() =>
                                    action(async () =>
                                        setNotice(
                                            (await downloadQuotePdf(
                                                quote.links.pdf,
                                            )) ?? "Quotation PDF downloaded.",
                                        ),
                                    )
                                }
                            >
                                Download quotation PDF
                            </Button>
                        )}
                        <Button
                            disabled={busy}
                            variant="outline"
                            onClick={() =>
                                action(async () => {
                                    await navigator.clipboard.writeText(
                                        quote.links.view,
                                    );
                                    setNotice("Private quotation link copied.");
                                })
                            }
                        >
                            Copy private link
                        </Button>
                        {["quoted", "requires_review"].includes(
                            quote.status,
                        ) && (
                            <Button
                                disabled={busy}
                                variant="outline"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            "Close this quotation request? It cannot be accepted after closing.",
                                        )
                                    )
                                        action(async () => {
                                            const operation =
                                                quote.status === "quoted"
                                                    ? "decline"
                                                    : "cancel";
                                            setQuote(
                                                await quoteRequest<QuotationData>(
                                                    quote.links[operation],
                                                    {
                                                        lock_version:
                                                            quote.lock_version,
                                                    },
                                                ),
                                            );
                                            setNotice(
                                                "Quotation request closed.",
                                            );
                                        });
                                }}
                            >
                                {quote.status === "quoted"
                                    ? "Decline offer"
                                    : "Cancel request"}
                            </Button>
                        )}
                        <Button
                            disabled={busy}
                            variant="outline"
                            onClick={() => history()}
                        >
                            Revision history
                        </Button>
                        <Button
                            disabled={busy}
                            variant="outline"
                            onClick={() =>
                                action(async () => {
                                    setQuote(
                                        await quoteRequest<QuotationData>(
                                            quote.links.view,
                                        ),
                                    );
                                    setNotice("Quotation refreshed.");
                                })
                            }
                        >
                            Refresh quotation
                        </Button>
                    </div>
                    {revisions.length > 0 && (
                        <div className="mt-5 grid gap-3">
                            {revisions.map((revision) => (
                                <details
                                    key={revision.public_id}
                                    className="rounded-lg border p-3"
                                >
                                    <summary className="cursor-pointer font-semibold">
                                        Revision {revision.number} ·{" "}
                                        {revision.approval}
                                    </summary>
                                    <p className="mt-3">
                                        {revision.currency}{" "}
                                        {revision.total_minor === null
                                            ? "Awaiting pricing"
                                            : (
                                                  revision.total_minor / 100
                                              ).toFixed(2)}{" "}
                                        · valid until{" "}
                                        {new Date(
                                            revision.expires_at,
                                        ).toLocaleDateString("en-ZA")}
                                    </p>
                                    <ul className="mt-3 text-sm">
                                        {revision.lines.map((line) => (
                                            <li key={line.code}>
                                                {line.description}:{" "}
                                                {revision.currency}{" "}
                                                {(
                                                    line.amount_minor / 100
                                                ).toFixed(2)}
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            ))}
                            {nextPage && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => history(nextPage)}
                                >
                                    Earlier revisions
                                </Button>
                            )}
                        </div>
                    )}
                </Card>
            </div>
        </QuotationLayout>
    );
}
