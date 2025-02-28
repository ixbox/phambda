<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Exception\TransformationException;
use Phambda\Factory\HttpWorkerFactory;
use Phambda\RuntimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private ?LoggerInterface $logger = null,
        private ?HttpWorkerInterface $worker = null,
    ) {
        $logger?->info('Creating HTTP runtime');
        $this->worker ??= HttpWorkerFactory::create(logger: $logger);
    }

    public function run(): void
    {
        $this->logger?->info('Starting HTTP request handling loop');
        do {
            try {
                $this->logger?->debug('Processing new HTTP request');
                $request = $this->worker->nextRequest();

                // Validate request has required attributes
                $awsRequestId = $this->validateRequest($request);

                $response = $this->handler->handle($request);
                $this->worker->respond(
                    $awsRequestId,
                    $response,
                );
            } catch (PhambdaException $error) {
                $this->logger?->error('Error processing HTTP request: ' . $error->getMessage());

                // If we have a request ID, we can respond with an error
                if (isset($request) && $request->getAttribute('awsRequestId')) {
                    $this->worker->respond(
                        $request->getAttribute('awsRequestId'),
                        $this->createErrorResponse($error)
                    );
                }
            } catch (\Throwable $error) {
                $this->logger?->error('Unexpected error in HTTP runtime: ' . $error->getMessage());

                // If we have a request ID, we can respond with an error
                if (isset($request) && $request->getAttribute('awsRequestId')) {
                    $this->worker->respond(
                        $request->getAttribute('awsRequestId'),
                        $this->createErrorResponse($error)
                    );
                }
            }
        } while (true);
    }

    /**
     * Validate that the request has the required attributes.
     *
     * @param ServerRequestInterface $request
     * @return string The AWS request ID
     * @throws TransformationException If the request is invalid
     */
    private function validateRequest(ServerRequestInterface $request): string
    {
        $awsRequestId = $request->getAttribute('awsRequestId');
        if (!$awsRequestId) {
            throw TransformationException::forRequest(
                'Request is missing awsRequestId attribute',
                ['request_path' => $request->getUri()->getPath()]
            );
        }

        return $awsRequestId;
    }

    /**
     * Create an error response from an exception.
     *
     * @param \Throwable $error
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function createErrorResponse(\Throwable $error): \Psr\Http\Message\ResponseInterface
    {
        // Use HTTP Discovery to create a response
        $psr18Client = new \Http\Discovery\Psr18Client();
        $responseFactory = $psr18Client;
        $streamFactory = $psr18Client;

        // Create a simple JSON error response
        $errorData = [
            'error' => $error->getMessage(),
            'code' => $error->getCode(),
            'type' => get_class($error),
        ];

        // If it's a PhambdaException, include context information
        if ($error instanceof PhambdaException && !empty($error->getContext())) {
            $errorData['context'] = $error->getContext();
        }

        // Create a simple error response
        $response = $responseFactory->createResponse(500)
            ->withHeader('Content-Type', 'application/json');

        $errorBody = $streamFactory->createStream(json_encode($errorData));

        return $response->withBody($errorBody);
    }

    public static function execute(RequestHandlerInterface $handler): void
    {
        (new self($handler))->run();
    }
}
