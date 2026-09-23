<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * Contract for email-verification adapters.
 *
 * Adapters MUST be side-effect free on the caller's control flow:
 * HTTP/network failures, bad credentials, rate limits, or unparseable
 * responses are reported as an 'unknown' EmailVerificationResult, never
 * thrown to the caller. (Adapters may throw on programmer errors, e.g. a
 * bad constructor argument; the gate in Compliance defensively catches
 * everything anyway.)
 */
interface EmailVerificationProvider
{
    /** Human-readable adapter name used in logs and the leads table, e.g. 'millionverifier'. */
    public function name(): string;

    /**
     * Verify one address.
     *
     * Implementations should fail fast on syntactically invalid input and
     * NEVER throw on provider-side failures (outage, auth errors, rate
     * limits) — those map to status 'unknown'.
     */
    public function verify(string $email): EmailVerificationResult;
}

/**
 * Null provider — test seam / safe fallback.
 *
 * Always returns the configured status (default 'unknown') without any
 * network I/O. Used by the compliance gate when no usable provider is
 * configured, and by tests that need a scripted adapter.
 */
class NullVerificationProvider implements EmailVerificationProvider
{
    public function __construct(private readonly string $status = EmailVerificationResult::UNKNOWN)
    {
        if (!in_array($status, [
            EmailVerificationResult::VALID,
            EmailVerificationResult::INVALID,
            EmailVerificationResult::RISKY,
            EmailVerificationResult::UNKNOWN,
        ], true)) {
            throw new \InvalidArgumentException("Invalid verification status: {$status}");
        }
    }

    public function name(): string
    {
        return 'null';
    }

    public function verify(string $email): EmailVerificationResult
    {
        return new EmailVerificationResult($this->status, $this->name(), new \DateTimeImmutable(), [
            'note' => 'NullVerificationProvider: no real provider configured; no network call was made.',
        ]);
    }
}
