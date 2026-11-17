import { useEffect, useRef, useState } from "react";
import type { AvailabilitySlot, AvailabilityResult } from "@logistics/types";
import { Button, Card } from "@logistics/design-system";
import { quoteRequest } from "../features/quotes/http";
import { money } from "../features/quotes/money";
import {
    displayDate,
    displayTime,
    type BookingState,
    type BookingResource,
} from "../features/bookings/types";
export default function BookingPanel({
    initial,
    operationBase,
    resourceOptions = [],
}: {
    initial: BookingState;
    operationBase?: string;
    resourceOptions?: BookingResource[];
}) {
    const [state, setState] = useState(initial);
    const [date, setDate] = useState(
        initial.quote.preferred_date?.slice(0, 10) ??
            new Date().toISOString().slice(0, 10),
    );
    const [slots, setSlots] = useState<AvailabilitySlot[]>([]);
    const [selected, setSelected] = useState("");
    const [rescheduleReason, setRescheduleReason] = useState("");
    const [cancellationReason, setCancellationReason] = useState("");
    const [internalNote, setInternalNote] = useState("");
    const [preferred, setPreferred] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [rescheduling, setRescheduling] = useState(
        initial.hold?.purpose === "reschedule",
    );
    const [now, setNow] = useState(Date.now());
    const pending = useRef(false);
    const keys = useRef(new Map<string, string>());
    const [offset, setOffset] = useState(
        Date.parse(initial.server_time) - Date.now(),
    );
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, []);
    const hold = state.hold;
    const booking = state.booking;
    const operator = !!operationBase;
    const remaining = hold
        ? Math.max(
              0,
              Math.ceil((Date.parse(hold.expires_at) - now - offset) / 1000),
          )
        : 0;
    const activeHold = !!hold && hold.status === "active" && remaining > 0;
    const url = (operation: string) =>
        operationBase
            ? operationBase + "/" + operation
            : state.links[operation];
    function bodyKey(operation: string, body: Record<string, unknown>) {
        const signature = operation + JSON.stringify(body);
        if (!keys.current.has(signature))
            keys.current.set(signature, crypto.randomUUID());
        return keys.current.get(signature)!;
    }
    async function action(
        operation: string,
        body: Record<string, unknown> = {},
    ) {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError("");
        setNotice("");
        try {
            const result = await quoteRequest<
                BookingState | AvailabilityResult
            >(url(operation), {
                ...body,
                ...(operation === "availability"
                    ? {}
                    : { idempotency_key: bodyKey(operation, body) }),
            });
            if (operation === "availability") {
                const resultSlots = result as AvailabilityResult;
                setSlots(resultSlots.slots);
                setSelected("");
                if (!resultSlots.slots.length)
                    setNotice(
                        resultSlots.reasons
                            .map((reason) => reason.message)
                            .join(" ") ||
                            "No slots are available. Try another date.",
                    );
            } else {
                const updated = result as BookingState;
                setState(updated);
                setOffset(Date.parse(updated.server_time) - Date.now());
                setSelected("");
                setSlots([]);
                setNotice(
                    operation === "release"
                        ? "The temporary hold was released."
                        : operation === "note"
                          ? "Internal note saved."
                          : "Booking state updated from the reservation system.",
                );
                if (operation === "reschedule" || operation === "cancel")
                    setRescheduling(false);
            }
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : "The booking could not be updated.",
            );
            setSlots([]);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    async function refresh() {
        if (pending.current) return;
        setBusy(true);
        try {
            const result = await quoteRequest<BookingState>(
                operationBase ?? state.links.view,
            );
            setState(result);
            setOffset(Date.parse(result.server_time) - Date.now());
            setError("");
        } catch (error) {
            setError(
                error instanceof Error ? error.message : "Refresh failed.",
            );
        } finally {
            setBusy(false);
        }
    }
    const canSearch =
        (!booking || (booking.status === "confirmed" && rescheduling)) &&
        !activeHold;
    return (
        <div className="grid min-w-0 gap-5">
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-widest text-teal-700">
                            Reservation desk
                        </p>
                        <h2 className="mt-2 text-2xl font-bold">
                            {booking?.reference ?? "Choose your moving time"}
                        </h2>
                        <p className="mt-2 text-sm text-slate-500">
                            Times in {state.timezone}. Arrival is an indicative
                            window, not a guaranteed arrival time.
                        </p>
                    </div>
                    <span
                        className="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold capitalize"
                        data-testid="booking-status"
                    >
                        {booking?.status.replaceAll("_", " ") ??
                            (activeHold ? "Temporarily held" : "Not booked")}
                    </span>
                </div>
                {booking && (
                    <>
                        <p
                            className={
                                "mt-5 rounded-lg p-4 text-sm " +
                                (booking.status === "confirmed"
                                    ? "bg-teal-50 text-teal-900"
                                    : "bg-amber-50 text-amber-900")
                            }
                        >
                            {booking.confirmation_instructions}
                        </p>
                        <dl className="mt-5 grid gap-5 sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-slate-500">
                                    Pickup window
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {displayDate(
                                        booking.arrival_starts_at,
                                        state.timezone,
                                    )}{" "}
                                    ·{" "}
                                    {displayTime(
                                        booking.arrival_starts_at,
                                        state.timezone,
                                    )}
                                    –
                                    {displayTime(
                                        booking.arrival_ends_at,
                                        state.timezone,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">
                                    Estimated service interval
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {displayTime(
                                        booking.service_starts_at,
                                        state.timezone,
                                    )}
                                    –
                                    {displayTime(
                                        booking.service_ends_at,
                                        state.timezone,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">
                                    Accepted quote total
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {money(
                                        state.quote.revision?.total_minor ??
                                            null,
                                        state.quote.revision?.currency ?? null,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">
                                    Payment requirement
                                </dt>
                                <dd className="mt-1 capitalize">
                                    {booking.financial_state.replaceAll(
                                        "_",
                                        " ",
                                    )}{" "}
                                    ·{" "}
                                    {money(
                                        booking.required_payment_minor,
                                        booking.currency,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">
                                    Dispatch preparation
                                </dt>
                                <dd className="mt-1 capitalize">
                                    {booking.dispatch_state.replaceAll(
                                        "_",
                                        " ",
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </>
                )}
                {hold && (
                    <div className="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <h3 className="font-semibold">
                            {hold.purpose === "reschedule"
                                ? "Replacement time temporarily held"
                                : "Resources temporarily held"}
                        </h3>
                        <p className="mt-2 text-sm">
                            {displayDate(
                                hold.arrival_starts_at,
                                state.timezone,
                            )}{" "}
                            ·{" "}
                            {displayTime(
                                hold.arrival_starts_at,
                                state.timezone,
                            )}
                            –{displayTime(hold.arrival_ends_at, state.timezone)}
                        </p>
                        <p className="mt-2 text-sm" role="timer">
                            {activeHold
                                ? `Hold expires in ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, "0")}`
                                : "This hold expired. Refresh availability before continuing."}
                        </p>
                        {hold.purpose === "reschedule" && (
                            <p className="mt-2 text-sm">
                                Your existing booking remains in place until the
                                replacement is confirmed.
                            </p>
                        )}
                        {hold.purpose === "reschedule" && (
                            <label className="mt-4 block">
                                Reason for rescheduling
                                <input
                                    value={rescheduleReason}
                                    maxLength={2000}
                                    onChange={(event) =>
                                        setRescheduleReason(event.target.value)
                                    }
                                />
                            </label>
                        )}
                        <div className="mt-4 flex flex-wrap gap-3">
                            <Button
                                disabled={
                                    busy ||
                                    !activeHold ||
                                    (hold.purpose === "reschedule" &&
                                        rescheduleReason.trim().length < 3)
                                }
                                onClick={() =>
                                    action(
                                        hold.purpose === "reschedule"
                                            ? "reschedule"
                                            : "confirm",
                                        {
                                            hold_public_id: hold.public_id,
                                            ...(hold.purpose === "reschedule"
                                                ? {
                                                      booking_public_id:
                                                          booking?.public_id,
                                                      lock_version:
                                                          booking?.lock_version,
                                                      reason: rescheduleReason,
                                                  }
                                                : {}),
                                        },
                                    )
                                }
                            >
                                {hold.purpose === "reschedule"
                                    ? "Confirm reschedule"
                                    : operator
                                      ? "Confirm booking"
                                      : "Request booking confirmation"}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={busy || !activeHold}
                                onClick={() =>
                                    action("release", {
                                        hold_public_id: hold.public_id,
                                    })
                                }
                            >
                                Release hold
                            </Button>
                        </div>
                    </div>
                )}
                <div className="mt-5 flex flex-wrap gap-3">
                    <Button variant="outline" disabled={busy} onClick={refresh}>
                        Refresh booking status
                    </Button>
                    {booking?.status === "confirmed" && !activeHold && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                setRescheduling(!rescheduling);
                                setSlots([]);
                                setError("");
                            }}
                        >
                            Reschedule booking
                        </Button>
                    )}
                </div>
            </Card>
            {canSearch && (
                <Card>
                    <h2 className="text-xl font-bold">
                        {rescheduling
                            ? "Find a replacement time"
                            : "Find availability"}
                    </h2>
                    <p className="mt-2 text-sm text-slate-500">
                        Search results are advisory. Only a successful hold
                        reserves the full truck, driver and crew combination.
                    </p>
                    <div className="mt-5 flex flex-wrap items-end gap-4">
                        <label>
                            Moving date
                            <input
                                type="date"
                                required
                                value={date}
                                onChange={(event) => {
                                    setDate(event.target.value);
                                    setSlots([]);
                                    setSelected("");
                                }}
                            />
                        </label>
                        <Button
                            disabled={busy || !date}
                            onClick={() =>
                                action("availability", {
                                    date,
                                    days: 1,
                                    ...(rescheduling && booking
                                        ? {
                                              booking_public_id:
                                                  booking.public_id,
                                          }
                                        : {}),
                                })
                            }
                        >
                            Find available times
                        </Button>
                    </div>
                    {operator && resourceOptions.length > 0 && (
                        <details className="mt-4">
                            <summary className="cursor-pointer text-sm font-semibold">
                                Select specific operations resources (optional)
                            </summary>
                            <label className="mt-3 block text-sm">
                                Truck, qualified driver and complete required
                                moving crew
                                <select
                                    multiple
                                    size={7}
                                    value={preferred}
                                    onChange={(event) =>
                                        setPreferred(
                                            Array.from(
                                                event.target.selectedOptions,
                                                (option) => option.value,
                                            ),
                                        )
                                    }
                                >
                                    {resourceOptions.map((resource) => (
                                        <option
                                            key={resource.public_id}
                                            value={resource.public_id}
                                        >
                                            {resource.kind} · {resource.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <p className="mt-2 text-xs text-slate-500">
                                Leave empty for deterministic automatic
                                matching. A partial resource selection is
                                rejected.
                            </p>
                        </details>
                    )}
                    {slots.length > 0 && (
                        <>
                            <fieldset className="mt-6 grid gap-3 sm:grid-cols-3">
                                <legend className="mb-3 font-semibold">
                                    Available time slots
                                </legend>
                                {slots.map((slot) => (
                                    <label
                                        className={
                                            "flex cursor-pointer items-center gap-3 rounded-xl border p-4 " +
                                            (selected === slot.starts_at
                                                ? "border-teal-700 bg-teal-50"
                                                : "border-slate-200")
                                        }
                                        key={slot.starts_at}
                                    >
                                        <input
                                            className="!w-auto"
                                            type="radio"
                                            name="booking-slot"
                                            value={slot.starts_at}
                                            checked={
                                                selected === slot.starts_at
                                            }
                                            onChange={() =>
                                                setSelected(slot.starts_at)
                                            }
                                        />
                                        <span>
                                            <strong>
                                                {displayTime(
                                                    slot.starts_at,
                                                    state.timezone,
                                                )}
                                            </strong>
                                            <span className="block text-xs font-normal text-slate-500">
                                                ~
                                                {slot.estimated_service_minutes}{" "}
                                                min service
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                            <Button
                                className="mt-5"
                                disabled={busy || !selected}
                                onClick={() =>
                                    action("hold", {
                                        starts_at: selected,
                                        ...(rescheduling && booking
                                            ? {
                                                  booking_public_id:
                                                      booking.public_id,
                                              }
                                            : {}),
                                        ...(preferred.length
                                            ? { resource_public_ids: preferred }
                                            : {}),
                                    })
                                }
                            >
                                Hold selected time
                            </Button>
                        </>
                    )}
                </Card>
            )}
            {booking &&
                [
                    "confirmed",
                    "pending_confirmation",
                    "pending_payment",
                ].includes(booking.status) && (
                    <Card>
                        <h2 className="text-lg font-semibold">Cancellation</h2>
                        <p className="mt-2 text-sm text-slate-500">
                            Cancellation follows the agreed policy. Refunds, if
                            required, need a separate financial review.
                        </p>
                        <label className="mt-4 block">
                            Cancellation reason
                            <textarea
                                value={cancellationReason}
                                maxLength={2000}
                                onChange={(event) =>
                                    setCancellationReason(event.target.value)
                                }
                            />
                        </label>
                        <Button
                            className="mt-4"
                            variant="outline"
                            disabled={
                                busy || cancellationReason.trim().length < 3
                            }
                            onClick={() =>
                                action("cancel", {
                                    booking_public_id: booking.public_id,
                                    lock_version: booking.lock_version,
                                    reason: cancellationReason,
                                })
                            }
                        >
                            Cancel booking
                        </Button>
                    </Card>
                )}
            {operator && booking && (
                <Card>
                    <h2 className="font-semibold">Allocated resources</h2>
                    <ul className="mt-3 grid gap-2 text-sm">
                        {booking.resources?.map((resource) => (
                            <li key={resource.public_id} className="capitalize">
                                {resource.kind} · {resource.label}{" "}
                                {resource.registration
                                    ? `· ${resource.registration}`
                                    : ""}
                            </li>
                        ))}
                    </ul>
                    <label className="mt-5 block">
                        Internal operations note
                        <textarea
                            value={internalNote}
                            maxLength={2000}
                            onChange={(event) =>
                                setInternalNote(event.target.value)
                            }
                        />
                    </label>
                    <Button
                        className="mt-3"
                        disabled={busy || internalNote.trim().length < 3}
                        onClick={() =>
                            action("note", {
                                booking_public_id: booking.public_id,
                                reason: internalNote,
                            })
                        }
                    >
                        Save internal note
                    </Button>
                    <ul className="mt-4 grid gap-2 text-sm">
                        {booking.changes
                            ?.filter((change) => change.kind === "note")
                            .map((change, index) => (
                                <li
                                    key={index}
                                    className="rounded bg-slate-50 p-3"
                                >
                                    {change.reason}
                                </li>
                            ))}
                    </ul>
                </Card>
            )}
            {booking?.history.length ? (
                <Card>
                    <h2 className="text-lg font-semibold">Booking history</h2>
                    <ol className="mt-4 grid gap-3">
                        {booking.history.map((entry, index) => (
                            <li
                                className="border-l-2 border-teal-200 pl-4 text-sm"
                                key={index}
                            >
                                <strong className="capitalize">
                                    {entry.to_status.replaceAll("_", " ")}
                                </strong>{" "}
                                ·{" "}
                                {displayDate(entry.created_at, state.timezone)}{" "}
                                {displayTime(entry.created_at, state.timezone)}
                                {entry.reason && (
                                    <p className="mt-1 text-slate-500">
                                        {entry.reason}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </Card>
            ) : null}
            {busy && (
                <p role="status" className="text-sm text-teal-800">
                    Checking authoritative booking state…
                </p>
            )}
            {notice && (
                <p role="status" className="rounded-lg bg-teal-50 p-4 text-sm">
                    {notice}
                </p>
            )}
            {error && (
                <div
                    role="alert"
                    className="rounded-lg bg-amber-50 p-4 text-sm text-amber-900"
                >
                    {error}
                    <p className="mt-2">
                        Refresh booking status or search another date. A failed
                        replacement does not cancel your existing booking.
                    </p>
                </div>
            )}
        </div>
    );
}
