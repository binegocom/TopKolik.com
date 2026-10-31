<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class StaffInvitationMail extends Mailable
{
    public function __construct(public readonly string $acceptUrl)
    {
    }

    public function build(): self
    {
        return $this->subject('Topkolik staff invitation')
            ->text('mail.staff-invitation');
    }
}
