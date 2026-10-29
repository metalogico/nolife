<?php

use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\Servers\LibraryServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware(AuthenticateMcp::class)->group(function (): void {
    Mcp::web('/mcp', LibraryServer::class);
});
