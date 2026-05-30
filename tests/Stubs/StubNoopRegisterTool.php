<?php

namespace Anilcancakir\LaravelAgentMcp\Tests\Stubs;

use Anilcancakir\LaravelAgentMcp\Tools\AbstractAgentTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Variant whose shouldRegister() is a no-op that always returns true, proving the
 * tool-enabled boundary is handle()/authorize() and NOT registration visibility:
 * even if a tool is always registered, a disabled tool is denied in handle().
 */
class StubNoopRegisterTool extends AbstractAgentTool
{
    public function shouldRegister(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        if ($denial = $this->authorize()) {
            return $denial;
        }

        $this->audit($this->argumentShape($request->all()));

        return Response::text('handled');
    }
}
