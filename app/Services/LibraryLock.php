<?php

namespace App\Services;

use Closure;
use RuntimeException;

class LibraryLock
{
    private int $depth = 0;

    public function run(Closure $callback): mixed
    {
        // Nested library operations share the lock already held by this process.
        if ($this->depth > 0) {
            return $callback();
        }

        $handle = fopen(storage_path('framework/nolife-library.lock'), 'c');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('The library is busy. Please try again.');
        }

        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
