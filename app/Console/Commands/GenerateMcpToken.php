<?php

namespace App\Console\Commands;

use App\Services\McpAccess;
use Illuminate\Console\Command;

class GenerateMcpToken extends Command
{
    protected $signature = 'nolife:mcp-token {--access=read : read or write (write also permits reading)}';

    protected $description = 'Generate an MCP bearer token and its SHA-256 configuration hash';

    public function handle(): int
    {
        $access = $this->option('access');
        if (! in_array($access, ['read', 'write'], true)) {
            $this->error('Access must be read or write.');

            return self::FAILURE;
        }

        $token = McpAccess::generateToken();
        $this->info('Save this bearer token in your agent configuration; it is shown only once:');
        $this->line($token);
        $this->newLine();
        $this->info('Legacy .env configuration only. Web-managed keys must be generated in Settings:');
        $this->line('NOLIFE_MCP_'.strtoupper($access).'_TOKEN_HASH='.hash('sha256', $token));

        return self::SUCCESS;
    }
}
