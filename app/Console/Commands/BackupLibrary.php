<?php

namespace App\Console\Commands;

use App\Services\LibraryBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class BackupLibrary extends Command
{
    protected $signature = 'nolife:backup {--path= : Directory for the database and media archive}';

    protected $description = 'Create and verify a consistent backup of the NoLife library and images';

    public function handle(LibraryBackup $backups): int
    {
        $destination = $this->option('path') ?: config('nolife.backup_path');
        $tracked = ! $this->option('path');
        try {
            if ($tracked && ($volumeId = config('nolife.backup_volume_id'))) {
                $sentinel = $destination.'/.volume-id';
                if (! File::isFile($sentinel) || ! hash_equals($volumeId, trim(File::get($sentinel)))) {
                    throw new RuntimeException('The configured backup disk is unavailable.');
                }
            }
            $path = $backups->create($destination);
            if ($tracked) {
                $this->recordStatus([
                    'ok' => true,
                    'completed_at' => now()->toIso8601String(),
                    'archive' => basename($path),
                    'archive_bytes' => filesize($path),
                ]);
            }
        } catch (Throwable $error) {
            if ($tracked) {
                $this->recordStatus(['ok' => false, 'failed_at' => now()->toIso8601String()]);
            }
            report($error);
            $this->error('Backup failed. Check the backup disk and application logs.');

            return self::FAILURE;
        }
        $this->info('Backup verified: '.$path);

        return self::SUCCESS;
    }

    /** @param array<string, bool|int|string> $status */
    private function recordStatus(array $status): void
    {
        File::replace(storage_path('app/nolife-backup-status.json'), json_encode($status, JSON_THROW_ON_ERROR), 0600);
    }
}
