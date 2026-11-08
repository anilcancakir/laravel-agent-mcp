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
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool: routes
 *
 * One tool for both route questions, so the agent pays for one schema: `name` or
 * `uri` selects detail mode (one route, plus its `defaults`), otherwise list mode
 * returns the filtered snapshot with `routes_are_cached`, `count` and `routes[]`.
 * Both modes share one row builder, so a row has the same keys either way.
 *
 * Controllers are never instantiated (class_exists() only). Middleware is reported
 * by NAME only: closures become "[Closure]", so no signed-route secrets or closure
 * state are emitted.
 */
#[Name('routes')]
#[Description(<<<'TEXT'
    Routes: list registered routes or inspect one by name or URI, with methods, controller, middleware, wheres and defaults; use it to map the API surface, find a route or audit middleware coverage.

    - Pass name or uri (exact match, name wins) for one route's full detail including its defaults; the URI is given without the leading slash.
    - Otherwise it lists routes, narrowed by any combination of method, uri_prefix, name_pattern (Str::is wildcard like "api.*"), middleware, exclude_middleware (e.g. "auth" to find unprotected routes), domain and only_fallback.
    - Middleware is reported by name only, so no signed-route keys are exposed.
    TEXT)]
#[IsReadOnly]
class RoutesTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->nullable()
                ->description('Route name for detail mode (exact match). Takes precedence over uri.'),
            'uri' => $schema->string()
                ->nullable()
                ->description('Route URI for detail mode (exact match, without leading slash).'),
            'method' => $schema->string()
                ->nullable()
                ->description('List filter by HTTP method (GET, POST, PUT, PATCH, DELETE, etc.). Case-insensitive.'),
            'uri_prefix' => $schema->string()
                ->nullable()
                ->description('List filter by URI prefix. Only routes whose URI starts with this value are returned.'),
            'name_pattern' => $schema->string()
                ->nullable()
                ->description('List filter by route name using Str::is wildcard (e.g. "api.*").'),
            'middleware' => $schema->string()
                ->nullable()
                ->description('List filter to routes that include this middleware name (short or class name).'),
            'domain' => $schema->string()
                ->nullable()
                ->description('List filter to routes for a specific domain.'),
            'exclude_middleware' => $schema->string()
                ->nullable()
                ->description('List filter excluding routes that include this middleware name (e.g. "auth").'),
            'only_fallback' => $schema->boolean()
                ->nullable()
                ->description('List filter: when true, return only fallback routes.'),
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

        // 3. A locator selects detail mode; without one the call is a list.
        $name = $request->get('name');
        $uri = $request->get('uri');

        if (filled($name) || filled($uri)) {
            return $this->routeDetail($name, $uri);
        }

        return $this->routeList($request);
    }

    /**
     * One route by exact name or URI, with its defaults.
     */
    private function routeDetail(mixed $name, mixed $uri): Response
    {
        /** @var Router $router */
        $router = app('router');

        $route = $this->findRoute($router, $name, $uri);

        if ($route === null) {
            $locator = filled($name) ? "name={$name}" : "uri={$uri}";

            return Response::error("Route not found: {$locator}");
        }

        return $this->jsonResponse($this->buildRouteRow($route, $router, withDefaults: true));
    }

    /**
     * The filtered route snapshot wrapped in its metadata envelope.
     */
    private function routeList(Request $request): Response
    {
        /** @var Router $router */
        $router = app('router');

        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $row = $this->buildRouteRow($route, $router);

            if (! $this->passesFilters(
                row: $row,
                route: $route,
                methodFilter: $request->get('method'),
                uriPrefixFilter: $request->get('uri_prefix'),
                namePattern: $request->get('name_pattern'),
                middlewareFilter: $request->get('middleware'),
                domainFilter: $request->get('domain'),
                excludeMiddleware: $request->get('exclude_middleware'),
                onlyFallback: (bool) $request->get('only_fallback', false),
            )) {
                continue;
            }

            $routes[] = $row;
        }

        return $this->jsonResponse([
            'routes_are_cached' => app()->routesAreCached(),
            'count' => count($routes),
            'routes' => $routes,
        ]);
    }

    /**
     * Locate a route by name, or by URI when no name is given.
     *
     * We iterate the route collection directly rather than using getByName() because
     * routes named via the fluent ->name() chain after addRoute() may not be reflected
     * in the collection's internal name lookup index (the index is built at addRoute
     * time, before the fluent chain runs). Iterating ensures we find such routes.
     *
     * Name is the primary locator: when given, only a name match is attempted.
     */
    private function findRoute(Router $router, mixed $name, mixed $uri): ?Route
    {
        $byName = filled($name);
        $needle = $byName ? (string) $name : ltrim((string) $uri, '/');

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $candidate = $byName ? $route->getName() : $route->uri();

            if ($candidate === $needle) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Build a single route row array. Controllers are never instantiated;
     * existence is checked via class_exists() only. Detail mode adds `defaults`,
     * which list rows omit to keep a large list small.
     *
     * @return array<string, mixed>
     */
    private function buildRouteRow(Route $route, Router $router, bool $withDefaults = false): array
    {
        $actionName = $route->getActionName();
        $isClosure = $actionName === 'Closure' || $route->getAction('uses') instanceof Closure;
        $controllerClass = $isClosure ? null : $route->getControllerClass();

        // Invokable: controller action uses __invoke (no explicit method separator).
        $isInvokable = ! $isClosure
            && $controllerClass !== null
            && $route->getActionMethod() === '__invoke';

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
            'is_redirect' => $controllerClass === RedirectController::class,
            'is_view' => $controllerClass === ViewController::class,
            'wheres' => $route->wheres,
            'prefix' => $route->getPrefix(),
            ...($withDefaults ? ['defaults' => $route->defaults] : []),
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
