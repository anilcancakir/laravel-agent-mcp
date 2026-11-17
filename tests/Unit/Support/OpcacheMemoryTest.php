<?php

namespace Anilcancakir\LaravelAgentMcp\Tests\Unit\Support;

use Anilcancakir\LaravelAgentMcp\Support\OpcacheMemory;

it('returns a sane memory_usage array untouched and without a note', function (): void {
    $memory = [
        'used_memory' => 33554432,
        'free_memory' => 100663296,
        'wasted_memory' => 0,
        'current_wasted_percentage' => 0.0,
    ];

    expect(OpcacheMemory::sanitise($memory))->toBe($memory);
});

it('nulls a negative used_memory and flags the host with one note', function (): void {
    $memory = OpcacheMemory::sanitise([
        'used_memory' => -70228448,
        'free_memory' => 70228448,
        'wasted_memory' => 0,
    ]);

    expect($memory['used_memory'])->toBeNull()
        ->and($memory['free_memory'])->toBe(70228448)
        ->and($memory['wasted_memory'])->toBe(0)
        ->and($memory['note'])->toContain('impossible');
});

it('nulls a negative free_memory and wasted_memory', function (): void {
    $memory = OpcacheMemory::sanitise([
        'used_memory' => 10,
        'free_memory' => -1,
        'wasted_memory' => -2,
    ]);

    expect($memory['used_memory'])->toBe(10)
        ->and($memory['free_memory'])->toBeNull()
        ->and($memory['wasted_memory'])->toBeNull()
        ->and($memory)->toHaveKey('note');
});

it('treats floats and other non-int values as impossible', function (mixed $value): void {
    $memory = OpcacheMemory::sanitise(['used_memory' => $value, 'free_memory' => 5]);

    expect($memory['used_memory'])->toBeNull()
        ->and($memory['free_memory'])->toBe(5)
        ->and($memory)->toHaveKey('note');
})->with([
    'float' => 1.5e9,
    'numeric string' => '1024',
    'null' => null,
    'bool' => true,
]);

it('adds no keys for counters the host did not report', function (): void {
    $memory = OpcacheMemory::sanitise(['free_memory' => 7]);

    expect($memory)->toBe(['free_memory' => 7]);
});

it('keeps the keys it does not police', function (): void {
    $memory = OpcacheMemory::sanitise([
        'used_memory' => -1,
        'current_wasted_percentage' => 12.5,
    ]);

    expect($memory['current_wasted_percentage'])->toBe(12.5);
});

it('returns an empty array for an empty input', function (): void {
    expect(OpcacheMemory::sanitise([]))->toBe([]);
});
