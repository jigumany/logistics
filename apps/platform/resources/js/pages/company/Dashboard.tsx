import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import { Button, Card } from "@logistics/design-system";
import type {
    CompanySettings,
    ServiceType,
    SharedProps,
} from "@logistics/types";
import { depositBasisPoints } from "../../paymentPreferences";
import Icon, { type IconName } from "../../components/Icon";
import CompanyLayout from "../../layouts/CompanyLayout";
export default function Dashboard({
    settings,
    services,
}: {
    settings: CompanySettings;
    services: ServiceType[];
}) {
    const { company, auth } = usePage<SharedProps>().props;
    const canEdit = auth.role === "owner" || auth.role === "admin";
    const form = useForm({
        display_name: settings.display_name,
        contact_email: settings.contact_email,
        contact_phone: settings.contact_phone ?? "",
        payment_terms: settings.payment_terms,
        deposit_basis_points: settings.deposit_basis_points,
    });
    return (
        <CompanyLayout>
            <Head title="Company dashboard" />
            <div className="mb-8">
                <p className="text-xs font-bold uppercase tracking-widest text-teal-700">
                    Company overview
                </p>
                <h1 className="mt-2 text-3xl font-bold">
                    A clear view of your operation.
                </h1>
                <p className="mt-3 text-slate-500">
                    Manage your company profile, services and payment
                    preferences.
                </p>
            </div>
            <div className="mb-8 grid gap-4 xl:grid-cols-3">
                {[
                    {
                        title: "Fleet management",
                        text: "Vehicles, cargo measurements, payload and depots.",
                        path: "fleet",
                        icon: "truck" as IconName,
                        tone: "bg-teal-50 text-teal-700",
                    },
                    {
                        title: "Service areas",
                        text: "Coverage boundaries and areas requiring manual approval.",
                        path: "zones",
                        icon: "map" as IconName,
                        tone: "bg-indigo-50 text-indigo-600",
                    },
                    {
                        title: "Operations management",
                        text: canEdit
                            ? "Your people, working hours and resource unavailability."
                            : "Your operational bases and company configuration.",
                        path: canEdit ? "calendar/weekly" : "depots",
                        icon: "calendar" as IconName,
                        tone: "bg-amber-50 text-amber-600",
                    },
                ].map((item) => (
                    <Link
                        key={item.path}
                        href={"/c/" + company?.slug + "/" + item.path}
                        className="group rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-teal-200 hover:shadow-md"
                    >
                        <span
                            className={
                                "mb-5 inline-flex rounded-xl p-3 " + item.tone
                            }
                        >
                            <Icon name={item.icon} width="24" height="24" />
                        </span>
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold">
                                {item.title}
                            </h2>
                            <Icon
                                name="arrow"
                                className="text-slate-300 transition-transform group-hover:translate-x-1 group-hover:text-teal-700"
                            />
                        </div>
                        <p className="mt-2 text-xs leading-6 text-slate-500">
                            {item.text}
                        </p>
                    </Link>
                ))}
            </div>
            <div className="mb-8 grid gap-4 sm:grid-cols-3">
                {[
                    ["Operating region", "Cape Town & surrounds"],
                    ["Currency", settings.currency],
                    ["Time zone", settings.timezone],
                ].map(([label, value]) => (
                    <Card key={label}>
                        <p className="text-sm text-slate-500">{label}</p>
                        <p className="mt-2 font-semibold">{value}</p>
                    </Card>
                ))}
            </div>
            <div className="grid items-start gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <h2 className="mb-6 text-xl font-semibold">
                        Company settings
                    </h2>
                    <form
                        className="grid gap-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put("/c/" + company?.slug + "/settings");
                        }}
                    >
                        <fieldset
                            disabled={!canEdit || form.processing}
                            className="grid gap-5"
                        >
                            <label>
                                Company name
                                <input
                                    required
                                    maxLength={160}
                                    value={form.data.display_name}
                                    onChange={(e) =>
                                        form.setData(
                                            "display_name",
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="grid gap-5 sm:grid-cols-2">
                                <label>
                                    Contact email
                                    <input
                                        type="email"
                                        required
                                        value={form.data.contact_email}
                                        onChange={(e) =>
                                            form.setData(
                                                "contact_email",
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label>
                                    Contact phone
                                    <input
                                        type="tel"
                                        value={form.data.contact_phone}
                                        onChange={(e) =>
                                            form.setData(
                                                "contact_phone",
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <label>
                                Payment terms
                                <select
                                    value={form.data.payment_terms}
                                    onChange={(e) =>
                                        form.setData(
                                            "payment_terms",
                                            e.target
                                                .value as CompanySettings["payment_terms"],
                                        )
                                    }
                                >
                                    <option value="deposit">Deposit</option>
                                    <option value="full">Full payment</option>
                                    <option value="invoice">
                                        Invoice terms
                                    </option>
                                </select>
                            </label>
                            {form.data.payment_terms === "deposit" && (
                                <label>
                                    Deposit percentage
                                    <input
                                        type="number"
                                        min="0.01"
                                        max="100"
                                        step="0.01"
                                        value={
                                            form.data.deposit_basis_points / 100
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                "deposit_basis_points",
                                                e.target.validity.valid &&
                                                    Number(e.target.value) > 0
                                                    ? depositBasisPoints(
                                                          Number(
                                                              e.target.value,
                                                          ),
                                                      )
                                                    : 0,
                                            )
                                        }
                                    />
                                </label>
                            )}
                            {canEdit && (
                                <Button type="submit">
                                    {form.processing
                                        ? "Saving…"
                                        : "Save settings"}
                                </Button>
                            )}
                        </fieldset>
                        {Object.entries(form.errors).map(([field, error]) => (
                            <p
                                role="alert"
                                key={field}
                                className="text-sm text-red-700"
                            >
                                {error}
                            </p>
                        ))}
                        {!canEdit && (
                            <p className="text-sm text-slate-500">
                                Your role has read access. An owner or
                                administrator can update these settings.
                            </p>
                        )}
                    </form>
                </Card>
                <Card>
                    <h2 className="mb-2 text-xl font-semibold">
                        Your services
                    </h2>
                    <p className="mb-6 text-sm text-slate-500">
                        Choose what your company offers.
                    </p>
                    <div className="grid gap-5">
                        {services.map((service) => (
                            <div
                                key={service.public_id}
                                className="border-b border-slate-100 pb-4"
                            >
                                <p className="mb-2 font-medium">
                                    {service.name}
                                </p>
                                <Button
                                    variant="outline"
                                    disabled={!canEdit}
                                    aria-pressed={service.enabled}
                                    onClick={() =>
                                        router.patch(
                                            "/c/" +
                                                company?.slug +
                                                "/services/" +
                                                service.public_id,
                                            { enabled: !service.enabled },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {service.enabled ? "Enabled" : "Disabled"}
                                </Button>
                            </div>
                        ))}
                    </div>
                </Card>
            </div>
        </CompanyLayout>
    );
}
