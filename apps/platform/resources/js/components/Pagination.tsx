import { Link } from "@inertiajs/react";
export interface Paged<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    current_page: number;
    last_page: number;
}
export default function Pagination({
    links,
}: {
    links: Paged<unknown>["links"];
}) {
    return (
        <nav aria-label="Pagination" className="mt-6 flex flex-wrap gap-2">
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        className={
                            "rounded-lg border px-3 py-2 text-sm " +
                            (link.active
                                ? "bg-teal-900 text-white"
                                : "bg-white")
                        }
                        aria-current={link.active ? "page" : undefined}
                    >
                        {link.label
                            .replace(/&laquo;/g, "«")
                            .replace(/&raquo;/g, "»")}
                    </Link>
                ) : (
                    <span
                        className="px-3 py-2 text-sm text-slate-400"
                        key={index}
                    >
                        {link.label
                            .replace(/&laquo;/g, "«")
                            .replace(/&raquo;/g, "»")}
                    </span>
                ),
            )}
        </nav>
    );
}
