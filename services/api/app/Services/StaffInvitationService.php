<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\StaffMember;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class StaffInvitationService
{
    public const ALLOWED_ROLES = ['editor', 'support', 'ads', 'finance'];

    public function issue(string $email, string $role, string $operator): StaffInvitation
    {
        $email = mb_strtolower(trim($email));
        $operator = trim($operator);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
            || ! in_array($role, self::ALLOWED_ROLES, true)
            || $operator === '' || strlen($operator) > 120) {
            throw new InvalidArgumentException('Invalid invitation arguments.');
        }

        if (StaffMember::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('Staff account already exists.');
        }

        $base = config('staff.accept_url');
        if (! $this->hasSafeAcceptUrl($base)) {
            throw new LogicException('Trusted staff invitation accept URL is not configured.');
        }

        // Mail that writes tokens to application logs is forbidden even locally.
        $mailer = config('mail.default');
        if (! in_array($mailer, ['smtp', 'ses', 'postmark', 'resend', 'mailgun', 'array'], true)) {
            throw new LogicException('A non-logging mail delivery transport must be configured.');
        }

        $token = Str::random(64);
        $invitation = StaffInvitation::create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'issued_by' => $operator,
            'expires_at' => now()->addDay(),
        ]);

        $url = $base.'?'.http_build_query([
            'invitation' => $invitation->id,
            'token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);

        Mail::to($email)->send(new StaffInvitationMail($url));

        return $invitation;
    }

    private function hasSafeAcceptUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])
            || $parts['path'] === '/'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http'
            && app()->environment(['local', 'testing'])
            && in_array($parts['host'], ['127.0.0.1', 'localhost'], true);
    }
}
