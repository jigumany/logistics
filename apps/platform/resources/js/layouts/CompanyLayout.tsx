import { Link, usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import type { PropsWithChildren } from "react";
import { Button } from "@logistics/design-system";
export default function CompanyLayout({ children }: PropsWithChildren) {
    const { company, auth, flash } = usePage<SharedProps>().props;
    return (
        <div>
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
                    <Link
                        href="/companies"
                        className="font-bold tracking-tight"
                    >
                        LOGISTICS{" "}
                        <span className="font-normal text-slate-500">
                            / {company?.name}
                        </span>
                    </Link>
                    <nav
                        aria-label="Account"
                        className="flex items-center gap-5 text-sm"
                    >
                        <span>{auth.user?.name}</span>
                        <Button variant="outline" asChild>
                            <Link href="/logout" method="post" as="button">
                                Sign out
                            </Link>
                        </Button>
                    </nav>
                </div>
            </header>
            {company && (
                <nav
                    aria-label="Company navigation"
                    className="border-b border-slate-200 bg-white"
                >
                    <div className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-6 py-3 text-sm">
                        {[
                            ["", "Overview"],
                            ["settings", "Company settings"],
                            ["services", "Services"],
                            ["zones", "Service areas"],
                            ["depots", "Depots"],
                            ["fleet", "Fleet"],
                            ...(["owner", "admin", "dispatcher"].includes(
                                auth.role ?? "",
                            )
                                ? [["quotes", "Quote inbox"]]
                                : []),
                            ...(["owner", "admin"].includes(auth.role ?? "")
                                ? [
                                      ["inventory", "Inventory catalogue"],
                                      ["price-books", "Price books"],
                                      ["quotation-policies", "Quote policies"],
                                      ["staff", "Staff and drivers"],
                                      ["calendar/weekly", "Operating calendar"],
                                  ]
                                : []),
                        ].map(([path, label]) => (
                            <Link
                                className="shrink-0 rounded-lg px-3 py-2 text-slate-600 hover:bg-teal-50 hover:text-teal-900"
                                href={
                                    "/c/" +
                                    company.slug +
                                    (path ? "/" + path : "")
                                }
                                key={path}
                            >
                                {label}
                            </Link>
                        ))}
                    </div>
                </nav>
            )}
            <main className="mx-auto max-w-6xl px-6 py-10">
                {flash.success && (
                    <p
                        role="status"
                        className="mb-6 rounded-lg bg-teal-100 p-4"
                    >
                        {flash.success}
                    </p>
                )}
                {children}
            </main>
        </div>
    );
}
