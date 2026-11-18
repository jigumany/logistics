<?php

namespace App\Domain\Dispatch;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\SchedulingTransaction;
use App\Infrastructure\Storage\PrivateTenantFiles;
use Illuminate\Http\UploadedFile;

final class CaptureDeliveryProof
{
    public function execute(DispatchJob $job, array $data, UploadedFile $photo, int $actor): DeliveryProof
    {
        app(DispatchAccess::class)->assigned($job, $actor);
        if (! filter_var($data['recipient_acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN) || trim($data['recipient_name'] ?? '') === '') {
            throw BookingConflict::because('acknowledgement_required', 'The named recipient must acknowledge delivery.');
        }
        $digest = hash_file('sha256', $photo->getRealPath());
        $hash = hash('sha256', json_encode([$job->public_id, $data['recipient_name'], $data['recipient_acknowledged'], $data['notes'] ?? null, $digest], JSON_THROW_ON_ERROR));
        $storage = app(PrivateTenantFiles::class);
        $path = $storage->store($photo, 'delivery-proofs');
        try {
            $result = app(SchedulingTransaction::class)->execute(function () use ($job, $data, $actor, $path, $digest, $hash, $photo) {
                $j = DispatchJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
                $old = DeliveryProof::where('idempotency_key', $data['idempotency_key'])->first();
                if ($old) {
                    if (! hash_equals($old->request_hash, $hash)) {
                        throw BookingConflict::because('idempotency_conflict', 'This evidence key has already been used.');
                    }

                    return $old;
                }
                if ($j->status !== 'in_transit' || DeliveryProof::where('job_id', $j->id)->exists()) {
                    throw BookingConflict::because('proof_conflict', 'Delivery proof can only be captured once while this job is in transit.');
                }

                return DeliveryProof::create(['job_id' => $j->id, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'recipient_name' => $data['recipient_name'], 'recipient_acknowledged' => true, 'notes' => $data['notes'] ?? null, 'photo_path' => $path, 'sha256' => $digest, 'mime_type' => $photo->getMimeType(), 'size_bytes' => $photo->getSize(), 'captured_by' => $actor, 'captured_at' => now()]);
            });
            if ($result->photo_path !== $path) {
                $storage->delete($path);
            }

            return $result;
        } catch (\Throwable $e) {
            $storage->delete($path);
            throw $e;
        }
    }
}
