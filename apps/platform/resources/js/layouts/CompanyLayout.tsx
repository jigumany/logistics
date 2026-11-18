import { Link, usePage } from "@inertiajs/react";
import type { SharedProps } from "@logistics/types";
import { useEffect, useState, type PropsWithChildren } from "react";
import Icon, { type IconName } from "../components/Icon";
type Item = { path: string; label: string; icon: IconName };
export default function CompanyLayout({ children }: PropsWithChildren) {
    const page = usePage<SharedProps>();
    const { company, auth, flash } = page.props;
    const [open, setOpen] = useState(false);
    const base = "/c/" + company?.slug;
    useEffect(() => {
        setOpen(false);
    }, [page.url]);
    useEffect(() => {
        if (!open) return;
        const handle = (e: KeyboardEvent) => {
            if (e.key === "Escape") setOpen(false);
        };
        document.addEventListener("keydown", handle);
        return () => document.removeEventListener("keydown", handle);
    }, [open]);
    const manager = ["owner", "admin"].includes(auth.role ?? "");
    const groups: { label: string; items: Item[] }[] = [
        {
            label: "Workspace",
            items: [
                { path: "", label: "Overview", icon: "dashboard" },
                ...(["owner", "admin", "dispatcher"].includes(auth.role ?? "")
                    ? [
                          {
                              path: "quotes",
                              label: "Quote inbox",
                              icon: "file" as const,
                          },
                      ]
                    : []),
            ],
        },
        {
            label: "Operations",
            items: [
                ...(["owner", "admin", "dispatcher"].includes(auth.role ?? "")
                    ? [
                          {
                              path: "bookings",
                              label: "Bookings",
                              icon: "file" as const,
                          },
                          {
                              path: "planner",
                              label: "Operations planner",
                              icon: "calendar" as const,
                          },
                      ]
                    : []),
                ...(["owner", "admin", "dispatcher", "driver"].includes(
                    auth.role ?? "",
                )
                    ? [
                          {
                              path: "dispatch",
                              label:
                                  auth.role === "driver"
                                      ? "My driving jobs"
                                      : "Dispatch board",
                              icon: "truck" as const,
                          },
                      ]
                    : []),
                ...(manager
                    ? [
                          {
                              path: "finance",
                              label: "Payments and invoices",
                              icon: "file" as const,
                          },
                      ]
                    : []),
                { path: "fleet", label: "Fleet", icon: "truck" },
                { path: "zones", label: "Service areas", icon: "map" },
                { path: "depots", label: "Depots", icon: "depot" },
                ...(manager
                    ? [
                          {
                              path: "staff",
                              label: "Staff and drivers",
                              icon: "users" as const,
                          },
                          {
                              path: "calendar/weekly",
                              label: "Operating calendar",
                              icon: "calendar" as const,
                          },
                      ]
                    : []),
            ],
        },
        {
            label: "Configuration",
            items: [
                { path: "services", label: "Services", icon: "box" },
                ...(manager
                    ? [
                          {
                              path: "inventory",
                              label: "Inventory catalogue",
                              icon: "box" as const,
                          },
                          {
                              path: "price-books",
                              label: "Price books",
                              icon: "file" as const,
                          },
                          {
                              path: "quotation-policies",
                              label: "Quote policies",
                              icon: "check" as const,
                          },
                      ]
                    : []),
                {
                    path: "settings",
                    label: "Company settings",
                    icon: "settings",
                },
            ],
        },
    ];
    if (auth.role === "driver") {
        groups.splice(0, groups.length, {
            label: "Driver workspace",
            items: [
                { path: "dispatch", label: "My driving jobs", icon: "truck" },
            ],
        });
    }
    const active = (path: string) =>
        path === "calendar/weekly"
            ? page.url.split("?")[0].startsWith(base + "/calendar/")
            : path
              ? page.url.split("?")[0].startsWith(base + "/" + path)
              : page.url.split("?")[0] === base;
    const current = groups.flatMap((g) => g.items).find((i) => active(i.path));
    return (
        <div className="company-shell min-h-screen bg-[#f5f7fb] text-slate-800">
            <a
                href="#workspace-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-lg focus:bg-white focus:p-3"
            >
                Skip to content
            </a>
            {open && (
                <button
                    className="fixed inset-0 z-40 bg-slate-950/45 lg:hidden"
                    aria-label="Close navigation"
                    onClick={() => setOpen(false)}
                />
            )}
            <aside
                id="company-sidebar"
                aria-label="Workspace sidebar"
                className={
                    "fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform lg:translate-x-0 " +
                    (open
                        ? "visible translate-x-0"
                        : "invisible -translate-x-full lg:visible")
                }
            >
                <Link
                    href="/companies"
                    className="flex h-20 shrink-0 items-center gap-3 border-b border-slate-100 px-6"
                >
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#172b4d] text-white">
                        <Icon name="truck" width="24" height="24" />
                    </span>
                    <span>
                        <span className="block text-lg font-bold tracking-tight text-[#172b4d]">
                            Logistics<span className="text-teal-600">.</span>
                        </span>
                        <span className="block text-[10px] font-semibold uppercase tracking-[.2em] text-slate-400">
                            Company workspace
                        </span>
                    </span>
                </Link>
                {company && (
                    <div className="mx-4 mt-5 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-sm font-bold text-teal-800 shadow-sm">
                            {company.name
                                .split(" ")
                                .map((s) => s[0])
                                .slice(0, 2)
                                .join("")}
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold">
                                {company.name}
                            </p>
                            <Link
                                href="/companies"
                                className="text-xs text-slate-500 hover:text-teal-700"
                            >
                                Switch workspace ↗
                            </Link>
                        </div>
                    </div>
                )}
                <nav
                    aria-label="Company navigation"
                    className="flex-1 overflow-y-auto px-4 py-5"
                >
                    {groups.map((group) => (
                        <div key={group.label} className="mb-6">
                            <p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[.15em] text-slate-400">
                                {group.label}
                            </p>
                            {group.items.map((item) => (
                                <Link
                                    key={item.path}
                                    href={
                                        base +
                                        (item.path ? "/" + item.path : "")
                                    }
                                    aria-current={
                                        active(item.path) ? "page" : undefined
                                    }
                                    className={
                                        "mb-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium transition-colors " +
                                        (active(item.path)
                                            ? "bg-teal-50 text-teal-900 ring-1 ring-inset ring-teal-100"
                                            : "text-slate-500 hover:bg-slate-50 hover:text-slate-900")
                                    }
                                >
                                    <Icon
                                        name={item.icon}
                                        className={
                                            active(item.path)
                                                ? "text-teal-700"
                                                : "text-slate-400"
                                        }
                                    />
                                    {item.label}
                                    {active(item.path) && (
                                        <span className="ml-auto h-1.5 w-1.5 rounded-full bg-teal-600" />
                                    )}
                                </Link>
                            ))}
                        </div>
                    ))}
                </nav>
                <div className="flex items-center gap-3 border-t border-slate-100 p-5">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold">
                        {auth.user?.name?.slice(0, 1)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">
                            {auth.user?.name}
                        </p>
                        <p className="text-xs capitalize text-slate-400">
                            {auth.role}
                        </p>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        aria-label="Sign out"
                        title="Sign out"
                        className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-800"
                    >
                        <Icon name="logout" />
                    </Link>
                </div>
            </aside>
            <div className="lg:pl-64">
                <header className="flex h-20 items-center justify-between gap-4 border-b border-slate-200/80 bg-white px-5 sm:px-8">
                    <div className="flex min-w-0 items-center gap-3">
                        <button
                            type="button"
                            aria-label={open ? "Close menu" : "Open menu"}
                            aria-expanded={open}
                            aria-controls="company-sidebar"
                            onClick={() => setOpen(!open)}
                            className="rounded-lg border border-slate-200 p-2 lg:hidden"
                        >
                            <Icon name="menu" />
                        </button>
                        <span className="hidden text-sm text-slate-400 sm:inline">
                            Workspace
                        </span>
                        <Icon
                            name="chevron"
                            className="hidden h-3 w-3 text-slate-300 sm:block"
                        />
                        <span className="truncate text-sm font-semibold">
                            {current?.label ?? "Company"}
                        </span>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="hidden rounded-full border border-slate-200 px-3 py-1.5 text-[11px] font-medium text-slate-500 sm:inline-flex">
                            Company operations
                        </span>
                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-teal-50 text-xs font-bold text-teal-800">
                            {auth.user?.name?.slice(0, 1)}
                        </span>
                    </div>
                </header>
                <main
                    id="workspace-content"
                    className="mx-auto max-w-[1600px] px-4 py-7 sm:px-8 sm:py-9"
                    tabIndex={-1}
                >
                    {flash.success && (
                        <p
                            role="status"
                            className="mb-6 flex items-center gap-3 rounded-xl border border-teal-200 bg-teal-50 px-5 py-4 text-sm text-teal-900"
                        >
                            <Icon name="check" />
                            {flash.success}
                        </p>
                    )}
                    {children}
                </main>
                <footer className="flex flex-wrap justify-between gap-2 px-8 pb-6 text-[11px] text-slate-400">
                    <span>{company?.name} · Logistics workspace</span>
                    <span>Plan clearly. Move confidently.</span>
                </footer>
            </div>
        </div>
    );
}
