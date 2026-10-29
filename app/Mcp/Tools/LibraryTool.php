<?php

namespace App\Mcp\Tools;

use App\Services\McpAccess;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class LibraryTool extends Tool
{
    protected bool $writes = false;

    public function __construct(protected McpAccess $access) {}

    public function shouldRegister(): bool
    {
        return $this->access->canRead() && (! $this->writes || $this->access->canWrite());
    }

    /** @param Closure(): array<string, mixed> $operation */
    protected function respond(Closure $operation): Response|ResponseFactory
    {
        if (! $this->shouldRegister()) {
            return Response::error('This MCP token does not permit this operation.');
        }

        try {
            return Response::structured($operation());
        } catch (ValidationException $error) {
            return Response::error(json_encode(['message' => 'Validation failed.', 'errors' => $error->errors()], JSON_THROW_ON_ERROR));
        } catch (ModelNotFoundException) {
            return Response::error('The requested game was not found.');
        } catch (HttpExceptionInterface $error) {
            return Response::error($error->getMessage() ?: 'The operation could not be completed.');
        } catch (Throwable $error) {
            report($error);

            return Response::error('NoLife could not complete this operation. Please try again.');
        }
    }
}
