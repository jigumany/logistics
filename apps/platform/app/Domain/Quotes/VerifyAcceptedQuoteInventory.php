<?php

namespace App\Domain\Quotes;

use App\Domain\Fleet\VehicleType;
use App\Domain\Inventory\QuoteInventory;
use App\Domain\Tenancy\AuditEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class VerifyAcceptedQuoteInventory
{
    public function execute(Quote $quote, array $input, int $actorId): Quote
    {
        app(QuotePermissions::class)->manage($actorId);
        $data = Validator::make($input, [
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:public_id,length_cm,width_cm,height_cm,weight_kg'],
            'items.*.public_id' => ['required', 'ulid', 'distinct'],
            'items.*.length_cm' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/D'],
            'items.*.width_cm' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/D'],
            'items.*.height_cm' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/D'],
            'items.*.weight_kg' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/D'],
            'vehicle_type_key' => ['required', 'string', 'max:50'],
            'crew_size' => ['required', 'integer', 'min:1', 'max:20'],
        ])->validate();

        return DB::transaction(function () use ($quote, $data, $actorId): Quote {
            $quote = Quote::whereKey($quote->id)->with(['currentRevision.inventory'])->lockForUpdate()->firstOrFail();
            $revision = $quote->currentRevision;
            if ($quote->status !== 'accepted' || ! $quote->accepted_at || ! $revision || ! $revision->sealed_at || $revision->expires_at->isPast()) {
                throw ValidationException::withMessages(['quote' => 'Only a current, accepted offer can have its load verified.']);
            }

            $vehicleType = VehicleType::where('key', $data['vehicle_type_key'])->where('active', true)->first();
            if (! $vehicleType) {
                throw ValidationException::withMessages(['vehicle_type_key' => 'Choose an active vehicle class from the operations catalogue.']);
            }
            $pricedVehicleType = $revision->pricing_snapshot['screened_vehicle_type'] ?? null;
            $pricedCrewSize = $revision->pricing_snapshot['configuration']['crew_size'] ?? null;
            $assessedVehicleTypes = collect($revision->suitability)
                ->filter(fn ($assessment) => ($assessment['technically_fits'] ?? false) === true)
                ->pluck('vehicle_type_key')->all();
            if (($pricedVehicleType && $data['vehicle_type_key'] !== $pricedVehicleType)
                || (! $pricedVehicleType && $assessedVehicleTypes && ! in_array($data['vehicle_type_key'], $assessedVehicleTypes, true))) {
                throw ValidationException::withMessages(['vehicle_type_key' => 'The required vehicle class differs from the accepted offer assessment. A corrected offer must be reviewed by the customer.']);
            }
            if ($pricedCrewSize !== null && (int) $data['crew_size'] !== (int) $pricedCrewSize) {
                throw ValidationException::withMessages(['crew_size' => 'The required crew differs from the accepted offer. A corrected offer must be reviewed by the customer.']);
            }

            $inventory = $revision->inventory->keyBy('public_id');
            if ($inventory->count() !== count($data['items']) || array_diff($inventory->keys()->all(), array_column($data['items'], 'public_id'))) {
                throw ValidationException::withMessages(['items' => 'Verify every item in the accepted quotation.']);
            }

            $previousEntry = AuditEntry::where('action', 'quote.inventory.verify')
                ->where('subject_public_id', $quote->public_id)
                ->orderByDesc('id')->first();
            $previous = $previousEntry->details ?? [];
            $previousItems = ($previous['revision_public_id'] ?? null) === $revision->public_id
                ? collect($previous['items'] ?? [])->keyBy('public_id')
                : collect();
            if (isset($previous['vehicle_type_key']) && $previous['vehicle_type_key'] !== $data['vehicle_type_key']) {
                throw ValidationException::withMessages(['vehicle_type_key' => 'The vehicle class cannot be changed after verification on an accepted offer. A corrected offer must be reviewed by the customer.']);
            }
            if (isset($previous['crew_size']) && (int) $previous['crew_size'] !== (int) $data['crew_size']) {
                throw ValidationException::withMessages(['crew_size' => 'The crew size cannot be changed after verification on an accepted offer. A corrected offer must be reviewed by the customer.']);
            }
            $verified = [];

            foreach ($data['items'] as $row) {
                /** @var QuoteInventory|null $item */
                $item = $inventory->get($row['public_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => 'An item does not belong to this accepted quotation.']);
                }

                $measurements = [];
                foreach (['length_cm', 'width_cm', 'height_cm', 'weight_kg'] as $field) {
                    $value = (string) BigDecimal::of($row[$field])->toScale(6);
                    if (in_array($field, ['length_cm', 'width_cm', 'height_cm'], true) && BigDecimal::of($value)->isZero()) {
                        throw ValidationException::withMessages(['items' => 'Item dimensions must be greater than zero.']);
                    }
                    $quoted = $item->getAttribute($field);
                    if ($quoted !== null && BigDecimal::of((string) $quoted)->compareTo(BigDecimal::of($value)) !== 0) {
                        throw ValidationException::withMessages(['items' => 'A measured value differs from the accepted quote. Ask the customer to review a corrected offer before booking.']);
                    }
                    $measurements[$field] = $value;
                }

                $prior = $previousItems->get($item->public_id);
                if ($prior && collect($measurements)->contains(fn ($value, $field) => ($prior[$field] ?? null) !== $value)) {
                    throw ValidationException::withMessages(['items' => 'Previously verified values cannot be changed on an accepted offer. A corrected offer must be reviewed by the customer.']);
                }
                $verified[] = ['public_id' => $item->public_id, 'name' => $item->name, ...$measurements];
            }

            if ($previousItems->count() === count($verified)
                && ($previous['vehicle_type_key'] ?? null) === $vehicleType->key
                && isset($previous['crew_size'])
                && (int) $previous['crew_size'] === (int) $data['crew_size']) {
                return $quote;
            }

            $audit = new AuditEntry(['actor_id' => $actorId, 'action' => 'quote.inventory.verify', 'subject_public_id' => $quote->public_id]);
            $audit->setAttribute('details', ['revision_public_id' => $revision->public_id, 'vehicle_type_key' => $vehicleType->key, 'crew_size' => (int) $data['crew_size'], 'notes' => $data['notes'], 'items' => $verified]);
            $audit->save();

            return $quote;
        });
    }
}
