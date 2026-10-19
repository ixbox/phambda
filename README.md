# PHP Runtime for AWS Lambda

## 概要

Phambda は、PHP で AWS Lambda を簡単に利用するためのランタイムを提供します。このパッケージを使用することで、AWS Lambda 上で PHP コードを実行するための設定や実装が簡素化されます。

### 主な特徴

- PSR-7 および PSR-15 に準拠したリクエスト/レスポンス処理
  - 標準的な PHP インターフェースを使用することで、既存の PHP アプリケーションとの互換性を確保
  - ミドルウェアパターンによる柔軟な処理の追加が可能
- AWS Lambda 環境に最適化されたランタイム
  - コールドスタートの最小化
  - メモリ使用の効率化
  - エラーハンドリングの強化
- [static-php-cli](https://static-php.dev/) 対応
  - **コンテナ化不要**: 静的ビルドされた PHP バイナリを使用して直接デプロイ
  - 軽量なデプロイパッケージで高速なコールドスタートを実現
  - Docker 環境が不要なため、CI/CD パイプラインが簡素化
- シンプルなインターフェースでの実装
  - 最小限のコードで Lambda 関数を作成可能
  - 豊富なログ機能により、デバッグとモニタリングが容易

## ランタイム方式の比較

AWS Lambda で PHP を動かす主な手段と、それぞれの特性を以下に示します。

| 手段 | 概要 | Lambda 適性 | 備考 |
|------|------|------------|------|
| **static-php-cli（本プロジェクト採用）** | 静的リンクされた PHP CLI バイナリ | 最適 | Runtime API と直接通信。軽量で高速なコールドスタート |
| FrankenPHP | Go + PHP の統合 Web サーバー | 不向き | HTTP サーバー型の設計で、Lambda の Runtime API ポーリングモデルと合わない |
| Bref | PHP 向け Lambda ランタイムフレームワーク | 良好 | 成熟したエコシステムだが独自の抽象化レイヤーを持つ |
| Docker コンテナ | コンテナイメージとしてデプロイ | 可能 | 柔軟だがコールドスタートが遅く、イメージサイズの管理が必要 |

本プロジェクトでは static-php-cli を採用しています。Lambda のカスタムランタイム（`provided.al2023`）上で PHP CLI バイナリを直接実行するため、余分な抽象化レイヤーやサーバープロセスが不要で、最もシンプルかつ軽量な構成を実現できます。

## インストール方法

以下のコマンドを実行してインストールします：

```console
composer require ixbox/phambda
```

### 必要な環境

- PHP 8.2 以上
- Composer
- AWS Lambda の実行権限を持つ IAM ロール
  - 基本的な Lambda 実行権限
  - CloudWatch Logs へのアクセス権限

### AWS Lambda へのデプロイ

Phambda は Lambda のカスタムランタイム（`provided.al2023`）で動きます。[static-php-cli](https://static-php.dev/) の静的ビルドを使えば、コンテナイメージを作らずに zip でデプロイできます。

関数に必要なものは次の 3 つです。

- **PHP バイナリ**: static-php-cli の静的ビルド（例: `https://dl.static-php.dev/static-php-cli/common/php-8.4.15-cli-linux-aarch64.tar.gz`）。関数のアーキテクチャ（arm64 なら `aarch64`、x86_64 なら `x86_64`）に合わせます。Layer に置くと、関数のコードと分けて更新できます。
- **アプリケーション**: エントリーポイントの PHP ファイルと `vendor/`。`composer install --no-dev --optimize-autoloader` で作ります。Phambda は PSR-7 / PSR-17 / PSR-18 の実装を同梱しないので、使う実装（例: `nyholm/psr7` と `php-http/curl-client`）をアプリケーションの `require` に追加してください。使う実装に必要な拡張（`php-http/curl-client` なら `curl`）がバイナリに含まれていることも確認してください。
- **`bootstrap`**: Lambda が起動する実行可能ファイルです（`chmod +x` が必要）。

PHP バイナリを Layer に置いた場合の構成は次のとおりです。Layer は `/opt`、関数のコードは `/var/task` に展開されます。

```
/opt              ← Layer
└── bin/php
/var/task         ← 関数
├── bootstrap
├── main.php
└── vendor/
```

```sh
#!/bin/sh
exec /opt/bin/php /var/task/main.php
```

関数の設定:

- ランタイム: `provided.al2023`
- ハンドラー: カスタムランタイムでは使われないので、任意の値（例: `bootstrap`）
- 環境変数: `AWS_LAMBDA_RUNTIME_API` などは Lambda が設定します。必要に応じて `LOG_TIMEZONE`（ログのタイムゾーン、デフォルト: UTC）を設定してください

## 使用方法

### 基本的な Hello World の例

以下は、AWS Lambda 上で動作する簡単な Hello World の例です：

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Phambda\Http\Runtime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(200, [], "Hello World!");
    }
};
$runtime = new Runtime($handler);
$runtime->run();
```

### ロギング機能を使用した例

以下は、ロギング機能を活用した実装例です：

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Phambda\Context;
use Phambda\Http\Runtime;
use Phambda\Logger\AbstractLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class MyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

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
                'memory' => $context->memoryLimitInMb
            ]
        ]);

        // 処理ロジック
        $data = [
            'message' => 'Hello from Phambda!',
            'timestamp' => time(),
            'request_id' => $requestId,
        ];

        $this->logger->info('レスポンス送信', ['data' => $data]);

        return new Response(
            status: 200,
            headers: [
                'Content-Type' => 'application/json',
                'X-Request-ID' => $requestId
            ],
            body: json_encode($data)
        );
    }
}

// ロガーの作成
$logger = new AbstractLogger();

// ハンドラーの作成とランタイムの実行
$handler = new MyHandler($logger);
$runtime = new Runtime($handler);
$runtime->run();
```

### セキュリティヘッダーを設定した例

以下は、セキュリティを考慮したヘッダー設定の例です：

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $requestId = $request->getAttribute('awsRequestId');
    $context = $request->getAttribute('lambda-context');

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
        ],
        body: json_encode($responseData)
    );
}
```

### エラーハンドリングの例

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    try {
        // 処理ロジック
        $result = $this->processRequest($request);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['data' => $result])
        );
    } catch (\InvalidArgumentException $e) {
        $this->logger->warning('不正なリクエスト', [
            'error' => $e->getMessage(),
            'request_id' => $request->getAttribute('awsRequestId')
        ]);

        return new Response(
            status: 400,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'error' => 'Bad Request',
                'message' => $e->getMessage()
            ])
        );
    } catch (\Exception $e) {
        $this->logger->error('内部エラー', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_id' => $request->getAttribute('awsRequestId')
        ]);

        return new Response(
            status: 500,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'error' => 'Internal Server Error',
                'request_id' => $request->getAttribute('awsRequestId')
            ])
        );
    }
}
```

## 設定オプション

Phambda は以下の設定オプションをサポートしています：

### 環境変数

| 環境変数                          | 説明                                   | デフォルト値     |
| --------------------------------- | -------------------------------------- | ---------------- |
| `AWS_LAMBDA_RUNTIME_API`          | Lambda ランタイム API のエンドポイント | `127.0.0.1:9001` |
| `AWS_LAMBDA_FUNCTION_NAME`        | Lambda 関数名                          | -                |
| `AWS_LAMBDA_FUNCTION_VERSION`     | Lambda 関数のバージョン                | -                |
| `AWS_LAMBDA_FUNCTION_MEMORY_SIZE` | Lambda 関数のメモリサイズ              | -                |
| `AWS_LAMBDA_LOG_GROUP_NAME`       | CloudWatch ログのグループ名            | -                |
| `AWS_LAMBDA_LOG_STREAM_NAME`      | CloudWatch ログのストリーム名          | -                |
| `LOG_TIMEZONE`                    | ログのタイムゾーン                     | `UTC`            |

### ランタイム設定

```php
// ランタイム設定のカスタマイズ
$config = [
    'logger' => $customLogger,
    'errorHandler' => $customErrorHandler,
    'timeout' => 15, // 秒単位
];

$runtime = new Runtime($handler, $config);
$runtime->run();
```

## AWS Lambda 特有の考慮事項

### コールドスタート対策

Lambda 関数は、一定期間使用されないとコンテナが破棄され、次回実行時に新しいコンテナが起動します（コールドスタート）。これにより初回実行時のレイテンシが増加します。

対策：

- メモリ割り当てを増やす（256MB 以上推奨）
- 初期化コードをハンドラー関数の外に配置する
- 定期的なウォームアップ呼び出しを設定する
- Provisioned Concurrency を使用する

```php
// 初期化コードをハンドラー外に配置
$db = new Database(); // コールドスタート時のみ実行される

$handler = new class($db) implements RequestHandlerInterface {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface {
        // ハンドラーロジック
    }
};
```

### メモリ設定の最適化

- 256MB: 基本的な API 処理、シンプルなウェブアプリケーション
- 512MB: 中規模のアプリケーション、軽量な画像処理
- 1024MB 以上: 大規模なデータ処理、複雑な計算、画像処理

### タイムアウト設定

Lambda 関数のデフォルトタイムアウトは 29 秒ですが、Phambda はデフォルトで 28 秒に設定しています（安全マージン確保のため）。長時間実行が必要な処理は、複数の関数に分割するか、Step Functions などを使用して実装することを検討してください。

## デバッグとトラブルシューティング

### ログの確認方法

Lambda 関数のログは CloudWatch Logs に保存されます。以下の方法でログを確認できます：

1. AWS マネジメントコンソールから CloudWatch Logs にアクセス
2. `/aws/lambda/[関数名]` のロググループを選択
3. 最新のログストリームを選択して詳細を確認

### 一般的なエラーと解決方法

| エラー                            | 考えられる原因                     | 解決方法                                 |
| --------------------------------- | ---------------------------------- | ---------------------------------------- |
| `Execution timed out`             | 関数の実行時間が長すぎる           | タイムアウト設定の見直し、処理の最適化   |
| `Memory size exceeded`            | メモリ使用量が割り当てを超えている | メモリ割り当ての増加、メモリリークの修正 |
| `Unable to import module 'index'` | エントリーポイントが見つからない   | ファイル名とハンドラー設定の確認         |
| `Internal server error`           | 未処理の例外が発生                 | エラーハンドリングの実装、ログの確認     |

### パフォーマンスチューニング

1. **依存関係の最適化**

   - 不要なパッケージを削除
   - Composer のオートローダーを最適化 (`--optimize-autoloader`)

2. **メモリ使用量の監視**

   ```php
   $this->logger->info('メモリ使用量', [
       'used' => memory_get_usage(true) / 1024 / 1024 . ' MB',
       'peak' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
   ]);
   ```

3. **実行時間の計測**
   ```php
   $startTime = microtime(true);
   // 処理
   $executionTime = microtime(true) - $startTime;
   $this->logger->info('実行時間', ['time' => $executionTime . ' 秒']);
   ```

## ライセンス

このプロジェクトは MIT ライセンスの下で提供されています。

## コントリビューション

コントリビューションは歓迎します！バグ報告や機能提案は、GitHub の Issue を通じて行ってください。プルリクエストを送信する前に、以下のことを確認してください：

1. テストが追加されていること
2. コードスタイルが守られていること（`composer run-script analyze` を実行）
3. すべてのテストが通ること（`composer run-script test` を実行）
