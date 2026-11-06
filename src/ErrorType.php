<?php

declare(strict_types=1);

namespace Phambda;

use ReflectionClass;
use Throwable;

/**
 * Builds the Lambda-Runtime-Function-Error-Type header value.
 *
 * Lambda recommends "<Category.Reason>", where Category is "Runtime" or "Function" and
 * Reason starts with an uppercase letter. Other values are normalized to "Runtime.Unknown"
 * or "Function.Unknown".
 *
 * @internal
 */
final class ErrorType
{
    /**
     * An error in the user's code (the handler or boot()).
     */
    public static function ofFunction(Throwable $error): string
    {
        return 'Function.' . self::reason($error);
    }

    /**
     * An error detected by the runtime itself (Runtime API, event transformation, ...).
     */
    public static function ofRuntime(Throwable $error): string
    {
        return 'Runtime.' . self::reason($error);
    }

    private static function reason(Throwable $error): string
    {
        $class = new ReflectionClass($error);
        while ($class->isAnonymous()) {
            $parent = $class->getParentClass();
            if ($parent === false) {
                return 'Unknown';
            }
            $class = $parent;
        }

        $reason = preg_replace('/[^A-Za-z0-9]/', '', $class->getShortName()) ?? '';

        return $reason === '' ? 'Unknown' : ucfirst($reason);
    }
}
