import { Head } from "@inertiajs/react";
import type { PropsWithChildren } from "react";
export default function QuotationLayout({
    business,
    children,
    title,
}: {
    business: { name: string; slug: string };
    title: string;
} & PropsWithChildren) {
    return (
        <div>
            <Head title={title} />
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-6">
                    <a
                        className="text-xl font-bold text-teal-900"
                        href={"/q/" + business.slug}
                    >
                        {business.name}
                    </a>
                    <span className="text-sm text-slate-500">
                        A clear estimate for your next move
                    </span>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-5 py-8 md:py-12">
                {children}
            </main>
            <footer className="mx-auto max-w-5xl px-6 py-8 text-sm text-slate-500">
                Preferred dates are indicative. Quotations and acceptance do not
                confirm availability, reserve a vehicle or create a booking.
            </footer>
        </div>
    );
}
