<?php

declare(strict_types=1);

namespace Phambda\Example\Http;

use Nyholm\Psr7\Response;
use Phambda\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ExampleHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        //
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // リクエストIDの取得
        $requestId = $request->getAttribute('awsRequestId');

        /** @var \Phambda\Context $context */
        $context = $request->getAttribute('lambda-context');

        $this->logger->info('リクエスト処理開始', [
            'request_id' => $requestId,
            'function' => [
                'name' => $context->functionName,
                'version' => $context->functionVersion,
                'memory' => $context->memoryLimitInMb,
            ],
        ]);

        // レスポンスデータの準備
        $responseData = [
            'message' => 'Hello from Phambda!',
            'timestamp' => time(),
            'request_id' => $requestId,
        ];

        // セキュリティとトレーサビリティを考慮したヘッダー
        return new Response(
            status: 200,
            headers: [
                'Content-Type' => 'application/json',
                'X-Request-ID' => $requestId,
                'X-Function-Name' => $context->functionName,
                'X-Function-Version' => $context->functionVersion,
                'X-Content-Type-Options' => 'nosniff',
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
                'Cache-Control' => 'no-store, max-age=0',
                'Set-Cookie' => [
                    'session=' . bin2hex(random_bytes(16)) . '; HttpOnly; Secure; SameSite=Strict; Path=/',
                    'request_id=' . $requestId . '; HttpOnly; Secure; SameSite=Lax; Path=/',
                    'region=' . ($context->invokedFunctionArn ? explode(':', $context->invokedFunctionArn)[3] : 'unknown') . '; Path=/',
                ],
            ],
            body: json_encode($responseData, JSON_PRETTY_PRINT)
        );
    }
}
