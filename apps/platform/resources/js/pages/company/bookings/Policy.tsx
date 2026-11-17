import { Head, useForm, usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import { useUnsavedChanges } from "../../../hooks/useUnsavedChanges";
type Config = {
    reviewed: boolean;
    hold_minutes: number;
    search_days: number;
    slot_minutes: number;
    arrival_window_minutes: number;
    manual_confirmation: boolean;
    customer_cancellation: boolean;
    cancellation_notice_minutes: number;
    hold_attempts_per_hour: number;
    conservative_job_minutes: number | null;
    stop_minutes: number;
    stairs_minutes: number;
    reposition_minutes: number;
};
type Service = {
    public_id: string;
    name: string;
    publicly_bookable: boolean;
    manual_approval: boolean;
    notice_minutes: number;
    rules: {
        weekdays: number[];
        opens_at: string | null;
        closes_at: string | null;
        vehicles: number;
        minimum_movers: number;
        depot_public_id: string | null;
    };
};
export default function Policy({
    configuration,
    bookingEnabled,
    paymentTerms,
    services,
    depots,
}: {
    configuration: Config;
    bookingEnabled: boolean;
    paymentTerms: string;
    services: Service[];
    depots: { public_id: string; name: string }[];
}) {
    const { company } = usePage<SharedProps>().props;
    const form = useForm({
        configuration,
        booking_enabled: bookingEnabled,
        services,
    });
    useUnsavedChanges(form.isDirty);
    function config(values: Partial<Config>) {
        form.setData("configuration", {
            ...form.data.configuration,
            ...values,
        });
    }
    function service(index: number, values: Partial<Service>) {
        form.setData(
            "services",
            form.data.services.map((row, i) =>
                i === index ? { ...row, ...values } : row,
            ),
        );
    }
    return (
        <CompanyLayout>
            <Head title="Booking policy" />
            <h1 className="mb-3 text-3xl font-bold">Booking policy</h1>
            <p className="mb-7 text-slate-500">
                Configure conservative scheduling and confirmation rules.
                Payment terms remain managed by the company business profile.
            </p>
            <form
                className="grid gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put("/c/" + company?.slug + "/booking-policy");
                }}
            >
                <Card>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {(
                            [
                                ["hold_minutes", "Hold duration (minutes)"],
                                ["search_days", "Booking horizon (days)"],
                                [
                                    "arrival_window_minutes",
                                    "Arrival window (minutes)",
                                ],
                                [
                                    "cancellation_notice_minutes",
                                    "Customer cancellation notice (minutes)",
                                ],
                                [
                                    "hold_attempts_per_hour",
                                    "Maximum holds per quote per hour",
                                ],
                                [
                                    "conservative_job_minutes",
                                    "Fallback service duration (minutes, optional)",
                                ],
                                [
                                    "stop_minutes",
                                    "Additional stop allowance (minutes)",
                                ],
                                [
                                    "stairs_minutes",
                                    "Access allowance per stair flight (minutes)",
                                ],
                                [
                                    "reposition_minutes",
                                    "Repositioning allowance at each end (minutes)",
                                ],
                            ] as const
                        ).map(([field, label]) => (
                            <label key={field}>
                                {label}
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={form.data.configuration[field] ?? ""}
                                    onChange={(event) =>
                                        config({
                                            [field]:
                                                event.target.value === ""
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                />
                            </label>
                        ))}
                        <label>
                            Time-slot interval
                            <select
                                aria-label="Time-slot interval"
                                value={form.data.configuration.slot_minutes}
                                onChange={(e) =>
                                    config({
                                        slot_minutes: Number(e.target.value),
                                    })
                                }
                            >
                                {[15, 30, 60].map((value) => (
                                    <option key={value} value={value}>
                                        {value} minutes
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="mt-6 grid gap-4">
                        {(
                            [
                                [
                                    "reviewed",
                                    "I have reviewed schedules, resource requirements and conservative time allowances",
                                ],
                                [
                                    "manual_confirmation",
                                    "Require an authorised operator to confirm every booking",
                                ],
                                [
                                    "customer_cancellation",
                                    "Allow customer cancellation within the configured notice period",
                                ],
                            ] as const
                        ).map(([field, label]) => (
                            <label
                                className="flex items-start gap-3"
                                key={field}
                            >
                                <input
                                    className="!w-auto"
                                    type="checkbox"
                                    checked={form.data.configuration[field]}
                                    onChange={(e) =>
                                        config({ [field]: e.target.checked })
                                    }
                                />
                                {label}
                            </label>
                        ))}
                        <label className="flex items-start gap-3">
                            <input
                                className="!w-auto"
                                type="checkbox"
                                checked={form.data.booking_enabled}
                                onChange={(e) =>
                                    form.setData(
                                        "booking_enabled",
                                        e.target.checked,
                                    )
                                }
                            />
                            Enable customer booking after an accepted quotation
                        </label>
                    </div>
                    <p className="mt-5 rounded-lg bg-amber-50 p-4 text-sm">
                        Current payment policy: {paymentTerms}. Deposit and
                        full-payment policies remain pending until verified
                        payment integration exists. No browser action can mark a
                        payment as verified.
                    </p>
                </Card>
                {form.data.services.map((row, index) => (
                    <Card key={row.public_id}>
                        <h2 className="text-xl font-semibold">{row.name}</h2>
                        <p className="mt-2 text-sm text-slate-500">
                            Minimum service notice: {row.notice_minutes}{" "}
                            minutes.{" "}
                            {row.manual_approval
                                ? "Operator approval required."
                                : "Uses company confirmation policy."}
                        </p>
                        <label className="my-4 flex gap-3">
                            <input
                                className="!w-auto"
                                type="checkbox"
                                checked={row.publicly_bookable}
                                onChange={(e) =>
                                    service(index, {
                                        publicly_bookable: e.target.checked,
                                    })
                                }
                            />
                            Allow availability search and booking for this
                            service
                        </label>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label>
                                Required vehicles
                                <input
                                    type="number"
                                    min="1"
                                    max="4"
                                    value={row.rules.vehicles}
                                    onChange={(e) =>
                                        service(index, {
                                            rules: {
                                                ...row.rules,
                                                vehicles: Number(
                                                    e.target.value,
                                                ),
                                            },
                                        })
                                    }
                                />
                            </label>
                            <label>
                                Minimum additional movers
                                <input
                                    type="number"
                                    min="0"
                                    max="20"
                                    value={row.rules.minimum_movers}
                                    onChange={(e) =>
                                        service(index, {
                                            rules: {
                                                ...row.rules,
                                                minimum_movers: Number(
                                                    e.target.value,
                                                ),
                                            },
                                        })
                                    }
                                />
                            </label>
                            <label>
                                Required depot (optional)
                                <select
                                    aria-label={row.name + " required depot"}
                                    value={row.rules.depot_public_id ?? ""}
                                    onChange={(e) =>
                                        service(index, {
                                            rules: {
                                                ...row.rules,
                                                depot_public_id:
                                                    e.target.value || null,
                                            },
                                        })
                                    }
                                >
                                    <option value="">
                                        Any suitable operational depot
                                    </option>
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
                            <label>
                                Service opens (optional)
                                <input
                                    type="time"
                                    value={row.rules.opens_at ?? ""}
                                    onChange={(e) =>
                                        service(index, {
                                            rules: {
                                                ...row.rules,
                                                opens_at:
                                                    e.target.value || null,
                                            },
                                        })
                                    }
                                />
                            </label>
                            <label>
                                Service closes (optional)
                                <input
                                    type="time"
                                    value={row.rules.closes_at ?? ""}
                                    onChange={(e) =>
                                        service(index, {
                                            rules: {
                                                ...row.rules,
                                                closes_at:
                                                    e.target.value || null,
                                            },
                                        })
                                    }
                                />
                            </label>
                        </div>
                        <fieldset className="mt-5 flex flex-wrap gap-4">
                            <legend className="mb-3 text-sm font-semibold">
                                Operating weekdays
                            </legend>
                            {[
                                "Sunday",
                                "Monday",
                                "Tuesday",
                                "Wednesday",
                                "Thursday",
                                "Friday",
                                "Saturday",
                            ].map((label, day) => (
                                <label className="flex gap-2 text-sm" key={day}>
                                    <input
                                        className="!w-auto"
                                        type="checkbox"
                                        checked={row.rules.weekdays.includes(
                                            day,
                                        )}
                                        onChange={(e) =>
                                            service(index, {
                                                rules: {
                                                    ...row.rules,
                                                    weekdays: e.target.checked
                                                        ? [
                                                              ...row.rules
                                                                  .weekdays,
                                                              day,
                                                          ]
                                                        : row.rules.weekdays.filter(
                                                              (value) =>
                                                                  value !== day,
                                                          ),
                                                },
                                            })
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        </fieldset>
                        {row.rules.vehicles > 1 && (
                            <p className="mt-4 text-sm text-amber-800">
                                Multi-vehicle jobs require an operator
                                allocation review. Automatic partial bookings
                                are blocked.
                            </p>
                        )}
                    </Card>
                ))}
                {Object.values(form.errors).map((error, index) => (
                    <p role="alert" className="text-red-700" key={index}>
                        {error}
                    </p>
                ))}
                <Button disabled={form.processing} type="submit">
                    Save booking policy
                </Button>
            </form>
        </CompanyLayout>
    );
}
