<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\InitializationException;
use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Exception\TransformationException;
use Phambda\Factory\HttpWorkerFactory;
use Phambda\RuntimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Nyholm\Psr7\Factory\Psr17Factory;

class Runtime implements RuntimeInterface
{
    private readonly HttpWorkerInterface $worker;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        ?LoggerInterface $logger = null,
        ?HttpWorkerInterface $worker = null,
    ) {
        $this->worker = $worker ?? HttpWorkerFactory::create(logger: $logger);
        $this->worker->logger->info('HTTP runtime initialized');
    }

    public function run(): void
    {
        $this->worker->logger->info('Starting HTTP request handling loop');

        while (true) {
            try {
                $request = $this->worker->nextRequest();
                $awsRequestId = $this->validateRequest($request);
                $response = $this->handler->handle($request);
                $this->worker->respond($awsRequestId, $response);
            } catch (InitializationException $error) {
                $this->worker->logger->critical('Fatal initialization error', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'context' => $error->getContext()
                ]);
                throw $error;
            } catch (PhambdaException $error) {
                $this->worker->logger->error('Request handling error', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $awsRequestId,
                    'context' => $error->getContext()
                ]);

                $this->worker->respond(
                    $awsRequestId,
                    $this->createErrorResponse($error)
                );
            } catch (\Throwable $error) {
                $this->worker->logger->error('Unexpected runtime error', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $awsRequestId,
                    'file' => $error->getFile(),
                    'line' => $error->getLine()
                ]);

                $runtimeError = RuntimeException::forInvocation(
                    $error->getMessage(),
                    $awsRequestId,
                    $error->getCode(),
                    $error
                );

                $this->worker->respond(
                    $awsRequestId,
                    $this->createErrorResponse($runtimeError)
                );
            }
        }
    }

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

    private function createErrorResponse(\Throwable $error): ResponseInterface
    {
        $psr17Factory = new Psr17Factory();

        $errorData = [
            'errorMessage' => $error->getMessage(),
            'errorType' => $error::class,
            'stackTrace' => explode("\n", $error->getTraceAsString())
        ];

        if ($error instanceof PhambdaException && !empty($error->getContext())) {
            $errorData['context'] = $error->getContext();
        }

        $response = $psr17Factory->createResponse(500)
            ->withHeader('Content-Type', 'application/vnd.aws.lambda.error+json');

        $errorBody = $psr17Factory->createStream(
            json_encode($errorData, JSON_PRETTY_PRINT)
        );

        return $response->withBody($errorBody);
    }

    public static function execute(RequestHandlerInterface $handler, LoggerInterface $logger = new NullLogger()): void
    {
        (new self($handler, $logger))->run();
    }
}
