<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Phar;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class LibraryBackup
{
    public function create(string $destination): string
    {
        return app(LibraryLock::class)->run(fn (): string => $this->snapshot($destination));
    }

    private function snapshot(string $destination): string
    {
        File::ensureDirectoryExists($destination, 0700);
        $workDirectory = storage_path('app/private/backup-work-'.Str::uuid());
        File::ensureDirectoryExists($workDirectory, 0700);
        $archive = rtrim($destination, '/').'/nolife-'.now()->format('Ymd-His').'-'.Str::random(6).'.tar';
        try {
            $snapshot = $workDirectory.'/database.sqlite';
            $pdo = DB::connection()->getPdo();
            // VACUUM INTO includes committed WAL data in a standalone SQLite snapshot.
            $pdo->exec('VACUUM INTO '.$pdo->quote($snapshot));
            $this->verifyDatabase($snapshot);
            File::put($workDirectory.'/manifest.json', json_encode([
                'application' => 'NoLife',
                'version' => 1,
                'created_at' => now()->toIso8601String(),
                'database_sha256' => hash_file('sha256', $snapshot),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $archiveFile = new PharData($archive);
            $archiveFile->addFile($snapshot, 'database.sqlite');
            $archiveFile->addFile($workDirectory.'/manifest.json', 'manifest.json');
            foreach (Storage::disk('local')->allFiles('media') as $path) {
                $archiveFile->addFile(Storage::disk('local')->path($path), $path);
            }
            $archiveFile->compress(Phar::GZ);
            unset($archiveFile);
            File::delete($archive);
            chmod($archive.'.gz', 0600);

            return $archive.'.gz';
        } catch (Throwable $error) {
            File::delete([$archive, $archive.'.gz']);
            throw $error;
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    public function extract(string $archive, string $destination): void
    {
        $archivePath = realpath($archive);
        if (! $archivePath || ! is_file($archivePath)) {
            throw new RuntimeException('Backup file not found.');
        }
        if (File::exists($destination) && count(File::allFiles($destination))) {
            throw new RuntimeException('The restore destination must be empty.');
        }

        File::ensureDirectoryExists($destination, 0700);
        $archiveFile = new PharData($archivePath);
        $prefix = 'phar://'.$archivePath.'/';
        foreach (new RecursiveIteratorIterator($archiveFile) as $name => $file) {
            $relative = substr((string) $name, strlen($prefix));
            if ($file->isLink() || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)
                || ! ($relative === 'database.sqlite' || $relative === 'manifest.json' || str_starts_with($relative, 'media/'))) {
                throw new RuntimeException('The archive contains an unsupported path.');
            }
            if ($file->isFile()) {
                File::ensureDirectoryExists(dirname($destination.'/'.$relative), 0700);
                File::put($destination.'/'.$relative, $file->getContent());
            }
        }

        $manifest = json_decode(File::get($destination.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['application'] ?? null) !== 'NoLife' || ($manifest['version'] ?? null) !== 1
            || ! hash_equals($manifest['database_sha256'] ?? '', hash_file('sha256', $destination.'/database.sqlite'))) {
            throw new RuntimeException('The backup manifest could not be verified.');
        }
        $this->verifyDatabase($destination.'/database.sqlite');
    }

    private function verifyDatabase(string $path): void
    {
        $database = new PDO('sqlite:'.$path);
        if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('The SQLite backup failed its integrity check.');
        }
        $database = null;
    }
}
