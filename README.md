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

AWS Lambda で PHP を動かす主な方法と、Phambda との違いです。

| 方法 | 仕組み | 扱えるイベント | 向いている用途 |
|------|--------|----------------|----------------|
| **Phambda**（本ライブラリ） | カスタムランタイム（`provided.al2023`）上の PHP が Runtime API と直接やり取りする。HTTP のイベントは PSR-7 のリクエストに変換し、PSR-15 のハンドラに渡す | HTTP（API Gateway、関数 URL、ALB）と HTTP 以外（SQS など） | Web サーバーを置かずに、PSR-15 のハンドラやイベント処理を軽い構成で動かしたい |
| [Bref](https://bref.sh/) | PHP のランタイムを Layer で提供する。PHP-FPM ランタイムを使えば、既存のアプリをそのまま動かせる | HTTP と HTTP 以外 | Laravel や Symfony のアプリを、デプロイ用のツールも含めて揃った環境で運用したい |
| [Lambda Web Adapter](https://github.com/aws/aws-lambda-web-adapter)（LWA） | Lambda の拡張機能がイベントを HTTP リクエストに変換し、関数の中で動く Web サーバー（nginx + PHP-FPM、FrankenPHP など）に転送する | HTTP（API Gateway、関数 URL、ALB）。HTTP 以外のイベントは、指定したパスへの POST として届く | 既存の Web アプリを、Lambda 向けに書き換えずに動かしたい |
| [Lambda Web Functions](https://docs.aws.amazon.com/lambda/latest/dg/web-functions.html) | 2026 年 10 月に追加された関数の形態。アプリが listen するポートに、Lambda が HTTP リクエストを直接渡す。1 つの実行環境が複数のリクエストを同時に処理し、HTTPS のエンドポイントも Lambda が用意する | HTTP | 同時リクエストの多い Web アプリや API。ただし公式に対応しているランタイムは、現時点で Node.js だけ |

FrankenPHP や nginx + PHP-FPM のような Web サーバーは、Runtime API を扱わないので、それだけでは Lambda で動きません。LWA と組み合わせて動かします。
LWA の README でも、Node.js 以外のランタイムでは、Web Functions が対応するまで LWA を使い続けるよう案内されています。

PHP は Web Functions に公式には対応していません。
Node.js ランタイムから FrankenPHP に `execve` で置き換えて動かす PoC（[ixbox/lambda-web-function-php](https://github.com/ixbox/lambda-web-function-php)）がありますが、ランタイム内部の非公開 API に依存しているため、ランタイムの更新で動かなくなる可能性があります。

Phambda は Lambda Event Functions（Web Functions の登場で、従来の Lambda 関数はこう呼ばれるようになりました）として動きます。
HTTP 以外のイベントも同じ仕組みで扱えることと、Web サーバーやアダプターを挟まず、PHP の CLI とアプリのコードだけで動くことが特徴です。

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

- **PHP バイナリ**: static-php-cli の静的ビルド（例: `https://dl.static-php.dev/static-php-cli/common/php-8.5.8-cli-linux-aarch64.tar.gz`）。関数のアーキテクチャ（arm64 なら `aarch64`、x86_64 なら `x86_64`）に合わせます。Layer に置くと、関数のコードと分けて更新できます。
- **アプリケーション**: エントリーポイントの PHP ファイルと `vendor/`。`composer install --no-dev --optimize-autoloader` で作ります。Phambda は PSR-7 / PSR-17 / PSR-18 の実装を同梱しないので、使う実装（例: `nyholm/psr7` と `php-http/curl-client`）をアプリケーションの `require` に追加してください。使う実装に必要な拡張（`php-http/curl-client` なら `curl`）がバイナリに含まれていることも確認してください。アプリケーションで php-http/discovery の Composer プラグインを許可している場合（`composer config allow-plugins.php-http/discovery true`）は、実装が入っていなければ自動でインストールされます。
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
use Phambda\Logger\JsonLogger;
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

// ロガーの作成（JSON 形式で標準エラー出力に書き、CloudWatch Logs に送られる。info 以上を出力）
$logger = new JsonLogger('info');

// ハンドラーの作成とランタイムの実行（ランタイム自身のログも同じロガーに出す）
$handler = new MyHandler($logger);
$runtime = new Runtime($handler, $logger);
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
| `PHAMBDA_DEBUG`                   | `1` のとき、HTTP のハンドラーで例外が出た際の 500 応答に、例外メッセージとスタックトレースを含める。指定しなければ `{"message":"Internal Server Error"}` だけを返し、詳細はログに出す | -                |

### ランタイム設定

`Runtime` のコンストラクタが受け取るのは、ハンドラーとロガー、それにランタイム API とやり取りする Worker です。
`Runtime::execute($handler, $logger)` は、`new Runtime($handler, $logger)` で作って `run()` するのと同じです。

PSR-7 / PSR-17 / PSR-18 の実装は、何も指定しなければ [php-http/discovery](https://github.com/php-http/discovery) がインストール済みのものから選びます。
使う実装を明示したい場合は、Worker を自分で組み立てて渡します。

```php
use Http\Client\Curl\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Phambda\Factory\HttpWorkerFactory;
use Phambda\Factory\WorkerFactory;
use Phambda\Http\Runtime;

$psr17 = new Psr17Factory();
$client = new Client($psr17, $psr17);

$worker = HttpWorkerFactory::create(
    logger: $logger,
    worker: WorkerFactory::create($logger, $client, $psr17, $psr17),
    serverRequestFactory: $psr17,
    streamFactory: $psr17,
    responseFactory: $psr17,
);

$runtime = new Runtime($handler, $logger, $worker);
$runtime->run();
```

## AWS Lambda 特有の考慮事項

### 初期化を 1 回だけにする

Phambda は、実行環境ごとに PHP のプロセスを 1 つだけ起動し、そのプロセスで呼び出しを順番に処理します。
そのため、ハンドラーのプロパティに入れたものは、次の呼び出しでも使えます。

初期化処理は、ハンドラーに `Phambda\BootableInterface` を実装し、`boot()` に書いてください。
`Runtime` と `Http\Runtime` は、最初の呼び出しを受け取る前（Lambda の初期化フェーズ）に `boot()` を 1 回だけ呼びます。

```php
use Phambda\BootableInterface;

$handler = new class implements RequestHandlerInterface, BootableInterface {
    private Database $db;

    public function boot(): void
    {
        $this->db = new Database();
        // ほかの初期化処理もここにまとめる
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // ハンドラーロジック
    }
};
```

`boot()` が例外を投げると、Phambda はその内容を Lambda に初期化エラーとして通知し、プロセスを終了します。次の呼び出しでは、実行環境が作り直されます。
`Runtime::execute()` より前（ハンドラーのコンストラクタの引数など）で初期化すると、失敗しても Phambda はエラーを通知できません。

DB の一時的な障害などで失敗したときに、実行環境を作り直さず同じプロセスのままやり直したい場合は、最初の呼び出しのときに `handle()` の中で初期化します。

```php
private ?bool $initialized = null;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    // 失敗した場合は、その呼び出しのエラーになり、次の呼び出しでやり直す
    $this->initialized ??= $this->initialize();
    // ハンドラーロジック
}
```

どちらの場合も、static プロパティやシングルトンに入れたリクエストごとのデータは、次の呼び出しに残ります。呼び出しの合間に DB の接続が切れていることもあるので、再接続できるようにしておいてください。

### メモリ設定の最適化

- 256MB: 基本的な API 処理、シンプルなウェブアプリケーション
- 512MB: 中規模のアプリケーション、軽量な画像処理
- 1024MB 以上: 大規模なデータ処理、複雑な計算、画像処理

### タイムアウト設定

タイムアウトは Lambda 関数の設定で決まります。デフォルトは 3 秒、最大は 900 秒です。Phambda はタイムアウトを独自に設定しません。
API Gateway を前に置く場合は、統合のタイムアウト（HTTP API は最大 30 秒、REST API はデフォルト 29 秒）にも注意してください。関数のタイムアウトを長くしても、先に API Gateway がエラーを返します。

呼び出しの期限は `$context->deadlineMs`（UNIX 時間のミリ秒）で取れます。HTTP のハンドラーでは、`$request->getAttribute('lambda-context')` で `Context` を取り出せます。
長い処理は、複数の関数に分けるか、Step Functions などを使うことを検討してください。

### ALB で同じ名前のヘッダーを複数返す

ALB のターゲットグループで複数値ヘッダーを有効にしていない場合、ALB は同じ名前のヘッダーを 1 つしか受け付けません。
このとき Phambda は最後の値だけを返し、落とした値があることを warning のログに記録します。
複数の `Set-Cookie` を返したい場合などは、ターゲットグループの属性で複数値ヘッダー（`lambda.multi_value_headers.enabled`）を有効にしてください。

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
| `Runtime.InvalidEntrypoint`       | `bootstrap` が無い、または実行権限が無い | `bootstrap` が zip の直下にあり、`chmod +x` されているか確認 |
| `Runtime.ExitError`               | PHP のプロセスが終了した（PHP バイナリのパス違い、致命的エラーなど） | `bootstrap` のパスとログを確認 |
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
