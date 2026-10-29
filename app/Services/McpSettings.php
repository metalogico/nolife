<?php

namespace App\Services;

use App\Models\McpSetting;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class McpSettings
{
    public function __construct(private LibraryLock $lock) {}

    public function current(): McpSetting
    {
        return McpSetting::query()->find(1) ?? new McpSetting([
            'id' => 1,
            'enabled' => (bool) config('nolife.mcp_enabled'),
            'read_token_hash' => $this->legacyHash('read'),
            'write_token_hash' => $this->legacyHash('write'),
        ]);
    }

    public function status(): array
    {
        return $this->statusFor($this->current());
    }

    public function setEnabled(bool $enabled): array
    {
        $settings = $this->change(function (McpSetting $settings) use ($enabled): void {
            $settings->enabled = $enabled;
        });

        return $this->statusFor($settings);
    }

    /** @return array{access: string, token: string, settings: array<string, mixed>} */
    public function generate(string $access): array
    {
        [$hashColumn, $dateColumn] = $this->columns($access);
        $token = McpAccess::generateToken();
        $settings = $this->change(function (McpSetting $settings) use ($hashColumn, $dateColumn, $token): void {
            $settings->setAttribute($hashColumn, hash('sha256', $token));
            $settings->setAttribute($dateColumn, now());
        });

        return ['access' => $access, 'token' => $token, 'settings' => $this->statusFor($settings)];
    }

    public function revoke(string $access): array
    {
        [$hashColumn, $dateColumn] = $this->columns($access);
        $settings = $this->change(function (McpSetting $settings) use ($hashColumn, $dateColumn): void {
            $settings->setAttribute($hashColumn, null);
            $settings->setAttribute($dateColumn, null);
        });

        return $this->statusFor($settings);
    }

    /** @param Closure(McpSetting): void $change */
    private function change(Closure $change): McpSetting
    {
        return $this->lock->run(fn (): McpSetting => DB::transaction(function () use ($change): McpSetting {
            // The first web edit adopts legacy environment values; later edits use only this row.
            $settings = $this->current();
            $change($settings);
            $settings->save();

            return $settings;
        }));
    }

    /** @return array{enabled: bool, url: string, keys: array<string, array{configured: bool, generated_at: ?string}>} */
    private function statusFor(McpSetting $settings): array
    {
        $keys = [];
        foreach (['read', 'write'] as $access) {
            [$hashColumn, $dateColumn] = $this->columns($access);
            $keys[$access] = [
                'configured' => $settings->getAttribute($hashColumn) !== null,
                'generated_at' => $settings->getAttribute($dateColumn)?->toIso8601String(),
            ];
        }

        return [
            'enabled' => $settings->enabled,
            'url' => rtrim(config('app.url'), '/').'/mcp',
            'keys' => $keys,
        ];
    }

    /** @return array{string, string} */
    private function columns(string $access): array
    {
        return match ($access) {
            'read' => ['read_token_hash', 'read_token_generated_at'],
            'write' => ['write_token_hash', 'write_token_generated_at'],
            default => throw new InvalidArgumentException('Access must be read or write.'),
        };
    }

    private function legacyHash(string $access): ?string
    {
        $hash = config('nolife.mcp_'.$access.'_token_hash');

        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/', $hash) ? $hash : null;
    }
}
