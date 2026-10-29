<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetPersonalPassword extends Command
{
    protected $signature = 'nolife:password {--write : Save the hash in the local .env file}';

    protected $description = 'Generate a password hash for the single personal account';

    public function handle(): int
    {
        $password = $this->secret('New NoLife password');
        if (! is_string($password) || mb_strlen($password) < 12) {
            $this->error('Use a password with at least 12 characters.');

            return self::FAILURE;
        }
        if ($this->secret('Confirm password') !== $password) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }
        $line = "NOLIFE_PASSWORD_HASH='".Hash::make($password)."'";
        if ($this->option('write')) {
            $file = app()->environmentFilePath();
            $content = file_get_contents($file);
            $content = preg_match('/^NOLIFE_PASSWORD_HASH=/m', $content)
                ? preg_replace_callback('/^NOLIFE_PASSWORD_HASH=.*$/m', fn (): string => $line, $content)
                : rtrim($content)."\n".$line."\n";
            file_put_contents($file, $content);
            chmod($file, 0600);
            $this->info('Password saved in .env. Clear configuration cache and restart the application.');
        } else {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
