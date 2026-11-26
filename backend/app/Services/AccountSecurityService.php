<?php

namespace App\Services;

use App\Contracts\SmsOtpDelivery;
use App\Exceptions\OtpDeliveryUnavailable;
use App\Mail\AccountOtpMail;
use App\Mail\PasswordRecoveryMail;
use App\Models\AccountSecurityChallenge;
use App\Models\AccountSecurityEvent;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountSecurityService
{
    public function __construct(private readonly SmsOtpDelivery $sms) {}

    public function requestVerification(Customer|User $account, string $channel, Request $request): string
    {
        if (! in_array($channel, ['phone', 'email'], true)) {
            throw ValidationException::withMessages(['channel' => ['This verification channel is not available for the account.']]);
        }
        $recipient = $channel === 'phone' ? GhanaPhoneNumber::normalize((string) $account->phone) : mb_strtolower(trim((string) $account->email));
        if ($recipient === '') throw ValidationException::withMessages([$channel => ['Add a valid contact detail before requesting verification.']]);
        if ($channel === 'email' && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw ValidationException::withMessages(['email' => ['Add a valid email address before requesting verification.']]);
        $verifiedField = $channel.'_verified_at';
        if ($account->{$verifiedField}) throw ValidationException::withMessages([$channel => ['This contact detail is already verified.']]);

        $code = (string) random_int(100000, 999999);
        $challenge = $this->createChallenge($account, 'verification', $channel, $recipient, Hash::make($code));
        try {
            $this->deliverOtp($channel, $recipient, $code, $channel === 'phone' ? 'verify your phone number' : 'verify your email address');
            $challenge->forceFill(['sent_at' => now()])->save();
            $this->event($account, $channel.'_verification_requested', $channel, $request);
            return $challenge->public_id;
        } catch (\Throwable $exception) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            $this->event($account, 'verification_delivery_unavailable', $channel, $request);
            if ($exception instanceof OtpDeliveryUnavailable) throw $exception;
            throw new OtpDeliveryUnavailable('Verification delivery is unavailable.');
        }
    }

    public function verifyContact(Customer|User $account, string $channel, string $challengeId, string $code, Request $request): void
    {
        $verified = DB::transaction(function () use ($account, $channel, $challengeId, $code, $request): bool {
            $challenge = $this->lockedChallenge($challengeId);
            $this->assertChallenge($challenge, $account, 'verification', $channel);
            if (! Hash::check($code, $challenge->secret_hash)) {
                $this->failedAttempt($challenge);
                $this->event($account, 'verification_code_rejected', $channel, $request);
                return false;
            }
            $challenge->forceFill(['consumed_at' => now()])->save();
            $account->forceFill([$channel.'_verified_at' => now()])->save();
            $this->event($account, $channel.'_verified', $channel, $request);
            return true;
        }, 3);
        if (! $verified) throw ValidationException::withMessages(['code' => ['The verification code is invalid or expired.']]);
    }

    public function requestEmailPasswordReset(string $accountType, string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        $account = $accountType === 'customer'
            ? Customer::query()->where('email', $email)->where('is_active', true)->first()
            : User::query()->where('email', $email)->where('is_active', true)->first();
        if (! $account || ! filter_var($email, FILTER_VALIDATE_EMAIL)) return;

        $rawToken = Str::random(64);
        try {
            $challenge = $this->createChallenge($account, 'password_recovery', 'email', $email, Hash::make($rawToken), 60);
        } catch (\Throwable) {
            return;
        }
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = $accountType === 'customer' ? '/account/password/reset' : '/admin/password/reset';
        $link = $base.$path.'?challenge='.$challenge->public_id.'&token='.urlencode($rawToken);
        try {
            $this->deliverEmail($email, new PasswordRecoveryMail($link));
            $challenge->forceFill(['sent_at' => now()])->save();
            $this->event($account, 'password_recovery_requested', 'email', $request);
        } catch (\Throwable) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            $this->event($account, 'password_recovery_delivery_unavailable', 'email', $request);
        }
    }

    public function requestPhonePasswordReset(string $accountType, string $phone, Request $request): void
    {
        try {
            $normalized = GhanaPhoneNumber::normalize($phone);
        } catch (ValidationException) {
            return;
        }
        $variants = GhanaPhoneNumber::lookupVariants($normalized);
        $model = $accountType === 'staff' ? User::class : Customer::class;
        $account = $model::query()->where('is_active', true)
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') IN (?, ?, ?)", $variants)
            ->first();
        if (! $account) return;
        $code = (string) random_int(100000, 999999);
        try {
            $challenge = $this->createChallenge($account, 'password_recovery', 'phone', $normalized, Hash::make($code));
        } catch (\Throwable) {
            return;
        }
        try {
            $this->deliverOtp('phone', $normalized, $code, 'reset your password', $challenge->public_id);
            $challenge->forceFill(['sent_at' => now()])->save();
            $this->event($account, 'password_recovery_requested', 'phone', $request);
        } catch (\Throwable) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            $this->event($account, 'password_recovery_delivery_unavailable', 'phone', $request);
        }
    }

    public function resetWithEmailToken(string $accountType, string $challengeId, string $token, string $password, Request $request): void
    {
        $completed = DB::transaction(function () use ($accountType, $challengeId, $token, $password, $request): bool {
            $challenge = $this->lockedChallenge($challengeId);
            $account = $this->accountFromChallenge($challenge, $accountType);
            $this->assertChallenge($challenge, $account, 'password_recovery', 'email');
            if (! Hash::check($token, $challenge->secret_hash)) {
                $this->failedAttempt($challenge);
                $this->event($account, 'password_recovery_token_rejected', 'email', $request);
                return false;
            }
            $this->completePasswordReset($account, $challenge, $password, $request, 'email');
            return true;
        }, 3);
        if (! $completed) throw ValidationException::withMessages(['token' => ['The password reset link is invalid or expired.']]);
    }

    public function resetCustomerWithPhoneOtp(string $challengeId, string $code, string $password, Request $request): void
    {
        $this->resetWithPhoneOtp('customer', $challengeId, $code, $password, $request);
    }

    public function resetStaffWithPhoneOtp(string $challengeId, string $code, string $password, Request $request): void
    {
        $this->resetWithPhoneOtp('staff', $challengeId, $code, $password, $request);
    }

    private function resetWithPhoneOtp(string $accountType, string $challengeId, string $code, string $password, Request $request): void
    {
        $completed = DB::transaction(function () use ($accountType, $challengeId, $code, $password, $request): bool {
            $challenge = $this->lockedChallenge($challengeId);
            $account = $this->accountFromChallenge($challenge, $accountType);
            $this->assertChallenge($challenge, $account, 'password_recovery', 'phone');
            if (! Hash::check($code, $challenge->secret_hash)) {
                $this->failedAttempt($challenge);
                $this->event($account, 'password_recovery_code_rejected', 'phone', $request);
                return false;
            }
            $this->completePasswordReset($account, $challenge, $password, $request, 'phone');
            return true;
        }, 3);
        if (! $completed) throw ValidationException::withMessages(['code' => ['The verification code is invalid or expired.']]);
    }

    private function createChallenge(Customer|User $account, string $purpose, string $channel, string $recipient, string $hash, int $expiresMinutes = 10): AccountSecurityChallenge
    {
        $accountQuery = AccountSecurityChallenge::query()->where('purpose', $purpose)->where('channel', $channel)->where('created_at', '>=', now()->subSeconds(60));
        $account instanceof Customer ? $accountQuery->where('customer_id', $account->id) : $accountQuery->where('user_id', $account->id);
        if ($accountQuery->exists()) throw new OtpDeliveryUnavailable('Please wait before requesting another code.');

        return AccountSecurityChallenge::query()->create([
            'public_id' => bin2hex(random_bytes(24)),
            'customer_id' => $account instanceof Customer ? $account->id : null,
            'user_id' => $account instanceof User ? $account->id : null,
            'purpose' => $purpose, 'channel' => $channel,
            'recipient_hash' => hash('sha256', mb_strtolower(trim($recipient))), 'secret_hash' => $hash,
            'expires_at' => now()->addMinutes($expiresMinutes),
        ]);
    }

    private function deliverOtp(string $channel, string $recipient, string $code, string $purpose, ?string $challengeId = null): void
    {
        if ($channel === 'phone') {
            $message = 'Your Special Delicacies code is '.$code.'. It expires in 10 minutes.';
            if ($challengeId !== null) $message .= ' Request reference: '.$challengeId.'.';
            $this->sms->send($recipient, $message);
            return;
        }
        $this->deliverEmail($recipient, new AccountOtpMail($code, $purpose));
    }

    private function deliverEmail(string $recipient, object $mail): void
    {
        $driver = (string) config('mail.default');
        if ($driver === 'log' || ($driver === 'array' && ! app()->environment('testing'))) {
            throw new OtpDeliveryUnavailable('Email delivery is not configured for secure account messages.');
        }
        Mail::to($recipient)->send($mail);
    }

    private function lockedChallenge(string $challengeId): AccountSecurityChallenge
    {
        return AccountSecurityChallenge::query()->where('public_id', $challengeId)->lockForUpdate()->firstOrFail();
    }

    private function assertChallenge(AccountSecurityChallenge $challenge, Customer|User $account, string $purpose, string $channel): void
    {
        $matches = $account instanceof Customer
            ? (int) $challenge->customer_id === (int) $account->id && $challenge->user_id === null
            : (int) $challenge->user_id === (int) $account->id && $challenge->customer_id === null;
        if (! $matches || $challenge->purpose !== $purpose || $challenge->channel !== $channel
            || $challenge->consumed_at !== null || $challenge->sent_at === null || $challenge->expires_at->isPast()
            || $challenge->failed_attempts >= 5) {
            throw ValidationException::withMessages(['challenge' => ['The verification request is invalid or expired.']]);
        }
        $recipient = $channel === 'phone'
            ? GhanaPhoneNumber::normalize((string) $account->phone)
            : mb_strtolower(trim((string) $account->email));
        if (! hash_equals($challenge->recipient_hash, hash('sha256', $recipient))) {
            throw ValidationException::withMessages(['challenge' => ['The verification request is invalid or expired.']]);
        }
    }

    private function failedAttempt(AccountSecurityChallenge $challenge): void
    {
        $attempts = (int) $challenge->failed_attempts + 1;
        $challenge->forceFill(['failed_attempts' => $attempts, 'consumed_at' => $attempts >= 5 ? now() : null])->save();
    }

    private function completePasswordReset(Customer|User $account, AccountSecurityChallenge $challenge, string $password, Request $request, string $channel): void
    {
        $challenge->forceFill(['consumed_at' => now()])->save();
        $account->forceFill(['password' => $password])->save();
        $account->tokens()->delete();
        $this->event($account, 'password_recovery_completed', $channel, $request);
    }

    private function accountFromChallenge(AccountSecurityChallenge $challenge, string $accountType): Customer|User
    {
        if ($accountType === 'customer' && $challenge->customer_id !== null && $challenge->user_id === null) {
            return Customer::query()->where('is_active', true)->findOrFail($challenge->customer_id);
        }
        if ($accountType === 'staff' && $challenge->user_id !== null && $challenge->customer_id === null) {
            return User::query()->where('is_active', true)->findOrFail($challenge->user_id);
        }
        throw ValidationException::withMessages(['challenge' => ['The password reset link is invalid or expired.']]);
    }

    private function event(Customer|User $account, string $name, ?string $channel, Request $request): void
    {
        AccountSecurityEvent::query()->create([
            'customer_id' => $account instanceof Customer ? $account->id : null,
            'user_id' => $account instanceof User ? $account->id : null,
            'event' => $name, 'channel' => $channel,
            'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500), 'created_at' => now(),
        ]);
    }
}
