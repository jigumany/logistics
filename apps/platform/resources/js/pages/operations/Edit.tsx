import { Head, Link, useForm, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Button, Card } from "@logistics/design-system";
import type { SharedProps } from "@logistics/types";
import CompanyLayout from "../../layouts/CompanyLayout";
import LookupSelect from "../../components/LookupSelect";
import MapSurface, {
    type PolygonBoundary,
} from "../../components/maps/MapSurface";
import OperationalMedia from "../../components/OperationalMedia";
import VehicleCapacityCheck from "../../components/VehicleCapacityCheck";
import { useUnsavedChanges } from "../../hooks/useUnsavedChanges";
import {
    modules,
    humanize,
    weekdayName,
    type Field,
} from "../../features/operations/config";
type OperationValue =
    string | number | boolean | null | string[] | PolygonBoundary;
type Props = {
    module: string;
    record: Record<string, unknown> | null;
    companyTimezone?: string;
};
export default function Edit(props: Props) {
    return (
        <Editor key={props.module + JSON.stringify(props.record)} {...props} />
    );
}
function Editor({ module, record, companyTimezone }: Props) {
    const config = modules[module];
    const { company, auth } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug;
    const canEdit = auth.role === "owner" || auth.role === "admin";
    const initial: Record<string, OperationValue> = { ...config.defaults };
    if (!record && companyTimezone && "timezone" in initial)
        initial.timezone = companyTimezone;
    for (const field of config.fields) {
        if (record && field.name in record)
            initial[field.name] = record[field.name] as OperationValue;
    }
    if (module === "services" && record)
        initial.service_type_key = String(record.system_key);
    for (const key of ["starts_at", "ends_at"])
        if (initial[key])
            initial[key] = new Date(String(initial[key]))
                .toISOString()
                .slice(0, 16);
    const form = useForm(initial);
    useUnsavedChanges(form.isDirty);
    const url =
        module === "settings"
            ? base + "/settings"
            : base + "/" + module + (record ? "/" + record.public_id : "");
    const fieldGroup = (field: Field) => {
        if (module === "fleet") {
            if (
                [
                    "name",
                    "registration_number",
                    "vehicle_type_key",
                    "make",
                    "model",
                    "manufacture_year",
                ].includes(field.name)
            )
                return "Vehicle identity";
            if (["status", "depot_public_id"].includes(field.name))
                return "Operational readiness";
            if (
                field.name.includes("cargo_") ||
                field.name.includes("max_item_") ||
                [
                    "usable_volume_m3",
                    "payload_kg",
                    "capacity_margin_basis_points",
                ].includes(field.name)
            )
                return "Cargo & capacity";
            return "Restrictions & notes";
        }
        if (module === "staff") {
            if (["full_name", "email", "phone"].includes(field.name))
                return "Personal details";
            if (
                [
                    "licence_category",
                    "licence_expires_on",
                    "qualifications",
                    "eligible_vehicle_classes",
                ].includes(field.name)
            )
                return "Driver qualifications";
            return "Role & employment";
        }
        return field.group ?? "Details";
    };
    const groups = [...new Set(config.fields.map(fieldGroup))];
    const submit = () => {
        form.transform((data) => {
            const next = { ...data };
            for (const field of config.fields) {
                if (field.type === "number")
                    next[field.name] =
                        data[field.name] === ""
                            ? null
                            : Number(data[field.name]);
                if (field.type === "datetime" && data[field.name])
                    next[field.name] = new Date(String(data[field.name]) + "Z")
                        .toISOString()
                        .replace(".000Z", "+00:00");
            }
            return next;
        });
        if (module === "services" && record) form.patch(url);
        else if (record || module === "settings") form.put(url);
        else form.post(url);
    };
    const fieldControl = (field: Field) => {
        const value = form.data[field.name];
        const disabled =
            !canEdit ||
            form.processing ||
            (module === "services" &&
                !!record &&
                field.name === "service_type_key");
        let control;
        if (field.type === "lookup")
            control = (
                <LookupSelect
                    base={base}
                    selectedLabel={
                        record
                            ? String(
                                  record[
                                      field.name === "depot_public_id"
                                          ? "depot_name"
                                          : field.name === "resource_public_id"
                                            ? "resource_name"
                                            : field.name ===
                                                "default_service_public_id"
                                              ? "default_service_name"
                                              : "vehicle_type_name"
                                  ] ?? "",
                              ) || undefined
                            : undefined
                    }
                    kind={field.lookup!}
                    label={field.label}
                    value={String(value ?? "")}
                    required={field.required}
                    disabled={disabled}
                    onChange={(value) => form.setData(field.name, value)}
                />
            );
        else if (field.type === "checkbox")
            control = (
                <label className="flex items-start gap-3 py-3">
                    <input
                        className="mt-1 w-auto"
                        type="checkbox"
                        disabled={disabled}
                        checked={Boolean(value)}
                        onChange={(event) =>
                            form.setData(field.name, event.target.checked)
                        }
                    />
                    {field.label}
                </label>
            );
        else if (field.type === "polygon")
            control = (
                <BoundaryEditor
                    value={value as PolygonBoundary | null}
                    disabled={disabled}
                    onChange={(value) => form.setData(field.name, value)}
                />
            );
        else if (field.type === "classes")
            control = (
                <fieldset>
                    <legend className="mb-3 text-sm font-semibold">
                        {field.label}
                    </legend>
                    <div className="flex flex-wrap gap-4">
                        {["light", "medium", "heavy", "articulated"].map(
                            (item) => (
                                <label
                                    className="flex items-center gap-2"
                                    key={item}
                                >
                                    <input
                                        className="w-auto"
                                        type="checkbox"
                                        disabled={disabled}
                                        checked={(value as string[]).includes(
                                            item,
                                        )}
                                        onChange={(event) =>
                                            form.setData(
                                                field.name,
                                                event.target.checked
                                                    ? [
                                                          ...(value as string[]),
                                                          item,
                                                      ]
                                                    : (
                                                          value as string[]
                                                      ).filter(
                                                          (value) =>
                                                              value !== item,
                                                      ),
                                            )
                                        }
                                    />
                                    {humanize(item)}
                                </label>
                            ),
                        )}
                    </div>
                </fieldset>
            );
        else {
            const inputProps = {
                id: field.name,
                disabled,
                required:
                    field.required &&
                    !(
                        module === "calendar/overrides" &&
                        form.data.closed &&
                        ["opens_at", "closes_at"].includes(field.name)
                    ),
                value:
                    field.type === "percentage" &&
                    value !== null &&
                    value !== ""
                        ? String(Number(value) / 100)
                        : String(value ?? ""),
                onChange: (
                    event: React.ChangeEvent<
                        | HTMLInputElement
                        | HTMLTextAreaElement
                        | HTMLSelectElement
                    >,
                ) =>
                    form.setData(
                        field.name,
                        field.type === "percentage" && event.target.value !== ""
                            ? Math.round(Number(event.target.value) * 100)
                            : event.target.value,
                    ),
            };
            control = (
                <div className="grid gap-2">
                    <label htmlFor={field.name}>{field.label}</label>
                    {field.type === "textarea" ? (
                        <textarea
                            className="min-h-24 rounded-lg border border-slate-300 bg-white p-3 font-normal"
                            {...inputProps}
                        />
                    ) : field.type === "select" ? (
                        <select {...inputProps}>
                            {field.options?.map((option) => (
                                <option value={option} key={option}>
                                    {field.name === "weekday"
                                        ? weekdayName(option)
                                        : humanize(option)}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <input
                            type={
                                field.type === "datetime"
                                    ? "datetime-local"
                                    : field.type === "percentage"
                                      ? "number"
                                      : (field.type ?? "text")
                            }
                            step={
                                field.type === "percentage"
                                    ? "0.01"
                                    : field.type === "number"
                                      ? "any"
                                      : undefined
                            }
                            min={field.type === "percentage" ? 0 : undefined}
                            max={field.type === "percentage" ? 100 : undefined}
                            {...inputProps}
                        />
                    )}
                </div>
            );
        }
        return (
            <div
                key={field.name}
                className={
                    field.type === "textarea" || field.type === "polygon"
                        ? "sm:col-span-2"
                        : ""
                }
            >
                {control}
                {field.help && (
                    <p className="mt-2 text-xs leading-relaxed text-slate-500">
                        {field.help}
                    </p>
                )}
                {form.errors[field.name] && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                        {form.errors[field.name]}
                    </p>
                )}
            </div>
        );
    };
    return (
        <CompanyLayout>
            <Head title={config.singular} />
            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    {module !== "settings" && (
                        <Link
                            className="mb-3 inline-block text-sm text-teal-800 underline"
                            href={base + "/" + module}
                        >
                            ← {config.title}
                        </Link>
                    )}
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {module === "settings"
                            ? config.title
                            : (record ? "Edit " : "Add ") +
                              config.singular.toLowerCase()}
                    </h1>
                    <p className="mt-2 text-sm text-slate-500">
                        {module === "settings"
                            ? "Your business profile, branding and operating preferences. Currency: ZAR."
                            : form.isDirty
                              ? "Unsaved changes"
                              : "Company-owned configuration · changes are recorded."}
                    </p>
                </div>
                {record && canEdit && (config.archive || config.removable) && (
                    <Button
                        variant="outline"
                        onClick={() => {
                            if (
                                window.confirm(
                                    config.archive
                                        ? "Archive this record? It will remain in history and cannot be offered for allocations."
                                        : "Remove this calendar configuration?",
                                )
                            )
                                form.delete(url);
                        }}
                    >
                        {config.archive ? "Archive" : "Remove"}
                    </Button>
                )}
            </div>
            {record?.archived_at ? (
                <p className="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm">
                    This record is archived. Historical relationships are
                    retained.
                </p>
            ) : null}
            <form
                className="grid gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                {groups.map((group) => (
                    <Card key={group}>
                        <div className="mb-6 border-b border-slate-100 pb-4">
                            <h2 className="text-base font-semibold">{group}</h2>
                            <p className="mt-1 text-xs text-slate-400">
                                {group === "Cargo & capacity"
                                    ? "Use verified internal measurements and safe working limits."
                                    : group === "Vehicle identity"
                                      ? "Identify this vehicle across your company operation."
                                      : group === "Operational readiness"
                                        ? "Choose the home depot and current operating status."
                                        : "Keep your company records complete and up to date."}
                            </p>
                        </div>
                        <div className="grid gap-6 sm:grid-cols-2">
                            {config.fields
                                .filter((field) => fieldGroup(field) === group)
                                .map(fieldControl)}
                        </div>
                        {module === "depots" && (
                            <div className="mt-6">
                                <MapSurface
                                    points={
                                        form.data.latitude !== "" &&
                                        form.data.longitude !== ""
                                            ? [
                                                  {
                                                      latitude: Number(
                                                          form.data.latitude,
                                                      ),
                                                      longitude: Number(
                                                          form.data.longitude,
                                                      ),
                                                  },
                                              ]
                                            : []
                                    }
                                    editing={canEdit ? "point" : undefined}
                                    onPointChange={(latitude, longitude) => {
                                        form.setData("latitude", latitude);
                                        form.setData("longitude", longitude);
                                        form.setData(
                                            "coordinates_verified",
                                            false,
                                        );
                                    }}
                                />
                                <p className="mt-2 text-xs text-slate-500">
                                    Click to position the depot. Confirm and
                                    verify the coordinates before operational
                                    use.
                                </p>
                            </div>
                        )}
                    </Card>
                ))}
                {Object.keys(form.errors).length > 0 && (
                    <p role="alert" className="text-sm text-red-700">
                        Review the highlighted fields before saving.{" "}
                        {Object.entries(form.errors)
                            .filter(
                                ([key]) =>
                                    !config.fields.some(
                                        (field) => field.name === key,
                                    ),
                            )
                            .map(([, error]) => error)
                            .join(" ")}
                    </p>
                )}
                {canEdit && (
                    <div className="sticky bottom-4 z-20 flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg shadow-slate-200/60 backdrop-blur">
                        <Button disabled={form.processing}>
                            {form.processing
                                ? "Saving…"
                                : "Save " + config.singular.toLowerCase()}
                        </Button>
                        {form.isDirty && (
                            <span className="text-sm text-amber-700">
                                Unsaved changes
                            </span>
                        )}
                    </div>
                )}
            </form>
            {record &&
                canEdit &&
                ["settings", "fleet", "staff"].includes(module) && (
                    <div className="mt-8 grid gap-6">
                        <OperationalMedia
                            base={base}
                            kind={
                                module === "settings"
                                    ? "logo"
                                    : module === "fleet"
                                      ? "vehicle"
                                      : "document"
                            }
                            id={String(record.public_id)}
                            hasImage={Boolean(
                                record.has_logo || record.has_photo,
                            )}
                        />
                        {module === "fleet" && (
                            <VehicleCapacityCheck
                                url={
                                    base +
                                    "/fleet/" +
                                    record.public_id +
                                    "/capacity"
                                }
                            />
                        )}
                    </div>
                )}
        </CompanyLayout>
    );
}
function BoundaryEditor({
    value,
    disabled,
    onChange,
}: {
    value: PolygonBoundary | null;
    disabled: boolean;
    onChange: (value: PolygonBoundary | null) => void;
}) {
    const [text, setText] = useState(
        value ? JSON.stringify(value, null, 2) : "",
    );
    return (
        <div className="grid gap-3">
            <p className="text-sm font-semibold">Coverage boundary</p>
            <MapSurface
                boundary={value}
                editing={disabled ? undefined : "polygon"}
                onBoundaryChange={(boundary) => {
                    onChange(boundary);
                    setText(boundary ? JSON.stringify(boundary, null, 2) : "");
                }}
            />
            <label>
                GeoJSON boundary
                <textarea
                    disabled={disabled}
                    className="min-h-36 rounded-lg border border-slate-300 p-3 font-mono text-xs"
                    value={text}
                    onChange={(event) => {
                        setText(event.target.value);
                        try {
                            const parsed = event.target.value
                                ? JSON.parse(event.target.value)
                                : null;
                            event.target.setCustomValidity("");
                            onChange(parsed);
                        } catch {
                            event.target.setCustomValidity(
                                "Enter valid GeoJSON.",
                            );
                        }
                    }}
                />
            </label>
        </div>
    );
}
