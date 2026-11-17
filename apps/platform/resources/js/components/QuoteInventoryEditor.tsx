import { useEffect, useState } from "react";
import type { InventoryEntry } from "@logistics/types";
import { Button } from "@logistics/design-system";
interface Item {
    public_id: string;
    name: string;
    category: string;
    description: string | null;
    length_cm: string | null;
    width_cm: string | null;
    height_cm: string | null;
    weight_kg: string | null;
    handling: string;
    image_url?: string | null;
}
export default function QuoteInventoryEditor({
    base,
    items,
    onChange,
    photos,
    onPhotos,
    photosLocked,
}: {
    base: string;
    items: InventoryEntry[];
    onChange: (items: InventoryEntry[]) => void;
    photos: Record<string, File[]>;
    onPhotos: (key: string, files: File[]) => void;
    photosLocked: boolean;
}) {
    const [search, setSearch] = useState("");
    const [catalogue, setCatalogue] = useState<Item[]>([]);
    const [nextPage, setNextPage] = useState<string | null>(null);
    const [notice, setNotice] = useState("");
    useEffect(() => {
        const abort = new AbortController();
        const timer = setTimeout(() => {
            fetch(base + "/catalogue?" + new URLSearchParams({ search }), {
                signal: abort.signal,
                headers: { Accept: "application/json" },
            })
                .then(async (response) => {
                    if (!response.ok) throw new Error();
                    return response.json();
                })
                .then(
                    (data: { data: Item[]; next_page_url: string | null }) => {
                        setCatalogue(data.data);
                        setNextPage(data.next_page_url);
                        setNotice("");
                    },
                )
                .catch(() => {
                    if (!abort.signal.aborted)
                        setNotice(
                            "The catalogue is unavailable. You can still add custom items.",
                        );
                });
        }, 300);
        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [base, search]);
    function add(definition?: Item) {
        if (items.length >= 100) {
            setNotice(
                "A request can contain up to 100 different inventory entries.",
            );
            return;
        }
        onChange([
            ...items,
            {
                item_key: crypto.randomUUID(),
                catalogue_public_id: definition?.public_id ?? null,
                name: definition?.name ?? "",
                quantity: 1,
                length_cm: null,
                width_cm: null,
                height_cm: null,
                weight_kg: null,
                weight_certainty: "estimated",
                fragile: definition?.handling === "fragile",
                oversized: false,
                stackable: true,
                rotation_allowed: true,
                instructions:
                    definition?.handling === "special"
                        ? "Special handling assessment required."
                        : null,
            },
        ]);
    }
    function change(index: number, values: Partial<InventoryEntry>) {
        onChange(
            items.map((item, i) =>
                i === index ? { ...item, ...values } : item,
            ),
        );
    }
    async function more() {
        if (!nextPage) return;
        try {
            const response = await fetch(nextPage, {
                headers: { Accept: "application/json" },
            });
            if (!response.ok) throw new Error();
            const data = (await response.json()) as {
                data: Item[];
                next_page_url: string | null;
            };
            setCatalogue([...catalogue, ...data.data]);
            setNextPage(data.next_page_url);
        } catch {
            setNotice(
                "More items could not be loaded. Retry or add a custom item.",
            );
        }
    }
    return (
        <div className="grid gap-6">
            <label>
                Search the item catalogue
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Furniture, boxes, appliances…"
                />
            </label>
            <p className="text-sm text-slate-500">
                Catalogue measurements are estimates. Add your own measurements
                when known; leave unknown values blank.
            </p>
            {notice && <p role="alert">{notice}</p>}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {catalogue.map((item) => (
                    <div
                        className="rounded-xl border border-slate-200 p-4"
                        key={item.public_id}
                    >
                        {item.image_url && (
                            <img
                                className="mb-3 h-20 w-20 rounded-lg object-cover"
                                src={item.image_url}
                                alt=""
                            />
                        )}
                        <p className="font-bold">{item.name}</p>
                        <p className="my-2 text-xs text-slate-500">
                            {item.category}
                            {item.length_cm
                                ? ` · approximately ${Number(item.length_cm)} × ${Number(item.width_cm)} × ${Number(item.height_cm)} cm`
                                : ""}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => add(item)}
                        >
                            Add {item.name}
                        </Button>
                    </div>
                ))}
            </div>
            {nextPage && (
                <Button type="button" variant="outline" onClick={more}>
                    More catalogue items
                </Button>
            )}
            <Button type="button" variant="outline" onClick={() => add()}>
                Add a custom item
            </Button>
            <h3 className="text-lg font-bold">
                Your inventory (
                {items.reduce((sum, item) => sum + item.quantity, 0)} items)
            </h3>
            {items.map((item, index) => (
                <fieldset
                    key={item.item_key}
                    className="grid gap-4 rounded-xl border border-slate-200 p-5"
                >
                    <legend className="px-2 font-bold">Item {index + 1}</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label>
                            Item name
                            <input
                                required
                                maxLength={160}
                                value={item.name ?? ""}
                                onChange={(event) =>
                                    change(index, { name: event.target.value })
                                }
                            />
                        </label>
                        <label>
                            Quantity
                            <input
                                required
                                type="number"
                                min={1}
                                max={1000}
                                value={item.quantity}
                                onChange={(event) =>
                                    change(index, {
                                        quantity: Math.max(
                                            1,
                                            Number(event.target.value),
                                        ),
                                    })
                                }
                            />
                        </label>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-4">
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
                                    type="number"
                                    min={field === "weight_kg" ? 0 : 0.000001}
                                    max={999999}
                                    step="0.000001"
                                    value={item[field] ?? ""}
                                    onChange={(event) =>
                                        change(index, {
                                            [field]: event.target.value || null,
                                        })
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <label>
                        Weight certainty
                        <select
                            value={item.weight_certainty ?? "estimated"}
                            onChange={(event) =>
                                change(index, {
                                    weight_certainty: event.target.value as
                                        "known" | "estimated" | "unknown",
                                })
                            }
                        >
                            <option value="known">
                                Known weight supplied by me
                            </option>
                            <option value="estimated">Estimated weight</option>
                            <option value="unknown">Unknown weight</option>
                        </select>
                    </label>
                    <div className="flex flex-wrap gap-5">
                        {(
                            [
                                ["fragile", "Fragile"],
                                ["oversized", "Oversized"],
                                ["stackable", "Can be stacked"],
                                ["rotation_allowed", "May be rotated"],
                            ] as const
                        ).map(([field, label]) => (
                            <label
                                className="flex items-center gap-2"
                                key={field}
                            >
                                <input
                                    className="!w-auto"
                                    type="checkbox"
                                    checked={item[field]}
                                    onChange={(event) =>
                                        change(index, {
                                            [field]: event.target.checked,
                                        })
                                    }
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                    <label>
                        Special handling instructions
                        <textarea
                            className="rounded-lg border border-slate-300 p-3"
                            maxLength={2000}
                            value={item.instructions ?? ""}
                            onChange={(event) =>
                                change(index, {
                                    instructions: event.target.value || null,
                                })
                            }
                        />
                    </label>
                    <label>
                        Photos (up to 5, JPG/PNG/WebP, 5 MB each)
                        <input
                            disabled={photosLocked}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            onChange={(event) => {
                                const files = Array.from(
                                    event.target.files ?? [],
                                );
                                if (
                                    files.length > 5 ||
                                    files.some((file) => file.size > 5242880)
                                ) {
                                    setNotice(
                                        "Choose at most five photos of 5 MB or less each.",
                                    );
                                    event.target.value = "";
                                    return;
                                }
                                onPhotos(item.item_key, files);
                            }}
                        />
                    </label>
                    {photosLocked && (
                        <p className="text-xs text-slate-500">
                            Photos are attached before the first estimate.
                            Previously attached photos remain with the request.
                        </p>
                    )}
                    {photos[item.item_key]?.length > 0 && (
                        <p className="text-sm">
                            {photos[item.item_key]
                                .map((file) => file.name)
                                .join(", ")}
                        </p>
                    )}
                    <button
                        className="justify-self-start text-sm text-red-700 underline"
                        type="button"
                        onClick={() =>
                            onChange(items.filter((_, i) => i !== index))
                        }
                    >
                        Remove item
                    </button>
                </fieldset>
            ))}
        </div>
    );
}
