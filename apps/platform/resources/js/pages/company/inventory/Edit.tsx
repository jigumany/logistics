import { Head, useForm, usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import type { CatalogueRecord } from "../../../features/quotes/adminTypes";
import { useUnsavedChanges } from "../../../hooks/useUnsavedChanges";
export default function Edit({ item }: { item: CatalogueRecord | null }) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/inventory";
    const form = useForm({
        name: item?.name ?? "",
        category: item?.category ?? "furniture",
        description: item?.description ?? "",
        length_cm: item?.length_cm ?? null,
        width_cm: item?.width_cm ?? null,
        height_cm: item?.height_cm ?? null,
        weight_kg: item?.weight_kg ?? null,
        volume_m3: item?.volume_m3 ?? null,
        handling: item?.handling ?? "standard",
        active: item?.active ?? true,
    });
    const image = useForm({ file: null as File | null });
    useUnsavedChanges(form.isDirty);
    return (
        <CompanyLayout>
            <Head title={item ? "Edit inventory item" : "Add inventory item"} />
            <h1 className="mb-7 text-3xl font-bold">
                {item ? "Edit catalogue item" : "Add company catalogue item"}
            </h1>
            <Card>
                <form
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (item) form.put(base + "/" + item.public_id);
                        else form.post(base);
                    }}
                >
                    <p className="text-sm text-slate-500">
                        Catalogue values are estimates, never verified
                        measurements of a customer’s actual items.
                    </p>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <label>
                            Item name
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
                            Category
                            <input
                                required
                                list="inventory-categories"
                                maxLength={80}
                                value={form.data.category}
                                onChange={(event) =>
                                    form.setData("category", event.target.value)
                                }
                            />
                        </label>
                        <datalist id="inventory-categories">
                            {[
                                "furniture",
                                "appliances",
                                "boxes",
                                "office equipment",
                                "general cargo",
                            ].map((value) => (
                                <option key={value}>{value}</option>
                            ))}
                        </datalist>
                    </div>
                    <label>
                        Description
                        <textarea
                            className="rounded-lg border border-slate-300 p-3"
                            maxLength={5000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData("description", event.target.value)
                            }
                        />
                    </label>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {(
                            [
                                ["length_cm", "Standard length (cm)"],
                                ["width_cm", "Standard width (cm)"],
                                ["height_cm", "Standard height (cm)"],
                                ["weight_kg", "Estimated weight (kg)"],
                                [
                                    "volume_m3",
                                    "Estimated volume (m³, if dimensions unknown)",
                                ],
                            ] as const
                        ).map(([field, label]) => (
                            <label key={field}>
                                {label}
                                <input
                                    type="number"
                                    step="0.000001"
                                    min={field === "weight_kg" ? 0 : 0.000001}
                                    max={999999}
                                    value={form.data[field] ?? ""}
                                    onChange={(event) =>
                                        form.setData(
                                            field,
                                            event.target.value || null,
                                        )
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <label>
                        Handling classification
                        <select
                            aria-label="Handling classification"
                            value={form.data.handling}
                            onChange={(event) =>
                                form.setData(
                                    "handling",
                                    event.target
                                        .value as CatalogueRecord["handling"],
                                )
                            }
                        >
                            <option value="standard">Standard</option>
                            <option value="fragile">Fragile</option>
                            <option value="special">Special handling</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-3">
                        <input
                            className="!w-auto"
                            type="checkbox"
                            checked={form.data.active}
                            onChange={(event) =>
                                form.setData("active", event.target.checked)
                            }
                        />
                        Active and available in customer searches
                    </label>
                    {Object.keys(form.errors).length > 0 && (
                        <p role="alert" className="text-red-700">
                            {Object.values(form.errors).join(" ")}
                        </p>
                    )}
                    <Button type="submit" disabled={form.processing}>
                        Save catalogue item
                    </Button>
                </form>
            </Card>
            {item && (
                <Card className="mt-6">
                    <h2 className="text-xl font-bold">Optional item image</h2>
                    {item.has_image && (
                        <img
                            className="my-4 max-h-48 rounded-lg"
                            alt={item.name}
                            src={
                                "/q/" +
                                company?.slug +
                                "/catalogue/" +
                                item.public_id +
                                "/image"
                            }
                        />
                    )}
                    <form
                        className="mt-4 grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            image.post(base + "/" + item.public_id + "/image", {
                                forceFormData: true,
                                onSuccess: () => image.reset(),
                            });
                        }}
                    >
                        <label>
                            Catalogue image (JPG/PNG/WebP, up to 5 MB)
                            <input
                                required
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(event) =>
                                    image.setData(
                                        "file",
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                        {image.errors.file && (
                            <p role="alert" className="text-red-700">
                                {image.errors.file}
                            </p>
                        )}
                        <Button
                            disabled={image.processing || !image.data.file}
                            type="submit"
                        >
                            Upload item image
                        </Button>
                    </form>
                </Card>
            )}
        </CompanyLayout>
    );
}
