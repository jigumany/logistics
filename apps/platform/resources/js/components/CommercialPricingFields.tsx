import type { CommercialPricing } from "../features/quotes/adminTypes";
import { parseScaledDecimal, scaledDecimal } from "../features/quotes/money";
export const initialCommercialPricing: CommercialPricing = {
    included_km: 0,
    minimum_chargeable_km: 0,
    minimum_labour_hours: 0,
    standard_labour_hours: 8,
    labour_model: "combined",
    labour_distance_basis: "customer",
    bill_waiting_in_labour: false,
    toll_policy: "review",
    tax_reviewed: false,
    tax_mode: "not_registered",
    tax_registration: "",
    tax_basis_points: 0,
    costs_reviewed: false,
    cost_distance_basis: "operational",
    target_margin_bps: 0,
    minimum_margin_bps: 0,
    override_roles: ["owner"],
    fuel_rate_micros: 0,
    vehicle_rate_micros: 0,
    driver_rate_micros: 0,
    mover_rate_micros: 0,
    driver_overtime_rate_micros: 0,
    mover_overtime_rate_micros: 0,
    fixed_cost_minor: 0,
    overhead_minor: 0,
    estimated_tolls_minor: 0,
};
export default function CommercialPricingFields({
    value,
    onChange,
    currency,
    taxRegistration = "",
}: {
    value: CommercialPricing;
    onChange: (value: CommercialPricing) => void;
    currency: string;
    taxRegistration?: string;
}) {
    const update = (data: Partial<CommercialPricing>) =>
        onChange({ ...value, ...data });
    const numeric = (
        field: keyof CommercialPricing,
        label: string,
        scale = 0,
        max = 1000000,
    ) => (
        <label key={field}>
            {label}
            <input
                required
                type="number"
                min="0"
                max={max}
                step={scale ? Math.pow(10, -scale) : "any"}
                value={
                    scale
                        ? scaledDecimal(Number(value[field]), scale)
                        : Number(value[field])
                }
                onChange={(e) => {
                    try {
                        e.currentTarget.setCustomValidity("");
                        update({
                            [field]: scale
                                ? parseScaledDecimal(
                                      e.target.value || "0",
                                      scale,
                                  )
                                : Number(e.target.value),
                        });
                    } catch {
                        e.currentTarget.setCustomValidity(
                            "Enter a valid decimal amount.",
                        );
                    }
                }}
            />
        </label>
    );
    return (
        <div className="grid gap-6 rounded-xl border border-teal-200 bg-teal-50/30 p-5">
            <div>
                <h2 className="text-xl font-bold">Commercial controls</h2>
                <p className="mt-2 text-sm text-slate-500">
                    These settings belong to this effective-dated price book.
                    Costs and margins are internal. Minimum margin failures
                    require approval; customer prices are never increased
                    automatically.
                </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
                {numeric("included_km", "Included distance (km)")}
                {numeric(
                    "minimum_chargeable_km",
                    "Minimum chargeable distance (km)",
                )}
                {numeric("minimum_labour_hours", "Minimum labour hours")}
                {numeric(
                    "standard_labour_hours",
                    "Standard hours before overtime",
                )}
                <label>
                    Labour charging model
                    <select
                        aria-label="Labour charging model"
                        value={value.labour_model}
                        onChange={(e) =>
                            update({
                                labour_model: e.target
                                    .value as CommercialPricing["labour_model"],
                            })
                        }
                    >
                        <option value="combined">Combined crew hours</option>
                        <option value="separate">
                            Separate driver and additional mover hours
                        </option>
                    </select>
                </label>
                <label>
                    Labour journey basis
                    <select
                        aria-label="Labour journey basis"
                        value={value.labour_distance_basis}
                        onChange={(e) =>
                            update({
                                labour_distance_basis: e.target.value as
                                    "customer" | "operational",
                            })
                        }
                    >
                        <option value="customer">Customer journey</option>
                        <option value="operational">
                            Full depot, customer and return journey
                        </option>
                    </select>
                </label>
                <label>
                    Toll treatment
                    <select
                        aria-label="Toll treatment"
                        value={value.toll_policy}
                        onChange={(e) =>
                            update({
                                toll_policy: e.target
                                    .value as CommercialPricing["toll_policy"],
                            })
                        }
                    >
                        <option value="review">
                            Require review until tolls are confirmed
                        </option>
                        <option value="included">
                            Included in customer price
                        </option>
                        <option value="actual">
                            Pass through confirmed tolls
                        </option>
                    </select>
                </label>
            </div>
            <label className="flex items-start gap-3">
                <input
                    className="!w-auto"
                    type="checkbox"
                    checked={value.bill_waiting_in_labour}
                    onChange={(e) =>
                        update({ bill_waiting_in_labour: e.target.checked })
                    }
                />
                Include waiting in labour hours (otherwise use a waiting-time
                rule)
            </label>
            <p className="text-xs text-slate-500">
                Chargeable distance is the greater of the minimum distance and
                the selected journey kilometres minus included kilometres.
                Labour includes loading and unloading. Overtime rates are
                additional hourly premiums above the base labour rate. Use
                explicit minimum rules for the minimum quote or vehicle-class
                charge; class rules replace matching general rules.
            </p>
            <h3 className="font-semibold">Tax and approval policy</h3>
            <div className="grid gap-4 sm:grid-cols-3">
                <label>
                    Tax status
                    <select
                        aria-label="Tax status"
                        value={value.tax_mode}
                        onChange={(e) =>
                            update({
                                tax_mode: e.target
                                    .value as CommercialPricing["tax_mode"],
                                tax_registration: taxRegistration,
                            })
                        }
                    >
                        <option value="not_registered">Not registered</option>
                        <option value="registered">Registered</option>
                        <option value="exempt">Exempt</option>
                    </select>
                </label>
                <label>
                    Tax registration number (from company profile)
                    <input readOnly value={value.tax_registration ?? ""} />
                </label>
                {numeric("tax_basis_points", "Applicable tax (%)", 2, 100)}
                {numeric(
                    "target_margin_bps",
                    "Target contribution margin (%)",
                    2,
                    99.99,
                )}
                {numeric(
                    "minimum_margin_bps",
                    "Minimum acceptable margin (%)",
                    2,
                    99.99,
                )}
            </div>
            <label className="flex items-start gap-3">
                <input
                    className="!w-auto"
                    type="checkbox"
                    checked={value.tax_reviewed}
                    onChange={(e) => update({ tax_reviewed: e.target.checked })}
                />
                Tax status and the matching tax rules have been reviewed
            </label>
            <fieldset className="flex flex-wrap gap-4">
                <legend className="mb-3 text-sm font-semibold">
                    Roles authorised to override prices
                </legend>
                {(["owner", "admin"] as const).map((role) => (
                    <label
                        key={role}
                        className="flex items-center gap-2 capitalize"
                    >
                        <input
                            className="!w-auto"
                            type="checkbox"
                            checked={value.override_roles.includes(role)}
                            onChange={(e) =>
                                update({
                                    override_roles: e.target.checked
                                        ? [...value.override_roles, role]
                                        : value.override_roles.filter(
                                              (r) => r !== role,
                                          ),
                                })
                            }
                        />
                        {role}
                    </label>
                ))}
            </fieldset>
            <h3 className="font-semibold">
                Internal operating costs ({currency})
            </h3>
            <p className="text-xs text-slate-500">
                Vehicle cost per kilometre excludes fuel. Driver cost is for one
                driver; mover cost is multiplied by additional crew. Margin is
                contribution divided by revenue excluding tax, after the
                optional overhead contribution.
            </p>
            <div className="grid gap-4 sm:grid-cols-3">
                {numeric("fuel_rate_micros", "Fuel cost per km", 6)}
                {numeric(
                    "vehicle_rate_micros",
                    "Vehicle cost per km (excluding fuel)",
                    6,
                )}
                {numeric("driver_rate_micros", "Driver cost per hour", 6)}
                {numeric(
                    "mover_rate_micros",
                    "Additional mover cost per hour",
                    6,
                )}
                {numeric(
                    "driver_overtime_rate_micros",
                    "Additional driver overtime cost per hour",
                    6,
                )}
                {numeric(
                    "mover_overtime_rate_micros",
                    "Additional mover overtime cost per hour",
                    6,
                )}
                {numeric("fixed_cost_minor", "Other direct costs per job", 2)}
                {numeric("overhead_minor", "Overhead contribution per job", 2)}
                {numeric(
                    "estimated_tolls_minor",
                    "Estimated toll cost when included in price",
                    2,
                )}
                <label>
                    Operating cost journey basis
                    <select
                        aria-label="Operating cost journey basis"
                        value={value.cost_distance_basis}
                        onChange={(e) =>
                            update({
                                cost_distance_basis: e.target.value as
                                    "customer" | "operational",
                            })
                        }
                    >
                        <option value="customer">Customer journey only</option>
                        <option value="operational">
                            Full operational journey (verified depot required)
                        </option>
                    </select>
                </label>
            </div>
            <label className="flex items-start gap-3">
                <input
                    className="!w-auto"
                    type="checkbox"
                    checked={value.costs_reviewed}
                    onChange={(e) =>
                        update({ costs_reviewed: e.target.checked })
                    }
                />
                Operating costs, zero-cost items and margin thresholds have been
                reviewed
            </label>
        </div>
    );
}
