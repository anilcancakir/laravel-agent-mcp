<?php

use Anilcancakir\LaravelAgentMcp\Support\RemoteUrl;

describe('RemoteUrl', function (): void {

    // -------------------------------------------------------------------------
    // valid(): https is always accepted (the TLS rule)
    // -------------------------------------------------------------------------

    it('accepts an https url', function (): void {
        expect(RemoteUrl::valid('https://x.test'))->toBeTrue();
    });

    it('accepts an https url with a port and path', function (): void {
        expect(RemoteUrl::valid('https://example.com:8443/mcp'))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // valid(): http is accepted only for loopback hosts
    // -------------------------------------------------------------------------

    it('accepts http to localhost', function (): void {
        expect(RemoteUrl::valid('http://localhost'))->toBeTrue();
    });

    it('accepts http to 127.0.0.1', function (): void {
        expect(RemoteUrl::valid('http://127.0.0.1'))->toBeTrue();
    });

    it('accepts http to 127.0.0.1 with a port', function (): void {
        expect(RemoteUrl::valid('http://127.0.0.1:8000'))->toBeTrue();
    });

    it('accepts http to the IPv6 loopback', function (): void {
        expect(RemoteUrl::valid('http://[::1]'))->toBeTrue();
    });

    it('accepts http to the IPv6 loopback with a port', function (): void {
        expect(RemoteUrl::valid('http://[::1]:8000'))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // valid(): http to a non-loopback host is rejected
    // -------------------------------------------------------------------------

    it('rejects http to a non-loopback host', function (): void {
        expect(RemoteUrl::valid('http://example.com'))->toBeFalse();
    });

    it('rejects http to a non-loopback host that merely contains localhost', function (): void {
        expect(RemoteUrl::valid('http://localhost.evil.com'))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // valid(): non-http schemes are rejected
    // -------------------------------------------------------------------------

    it('rejects an ftp scheme', function (): void {
        expect(RemoteUrl::valid('ftp://example.com'))->toBeFalse();
    });

    it('rejects a javascript scheme', function (): void {
        expect(RemoteUrl::valid('javascript:alert(1)'))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // valid(): malformed input is rejected
    // -------------------------------------------------------------------------

    it('rejects a typo scheme', function (): void {
        expect(RemoteUrl::valid('htps://x'))->toBeFalse();
    });

    it('rejects a non-url string', function (): void {
        expect(RemoteUrl::valid('not a url'))->toBeFalse();
    });

    it('rejects an https url with no host', function (): void {
        expect(RemoteUrl::valid('https://'))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // valid(): empty input is rejected
    // -------------------------------------------------------------------------

    it('rejects null', function (): void {
        expect(RemoteUrl::valid(null))->toBeFalse();
    });

    it('rejects an empty string', function (): void {
        expect(RemoteUrl::valid(''))->toBeFalse();
    });
});
