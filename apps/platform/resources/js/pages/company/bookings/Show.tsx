import { Head, Link } from "@inertiajs/react";
import CompanyLayout from "../../../layouts/CompanyLayout";
import BookingPanel from "../../../components/BookingPanel";
import QuoteSummary from "../../../components/QuoteSummary";
import type {
    BookingState,
    BookingResource,
} from "../../../features/bookings/types";
export default function Show({
    initial,
    operationBase,
    resourceOptions = [],
}: {
    initial: BookingState;
    operationBase: string;
    resourceOptions?: BookingResource[];
}) {
    return (
        <CompanyLayout>
            <Head title={initial.booking?.reference ?? "Prepare booking"} />
            <div className="mb-7">
                <Link
                    className="text-sm font-semibold text-teal-800 underline"
                    href={operationBase.split("/bookings/")[0] + "/bookings"}
                >
                    All bookings
                </Link>
                <h1 className="mt-3 text-3xl font-bold">
                    {initial.booking?.reference ??
                        "Prepare an operational booking"}
                </h1>
                <p className="mt-2 text-slate-500">
                    Accepted quotation {initial.quote.reference} ·{" "}
                    {initial.quote.revision?.contact_snapshot.name ??
                        "Customer"}
                </p>
                <p className="mt-1 text-sm text-slate-500">
                    {initial.quote.revision?.contact_snapshot.email ?? ""} ·{" "}
                    {initial.quote.revision?.contact_snapshot.phone ?? ""}
                </p>
            </div>
            <BookingPanel
                initial={initial}
                operationBase={operationBase}
                resourceOptions={resourceOptions}
            />
            <details className="mt-7">
                <summary className="cursor-pointer font-semibold">
                    Accepted quotation and move details
                </summary>
                <div className="mt-5">
                    <QuoteSummary quote={initial.quote} />
                </div>
            </details>
        </CompanyLayout>
    );
}
