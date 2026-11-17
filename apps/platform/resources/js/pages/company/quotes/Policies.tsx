import { Head, useForm, usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CommercialTermsFields, {
    blankCommercialTerms,
} from "../../../components/CommercialTermsFields";
import CompanyLayout from "../../../layouts/CompanyLayout";
import type { Policy } from "../../../features/quotes/adminTypes";
function PolicyForm({
    service,
    base,
}: {
    service: { public_id: string; name: string; policy: Policy };
    base: string;
}) {
    const policy = service.policy;
    const form = useForm({
        commercial_terms: {
            ...blankCommercialTerms,
            ...policy.commercial_terms,
        },
        automatic_estimates: policy.automatic_estimates,
        allow_catalogue_estimates: policy.allow_catalogue_estimates,
        allow_customer_measurements: policy.allow_customer_measurements,
        allow_cross_zone: policy.allow_cross_zone,
        outside_zone: policy.outside_zone,
        safety_margin_bps: policy.safety_margin_bps,
        borderline_bps: policy.borderline_bps,
        max_auto_stops: policy.max_auto_stops,
        max_auto_stairs: policy.max_auto_stairs,
        validity_hours: policy.validity_hours,
        notifications_enabled: policy.notifications_enabled,
        terms: policy.terms ?? "",
        exclusions: policy.exclusions ?? "",
    });
    return (
        <Card>
            <h2 className="mb-5 text-xl font-bold">{service.name}</h2>
            <form
                className="grid gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(base + "/" + service.public_id);
                }}
            >
                {(
                    [
                        [
                            "automatic_estimates",
                            "Enable automatic estimates when all checks pass",
                        ],
                        [
                            "allow_catalogue_estimates",
                            "Allow clearly labelled catalogue estimates for automatic screening",
                        ],
                        [
                            "allow_customer_measurements",
                            "Allow customer-supplied measurements for automatic screening",
                        ],
                        [
                            "allow_cross_zone",
                            "Allow routes between different covered zones",
                        ],
                        [
                            "notifications_enabled",
                            "Send transactional quotation emails when the customer opts in",
                        ],
                    ] as const
                ).map(([field, label]) => (
                    <label className="flex items-start gap-3" key={field}>
                        <input
                            className="!w-auto"
                            type="checkbox"
                            checked={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.checked)
                            }
                        />
                        {label}
                    </label>
                ))}
                <p className="text-sm text-slate-500">
                    Allowing estimates does not verify measurements or guarantee
                    packing. Unknown dimensions, uncertain weight, special
                    handling and near-capacity loads still require review.
                </p>
                <CommercialTermsFields
                    value={form.data.commercial_terms}
                    onChange={(value) =>
                        form.setData("commercial_terms", value)
                    }
                />
                {policy.terms_version && (
                    <p className="text-xs text-slate-400">
                        Saved terms version: {policy.terms_version}
                    </p>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    <label>
                        Outside service areas
                        <select
                            aria-label="Outside service areas"
                            value={form.data.outside_zone}
                            onChange={(event) =>
                                form.setData(
                                    "outside_zone",
                                    event.target.value as "review" | "reject",
                                )
                            }
                        >
                            <option value="review">
                                Require manual approval
                            </option>
                            <option value="reject">
                                Outside supported service area
                            </option>
                        </select>
                    </label>
                    <label>
                        Inventory safety margin (%)
                        <input
                            required
                            type="number"
                            min={0}
                            max={50}
                            step="0.01"
                            value={form.data.safety_margin_bps / 100}
                            onChange={(event) =>
                                form.setData(
                                    "safety_margin_bps",
                                    Math.round(
                                        Number(event.target.value) * 100,
                                    ),
                                )
                            }
                        />
                    </label>
                    <label>
                        Near-capacity review threshold (%)
                        <input
                            required
                            type="number"
                            min={50}
                            max={100}
                            step="0.01"
                            value={form.data.borderline_bps / 100}
                            onChange={(event) =>
                                form.setData(
                                    "borderline_bps",
                                    Math.round(
                                        Number(event.target.value) * 100,
                                    ),
                                )
                            }
                        />
                    </label>
                    {(
                        [
                            [
                                "max_auto_stops",
                                "Additional stops allowed before review",
                                8,
                            ],
                            [
                                "max_auto_stairs",
                                "Flights of stairs allowed before review",
                                100,
                            ],
                            ["validity_hours", "Offer validity (hours)", 720],
                        ] as const
                    ).map(([field, label, max]) => (
                        <label key={field}>
                            {label}
                            <input
                                required
                                type="number"
                                min={field === "validity_hours" ? 1 : 0}
                                max={max}
                                value={form.data[field]}
                                onChange={(event) =>
                                    form.setData(
                                        field,
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </label>
                    ))}
                </div>
                <label>
                    Customer quotation terms
                    <textarea
                        className="min-h-32 rounded-lg border border-slate-300 p-3"
                        required
                        maxLength={10000}
                        value={form.data.terms}
                        onChange={(event) =>
                            form.setData("terms", event.target.value)
                        }
                    />
                </label>
                <label>
                    Exclusions
                    <textarea
                        className="rounded-lg border border-slate-300 p-3"
                        maxLength={10000}
                        value={form.data.exclusions}
                        onChange={(event) =>
                            form.setData("exclusions", event.target.value)
                        }
                    />
                </label>
                {Object.keys(form.errors).length > 0 && (
                    <p role="alert" className="text-red-700">
                        {Object.values(form.errors).join(" ")}
                    </p>
                )}
                <Button disabled={form.processing} type="submit">
                    Save quotation policy
                </Button>
            </form>
        </Card>
    );
}
export default function Policies({
    services,
}: {
    services: { public_id: string; name: string; policy: Policy }[];
}) {
    const { company } = usePage<SharedProps>().props;
    return (
        <CompanyLayout>
            <Head title="Quotation policies" />
            <h1 className="text-3xl font-bold">Quotation policies</h1>
            <p className="mb-7 mt-3 text-slate-500">
                Set the review rules for each service. Incomplete pricing,
                addresses or technical checks always prevent an automatic offer.
            </p>
            <div className="grid gap-7">
                {services.map((service) => (
                    <PolicyForm
                        key={service.public_id}
                        service={service}
                        base={"/c/" + company?.slug + "/quotation-policies"}
                    />
                ))}
            </div>
        </CompanyLayout>
    );
}
