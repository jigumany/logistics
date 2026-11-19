import { useForm } from "@inertiajs/react";
import type { QuoteInventoryRow } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";

type VerifiedItem = Pick<
    QuoteInventoryRow,
    "public_id" | "name" | "length_cm" | "width_cm" | "height_cm" | "weight_kg"
>;

export default function InventoryVerificationPanel({
    inventory,
    verification,
    vehicleTypes,
    allowedVehicleTypeKeys,
    pricedCrewSize,
    actionUrl,
}: {
    inventory: QuoteInventoryRow[];
    verification: {
        notes: string;
        vehicle_type_key?: string;
        crew_size?: number;
        items: VerifiedItem[];
    } | null;
    vehicleTypes: { key: string; name: string }[];
    allowedVehicleTypeKeys: string[];
    pricedCrewSize: number | null;
    actionUrl: string;
}) {
    const availableVehicleTypes = vehicleTypes.filter(
        (type) =>
            allowedVehicleTypeKeys.length === 0 ||
            allowedVehicleTypeKeys.includes(type.key),
    );
    const verified = new Map(
        (verification?.items ?? []).map((item) => [item.public_id, item]),
    );
    const form = useForm({
        notes: verification?.notes ?? "",
        vehicle_type_key:
            verification?.vehicle_type_key ??
            availableVehicleTypes[0]?.key ??
            "",
        crew_size: String(pricedCrewSize ?? verification?.crew_size ?? ""),
        items: inventory.map((item) => {
            const measured = verified.get(item.public_id);
            return {
                public_id: item.public_id,
                length_cm: measured?.length_cm ?? item.length_cm ?? "",
                width_cm: measured?.width_cm ?? item.width_cm ?? "",
                height_cm: measured?.height_cm ?? item.height_cm ?? "",
                weight_kg: measured?.weight_kg ?? item.weight_kg ?? "",
            };
        }),
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(actionUrl);
    }

    return (
        <Card>
            <h2 className="text-xl font-bold">Verify customer inventory</h2>
            <p className="mt-2 text-sm text-slate-600">
                Confirm each item’s dimensions in centimetres and weight in
                kilograms per item with the customer or from an on-site
                measurement. This does not change the accepted price. If a
                measurement differs from the accepted quote, stop and arrange a
                corrected offer for customer acceptance.
            </p>
            <form className="mt-5 grid gap-5" onSubmit={submit}>
                <label>
                    Required vehicle class
                    <select
                        aria-label="Required vehicle class"
                        required
                        disabled={Boolean(verification?.vehicle_type_key)}
                        value={form.data.vehicle_type_key}
                        onChange={(event) =>
                            form.setData("vehicle_type_key", event.target.value)
                        }
                    >
                        <option value="">Choose a vehicle class</option>
                        {availableVehicleTypes.map((type) => (
                            <option key={type.key} value={type.key}>
                                {type.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    Required crew (including the driver)
                    <select
                        aria-label="Required crew including the driver"
                        required
                        disabled={
                            pricedCrewSize !== null ||
                            verification?.crew_size !== undefined
                        }
                        value={form.data.crew_size}
                        onChange={(event) =>
                            form.setData("crew_size", event.target.value)
                        }
                    >
                        <option value="">Confirm the required crew</option>
                        {Array.from(
                            { length: 20 },
                            (_, index) => index + 1,
                        ).map((size) => (
                            <option key={size} value={String(size)}>
                                {size} {size === 1 ? "person" : "people"}
                            </option>
                        ))}
                    </select>
                    {pricedCrewSize !== null && (
                        <span className="mt-1 block text-sm text-slate-600">
                            Fixed by the accepted quotation.
                        </span>
                    )}
                </label>
                {inventory.map((item, index) => {
                    const measured = verified.has(item.public_id);
                    return (
                        <fieldset
                            className="rounded-lg border border-slate-200 p-4"
                            key={item.public_id}
                        >
                            <legend className="px-2 font-semibold">
                                {item.name} · {item.quantity} item(s)
                                {measured ? " · Verified" : ""}
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(
                                    [
                                        ["length_cm", "Length (cm)"],
                                        ["width_cm", "Width (cm)"],
                                        ["height_cm", "Height (cm)"],
                                        ["weight_kg", "Weight per item (kg)"],
                                    ] as const
                                ).map(([field, label]) => (
                                    <label key={field}>
                                        {label}
                                        <input
                                            aria-label={`${item.name} ${label}`}
                                            type="number"
                                            inputMode="decimal"
                                            min={
                                                field === "weight_kg"
                                                    ? 0
                                                    : 0.000001
                                            }
                                            max={999999}
                                            step="0.000001"
                                            required
                                            disabled={measured}
                                            value={
                                                form.data.items[index][field]
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    "items",
                                                    form.data.items.map(
                                                        (row, rowIndex) =>
                                                            rowIndex === index
                                                                ? {
                                                                      ...row,
                                                                      [field]:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : row,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    );
                })}
                {inventory.length > 0 &&
                verified.size === inventory.length &&
                verification?.vehicle_type_key &&
                verification.crew_size !== undefined ? (
                    <p
                        role="status"
                        className="rounded-lg bg-teal-50 p-4 text-sm"
                    >
                        Item measurements, required vehicle class, and crew are
                        verified. The customer can refresh the booking page and
                        check availability again.
                    </p>
                ) : (
                    <>
                        <label>
                            Verification notes
                            <textarea
                                required
                                minLength={3}
                                maxLength={2000}
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData("notes", event.target.value)
                                }
                                placeholder="For example: confirmed with the customer by phone on 19 November."
                            />
                        </label>
                        {Object.keys(form.errors).length > 0 && (
                            <p role="alert" className="text-sm text-red-700">
                                {Object.values(form.errors).join(" ")}
                            </p>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? "Saving verification…"
                                : "Save operations verification"}
                        </Button>
                    </>
                )}
            </form>
        </Card>
    );
}
