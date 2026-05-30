<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use ReflectionFunction;

/**
 * MCP tool: schedule_list
 *
 * Introspects the application's task schedule and returns every registered
 * event with its expression, summary, timing metadata, and the next calculated
 * run date (resolved via the vendored dragonmantank/cron-expression library
 * through the framework's own $event->nextRunDate()).
 *
 * Closure-based events ("call" schedules) are reported with the summary
 * "Callback"; when the underlying callback is a Closure the tool appends
 * the source file:line for debugging.
 *
 * No schedule modifications are made; this tool is strictly read-only.
 */
#[Name('schedule_list')]
class ScheduleListTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sections' => $schema->array()
                ->nullable()
                ->description('Reserved for future filtering; currently unused. Omit.'),
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

        // 3. Resolve the registered schedule singleton and collect events.
        $schedule = app(Schedule::class);
        $events = $schedule->events();

        // 4. Map each event to a safe, serialisable shape.
        $payload = array_map(
            fn (object $event): array => $this->describeEvent($event),
            $events,
        );

        // 5. Redact and return.
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    /**
     * Convert a schedule event to a safe, serialisable array.
     *
     * @return array<string, mixed>
     */
    private function describeEvent(object $event): array
    {
        $command = $this->resolveCommand($event);
        $nextRun = $this->resolveNextRun($event);

        return [
            'expression' => property_exists($event, 'expression') ? (string) $event->expression : '',
            'command' => $command,
            'description' => property_exists($event, 'description') ? $event->description : null,
            'timezone' => property_exists($event, 'timezone')
                ? ($event->timezone instanceof \DateTimeZone
                    ? $event->timezone->getName()
                    : (string) $event->timezone)
                : null,
            'without_overlapping' => property_exists($event, 'withoutOverlapping')
                ? (bool) $event->withoutOverlapping
                : false,
            'on_one_server' => property_exists($event, 'onOneServer')
                ? (bool) $event->onOneServer
                : false,
            'even_in_maintenance' => property_exists($event, 'evenInMaintenanceMode')
                ? (bool) $event->evenInMaintenanceMode
                : false,
            'environments' => property_exists($event, 'environments')
                ? (array) $event->environments
                : [],
            'repeat_seconds' => property_exists($event, 'repeatSeconds')
                ? $event->repeatSeconds
                : null,
            'next_run' => $nextRun,
            'has_mutex' => property_exists($event, 'mutex') && $event->mutex !== null,
        ];
    }

    /**
     * Resolve a human-readable command summary for the event.
     *
     * For CallbackEvent (closure/callable), returns the framework summary
     * ("Callback") plus file:line when the underlying callback is a Closure.
     */
    private function resolveCommand(object $event): string
    {
        if ($event instanceof CallbackEvent) {
            $summary = $event->getSummaryForDisplay();

            // Attempt to add file:line for Closure callbacks.
            try {
                $callbackRef = new \ReflectionProperty($event, 'callback');
                $callback = $callbackRef->getValue($event);

                if ($callback instanceof \Closure) {
                    $rf = new ReflectionFunction($callback);
                    $file = $rf->getFileName();
                    $line = $rf->getStartLine();

                    if ($file !== false && $line !== false) {
                        return $summary.' ('.$file.':'.$line.')';
                    }
                }
            } catch (\Throwable) {
                // Reflection may fail on internal closures; fall through to plain summary.
            }

            return $summary;
        }

        return $event->getSummaryForDisplay();
    }

    /**
     * Compute the next run date string via the framework's CronExpression wrapper.
     *
     * Returns the ISO-8601 string or null when computation fails.
     */
    private function resolveNextRun(object $event): ?string
    {
        try {
            $nextRun = $event->nextRunDate();

            return $nextRun?->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
