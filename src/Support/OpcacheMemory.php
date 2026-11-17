<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

/**
 * Sanitiser for the `memory_usage` block of opcache_get_status().
 *
 * Some hosts report impossible figures there: a production FrankenPHP host answered a
 * negative used_memory with the same number positive as free_memory, which is not a
 * measurement (the cause was not traced). An agent that reads such a
 * number as fact draws wrong conclusions about cache pressure, so every counter that
 * is not a non-negative int is withheld as null and the block carries one note that
 * says the other figures cannot be trusted either.
 *
 * The package never derives anything from these counters (no sum, no percentage),
 * because arithmetic on a corrupted value only hides the corruption.
 */
final class OpcacheMemory
{
    /**
     * The byte counters that must be non-negative ints. current_wasted_percentage is a
     * float by contract and is left alone.
     *
     * @var array<int, string>
     */
    private const COUNTERS = [
        'used_memory',
        'free_memory',
        'wasted_memory',
    ];

    private const NOTE = 'The host reported an impossible opcache memory value; treat every memory figure here as unreliable.';

    /**
     * @param  array<string, mixed>  $memoryUsage  The `memory_usage` array from opcache_get_status().
     * @return array<string, mixed> The same array with impossible counters nulled, plus a `note` only when one was.
     */
    public static function sanitise(array $memoryUsage): array
    {
        $replaced = false;

        foreach (self::COUNTERS as $counter) {
            if (! array_key_exists($counter, $memoryUsage)) {
                continue;
            }

            $value = $memoryUsage[$counter];

            if (is_int($value) && $value >= 0) {
                continue;
            }

            $memoryUsage[$counter] = null;
            $replaced = true;
        }

        if ($replaced) {
            $memoryUsage['note'] = self::NOTE;
        }

        return $memoryUsage;
    }
}
