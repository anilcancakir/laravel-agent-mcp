<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use ReflectionFunction;
use ReflectionProperty;

/**
 * MCP tool: event_list
 *
 * Enumerates every registered event listener in the application, including
 * wildcard patterns (which getRawListeners() omits) obtained by reflecting
 * on the protected Dispatcher::$wildcards property.
 *
 * Each listener is classified as:
 *   - string: a "ClassName@method" string listener
 *   - closure: an anonymous function (reported with file:line from reflection)
 *   - array: an [object|class, method] callable pair
 *
 * ShouldQueue / ShouldBroadcast contracts are detected via class_implements()
 * on string or array-style listeners that resolve to a real class name.
 *
 * An optional `filter` argument narrows results to event names that contain
 * the supplied substring (case-sensitive).
 *
 * No event mutations are made; this tool is strictly read-only.
 */
#[Name('event_list')]
class EventListTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->nullable()
                ->description('Optional substring to filter event names (case-sensitive). Omit to return all events.'),
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

        // 3. Resolve filter arg.
        $filter = $request->get('filter');
        $filter = (is_string($filter) && $filter !== '') ? $filter : null;

        // 4. Collect regular + wildcard listeners from the Dispatcher.
        $dispatcher = app('events');

        // getRawListeners() is typed as array<> by PHPDoc; assign directly.
        $regular = $dispatcher->getRawListeners();

        $wildcards = $this->readWildcards($dispatcher);

        // 5. Merge, apply filter, and classify each listener.
        $merged = array_merge($regular, $wildcards);

        $payload = [];

        foreach ($merged as $event => $rawListeners) {
            $eventKey = (string) $event;

            if ($filter !== null && ! str_contains($eventKey, $filter)) {
                continue;
            }

            $payload[$eventKey] = [
                'listeners' => array_values(
                    array_map(
                        fn (mixed $listener): array => $this->classifyListener($listener),
                        is_array($rawListeners) ? $rawListeners : [],
                    ),
                ),
            ];
        }

        // 6. Redact and return.
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Read the protected Dispatcher::$wildcards property via reflection.
     *
     * getRawListeners() only exposes $this->listeners (exact-match events).
     * Wildcard patterns are stored separately in $wildcards and must be
     * read via reflection to surface them.
     *
     * @return array<string, array<int, mixed>>
     */
    private function readWildcards(object $dispatcher): array
    {
        try {
            $property = new ReflectionProperty(Dispatcher::class, 'wildcards');
            $value = $property->getValue($dispatcher);

            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            // If the Dispatcher implementation changes and $wildcards disappears,
            // degrade gracefully rather than throwing.
            return [];
        }
    }

    /**
     * Classify a single listener entry into a structured shape.
     *
     * @return array<string, mixed>
     */
    private function classifyListener(mixed $listener): array
    {
        // Array-style callable: [object|class-string, method].
        if (is_array($listener) && count($listener) === 2) {
            $target = $listener[0];
            $method = (string) ($listener[1] ?? '');
            $class = is_object($target) ? get_class($target) : (string) $target;

            return [
                'type' => 'array',
                'class' => $class,
                'method' => $method,
                'should_queue' => $this->implementsShouldQueue($class),
                'should_broadcast' => $this->implementsShouldBroadcast($class),
            ];
        }

        // String-style: "ClassName@method" or just "ClassName".
        if (is_string($listener)) {
            $class = (string) explode('@', $listener)[0];

            return [
                'type' => 'string',
                'listener' => $listener,
                'should_queue' => $this->implementsShouldQueue($class),
                'should_broadcast' => $this->implementsShouldBroadcast($class),
            ];
        }

        // Closure: report file and line only; the body is not exposed.
        if ($listener instanceof \Closure) {
            return $this->classifyClosure($listener);
        }

        // Unknown shape (wrapped dispatcher internals, etc.).
        return ['type' => 'unknown'];
    }

    /**
     * Extract file:line from a Closure via reflection.
     *
     * @return array<string, mixed>
     */
    private function classifyClosure(\Closure $closure): array
    {
        try {
            $rf = new ReflectionFunction($closure);
            $file = $rf->getFileName();
            $line = $rf->getStartLine();

            return [
                'type' => 'closure',
                'file' => ($file !== false) ? $file : null,
                'line' => ($line !== false) ? $line : null,
            ];
        } catch (\Throwable) {
            return ['type' => 'closure'];
        }
    }

    /**
     * Check whether a class name implements ShouldQueue.
     *
     * Uses class_implements() which does NOT autoload; safe for class names that
     * may not exist in the current process (returns false instead of throwing).
     */
    private function implementsShouldQueue(string $class): bool
    {
        if (! class_exists($class, false)) {
            return false;
        }

        $interfaces = class_implements($class);

        return is_array($interfaces)
            && isset($interfaces[ShouldQueue::class]);
    }

    /**
     * Check whether a class name implements ShouldBroadcast.
     *
     * Same no-autoload guard as implementsShouldQueue().
     */
    private function implementsShouldBroadcast(string $class): bool
    {
        if (! class_exists($class, false)) {
            return false;
        }

        $interfaces = class_implements($class);

        return is_array($interfaces)
            && isset($interfaces[ShouldBroadcast::class]);
    }
}
