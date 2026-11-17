import { Head, Link, usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import CompanyLayout from "../../../layouts/CompanyLayout";
import Pagination, { type Paged } from "../../../components/Pagination";
import type { PriceBookRecord } from "../../../features/quotes/adminTypes";
export default function Index({
    books,
}: {
    books: Paged<PriceBookRecord & { service_name: string }>;
}) {
    const { company } = usePage<SharedProps>().props;
    const base = "/c/" + company?.slug + "/price-books";
    return (
        <CompanyLayout>
            <Head title="Price books" />
            <div className="mb-7 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">Price books</h1>
                    <p className="mt-2 text-slate-500">
                        Draft, publish and retire versioned service pricing.
                    </p>
                </div>
                <Button asChild>
                    <Link href={base + "/create"}>Create price book</Link>
                </Button>
            </div>
            <Card>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="py-3">Name</th>
                                <th>Service</th>
                                <th>Version</th>
                                <th>Status</th>
                                <th>Currency</th>
                                <th>Effective from</th>
                            </tr>
                        </thead>
                        <tbody>
                            {books.data.map((book) => (
                                <tr
                                    key={book.public_id}
                                    className="border-b border-slate-100"
                                >
                                    <td className="py-4">
                                        <Link
                                            className="font-semibold text-teal-800 underline"
                                            href={base + "/" + book.public_id}
                                        >
                                            {book.name}
                                        </Link>
                                    </td>
                                    <td>{book.service_name}</td>
                                    <td>{book.version}</td>
                                    <td className="capitalize">
                                        {book.is_demonstration
                                            ? "Demo · unapproved · "
                                            : ""}
                                        {book.status}
                                    </td>
                                    <td>{book.currency}</td>
                                    <td>
                                        {new Date(
                                            book.effective_from,
                                        ).toLocaleString("en-ZA")}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {books.data.length === 0 && (
                    <p className="py-6 text-slate-500">
                        No price books have been configured. Requests will
                        require manual pricing.
                    </p>
                )}
                <Pagination links={books.links} />
            </Card>
        </CompanyLayout>
    );
}
