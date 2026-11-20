<?php

declare(strict_types=1);

namespace Phambda;

/**
 * When the runtime should stop so that the PHP process can be replaced with a fresh one.
 *
 * Runtime and Http\Runtime check the limits after each invocation and return from run()
 * once one is reached. The bootstrap must then start PHP again in a loop. Otherwise
 * the process exits before requesting the next invocation, and Lambda records that
 * invocation as a Runtime.ExitError.
 *
 * No limit is set by default.
 */
final readonly class ProcessLimits
{
    /**
     * @param int|null $maxInvocations Stop after this many invocations
     * @param int|null $maxMemoryMb Stop once PHP has allocated this many megabytes
     */
    public function __construct(
        public ?int $maxInvocations = null,
        public ?int $maxMemoryMb = null,
    ) {
        foreach (['maxInvocations' => $maxInvocations, 'maxMemoryMb' => $maxMemoryMb] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new \InvalidArgumentException(sprintf('%s must be a positive integer, %d given', $name, $value));
            }
        }
    }

    /**
     * Read the limits from PHAMBDA_MAX_INVOCATIONS and PHAMBDA_MAX_MEMORY_MB.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            maxInvocations: self::positiveIntFromEnvironment('PHAMBDA_MAX_INVOCATIONS'),
            maxMemoryMb: self::positiveIntFromEnvironment('PHAMBDA_MAX_MEMORY_MB'),
        );
    }

    /**
     * @param int $invocations Invocations this process has handled so far
     * @return string|null Why the process should stop, or null to keep going
     */
    public function reached(int $invocations): ?string
    {
        if ($this->maxInvocations !== null && $invocations >= $this->maxInvocations) {
            return sprintf('handled %d invocations (PHAMBDA_MAX_INVOCATIONS=%d)', $invocations, $this->maxInvocations);
        }

        if ($this->maxMemoryMb !== null) {
            $memoryMb = intdiv(memory_get_usage(true), 1024 * 1024);
            if ($memoryMb >= $this->maxMemoryMb) {
                return sprintf('allocated %d MB (PHAMBDA_MAX_MEMORY_MB=%d)', $memoryMb, $this->maxMemoryMb);
            }
        }

        return null;
    }

    private static function positiveIntFromEnvironment(string $name): ?int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer, "%s" given', $name, $value));
        }

        return $int;
    }
}
