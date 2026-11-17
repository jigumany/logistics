import QuotationLayout from "../../layouts/QuotationLayout";
import BookingPanel from "../../components/BookingPanel";
import QuoteSummary from "../../components/QuoteSummary";
import type { BookingState } from "../../features/bookings/types";
export default function Booking({
    business,
    initial,
}: {
    business: { name: string; slug: string };
    initial: BookingState;
}) {
    return (
        <QuotationLayout booking business={business} title="Your booking">
            <div className="mb-7">
                <p className="text-xs font-bold uppercase tracking-widest text-teal-700">
                    Your move
                </p>
                <h1 className="mt-3 text-3xl font-bold">
                    Moving plans, secured.
                </h1>
                <p className="mt-3 text-slate-500">
                    Your accepted quotation and your resource reservation are
                    separate. Check the confirmed booking state below.
                </p>
            </div>
            <BookingPanel initial={initial} />
            <details className="mt-7">
                <summary className="cursor-pointer font-semibold">
                    Accepted quotation, journey and inventory
                </summary>
                <div className="mt-5">
                    <QuoteSummary quote={initial.quote} />
                </div>
            </details>
        </QuotationLayout>
    );
}
