<?php

declare(strict_types=1);

namespace Phambda\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Interface for transformers that convert HTTP responses to Lambda response format.
 */
interface ResponseTransformerInterface extends TransformerInterface
{
    /**
     * Transform a PSR-7 Response into a Lambda response format.
     *
     * @param ResponseInterface $response The HTTP response
     * @param mixed|null $context Optional context data
     * @return array The transformed Lambda response
     */
    public function transform(mixed $response, mixed $context = null): array;
}
