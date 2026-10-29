<?php

namespace App\Services;

class McpAccess
{
    public const ATTRIBUTE = 'nolife_mcp_access';

    public function __construct(private McpSettings $settings) {}

    public static function generateToken(): string
    {
        return 'nolife_mcp_'.bin2hex(random_bytes(32));
    }

    public function enabled(): bool
    {
        return $this->settings->current()->enabled;
    }

    public function authenticate(string $token): ?string
    {
        if ($token === '' || strlen($token) > 256) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $settings = $this->settings->current();
        foreach (['write', 'read'] as $access) {
            $configuredHash = $settings->getAttribute($access.'_token_hash');
            if (is_string($configuredHash) && preg_match('/\A[a-f0-9]{64}\z/', $configuredHash)
                && hash_equals($configuredHash, $tokenHash)) {
                return $access;
            }
        }

        return null;
    }

    public function canRead(): bool
    {
        return $this->enabled()
            && in_array(request()->attributes->get(self::ATTRIBUTE), ['read', 'write'], true);
    }

    public function canWrite(): bool
    {
        return $this->canRead() && request()->attributes->get(self::ATTRIBUTE) === 'write';
    }
}
