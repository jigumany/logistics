<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\SearchAvailability;
use App\Domain\Quotes\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class BookingOperations
{
    public function execute(Quote $quote, string $operation, array $input, ?int $actorId = null): mixed
    {
        if ($actorId) {
            app(BookingPermissions::class)->manage($actorId);
        }
        $data = Validator::make($input, [
            'idempotency_key' => [$operation === 'availability' ? 'nullable' : 'required', 'uuid'],
            'date' => [$operation === 'availability' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,7'],
            'starts_at' => [$operation === 'hold' ? 'required' : 'nullable', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'booking_public_id' => [in_array($operation, ['reschedule', 'cancel', 'note'], true) ? 'required' : 'nullable', 'ulid'],
            'hold_public_id' => [in_array($operation, ['confirm', 'release', 'reschedule'], true) ? 'required' : 'nullable', 'ulid'],
            'lock_version' => [in_array($operation, ['reschedule', 'cancel'], true) ? 'required' : 'nullable', 'integer', 'min:0'],
            'reason' => [in_array($operation, ['reschedule', 'cancel', 'note'], true) ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
            'resource_public_ids' => ['sometimes', 'array', 'max:42'], 'resource_public_ids.*' => ['required', 'ulid', 'distinct'],
        ])->validate();
        $booking = ! empty($data['booking_public_id']) ? Booking::where('public_id', $data['booking_public_id'])->where('quote_id', $quote->id)->where('customer_id', $quote->customer_id)->firstOrFail() : null;
        $hold = ! empty($data['hold_public_id']) ? BookingHold::where('public_id', $data['hold_public_id'])->where('quote_id', $quote->id)->where('customer_id', $quote->customer_id)->firstOrFail() : null;

        return match ($operation) {
            'availability' => app(SearchAvailability::class)->execute($quote, $data['date'], (int) ($data['days'] ?? 1), $booking),
            'hold' => app(CreateBookingHold::class)->execute($quote, CarbonImmutable::parse($data['starts_at'])->utc(), $data['idempotency_key'], $booking, $data['resource_public_ids'] ?? [], $actorId),
            'confirm' => app(ConfirmBooking::class)->execute($hold, $data['idempotency_key'], $actorId),
            'reschedule' => app(RescheduleBooking::class)->execute($booking, $hold, $data['idempotency_key'], $data['reason'], (int) $data['lock_version'], $actorId),
            'cancel' => app(CancelBooking::class)->execute($booking, $data['idempotency_key'], $data['reason'], (int) $data['lock_version'], $actorId),
            'release' => app(SchedulingTransaction::class)->execute(function () use ($hold) {
                app(ExpireBookingHolds::class)->execute();
                app(ExpireBookingHolds::class)->release($hold->fresh());

                return $hold->fresh();
            }),
            'note' => $this->note($booking, $data, $actorId),
            default => abort(404),
        };
    }

    private function note(Booking $booking, array $data, ?int $actorId): Booking
    {
        abort_unless($actorId !== null, 403);
        app(BookingPermissions::class)->manage($actorId);

        return app(SchedulingTransaction::class)->execute(function () use ($booking, $data, $actorId) {
            $hash = hash('sha256', json_encode([$booking->public_id, $data['reason']], JSON_THROW_ON_ERROR));
            if (! app(BookingCommandReceipt::class)->find($data['idempotency_key'], 'note', $hash)) {
                app(BookingCommandReceipt::class)->save($booking, $data['idempotency_key'], 'note', $hash, $data['reason'], [], $actorId);
            }

            return $booking;
        });
    }
}
