<?php
/**
 * Path routing.
 *
 * ── ROUTE ORDER MATTERS ──────────────────────────────────────────────
 * Routes are matched in declaration order, so every literal path must be
 * registered BEFORE the pattern that could swallow it. /properties/mine
 * declared after /properties/{id} is matched as id="mine", which then fails
 * the ObjectId check with a confusing 400 instead of listing the user's
 * submissions. The same trap existed in the Express version and the route
 * file is ordered the same way here.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Middleware runs in the order given, before the handler, and any of them
 * may throw an ApiError to stop the request.
 */
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int,array{method:string,regex:string,names:array<int,string>,handler:callable,middleware:array<int,callable>}> */
    private array $routes = [];

    /** @var array<int,callable> Applied to every route. */
    private array $globalMiddleware = [];

    public function use(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * '/properties/{id}/reviews' → a regex with a named capture.
     *
     * Placeholders match one path segment and never a '/', so
     * /properties/a/b cannot be read as id="a/b".
     */
    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            array_shift($matches);
            foreach ($route['names'] as $i => $name) {
                $request->params[$name] = $matches[$i] ?? '';
            }

            foreach (array_merge($this->globalMiddleware, $route['middleware']) as $middleware) {
                $middleware($request);
            }

            ($route['handler'])($request);
            return;
        }

        /* A path that exists under a different verb is a 405, not a 404 —
           the difference tells a client whether the URL is wrong or the
           method is. */
        if ($pathMatched) {
            throw new ApiError(405, sprintf('Cannot %s %s', $request->method, $request->path));
        }
        throw ApiError::notFound(sprintf('Cannot %s /api/v1%s', $request->method, $request->path));
    }
}
