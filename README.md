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

## インストール方法

以下のコマンドを実行してインストールします：

```console
composer require ixbox/phambda
```

### 必要な環境

- PHP 8.1 以上
- Composer
- AWS Lambda の実行権限を持つ IAM ロール
  - 基本的な Lambda 実行権限
  - CloudWatch Logs へのアクセス権限

### AWS Lambda 環境のセットアップ

Phambda は [static-php-cli](https://static-php.dev/) を活用することで、**コンテナ化せずに** AWS Lambda にデプロイできます。static-php-cli は PHP を静的にリンクしたシングルバイナリを提供しており、AWS Lambda のカスタムランタイム（`provided.al2023`）で直接実行可能です。

#### static-php-cli を使用したデプロイ（推奨）

この方法では Docker イメージのビルドが不要で、デプロイパッケージが軽量になり、コールドスタートが高速化されます。

**Lambda Layer を活用した構成**により、PHP バイナリ（約 30MB）をアプリケーションコードから分離し、zip の 50MB 制限内に収めやすくなります。

```
┌─────────────────────────────┐
│  Lambda 関数（< 50MB）       │  ← アプリコード + vendor のみ
├─────────────────────────────┤
│  PHP Layer（~30MB）          │  ← static-php-cli バイナリ
└─────────────────────────────┘
```

1. **SAM テンプレートの設定**

   ```yaml
   AWSTemplateFormatVersion: '2010-09-09'
   Transform: AWS::Serverless-2016-10-31

   Resources:
     # PHP Runtime Layer (static-php-cli)
     PhpLayer:
       Type: AWS::Serverless::LayerVersion
       Properties:
         LayerName: php-runtime
         Description: PHP 8.4 runtime built with static-php-cli
         ContentUri: .
         CompatibleRuntimes:
           - provided.al2023
         CompatibleArchitectures:
           - arm64  # または x86_64
       Metadata:
         BuildMethod: makefile
         BuildArchitecture: arm64

     App:
       Type: AWS::Serverless::Function
       Properties:
         Runtime: provided.al2023
         Handler: bootstrap
         Architectures:
           - arm64
         Layers:
           - !Ref PhpLayer
         Events:
           HelloWorld:
             Type: HttpApi
             Properties:
               Path: /hello
               Method: get
       Metadata:
         BuildMethod: makefile
   ```

2. **Makefile の作成**

   ```makefile
   PHP_VERSION = 8.4.15

   # Detect architecture from build container (template.yaml の Architectures に連動)
   ARCH = $(shell uname -m)
   PHP_ARCHIVE = php-$(PHP_VERSION)-cli-linux-$(ARCH).tar.gz
   PHP_URL = https://dl.static-php.dev/static-php-cli/common/$(PHP_ARCHIVE)

   # Download PHP binary matching the target architecture
   prepare:
   	@echo "Detected architecture: $(ARCH)"
   	[ -e $(PHP_ARCHIVE) ] || curl -fsSL -o $(PHP_ARCHIVE) $(PHP_URL)
   	tar -xzf $(PHP_ARCHIVE)
   	chmod +x php
   	[ -e composer.phar ] || curl -fsSL -O https://getcomposer.org/download/latest-stable/composer.phar

   # Build PHP Layer (Layer is mounted at /opt, so php will be at /opt/bin/php)
   build-PhpLayer: prepare
   	mkdir -p $(ARTIFACTS_DIR)/bin
   	cp php $(ARTIFACTS_DIR)/bin/php

   # Build Application Function (code + vendor only, no PHP binary)
   build-App: prepare
   	cp -r src $(ARTIFACTS_DIR)/src
   	cp composer.json composer.lock $(ARTIFACTS_DIR)
   	cp bootstrap $(ARTIFACTS_DIR)
   	cp main.php $(ARTIFACTS_DIR)
   	./php composer.phar install --no-interaction --no-progress --prefer-dist --optimize-autoloader -d $(ARTIFACTS_DIR)
   	# Remove unnecessary files to reduce package size
   	find $(ARTIFACTS_DIR)/vendor -type f -name "*.md" -delete
   	find $(ARTIFACTS_DIR)/vendor -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
   ```

   > **Note**: 本番環境では `--no-dev` を使用し、必要な PSR 実装（HTTP クライアント、PSR-7 実装など）を `composer.json` の `require` セクションに追加してください。

3. **bootstrap ファイルの作成**

   ```bash
   #!/bin/bash
   # PHP binary is provided by the Layer at /opt/bin/php
   /opt/bin/php /var/task/main.php
   ```

4. **デプロイ**

   ```bash
   # --use-container でコンテナ内ビルド（Linux 環境でビルドするため必須）
   sam build --use-container
   sam deploy --guided
   ```

#### static-php-cli + Layer のメリット

- **コンテナ不要**: Docker イメージのビルド・プッシュが不要
- **50MB 制限に対応**: PHP バイナリを Layer に分離し、関数 zip を軽量化
- **デプロイ高速化**: PHP Layer は初回のみ、以降はアプリコードだけ更新
- **高速なコールドスタート**: コンテナ起動のオーバーヘッドがない
- **シンプルな CI/CD**: Docker 環境が不要なため、ビルドパイプラインが簡素化
- **Layer 共有**: 複数の Lambda 関数で同じ PHP Layer を再利用可能

#### コンテナを使用したデプロイ（代替方法）

Docker コンテナを使用したい場合は、以下の方法も利用可能です：

1. Lambda 関数の作成
   - ランタイム: カスタムランタイム
   - アーキテクチャ: x86_64 または arm64
   - メモリ: 推奨 256MB 以上
   - タイムアウト: アプリケーションの要件に応じて設定（デフォルト 29 秒）

2. デプロイメントパッケージの作成

   ```bash
   # 本番環境用の依存関係のみをインストール
   composer install --no-dev --optimize-autoloader

   # デプロイメントパッケージの作成
   zip -r function.zip . -x "*.git*" "tests/*" "*.dist" "*.md"
   ```

3. Lambda 関数の設定
   - ハンドラー: `index.php`（エントリーポイントとなる PHP ファイル）
   - 環境変数（必要に応じて設定）:
     ```
     AWS_LAMBDA_RUNTIME_API: Lambda ランタイム API のエンドポイント
     LOG_TIMEZONE: ログのタイムゾーン（デフォルト: UTC）
     ```

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
