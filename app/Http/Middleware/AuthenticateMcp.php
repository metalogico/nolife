<?php

namespace App\Http\Middleware;

use App\Services\McpAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    public function __construct(private McpAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->enabled()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $origin = $request->header('Origin');
        if ($origin !== null && ($this->origin($origin) === null || $this->origin($origin) !== $this->origin(config('app.url')))) {
            return response()->json(['message' => 'Origin is not allowed.'], 403);
        }

        $token = $request->bearerToken() ?? '';
        $access = $this->access->authenticate($token);
        $limiterKey = 'nolife:mcp:'.hash('sha256', $access ? $token : (string) $request->ip());
        if (RateLimiter::tooManyAttempts($limiterKey, 120)) {
            return response()->json(['message' => 'Too many MCP requests.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiterKey));
        }
        RateLimiter::hit($limiterKey, 60);

        if ($access === null) {
            return response()->json(['message' => 'A valid MCP bearer token is required.'], 401)
                ->header('WWW-Authenticate', 'Bearer realm="nolife-mcp"');
        }

        $request->attributes->set(McpAccess::ATTRIBUTE, $access);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
}
