<?php

namespace App\Console\Commands;

use App\Services\LibraryBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestoreLibrary extends Command
{
    protected $signature = 'nolife:restore
        {archive : Path to a NoLife .tar.gz backup}
        {--to= : Verify and extract into an empty directory only}
        {--force : Replace the current library while in maintenance mode}';

    protected $description = 'Verify a backup and restore the database and images';

    public function handle(LibraryBackup $backups): int
    {
        if ($target = $this->option('to')) {
            $backups->extract($this->argument('archive'), $target);
            $this->info('Backup verified and extracted to '.$target);

            return self::SUCCESS;
        }

        if (! $this->option('force') || ! app()->isDownForMaintenance()) {
            $this->error('Stop the worker, enter maintenance mode and use --force. Use --to to verify a backup separately.');

            return self::FAILURE;
        }

        $stage = storage_path('app/private/restore-work-'.Str::uuid());
        try {
            $backups->extract($this->argument('archive'), $stage);
            $database = config('database.connections.sqlite.database');
            DB::disconnect();
            File::delete([$database.'-wal', $database.'-shm']);
            File::copy($stage.'/database.sqlite', $database);
            Storage::disk('local')->deleteDirectory('media');
            if (File::isDirectory($stage.'/media')) {
                File::copyDirectory($stage.'/media', Storage::disk('local')->path('media'));
            }
            $this->info('Library restored. Leave maintenance mode before restarting the worker.');

            return self::SUCCESS;
        } finally {
            File::deleteDirectory($stage);
        }
    }
}
