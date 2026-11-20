<?php

declare(strict_types=1);

use Phambda\ProcessLimits;

afterEach(function (): void {
    putenv('PHAMBDA_MAX_INVOCATIONS');
    putenv('PHAMBDA_MAX_MEMORY_MB');
});

it('sets no limit by default', function (): void {
    putenv('PHAMBDA_MAX_INVOCATIONS');
    putenv('PHAMBDA_MAX_MEMORY_MB');
    $limits = ProcessLimits::fromEnvironment();

    expect($limits->maxInvocations)->toBeNull()
        ->and($limits->maxMemoryMb)->toBeNull()
        ->and($limits->reached(PHP_INT_MAX))->toBeNull();
});

it('reads the limits from the environment', function (): void {
    putenv('PHAMBDA_MAX_INVOCATIONS=100');
    putenv('PHAMBDA_MAX_MEMORY_MB=256');
    $limits = ProcessLimits::fromEnvironment();

    expect($limits->maxInvocations)->toBe(100)
        ->and($limits->maxMemoryMb)->toBe(256);
});

it('is reached after the given number of invocations', function (): void {
    $limits = new ProcessLimits(maxInvocations: 3);

    expect($limits->reached(2))->toBeNull()
        ->and($limits->reached(3))->toContain('handled 3 invocations');
});

it('is reached once PHP has allocated the given memory', function (): void {
    $allocatedMb = intdiv(memory_get_usage(true), 1024 * 1024);

    expect((new ProcessLimits(maxMemoryMb: $allocatedMb + 1024))->reached(1))->toBeNull()
        ->and((new ProcessLimits(maxMemoryMb: max(1, $allocatedMb)))->reached(1))->toContain('allocated');
});

it('rejects values that are not positive integers', function (string $value): void {
    putenv("PHAMBDA_MAX_MEMORY_MB={$value}");

    expect(fn () => ProcessLimits::fromEnvironment())->toThrow(InvalidArgumentException::class);
})->with(['0', '-1', '1.5', 'abc']);

it('rejects non-positive limits passed to the constructor', function (): void {
    expect(fn () => new ProcessLimits(maxInvocations: 0))->toThrow(InvalidArgumentException::class);
});
