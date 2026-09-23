<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * Normalized outcome of a single email-verification lookup.
 *
 * Providers disagree wildly on field names and taxonomies, so every adapter
 * maps its native response onto these four statuses:
 *
 *   'valid'   — the address was confirmed deliverable; safe to send.
 *   'invalid' — the address is dead (hard bounce) or disposable; must not send.
 *   'risky'   — catch-all domain / uncertain; send only if policy allows.
 *   'unknown' — provider outage, rate limit, or unparseable response.
 *               This is an infrastructure failure, NOT a verdict on the address.
 *
 * The raw provider response is always preserved for audit/debugging.
 */
class EmailVerificationResult
{
    public const VALID = 'valid';
    public const INVALID = 'invalid';
    public const RISKY = 'risky';
    public const UNKNOWN = 'unknown';

    public readonly string $status;
    public readonly string $provider;
    public readonly \DateTimeImmutable $checkedAt;
    /** @var array<string,mixed> */
    public readonly array $raw;

    /**
     * @param string $status One of the STATUS_* constants.
     * @param string $provider Adapter name, e.g. 'millionverifier'.
     * @param array<string,mixed> $raw Untouched provider payload (API keys stripped).
     */
    public function __construct(string $status, string $provider, \DateTimeImmutable $checkedAt, array $raw = [])
    {
        if (!in_array($status, [self::VALID, self::INVALID, self::RISKY, self::UNKNOWN], true)) {
            throw new \InvalidArgumentException("Invalid verification status: {$status}");
        }
        $this->status = $status;
        $this->provider = $provider;
        $this->checkedAt = $checkedAt;
        $this->raw = $raw;
    }

    public function isValid(): bool { return $this->status === self::VALID; }
    public function isInvalid(): bool { return $this->status === self::INVALID; }
    public function isRisky(): bool { return $this->status === self::RISKY; }
    public function isUnknown(): bool { return $this->status === self::UNKNOWN; }
}
