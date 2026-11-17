import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import Pagination, { type Paged } from "../../../components/Pagination";
import type { CatalogueRecord } from "../../../features/quotes/adminTypes";
export default function Index({
    items,
    filters,
}: {
    items: Paged<CatalogueRecord>;
    filters: { search?: string };
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/inventory";
    const [search, setSearch] = useState(filters.search ?? "");
    return (
        <CompanyLayout>
            <Head title="Inventory catalogue" />
            <div className="mb-7 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">Inventory catalogue</h1>
                    <p className="mt-2 text-slate-500">
                        Platform estimates are read only. Company items can be
                        configured independently.
                    </p>
                </div>
                <Button asChild>
                    <Link href={base + "/create"}>Add company item</Link>
                </Button>
            </div>
            <Card>
                <form
                    className="mb-6 flex items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(base, { search }, { preserveState: true });
                    }}
                >
                    <label className="grow">
                        Search catalogue
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                    <Button type="submit">Search</Button>
                </form>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="py-3">Item</th>
                                <th>Category</th>
                                <th>Definition</th>
                                <th>Estimated volume</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.data.map((item) => (
                                <tr
                                    key={item.public_id}
                                    className="border-b border-slate-100"
                                >
                                    <td className="py-4">
                                        {item.global ? (
                                            item.name
                                        ) : (
                                            <Link
                                                className="font-semibold text-teal-800 underline"
                                                href={
                                                    base + "/" + item.public_id
                                                }
                                            >
                                                {item.name}
                                            </Link>
                                        )}
                                    </td>
                                    <td>{item.category}</td>
                                    <td>
                                        {item.global
                                            ? "Platform default"
                                            : "Company item"}
                                    </td>
                                    <td>
                                        {item.volume_m3
                                            ? Number(item.volume_m3) + " m³"
                                            : "Unknown"}
                                    </td>
                                    <td>
                                        {item.active ? "Active" : "Archived"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {items.data.length === 0 && (
                    <p className="py-6 text-slate-500">
                        No matching catalogue items.
                    </p>
                )}
                <Pagination links={items.links} />
            </Card>
        </CompanyLayout>
    );
}
