<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'enabled', 'read_token_hash', 'write_token_hash', 'read_token_generated_at', 'write_token_generated_at'])]
class McpSetting extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $hidden = ['read_token_hash', 'write_token_hash'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'read_token_generated_at' => 'datetime',
            'write_token_generated_at' => 'datetime',
        ];
    }
}
