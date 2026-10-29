<?php

namespace App;

use Illuminate\Http\Request;

class PersonalAccess
{
    public function configured(): bool
    {
        return config('nolife.username') !== '' && config('nolife.password_hash') !== '';
    }

    public function fingerprint(): string
    {
        return hash_hmac('sha256', config('nolife.username').'|'.config('nolife.password_hash'), config('app.key'));
    }

    public function authenticated(Request $request): bool
    {
        $stored = $request->session()->get('personal_access');

        return $this->configured() && is_string($stored) && hash_equals($this->fingerprint(), $stored);
    }
}
