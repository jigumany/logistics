<?php

namespace App\Domain\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class DriverInvitationNotice extends Mailable implements ShouldQueue
{
    use Queueable,SerializesModels;

    public function __construct(public readonly string $business, public readonly string $invitationUrl)
    {
        $this->afterCommit();
    }

    public function build(): static
    {
        return $this->subject('Your driver workspace invitation')->view('drivers.invitation');
    }
}
