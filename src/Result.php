<?php
namespace CacheManagerForCloudPanel;

/**
 * Outcome of one operation on one cache layer.
 *
 * A layer is either cleared, deliberately skipped (with the reason), or failed
 * (with the cause). Keeping "skipped" separate from "error" is what lets a notice
 * say "Cloudflare: not connected" instead of reporting a misconfiguration as a fault.
 */
final class Result
{
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    private function __construct(public readonly string $status, public readonly string $message) {}

    public static function success(string $message): self
    {
        return new self(self::SUCCESS, $message);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message);
    }

    public function ok(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
