<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: list_routes
 *
 * Returns a read-only snapshot of the application's registered routes, with
 * per-route detail: HTTP methods, URI, name, action, controller class (existence
 * check only, never instantiated), closure/invokable/fallback/redirect/view
 * flags, where-constraints, raw middleware, and resolved middleware class names.
 *
 * Filters allow narrowing the result set by HTTP method, URI prefix, name
 * pattern (Str::is wildcard), middleware presence, domain, middleware exclusion
 * (e.g. find routes NOT behind auth), and fallback-only.
 *
 * Middleware NAMES (class strings) only: no signed-route secrets or closure
 * representations are emitted (closures are replaced with [Closure]).
 */
#[Name('list_routes')]
class ListRoutesTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()
                ->nullable()
                ->description('Filter by HTTP method (GET, POST, PUT, PATCH, DELETE, etc.). Case-insensitive.'),
            'uri_prefix' => $schema->string()
                ->nullable()
                ->description('Filter by URI prefix. Only routes whose URI starts with this value are returned.'),
            'name_pattern' => $schema->string()
                ->nullable()
                ->description('Filter by route name using Str::is wildcard (e.g. "api.*").'),
            'middleware' => $schema->string()
                ->nullable()
                ->description('Filter to routes that include this middleware name (short or class name).'),
            'domain' => $schema->string()
                ->nullable()
                ->description('Filter to routes for a specific domain.'),
            'exclude_middleware' => $schema->string()
                ->nullable()
                ->description('Exclude routes that include this middleware name (e.g. "auth" to find unprotected routes).'),
            'only_fallback' => $schema->boolean()
                ->nullable()
                ->description('When true, return only fallback routes.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Resolve filter parameters.
        $methodFilter = $request->get('method');
        $uriPrefixFilter = $request->get('uri_prefix');
        $namePattern = $request->get('name_pattern');
        $middlewareFilter = $request->get('middleware');
        $domainFilter = $request->get('domain');
        $excludeMiddleware = $request->get('exclude_middleware');
        $onlyFallback = (bool) $request->get('only_fallback', false);

        /** @var Router $router */
        $router = app('router');

        // 4. Build the route rows with filtering applied.
        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $row = $this->buildRouteRow($route, $router);

            if (! $this->passesFilters(
                row: $row,
                route: $route,
                methodFilter: $methodFilter,
                uriPrefixFilter: $uriPrefixFilter,
                namePattern: $namePattern,
                middlewareFilter: $middlewareFilter,
                domainFilter: $domainFilter,
                excludeMiddleware: $excludeMiddleware,
                onlyFallback: $onlyFallback,
            )) {
                continue;
            }

            $routes[] = $row;
        }

        // 5. Wrap in a metadata envelope and redact.
        $result = [
            'routes_are_cached' => app()->routesAreCached(),
            'count' => count($routes),
            'routes' => $routes,
        ];

        $redacted = $this->redactor()->redactArray($result);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Build a single route row array. Controllers are never instantiated;
     * existence is checked via class_exists() only.
     *
     * @return array<string, mixed>
     */
    private function buildRouteRow(Route $route, Router $router): array
    {
        $actionName = $route->getActionName();
        $isClosure = $actionName === 'Closure' || $route->getAction('uses') instanceof Closure;
        $controllerClass = $isClosure ? null : $route->getControllerClass();

        // Invokable: controller action uses __invoke (no explicit method separator).
        $isInvokable = ! $isClosure
            && $controllerClass !== null
            && $route->getActionMethod() === '__invoke';

        // Redirect route: action dispatches via RedirectController.
        $isRedirect = $controllerClass === RedirectController::class;

        // View route: action dispatches via ViewController.
        $isView = $controllerClass === ViewController::class;

        return [
            'domain' => $route->getDomain(),
            'methods' => $route->methods(),
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'action' => $actionName,
            'controller_class' => $controllerClass,
            'controller_exists' => $controllerClass !== null && class_exists($controllerClass),
            'is_closure' => $isClosure,
            'is_invokable' => $isInvokable,
            'is_fallback' => $route->isFallback,
            'is_redirect' => $isRedirect,
            'is_view' => $isView,
            'wheres' => $route->wheres,
            'prefix' => $route->getPrefix(),
            'middleware' => $route->gatherMiddleware(),
            'middleware_resolved' => $this->resolveMiddlewareNames($route, $router),
        ];
    }

    /**
     * Resolve middleware to their FQCN names only. Closures are replaced with
     * the literal string "[Closure]" to avoid leaking signed-route secrets or
     * any closure-serialized state.
     *
     * @return array<int, string>
     */
    private function resolveMiddlewareNames(Route $route, Router $router): array
    {
        $resolved = $router->gatherRouteMiddleware($route);

        return array_values(array_map(
            fn (mixed $m): string => $m instanceof Closure ? '[Closure]' : (string) $m,
            $resolved,
        ));
    }

    /**
     * Evaluate all active filters against a route row.
     *
     * @param  array<string, mixed>  $row
     */
    private function passesFilters(
        array $row,
        Route $route,
        mixed $methodFilter,
        mixed $uriPrefixFilter,
        mixed $namePattern,
        mixed $middlewareFilter,
        mixed $domainFilter,
        mixed $excludeMiddleware,
        bool $onlyFallback,
    ): bool {
        // Method filter: at least one of the route's methods must match.
        if ($methodFilter !== null && $methodFilter !== '') {
            $upper = strtoupper((string) $methodFilter);
            if (! in_array($upper, $row['methods'], true)) {
                return false;
            }
        }

        // URI prefix filter.
        if ($uriPrefixFilter !== null && $uriPrefixFilter !== '') {
            if (! str_starts_with((string) $row['uri'], (string) $uriPrefixFilter)) {
                return false;
            }
        }

        // Name pattern filter using Str::is wildcard.
        if ($namePattern !== null && $namePattern !== '') {
            if ($row['name'] === null || ! Str::is((string) $namePattern, (string) $row['name'])) {
                return false;
            }
        }

        // Domain filter.
        if ($domainFilter !== null && $domainFilter !== '') {
            if ($row['domain'] !== $domainFilter) {
                return false;
            }
        }

        // Middleware inclusion filter: match against raw middleware list.
        if ($middlewareFilter !== null && $middlewareFilter !== '') {
            $rawMiddleware = (array) $row['middleware'];
            if (! $this->middlewareListContains($rawMiddleware, (string) $middlewareFilter)) {
                return false;
            }
        }

        // Middleware exclusion filter: drop routes that have the given middleware.
        if ($excludeMiddleware !== null && $excludeMiddleware !== '') {
            $rawMiddleware = (array) $row['middleware'];
            if ($this->middlewareListContains($rawMiddleware, (string) $excludeMiddleware)) {
                return false;
            }
        }

        // Fallback-only filter.
        if ($onlyFallback && ! $route->isFallback) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a raw middleware list contains a given name.
     *
     * A raw middleware entry can be "auth", "auth:api", or a fully-qualified
     * class name. The comparison strips any parameter suffix after the first
     * colon before matching.
     *
     * @param  array<int, mixed>  $middlewareList
     */
    private function middlewareListContains(array $middlewareList, string $target): bool
    {
        foreach ($middlewareList as $entry) {
            $name = (string) $entry;
            // Strip parameter suffix (e.g. "auth:sanctum" -> "auth").
            $bare = explode(':', $name)[0];

            if ($bare === $target || $name === $target) {
                return true;
            }
        }

        return false;
    }
}
