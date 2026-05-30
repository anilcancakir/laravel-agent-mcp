<?php

use Anilcancakir\LaravelAgentMcp\Http\Middleware\KeyAuthMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Drives a request through the middleware and returns the resulting response.
 *
 * The $next closure stands in for the downstream pipeline: when the middleware
 * passes, it returns a 200 marker so the test can distinguish "passed" from
 * "rejected with 401".
 */
function runKeyAuthMiddleware(Request $request): Response
{
    $middleware = new KeyAuthMiddleware;

    return $middleware->handle($request, function (): Response {
        return new Response('next-reached', 200);
    });
}

it('fails closed with 401 when the configured key is unset, even with a Bearer header', function (): void {
    config()->set('agent-mcp.key', null);
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('Authorization', 'Bearer anything');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(401);
});

it('fails closed with 401 when the configured key is an empty string', function (): void {
    config()->set('agent-mcp.key', '');
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('Authorization', 'Bearer ');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects with 401 when no Bearer token is presented', function (): void {
    config()->set('agent-mcp.key', 'secret-key');
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects with 401 when the Authorization header lacks the Bearer prefix', function (): void {
    config()->set('agent-mcp.key', 'secret-key');
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('Authorization', 'secret-key');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects with 401 when the Bearer token does not match the configured key', function (): void {
    config()->set('agent-mcp.key', 'secret-key');
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('Authorization', 'Bearer wrong-key');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(401);
});

it('passes the request through when the Bearer token matches the configured key', function (): void {
    config()->set('agent-mcp.key', 'secret-key');
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('Authorization', 'Bearer secret-key');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('next-reached');
});

it('reads the raw value of a custom configured header instead of parsing Bearer', function (): void {
    config()->set('agent-mcp.key', 'secret-key');
    config()->set('agent-mcp.key_header', 'X-Agent-Mcp-Key');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('X-Agent-Mcp-Key', 'secret-key');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(200);
});

it('rejects with 401 when a custom header is configured but absent', function (): void {
    config()->set('agent-mcp.key', 'secret-key');
    config()->set('agent-mcp.key_header', 'X-Agent-Mcp-Key');

    $request = Request::create('/agent-mcp', 'POST');

    $response = runKeyAuthMiddleware($request);

    expect($response->getStatusCode())->toBe(401);
});

it('never echoes the configured key into the response body on rejection', function (): void {
    config()->set('agent-mcp.key', 'super-secret-key-value');
    config()->set('agent-mcp.key_header', 'Authorization');

    $request = Request::create('/agent-mcp', 'POST');
    $request->headers->set('Authorization', 'Bearer wrong-key');

    $response = runKeyAuthMiddleware($request);

    expect($response->getContent())->not()->toContain('super-secret-key-value');
});
