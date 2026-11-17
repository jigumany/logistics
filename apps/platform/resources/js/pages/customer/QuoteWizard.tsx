import { useEffect, useRef, useState } from "react";
import type { QuoteContact, QuoteInput, QuotationData } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import QuotationLayout from "../../layouts/QuotationLayout";
import QuoteAddressFields from "../../components/QuoteAddressFields";
import QuoteInventoryEditor from "../../components/QuoteInventoryEditor";
import QuoteSummary from "../../components/QuoteSummary";
import {
    clearDraft,
    emptyQuoteInput,
    readDraft,
    saveDraft,
} from "../../features/quotes/draft";
import { quoteRequest, uploadPhoto } from "../../features/quotes/http";
const steps = [
    "Service",
    "Locations",
    "Inventory",
    "Move details",
    "Estimate",
    "Your information",
];
export default function QuoteWizard({
    business,
    services,
}: {
    business: { name: string; slug: string };
    services: { public_id: string; name: string; description: string | null }[];
}) {
    const [recovered] = useState(() => readDraft(business.slug));
    const [input, setInput] = useState<QuoteInput>(
        () => recovered?.input ?? emptyQuoteInput(services[0]?.public_id ?? ""),
    );
    const [step, setStep] = useState(
        Math.min(5, Math.max(0, recovered?.step ?? 0)),
    );
    const [key, setKey] = useState(() => recovered?.key ?? crypto.randomUUID());
    const [quote, setQuote] = useState<QuotationData | null>(
        recovered?.quote ?? null,
    );
    const [photos, setPhotos] = useState<Record<string, File[]>>({});
    const [contact, setContact] = useState<QuoteContact>({
        name: "",
        email: "",
        phone: "",
        consent: false,
        communication: "email",
        marketing_consent: false,
    });
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    const leaving = useRef(false);
    const [error, setError] = useState("");
    const [progress, setProgress] = useState("");
    const [storageError, setStorageError] = useState(false);
    const base = "/q/" + business.slug;
    useEffect(() => {
        setStorageError(
            !saveDraft(business.slug, {
                version: 1,
                savedAt: Date.now(),
                step,
                key,
                input,
                quote,
            }),
        );
    }, [business.slug, step, key, input, quote]);
    useEffect(() => {
        const guard = (event: BeforeUnloadEvent) => {
            if (!leaving.current && (contact.name || contact.email)) {
                event.preventDefault();
                event.returnValue = "";
            }
        };
        window.addEventListener("beforeunload", guard);
        return () => window.removeEventListener("beforeunload", guard);
    }, [contact.name, contact.email]);
    function changeInput(values: Partial<QuoteInput>) {
        setInput((previous) => ({ ...previous, ...values }));
        setError("");
    }
    async function calculate() {
        if (input.inventory.length === 0)
            throw new Error("Add at least one inventory item.");
        let current = quote;
        if (!current) {
            setProgress("Saving your draft…");
            current = await quoteRequest<QuotationData>(base + "/drafts", {
                idempotency_key: key,
                input,
                website: "",
            });
            setQuote(current);
            saveDraft(business.slug, {
                version: 1,
                savedAt: Date.now(),
                step,
                key,
                input,
                quote: current,
            });
        }
        if (current.status === "draft") {
            current = await quoteRequest<QuotationData>(current.links.save, {
                lock_version: current.lock_version,
                input,
            });
            setQuote(current);
            setProgress("Attaching inventory photos…");
            for (const item of input.inventory)
                for (const file of photos[item.item_key] ?? [])
                    await uploadPhoto(current.links.photo, item.item_key, file);
        }
        setProgress("Checking the load, service area, route and pricing…");
        current = await quoteRequest<QuotationData>(current.links.calculate, {
            lock_version: current.lock_version,
            input,
        });
        setQuote(current);
        setStep(4);
    }
    async function next(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError("");
        try {
            if (step === 2 && input.inventory.length === 0)
                throw new Error("Add at least one inventory item.");
            if (step === 2) {
                for (const item of input.inventory) {
                    const count = [
                        item.length_cm,
                        item.width_cm,
                        item.height_cm,
                    ].filter(
                        (value) =>
                            value !== null &&
                            value !== undefined &&
                            value !== "",
                    ).length;
                    if (count !== 0 && count !== 3)
                        throw new Error(
                            "Enter all three dimensions for each item, or leave all three blank for review.",
                        );
                }
                if (
                    input.inventory.reduce(
                        (sum, item) => sum + item.quantity,
                        0,
                    ) > 10000
                )
                    throw new Error(
                        "A request can contain at most 10000 items.",
                    );
            }
            if (step === 3) {
                await calculate();
            } else if (step === 5) {
                if (!quote)
                    throw new Error(
                        "Calculate your request before submitting it.",
                    );
                setProgress("Submitting your request…");
                const submitted = await quoteRequest<QuotationData>(
                    quote.links.submit,
                    { contact, lock_version: quote.lock_version },
                );
                clearDraft(business.slug);
                leaving.current = true;
                window.location.assign(submitted.links.view);
            } else {
                setStep(step + 1);
            }
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : "The request could not be completed. Your draft is safe to retry.",
            );
        } finally {
            pending.current = false;
            setBusy(false);
            setProgress("");
        }
    }
    async function refresh() {
        if (!quote) return;
        try {
            setQuote(await quoteRequest<QuotationData>(quote.links.view));
            setError("");
        } catch {
            setError(
                "The saved request could not be refreshed. Check your connection and retry.",
            );
        }
    }
    function startOver() {
        if (!window.confirm("Start a new request and clear this draft?"))
            return;
        clearDraft(business.slug);
        setKey(crypto.randomUUID());
        setInput(emptyQuoteInput(services[0]?.public_id ?? ""));
        setQuote(null);
        setPhotos({});
        setStep(0);
        setError("");
    }
    return (
        <QuotationLayout business={business} title="Request a quotation">
            <div className="mb-8">
                <p className="text-sm font-semibold text-teal-800">
                    YOUR NEXT MOVE
                </p>
                <h1 className="mt-3 text-3xl font-bold md:text-4xl">
                    Let’s work out the details.
                </h1>
                <p className="mt-3 text-slate-600">
                    Get an itemised estimate, or let our team review a more
                    complex move. No account is needed.
                </p>
            </div>
            <ol
                aria-label="Quotation progress"
                className="mb-7 grid grid-cols-3 gap-2 sm:grid-cols-6"
            >
                {steps.map((title, index) => (
                    <li
                        aria-current={index === step ? "step" : undefined}
                        className={
                            "rounded-lg p-3 text-sm " +
                            (index === step
                                ? "bg-teal-900 font-bold text-white"
                                : "bg-white text-slate-500")
                        }
                        key={title}
                    >
                        {index + 1}. {title}
                    </li>
                ))}
            </ol>
            {recovered && (
                <p className="mb-5 rounded-lg bg-teal-50 p-4 text-sm">
                    Your draft was recovered from this tab. Contact details are
                    not saved here. Reattach photos that had not finished
                    uploading.
                </p>
            )}
            {storageError && (
                <p role="alert" className="mb-4 text-amber-800">
                    This browser cannot save drafts. Keep this tab open until
                    you submit.
                </p>
            )}
            {services.length === 0 ? (
                <Card>
                    <h2 className="text-xl font-bold">
                        Quotation requests are not available right now
                    </h2>
                    <p className="mt-3">Please contact the company directly.</p>
                </Card>
            ) : (
                <form onSubmit={next}>
                    <Card>
                        <h2 className="mb-6 text-2xl font-bold">
                            {steps[step]}
                        </h2>
                        {step === 0 && (
                            <fieldset className="grid gap-4">
                                <legend className="mb-4 text-sm text-slate-600">
                                    Choose the service for this request.
                                </legend>
                                {services.map((service) => (
                                    <label
                                        key={service.public_id}
                                        className={
                                            "flex items-start gap-4 rounded-xl border p-5 " +
                                            (input.service_public_id ===
                                            service.public_id
                                                ? "border-teal-700 bg-teal-50"
                                                : "border-slate-200")
                                        }
                                    >
                                        <input
                                            className="!w-auto"
                                            type="radio"
                                            name="service"
                                            checked={
                                                input.service_public_id ===
                                                service.public_id
                                            }
                                            value={service.public_id}
                                            required
                                            onChange={() =>
                                                changeInput({
                                                    service_public_id:
                                                        service.public_id,
                                                })
                                            }
                                        />
                                        <span>
                                            <strong>{service.name}</strong>
                                            {service.description && (
                                                <span className="mt-2 block font-normal text-slate-500">
                                                    {service.description}
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                        )}
                        {step === 1 && (
                            <div className="grid gap-6">
                                <p className="text-sm text-slate-500">
                                    Your preferred date is indicative and does
                                    not confirm availability.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <label>
                                        Preferred moving date
                                        <input
                                            required
                                            type="date"
                                            min={new Date().toLocaleDateString(
                                                "en-CA",
                                            )}
                                            value={input.preferred_date}
                                            onChange={(event) =>
                                                changeInput({
                                                    preferred_date:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        Preferred start time (optional)
                                        <input
                                            type="time"
                                            value={input.preferred_time ?? ""}
                                            onChange={(event) =>
                                                changeInput({
                                                    preferred_time:
                                                        event.target.value ||
                                                        null,
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                                {input.stops.map((address, index) => (
                                    <div key={index}>
                                        <QuoteAddressFields
                                            title={
                                                index === 0
                                                    ? "Pickup"
                                                    : index ===
                                                        input.stops.length - 1
                                                      ? "Delivery"
                                                      : "Additional stop " +
                                                        index
                                            }
                                            address={address}
                                            base={base}
                                            onChange={(address) =>
                                                changeInput({
                                                    stops: input.stops.map(
                                                        (stop, i) =>
                                                            i === index
                                                                ? address
                                                                : stop,
                                                    ),
                                                })
                                            }
                                        />
                                        {index > 0 &&
                                            index < input.stops.length - 1 && (
                                                <button
                                                    type="button"
                                                    className="mt-2 text-sm text-red-700 underline"
                                                    onClick={() =>
                                                        changeInput({
                                                            stops: input.stops.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        })
                                                    }
                                                >
                                                    Remove this stop
                                                </button>
                                            )}
                                    </div>
                                ))}
                                {input.stops.length < 10 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            changeInput({
                                                stops: [
                                                    ...input.stops.slice(0, -1),
                                                    {
                                                        address_line: "",
                                                        city: "",
                                                        country_code: "ZA",
                                                    },
                                                    input.stops[
                                                        input.stops.length - 1
                                                    ],
                                                ],
                                            })
                                        }
                                    >
                                        Add an additional stop
                                    </Button>
                                )}
                            </div>
                        )}
                        {step === 2 && (
                            <QuoteInventoryEditor
                                base={base}
                                items={input.inventory}
                                onChange={(inventory) =>
                                    changeInput({ inventory })
                                }
                                photos={photos}
                                onPhotos={(key, files) =>
                                    setPhotos((previous) => ({
                                        ...previous,
                                        [key]: files,
                                    }))
                                }
                                photosLocked={
                                    quote !== null && quote.status !== "draft"
                                }
                            />
                        )}{" "}
                        {step === 3 && (
                            <div className="grid gap-6">
                                <p className="text-sm text-slate-500">
                                    Tell us about both properties so loading and
                                    access can be assessed accurately.
                                </p>
                                <div className="grid gap-5 sm:grid-cols-2">
                                    {(
                                        [
                                            [
                                                "pickup_stairs",
                                                "Pickup flights of stairs",
                                                100,
                                            ],
                                            [
                                                "delivery_stairs",
                                                "Delivery flights of stairs",
                                                100,
                                            ],
                                            [
                                                "pickup_carry_metres",
                                                "Pickup carrying distance (m)",
                                                10000,
                                            ],
                                            [
                                                "delivery_carry_metres",
                                                "Delivery carrying distance (m)",
                                                10000,
                                            ],
                                            [
                                                "waiting_minutes",
                                                "Expected waiting time (minutes)",
                                                10000,
                                            ],
                                        ] as const
                                    ).map(([field, label, max]) => (
                                        <label key={field}>
                                            {label}
                                            <input
                                                required
                                                type="number"
                                                min={0}
                                                max={max}
                                                value={input.details[field]}
                                                onChange={(event) =>
                                                    changeInput({
                                                        details: {
                                                            ...input.details,
                                                            [field]: Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        },
                                                    })
                                                }
                                            />
                                        </label>
                                    ))}
                                </div>
                                {(
                                    [
                                        [
                                            "pickup_lift",
                                            "A suitable lift is available at pickup",
                                        ],
                                        [
                                            "delivery_lift",
                                            "A suitable lift is available at delivery",
                                        ],
                                        [
                                            "parking_limited",
                                            "Parking is limited",
                                        ],
                                        [
                                            "difficult_access",
                                            "There are other difficult access restrictions",
                                        ],
                                        [
                                            "special_equipment",
                                            "Special equipment is needed",
                                        ],
                                        ["packing", "Include packing services"],
                                    ] as const
                                ).map(([field, label]) => (
                                    <label
                                        className="flex items-center gap-3"
                                        key={field}
                                    >
                                        <input
                                            className="!w-auto"
                                            type="checkbox"
                                            checked={input.details[field]}
                                            onChange={(event) =>
                                                changeInput({
                                                    details: {
                                                        ...input.details,
                                                        [field]:
                                                            event.target
                                                                .checked,
                                                    },
                                                })
                                            }
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        )}
                        {step === 4 && quote && <QuoteSummary quote={quote} />}{" "}
                        {step === 5 && (
                            <div className="grid gap-5">
                                <p className="text-slate-600">
                                    Save your private quotation link after
                                    submitting. It lets you view the request and
                                    accept a valid offer without registering.
                                </p>
                                <label>
                                    Full name
                                    <input
                                        autoComplete="name"
                                        required
                                        maxLength={160}
                                        value={contact.name}
                                        onChange={(event) =>
                                            setContact({
                                                ...contact,
                                                name: event.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <label>
                                        Email address
                                        <input
                                            autoComplete="email"
                                            required
                                            type="email"
                                            maxLength={254}
                                            value={contact.email}
                                            onChange={(event) =>
                                                setContact({
                                                    ...contact,
                                                    email: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        Phone number (optional)
                                        <input
                                            autoComplete="tel"
                                            type="tel"
                                            maxLength={30}
                                            value={contact.phone ?? ""}
                                            onChange={(event) =>
                                                setContact({
                                                    ...contact,
                                                    phone: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                                <label>
                                    Quotation updates
                                    <select
                                        aria-label="Quotation updates"
                                        value={contact.communication}
                                        onChange={(event) =>
                                            setContact({
                                                ...contact,
                                                communication: event.target
                                                    .value as "email" | "none",
                                            })
                                        }
                                    >
                                        <option value="email">
                                            Email me about this quotation
                                        </option>
                                        <option value="none">
                                            I will check my private link
                                        </option>
                                    </select>
                                </label>
                                <label className="flex items-start gap-3">
                                    <input
                                        className="!w-auto"
                                        required
                                        type="checkbox"
                                        checked={contact.consent}
                                        onChange={(event) =>
                                            setContact({
                                                ...contact,
                                                consent: event.target.checked,
                                            })
                                        }
                                    />
                                    I consent to using these details to prepare
                                    and manage this quotation.
                                </label>
                                <label className="flex items-start gap-3">
                                    <input
                                        className="!w-auto"
                                        type="checkbox"
                                        checked={contact.marketing_consent}
                                        onChange={(event) =>
                                            setContact({
                                                ...contact,
                                                marketing_consent:
                                                    event.target.checked,
                                            })
                                        }
                                    />
                                    I would also like optional marketing
                                    updates.
                                </label>
                            </div>
                        )}
                        {error && (
                            <div
                                role="alert"
                                className="mt-6 rounded-lg bg-red-50 p-4 text-red-800"
                            >
                                {error}
                                {quote && (
                                    <button
                                        type="button"
                                        className="ml-3 underline"
                                        onClick={refresh}
                                    >
                                        Refresh saved request
                                    </button>
                                )}
                            </div>
                        )}
                        {busy && (
                            <p role="status" className="mt-5 text-teal-800">
                                {progress || "Working…"}
                            </p>
                        )}
                        <div className="mt-8 flex flex-wrap justify-between gap-4">
                            {step > 0 ? (
                                <Button
                                    type="button"
                                    disabled={busy}
                                    variant="outline"
                                    onClick={() => {
                                        setStep(step - 1);
                                        setError("");
                                    }}
                                >
                                    Back
                                </Button>
                            ) : (
                                <span />
                            )}
                            <Button disabled={busy} type="submit">
                                {step === 3
                                    ? "Calculate estimate"
                                    : step === 5
                                      ? "Submit quotation request"
                                      : "Continue"}
                            </Button>
                        </div>
                    </Card>
                    <button
                        className="mt-5 text-sm text-slate-500 underline"
                        type="button"
                        disabled={busy}
                        onClick={startOver}
                    >
                        Start a new request
                    </button>
                </form>
            )}
        </QuotationLayout>
    );
}
