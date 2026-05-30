<?php

use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;

describe('OutputRedactor', function (): void {

    beforeEach(function (): void {
        config()->set('agent-mcp.redaction.enabled', true);
        config()->set('agent-mcp.redaction.patterns', config('agent-mcp.redaction.patterns'));
    });

    // -------------------------------------------------------------------------
    // Enabled / disabled toggle
    // -------------------------------------------------------------------------

    it('passes input through unchanged when redaction is disabled', function (): void {
        config()->set('agent-mcp.redaction.enabled', false);

        $redactor = new OutputRedactor;
        $input = 'My email is user@example.com and password=supersecret123';

        expect($redactor->redact($input))->toBe($input);
    });

    it('redacts when redaction is enabled', function (): void {
        $redactor = new OutputRedactor;

        expect($redactor->redact('Contact user@example.com for help'))->toContain('[REDACTED]');
    });

    // -------------------------------------------------------------------------
    // Email pattern
    // -------------------------------------------------------------------------

    it('redacts a plain email address', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('Send invoice to billing@company.org');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('billing@company.org');
    });

    it('does not redact plain text with no email', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('No secrets here, just a plain sentence.');

        expect($result)->toBe('No secrets here, just a plain sentence.');
    });

    // -------------------------------------------------------------------------
    // Bearer token / JWT pattern
    // -------------------------------------------------------------------------

    it('redacts a Bearer authorization header value', function (): void {
        $redactor = new OutputRedactor;
        $input = 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

        $result = $redactor->redact($input);

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0');
    });

    it('redacts a standalone JWT triple-segment token', function (): void {
        $redactor = new OutputRedactor;
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

        $result = $redactor->redact("Token: {$jwt}");

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain($jwt);
    });

    it('does not redact a short dot-separated string that is not a JWT', function (): void {
        $redactor = new OutputRedactor;

        // Version string: three segments but far too short to be a JWT.
        $result = $redactor->redact('Version: 1.2.3');

        expect($result)->toBe('Version: 1.2.3');
    });

    // -------------------------------------------------------------------------
    // AWS key pattern
    // -------------------------------------------------------------------------

    it('redacts an AWS access key ID', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('AWS key: AKIAIOSFODNN7EXAMPLE');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('AKIAIOSFODNN7EXAMPLE');
    });

    it('does not redact a plain word that does not start with AKIA', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('Status: COMPLETED_SUCCESSFULLY_NOW');

        expect($result)->toBe('Status: COMPLETED_SUCCESSFULLY_NOW');
    });

    // -------------------------------------------------------------------------
    // Credit card pattern
    // -------------------------------------------------------------------------

    it('redacts a 16-digit credit card number', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('Card: 4111 1111 1111 1111');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('4111 1111 1111 1111');
    });

    it('redacts a hyphen-grouped credit card number', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('Number: 4111-1111-1111-1111');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('4111-1111-1111-1111');
    });

    it('does not redact a short numeric value', function (): void {
        $redactor = new OutputRedactor;

        // A 4-digit number is not a card number.
        $result = $redactor->redact('Year: 2024 and count: 42');

        expect($result)->toBe('Year: 2024 and count: 42');
    });

    // -------------------------------------------------------------------------
    // Password-like key=value pattern
    // -------------------------------------------------------------------------

    it('redacts a password= assignment', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('password=supersecret123');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('supersecret123');
    });

    it('redacts a secret: JSON-style pair', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('"secret": "my-very-secret-value"');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('my-very-secret-value');
    });

    it('redacts an api_key= assignment', function (): void {
        $redactor = new OutputRedactor;

        $result = $redactor->redact('api_key=abc123defghijklmno');

        expect($result)->toContain('[REDACTED]')
            ->and($result)->not->toContain('abc123defghijklmno');
    });

    it('does not redact a password key with a value that is too short', function (): void {
        $redactor = new OutputRedactor;

        // Value must be at least 6 chars; "abc" (3 chars) should not match.
        $result = $redactor->redact('password=abc');

        expect($result)->toBe('password=abc');
    });

    // -------------------------------------------------------------------------
    // redactArray
    // -------------------------------------------------------------------------

    it('redacts string leaves in a flat array', function (): void {
        $redactor = new OutputRedactor;

        $rows = [
            'email' => 'user@example.com',
            'name' => 'Alice',
            'count' => 42,
        ];

        $result = $redactor->redactArray($rows);

        expect($result['email'])->toBe('[REDACTED]')
            ->and($result['name'])->toBe('Alice')
            ->and($result['count'])->toBe(42);
    });

    it('preserves array structure and only transforms string leaves in nested arrays', function (): void {
        $redactor = new OutputRedactor;

        // Use a full JWT where every segment is >= 20 chars so the triple-segment
        // pattern matches. The second segment below has a padded payload to meet the
        // 20-char lower bound that guards against short non-JWT dot-separated strings.
        $rows = [
            'user' => [
                'email' => 'admin@site.com',
                'role' => 'admin',
            ],
            'metadata' => [
                'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',
                'version' => 1,
            ],
        ];

        $result = $redactor->redactArray($rows);

        expect($result['user']['email'])->toBe('[REDACTED]')
            ->and($result['user']['role'])->toBe('admin')
            ->and($result['metadata']['token'])->toContain('[REDACTED]')
            ->and($result['metadata']['version'])->toBe(1);
    });

    it('passes redactArray through unchanged when redaction is disabled', function (): void {
        config()->set('agent-mcp.redaction.enabled', false);

        $redactor = new OutputRedactor;
        $rows = ['email' => 'user@example.com'];

        expect($redactor->redactArray($rows))->toBe($rows);
    });

    it('does not throw when no patterns match', function (): void {
        $redactor = new OutputRedactor;

        // Redaction must be non-fatal; plain text returns unchanged.
        $result = $redactor->redact('Hello world');

        expect($result)->toBe('Hello world');
    });
});
