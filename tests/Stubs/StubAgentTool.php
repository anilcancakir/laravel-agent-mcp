<?php

namespace Anilcancakir\LaravelAgentMcp\Tests\Stubs;

use Anilcancakir\LaravelAgentMcp\Tools\AbstractAgentTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Minimal concrete tool exercising the AbstractAgentTool pipeline:
 * authorize() -> audit() -> work -> redactor() -> return.
 *
 * It echoes a redacted string so the tests can assert the tool-enabled gate, the
 * audit record, and the redaction layer.
 */
class StubAgentTool extends AbstractAgentTool
{
    public function handle(Request $request): Response
    {
        if ($denial = $this->authorize()) {
            return $denial;
        }

        $this->audit($this->argumentShape($request->all()));

        $leak = (string) $request->get('leak', 'handled');

        return Response::text($this->redactor()->redact($leak === '' ? 'handled' : "handled {$leak}"));
    }
}
