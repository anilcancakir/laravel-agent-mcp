<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Support;

/**
 * Best-effort output redactor for tool responses.
 *
 * Replaces matches of the PCRE patterns in config('agent-mcp.redaction.patterns')
 * with a [REDACTED] marker. This is BEST-EFFORT defense-in-depth, NOT a security
 * guarantee (Oracle IMP4): legitimately-stored data that resembles a secret will
 * be redacted, and novel secret formats will pass through undetected.
 *
 * The real security boundary is the readonly DB grant + Sanctum ability scoping.
 * This class adds a supplemental layer at the tool-output edge.
 */
final class OutputRedactor
{
    private const MARKER = '[REDACTED]';

    /**
     * Redact all configured-pattern matches in a single string.
     *
     * Returns the input unchanged when redaction is disabled or when no
     * patterns are configured. Never throws; redaction is non-fatal.
     *
     * @param  string  $text  Raw text that may contain secrets.
     * @return string Text with secret-shaped substrings replaced.
     */
    public function redact(string $text): string
    {
        if (! $this->enabled()) {
            return $text;
        }

        $patterns = $this->patterns();

        if ($patterns === []) {
            return $text;
        }

        // Apply each PCRE pattern in turn; unmatched patterns are no-ops.
        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, self::MARKER, $text);

            // preg_replace returns null only on a PCRE error; skip rather than
            // replacing the entire string with null on a bad pattern.
            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /**
     * Walk an array (arbitrarily nested) and redact every string leaf.
     *
     * Non-string scalars (int, float, bool, null) are passed through as-is.
     * Array structure and all keys are preserved.
     *
     * @param  array<mixed>  $rows  Input rows, possibly nested.
     * @return array<mixed> Same structure with string leaves redacted.
     */
    public function redactArray(array $rows): array
    {
        if (! $this->enabled()) {
            return $rows;
        }

        $result = [];

        foreach ($rows as $key => $value) {
            $result[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redact($value),
                default => $value,
            };
        }

        return $result;
    }

    /** Whether redaction is switched on in config. */
    private function enabled(): bool
    {
        return (bool) config('agent-mcp.redaction.enabled', true);
    }

    /**
     * Return the list of PCRE patterns from config.
     *
     * @return array<int, string>
     */
    private function patterns(): array
    {
        $patterns = config('agent-mcp.redaction.patterns', []);

        return is_array($patterns) ? $patterns : [];
    }
}
