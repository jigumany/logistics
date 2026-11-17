import type { CommercialTerms } from "../features/quotes/adminTypes";
export const blankCommercialTerms: CommercialTerms = {
    status: "draft",
    insurance_status: "draft",
    declared_value_required: false,
    insurance_information: "",
    special_handling: "",
    customer_responsibilities: "",
    access_parking: "",
    cancellation: "",
    rescheduling: "",
    damage_reporting: "",
    additional_charges: "",
};
export default function CommercialTermsFields({
    value,
    onChange,
}: {
    value: CommercialTerms;
    onChange: (v: CommercialTerms) => void;
}) {
    const update = (next: Partial<CommercialTerms>) =>
        onChange({ ...value, ...next });
    return (
        <fieldset className="grid gap-4 rounded-xl border border-slate-200 p-5">
            <legend className="px-2 font-semibold">
                Transport and liability terms
            </legend>
            <p className="text-xs leading-5 text-slate-500">
                Enter your actual business policies. Draft insurance information
                is never included in customer terms. Reviewed versions are
                preserved in every issued quotation; changes do not alter
                existing offers.
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
                <label>
                    Commercial terms review
                    <select
                        aria-label="Commercial terms review"
                        value={value.status}
                        onChange={(e) =>
                            update({
                                status: e.target.value as "draft" | "reviewed",
                            })
                        }
                    >
                        <option value="draft">Draft / unverified</option>
                        <option value="reviewed">Reviewed for use</option>
                    </select>
                </label>
                <label>
                    Insurance information review
                    <select
                        aria-label="Insurance information review"
                        value={value.insurance_status}
                        onChange={(e) =>
                            update({
                                insurance_status: e.target.value as
                                    "draft" | "reviewed",
                            })
                        }
                    >
                        <option value="draft">Draft / unverified</option>
                        <option value="reviewed">
                            Verified business insurance information
                        </option>
                    </select>
                </label>
            </div>
            {(
                [
                    [
                        "insurance_information",
                        "Goods-in-transit insurance information",
                    ],
                    [
                        "special_handling",
                        "Exclusions and special-handling conditions",
                    ],
                    ["customer_responsibilities", "Customer responsibilities"],
                    ["access_parking", "Access and parking conditions"],
                    ["cancellation", "Cancellation policy"],
                    ["rescheduling", "Rescheduling policy"],
                    ["damage_reporting", "Damage reporting process"],
                    ["additional_charges", "Conditions for additional charges"],
                ] as const
            ).map(([field, label]) => (
                <label key={field}>
                    {label}
                    <textarea
                        rows={3}
                        value={value[field]}
                        onChange={(e) => update({ [field]: e.target.value })}
                    />
                </label>
            ))}
            <label className="flex items-center gap-3">
                <input
                    className="!w-auto"
                    type="checkbox"
                    checked={value.declared_value_required}
                    onChange={(e) =>
                        update({ declared_value_required: e.target.checked })
                    }
                />
                Require declared goods value before an automatic estimate
            </label>
        </fieldset>
    );
}
