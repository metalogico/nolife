<?php

return [
    'username' => env('NOLIFE_USERNAME', ''),
    'password_hash' => env('NOLIFE_PASSWORD_HASH', ''),
    'igdb_client_id' => env('IGDB_CLIENT_ID', ''),
    'igdb_client_secret' => env('IGDB_CLIENT_SECRET', ''),
    'mcp_enabled' => env('NOLIFE_MCP_ENABLED', false),
    'mcp_read_token_hash' => env('NOLIFE_MCP_READ_TOKEN_HASH', ''),
    'mcp_write_token_hash' => env('NOLIFE_MCP_WRITE_TOKEN_HASH', ''),
];
