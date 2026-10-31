<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

final class StaffMfaService
{
    public function __construct(private readonly Google2FA $totp)
    {
    }

    public function generateSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    public function provisioningUri(StaffMember $member): string
    {
        $secret = $member->mfa_pending_secret;

        return 'otpauth://totp/'.rawurlencode('Topkolik:'.$member->email).'?'
            .http_build_query([
                'secret' => $secret,
                'issuer' => 'Topkolik',
                'algorithm' => 'SHA1',
                'digits' => 6,
                'period' => 30,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verifyPendingCode(StaffMember $member, string $code): int|false
    {
        if (! $member->mfa_pending_secret || ! preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }

        // Passing a null previous step returns a boolean in Google2FA; 0 forces
        // an actual TOTP time counter for later replay prevention.
        $step = $this->totp->verifyKeyNewer($member->mfa_pending_secret, $code, 0, 1);

        return $step === false || ! is_int($step) || $step <= 0 ? false : $step;
    }

    // Must be called with the staff row locked in a database transaction.
    public function consumeTotp(StaffMember $member, string $code): bool
    {
        if (! $member->mfa_secret || ! preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }

        $step = $this->totp->verifyKeyNewer(
            $member->mfa_secret,
            $code,
            $member->mfa_last_step,
            1,
        );

        if ($step === false || ! is_int($step)
            || $step <= (int) ($member->mfa_last_step ?? 0)) {
            return false;
        }

        $member->forceFill(['mfa_last_step' => (int) $step])->save();

        return true;
    }

    // High entropy random offline recovery codes: each is shown once, only
    // SHA-256 hashes are persisted, and a successful recovery deletes its row.
    public function issueRecoveryCodes(StaffMember $member): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(bin2hex(random_bytes(16)));
            $codes[] = $code;
            DB::table('staff_recovery_codes')->insert([
                'staff_member_id' => $member->getKey(),
                'code_hash' => hash('sha256', $code),
                'created_at' => now(),
            ]);
        }

        return $codes;
    }

    // Must be called within a transaction locking the staff row.
    public function consumeRecoveryCode(StaffMember $member, string $code): bool
    {
        if (! preg_match('/^[0-9A-F]{32}$/D', $code)) {
            return false;
        }

        return DB::table('staff_recovery_codes')
            ->where('staff_member_id', $member->getKey())
            ->where('code_hash', hash('sha256', strtoupper($code)))
            ->delete() === 1;
    }

    public function consumeFactor(StaffMember $member, ?string $totp, ?string $recovery): bool
    {
        if (($totp === null) === ($recovery === null)) {
            return false;
        }

        return $totp !== null
            ? $this->consumeTotp($member, $totp)
            : $this->consumeRecoveryCode($member, $recovery);
    }
}
