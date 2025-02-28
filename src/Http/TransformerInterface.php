<?php

declare(strict_types=1);

namespace Phambda\Http;

/**
 * Interface for transformers that convert between different data formats.
 */
interface TransformerInterface
{
    /**
     * Transform the input data to the output format.
     *
     * @param mixed $input The input data to transform
     * @param mixed|null $context Optional context data
     * @return mixed The transformed data
     */
    public function transform(mixed $input, mixed $context = null): mixed;
}
