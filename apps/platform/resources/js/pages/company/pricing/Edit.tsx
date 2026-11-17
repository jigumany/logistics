import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import type {
    BookConfiguration,
    PriceBookRecord,
    PriceRule,
} from "../../../features/quotes/adminTypes";
import {
    parseScaledDecimal,
    scaledDecimal,
} from "../../../features/quotes/money";
import { localDateTime } from "../../../features/quotes/date";
import { useUnsavedChanges } from "../../../hooks/useUnsavedChanges";
type EditRule = Omit<PriceRule, "rate_micros"> & { rate_value: string };
export default function Edit({
    book,
    isNew = false,
    services,
    depots,
    vehicleTypes,
    metrics,
}: {
    book: PriceBookRecord | null;
    isNew?: boolean;
    services: { public_id: string; name: string }[];
    depots: { public_id: string; name: string }[];
    vehicleTypes: { key: string; name: string }[];
    metrics: string[];
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/price-books";
    const creating = !book || isNew;
    const readOnly = !creating && book.status !== "draft";
    const [error, setError] = useState("");
    const [acknowledged, setAcknowledged] = useState(false);
    const initialRules: EditRule[] = book?.rules.map((rule) => ({
        ...rule,
        rate_value: scaledDecimal(
            rule.rate_micros,
            rule.kind === "percent" ? 4 : 6,
        ),
    })) ?? [
        {
            code: "base",
            label: "Base transport",
            stage: "charge",
            kind: "fixed",
            metric: "base",
            rate_value: "",
            position: 0,
            vehicle_type_key: null,
        },
        {
            code: "distance",
            label: "Chargeable distance",
            stage: "charge",
            kind: "rate",
            metric: "chargeable_km",
            rate_value: "",
            position: 1,
            vehicle_type_key: null,
        },
    ];
    const form = useForm({
        name: book?.name ?? "",
        service_public_id:
            book?.service_public_id ?? services[0]?.public_id ?? "",
        currency: book?.currency ?? "ZAR",
        effective_from: localDateTime(
            creating ? Date.now() : book.effective_from,
        ),
        effective_until: book?.effective_until
            ? localDateTime(book.effective_until)
            : "",
        distance_basis: book?.distance_basis ?? "customer",
        configuration:
            book?.configuration ??
            ({
                schema_version: 1,
                included_metrics: [],
                crew_size: 1,
                loading_minutes_per_complexity: 0,
                unloading_minutes_per_complexity: 0,
                carry_included_metres: 0,
                business_opens: "08:00",
                business_closes: "17:00",
                holiday_dates: [],
                depot_public_id: null,
            } as BookConfiguration),
        rules: initialRules,
        holiday_text: book?.configuration.holiday_dates.join("\n") ?? "",
    });
    useUnsavedChanges(form.isDirty && !readOnly);
    function changeConfig(values: Partial<BookConfiguration>) {
        form.setData("configuration", {
            ...form.data.configuration,
            ...values,
        });
    }
    function changeRule(index: number, values: Partial<EditRule>) {
        form.setData(
            "rules",
            form.data.rules.map((rule, i) =>
                i === index ? { ...rule, ...values } : rule,
            ),
        );
    }
    function submit(event: React.FormEvent) {
        event.preventDefault();
        setError("");
        try {
            const rules = form.data.rules.map((rule, index) => ({
                code: rule.code,
                label: rule.label,
                stage: rule.stage,
                kind: rule.kind,
                metric: ["charge", "surcharge"].includes(rule.stage)
                    ? rule.metric
                    : null,
                rate_micros: parseScaledDecimal(
                    rule.rate_value,
                    rule.kind === "percent" ? 4 : 6,
                ),
                position: index,
                vehicle_type_key: rule.vehicle_type_key || null,
            }));
            form.transform((data) => ({
                ...data,
                rules,
                effective_from: new Date(data.effective_from).toISOString(),
                effective_until: data.effective_until
                    ? new Date(data.effective_until).toISOString()
                    : null,
                configuration: {
                    ...data.configuration,
                    holiday_dates: data.holiday_text
                        .split(/\s+/)
                        .filter(Boolean),
                },
            }));
            if (creating) form.post(base);
            else form.put(base + "/" + book.public_id);
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : "Check the pricing rules.",
            );
        }
    }
    return (
        <CompanyLayout>
            <Head title={creating ? "Create price book" : book.name} />
            <div className="mb-7 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">
                        {creating ? "Create price book" : book.name}
                    </h1>
                    <p className="mt-2 text-slate-500">
                        {creating
                            ? "New pricing version"
                            : `Version ${book.version} · ${book.status}`}
                    </p>
                </div>
                {book && !creating && (
                    <Button asChild variant="outline">
                        <Link href={base + "/create?copy=" + book.public_id}>
                            Copy to new version
                        </Link>
                    </Button>
                )}
            </div>
            <Card>
                <form className="grid gap-6" onSubmit={submit}>
                    <fieldset
                        disabled={readOnly || form.processing}
                        className="grid gap-6"
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label>
                                Price book name
                                <input
                                    required
                                    maxLength={160}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData("name", event.target.value)
                                    }
                                />
                            </label>
                            <label>
                                Service
                                <select
                                    required
                                    aria-label="Service"
                                    value={form.data.service_public_id}
                                    onChange={(event) =>
                                        form.setData(
                                            "service_public_id",
                                            event.target.value,
                                        )
                                    }
                                >
                                    {services.map((service) => (
                                        <option
                                            key={service.public_id}
                                            value={service.public_id}
                                        >
                                            {service.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Currency
                                <select
                                    aria-label="Currency"
                                    value={form.data.currency}
                                    onChange={(event) =>
                                        form.setData(
                                            "currency",
                                            event.target.value,
                                        )
                                    }
                                >
                                    {["ZAR", "USD", "EUR", "GBP"].map(
                                        (value) => (
                                            <option key={value}>{value}</option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label>
                                Chargeable journey
                                <select
                                    aria-label="Chargeable journey"
                                    value={form.data.distance_basis}
                                    onChange={(event) =>
                                        form.setData(
                                            "distance_basis",
                                            event.target
                                                .value as PriceBookRecord["distance_basis"],
                                        )
                                    }
                                >
                                    <option value="customer">
                                        Pickup to delivery, including requested
                                        stops
                                    </option>
                                    <option value="customer_depot">
                                        Customer journey plus depot to pickup
                                    </option>
                                    <option value="customer_return">
                                        Customer journey plus return to depot
                                    </option>
                                    <option value="operational">
                                        Customer journey, depot approach and
                                        return
                                    </option>
                                </select>
                            </label>
                            <label>
                                Effective from
                                <input
                                    required
                                    type="datetime-local"
                                    value={form.data.effective_from}
                                    onChange={(event) =>
                                        form.setData(
                                            "effective_from",
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Effective until (optional)
                                <input
                                    type="datetime-local"
                                    value={form.data.effective_until}
                                    onChange={(event) =>
                                        form.setData(
                                            "effective_until",
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Verified depot (required for depot or return
                                charges)
                                <select
                                    value={
                                        form.data.configuration
                                            .depot_public_id ?? ""
                                    }
                                    onChange={(event) =>
                                        changeConfig({
                                            depot_public_id:
                                                event.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">No depot journey</option>
                                    {depots.map((depot) => (
                                        <option
                                            key={depot.public_id}
                                            value={depot.public_id}
                                        >
                                            {depot.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <p className="rounded-lg bg-teal-50 p-4 text-sm">
                            Customer journey distance and operational distance
                            are separate. Only the journey basis you select is
                            chargeable. Quotes never reserve vehicles.
                        </p>
                        <h2 className="text-xl font-bold">
                            Loading and operational assumptions
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {(
                                [
                                    ["crew_size", "Crew size", 1, 20],
                                    [
                                        "loading_minutes_per_complexity",
                                        "Loading minutes per complexity unit",
                                        0,
                                        120,
                                    ],
                                    [
                                        "unloading_minutes_per_complexity",
                                        "Unloading minutes per complexity unit",
                                        0,
                                        120,
                                    ],
                                    [
                                        "carry_included_metres",
                                        "Included carrying distance (m)",
                                        0,
                                        10000,
                                    ],
                                ] as const
                            ).map(([field, label, min, max]) => (
                                <label key={field}>
                                    {label}
                                    <input
                                        required
                                        type="number"
                                        min={min}
                                        max={max}
                                        step={
                                            field.includes("minutes")
                                                ? "0.01"
                                                : "1"
                                        }
                                        value={form.data.configuration[field]}
                                        onChange={(event) =>
                                            changeConfig({
                                                [field]: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    />
                                </label>
                            ))}
                            <label>
                                Business hours begin
                                <input
                                    required
                                    type="time"
                                    value={
                                        form.data.configuration.business_opens
                                    }
                                    onChange={(event) =>
                                        changeConfig({
                                            business_opens: event.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label>
                                Business hours end
                                <input
                                    required
                                    type="time"
                                    value={
                                        form.data.configuration.business_closes
                                    }
                                    onChange={(event) =>
                                        changeConfig({
                                            business_closes: event.target.value,
                                        })
                                    }
                                />
                            </label>
                        </div>
                        <label>
                            Public holidays for this tariff (one YYYY-MM-DD date
                            per line)
                            <textarea
                                className="rounded-lg border border-slate-300 p-3"
                                value={form.data.holiday_text}
                                onChange={(event) =>
                                    form.setData(
                                        "holiday_text",
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <h2 className="text-xl font-bold">Pricing rules</h2>
                        <p className="text-sm text-slate-500">
                            Calculation order: base charges → surcharges →
                            sequential discounts → minimum top-up → taxes on the
                            net subtotal. Each displayed line rounds half up.
                            Currency rates support six decimal places;
                            percentages support four.
                        </p>
                        {form.data.rules.map((rule, index) => (
                            <fieldset
                                className="grid gap-4 rounded-xl border border-slate-200 p-4"
                                key={index}
                            >
                                <legend className="px-2 font-bold">
                                    Rule {index + 1}
                                </legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <label>
                                        Customer-facing label
                                        <input
                                            required
                                            maxLength={160}
                                            value={rule.label}
                                            onChange={(event) =>
                                                changeRule(index, {
                                                    label: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        Calculation stage
                                        <select
                                            aria-label="Calculation stage"
                                            value={rule.stage}
                                            onChange={(event) => {
                                                const stage = event.target
                                                    .value as PriceRule["stage"];
                                                changeRule(index, {
                                                    stage,
                                                    kind:
                                                        stage === "tax"
                                                            ? "percent"
                                                            : stage ===
                                                                "minimum"
                                                              ? "fixed"
                                                              : rule.kind ===
                                                                      "percent" &&
                                                                  stage !==
                                                                      "discount"
                                                                ? "rate"
                                                                : rule.kind,
                                                    metric: [
                                                        "charge",
                                                        "surcharge",
                                                    ].includes(stage)
                                                        ? "base"
                                                        : null,
                                                    vehicle_type_key: null,
                                                });
                                            }}
                                        >
                                            {[
                                                "charge",
                                                "surcharge",
                                                "discount",
                                                "minimum",
                                                "tax",
                                            ].map((value) => (
                                                <option key={value}>
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label>
                                        Rule type
                                        <select
                                            aria-label="Rule type"
                                            value={rule.kind}
                                            onChange={(event) =>
                                                changeRule(index, {
                                                    kind: event.target
                                                        .value as PriceRule["kind"],
                                                })
                                            }
                                        >
                                            {(rule.stage === "tax"
                                                ? ["percent"]
                                                : rule.stage === "minimum"
                                                  ? ["fixed"]
                                                  : rule.stage === "discount"
                                                    ? ["fixed", "percent"]
                                                    : ["fixed", "rate"]
                                            ).map((value) => (
                                                <option key={value}>
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    {["charge", "surcharge"].includes(
                                        rule.stage,
                                    ) && (
                                        <>
                                            <label>
                                                Quantity or trigger
                                                <select
                                                    value={
                                                        rule.metric ?? "base"
                                                    }
                                                    onChange={(event) =>
                                                        changeRule(index, {
                                                            metric: event.target
                                                                .value,
                                                        })
                                                    }
                                                >
                                                    {metrics.map((value) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {value.replaceAll(
                                                                "_",
                                                                " ",
                                                            )}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label>
                                                Vehicle category (optional)
                                                <select
                                                    value={
                                                        rule.vehicle_type_key ??
                                                        ""
                                                    }
                                                    onChange={(event) =>
                                                        changeRule(index, {
                                                            vehicle_type_key:
                                                                event.target
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        All categories
                                                    </option>
                                                    {vehicleTypes.map(
                                                        (type) => (
                                                            <option
                                                                key={type.key}
                                                                value={type.key}
                                                            >
                                                                {type.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </label>
                                        </>
                                    )}
                                    <label>
                                        {rule.kind === "percent"
                                            ? "Percentage"
                                            : `Rate (${form.data.currency})`}
                                        <input
                                            required
                                            inputMode="decimal"
                                            value={rule.rate_value}
                                            onChange={(event) =>
                                                changeRule(index, {
                                                    rate_value:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        form.setData(
                                            "rules",
                                            form.data.rules.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    Remove rule
                                </Button>
                            </fieldset>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.data.rules.length >= 100}
                            onClick={() =>
                                form.setData("rules", [
                                    ...form.data.rules,
                                    {
                                        code:
                                            "charge_" +
                                            crypto.randomUUID().slice(0, 8),
                                        label: "",
                                        stage: "charge",
                                        kind: "rate",
                                        metric: "base",
                                        rate_value: "",
                                        position: form.data.rules.length,
                                        vehicle_type_key: null,
                                    },
                                ])
                            }
                        >
                            Add pricing rule
                        </Button>
                        <h2 className="text-xl font-bold">
                            Components included at no additional charge
                        </h2>
                        <p className="text-sm text-slate-500">
                            Every component must have a pricing rule or be
                            explicitly included below. Do not mark a priced
                            component as included.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {metrics.map((metric) => (
                                <label
                                    className="flex items-center gap-3"
                                    key={metric}
                                >
                                    <input
                                        className="!w-auto"
                                        type="checkbox"
                                        checked={form.data.configuration.included_metrics.includes(
                                            metric,
                                        )}
                                        onChange={(event) =>
                                            changeConfig({
                                                included_metrics: event.target
                                                    .checked
                                                    ? [
                                                          ...form.data
                                                              .configuration
                                                              .included_metrics,
                                                          metric,
                                                      ]
                                                    : form.data.configuration.included_metrics.filter(
                                                          (value) =>
                                                              value !== metric,
                                                      ),
                                            })
                                        }
                                    />
                                    {metric.replaceAll("_", " ")}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    {(error || Object.keys(form.errors).length > 0) && (
                        <p role="alert" className="text-red-700">
                            {error || Object.values(form.errors).join(" ")}
                        </p>
                    )}
                    {!readOnly && (
                        <Button disabled={form.processing} type="submit">
                            Save draft price book
                        </Button>
                    )}
                </form>
            </Card>
            {book && !creating && book.status === "draft" && (
                <Card className="mt-6">
                    <h2 className="text-xl font-bold">Publish this version</h2>
                    <p className="mt-3 text-sm text-slate-600">
                        Published pricing is immutable. Retire an overlapping
                        published book before publishing its replacement.
                        Existing quoted revisions retain their original price.
                    </p>
                    <label className="my-5 flex items-start gap-3">
                        <input
                            className="!w-auto"
                            type="checkbox"
                            checked={acknowledged}
                            onChange={(event) =>
                                setAcknowledged(event.target.checked)
                            }
                        />
                        I have checked every rate, included component, tax
                        setting and effective date.
                    </label>
                    <Button
                        disabled={!acknowledged || form.isDirty}
                        onClick={() =>
                            router.post(
                                base + "/" + book.public_id + "/publish",
                            )
                        }
                    >
                        Publish price book
                    </Button>
                </Card>
            )}
            {book && !creating && book.status === "published" && (
                <Card className="mt-6">
                    <h2 className="text-xl font-bold">Retire pricing</h2>
                    <p className="my-4 text-sm text-slate-500">
                        New calculations will no longer use this version.
                        Previously issued offers remain unchanged.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() => {
                            if (
                                window.confirm(
                                    "Retire this price book for new calculations?",
                                )
                            )
                                router.post(
                                    base + "/" + book.public_id + "/retire",
                                );
                        }}
                    >
                        Retire this version
                    </Button>
                </Card>
            )}
        </CompanyLayout>
    );
}
