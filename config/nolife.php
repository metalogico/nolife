<?php

return [
    'username' => env('NOLIFE_USERNAME', ''),
    'password_hash' => env('NOLIFE_PASSWORD_HASH', ''),
    'igdb_client_id' => env('IGDB_CLIENT_ID', ''),
    'igdb_client_secret' => env('IGDB_CLIENT_SECRET', ''),
    'backup_path' => env('NOLIFE_BACKUP_PATH', storage_path('app/private/backups')),
    'backup_volume_id' => env('NOLIFE_BACKUP_VOLUME_ID', ''),
];
