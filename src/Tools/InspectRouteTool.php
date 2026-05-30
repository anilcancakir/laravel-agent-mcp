<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: inspect_route
 *
 * Returns a deep-dive snapshot for a single route, identified by name or URI.
 * The payload matches the list_routes row shape plus a `defaults` field for
 * redirect and view routes.
 *
 * Controllers are never instantiated: existence is checked via class_exists()
 * only. Middleware are returned as NAMES (class strings) only; no signed-route
 * keys or closure internals are emitted.
 *
 * Targets Laravel 11+: uses gatherMiddleware/gatherRouteMiddleware APIs on the
 * Router which are stable across L11/L12.
 */
#[Name('inspect_route')]
class InspectRouteTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->nullable()
                ->description('Route name to look up. Either name or uri must be provided.'),
            'uri' => $schema->string()
                ->nullable()
                ->description('Route URI to look up (without leading slash). Either name or uri must be provided.'),
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

        // 3. Validate that at least one locator was given.
        $name = $request->get('name');
        $uri = $request->get('uri');

        if (($name === null || $name === '') && ($uri === null || $uri === '')) {
            return Response::error('Either name or uri must be provided.');
        }

        /** @var Router $router */
        $router = app('router');

        // 4. Find the route by name or URI.
        $route = $this->findRoute($router, $name, $uri);

        if ($route === null) {
            $locator = ($name !== null && $name !== '') ? "name={$name}" : "uri={$uri}";

            return Response::error("Route not found: {$locator}");
        }

        // 5. Build the deep-dive row and redact.
        $result = $this->buildRouteDetail($route, $router);

        $redacted = $this->redactor()->redactArray($result);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
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
     * URI is the fallback locator used only when no name was supplied.
     */
    private function findRoute(Router $router, mixed $name, mixed $uri): ?Route
    {
        $useNameLookup = $name !== null && $name !== '';
        $namNeedle = $useNameLookup ? (string) $name : null;
        $uriNeedle = ($uri !== null && $uri !== '') ? ltrim((string) $uri, '/') : null;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($useNameLookup) {
                if ($route->getName() === $namNeedle) {
                    return $route;
                }
            } elseif ($uriNeedle !== null && $route->uri() === $uriNeedle) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Build the full route detail array. Controllers are never instantiated;
     * existence is checked via class_exists() only.
     *
     * @return array<string, mixed>
     */
    private function buildRouteDetail(Route $route, Router $router): array
    {
        $actionName = $route->getActionName();
        $isClosure = $actionName === 'Closure' || $route->getAction('uses') instanceof Closure;
        $controllerClass = $isClosure ? null : $route->getControllerClass();

        // Invokable: controller action uses __invoke (no explicit method separator).
        $isInvokable = ! $isClosure
            && $controllerClass !== null
            && $route->getActionMethod() === '__invoke';

        // Redirect and view route detection via the well-known controller classes.
        $isRedirect = $controllerClass === RedirectController::class;
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
            'defaults' => $route->defaults,
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
}
