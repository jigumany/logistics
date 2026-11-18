<?php

namespace App\Http\Resources;

use App\Domain\Dispatch\DeliveryProof;
use App\Domain\Dispatch\DispatchEvent;
use App\Domain\Dispatch\DispatchJob;
use App\Domain\Dispatch\TransitionJob;

final class DispatchView
{
    public function make(DispatchJob $job, bool $operator = false): array
    {
        $proof = DeliveryProof::where('job_id', $job->id)->first();

        return [...$job->only(['public_id', 'status', 'lock_version', 'resource_snapshot', 'work_snapshot', 'completed_at']), 'next_status' => TransitionJob::NEXT[$job->status] ?? null, 'proof' => $proof?->only(['public_id', 'recipient_name', 'recipient_acknowledged', 'notes', 'captured_at', 'sha256']), 'events' => DispatchEvent::where('job_id', $job->id)->orderBy('id')->get()->map(fn ($e) => $e->only($operator ? ['from_status', 'to_status', 'note', 'created_at'] : ['to_status', 'created_at']))->all()];
    }
}
