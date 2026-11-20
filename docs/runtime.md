# ランタイムの動き

## 初期化を 1 回だけにする

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

## 呼び出しの情報（Context）

ハンドラーは、呼び出しの情報を `Phambda\Context` で受け取ります。HTTP のハンドラーでは、`$request->getAttribute('lambda-context')` で取り出せます。

| プロパティ | 内容 |
|------------|------|
| `awsRequestId` | 呼び出しのリクエスト ID |
| `invocationId` | 呼び出しの試行ごとの ID（`Lambda-Runtime-Invocation-Id`）。Runtime API が送らない場合は空文字列 |
| `functionName`、`functionVersion`、`invokedFunctionArn` | 関数名、バージョン、呼び出しに使われた ARN |
| `memoryLimitInMb` | 関数のメモリサイズ |
| `logGroupName`、`logStreamName` | CloudWatch Logs のロググループとログストリーム |
| `deadlineMs` | 呼び出しの期限（UNIX 時間のミリ秒）。残り時間は `getRemainingTimeInMillis()` で取れる |
| `traceId` | X-Ray のトレースヘッダー。届かなかった場合は空文字列 |
| `clientContext` | AWS Mobile SDK から呼び出されたときの、クライアントアプリとデバイスの情報（JSON をデコードした配列）。それ以外の呼び出しでは `null` |
| `cognitoIdentity` | AWS Mobile SDK から呼び出されたときの、Amazon Cognito の ID（`cognitoIdentityId` と `cognitoIdentityPoolId`）。それ以外の呼び出しでは `null` |

`clientContext` と `cognitoIdentity` は、ヘッダーの値が JSON のオブジェクトとして読めない場合も `null` にし、warning のログを出します。

## タイムアウト

タイムアウトは Lambda 関数の設定で決まります。デフォルトは 3 秒、最大は 900 秒です。Phambda はタイムアウトを独自に設定しません。
API Gateway を前に置く場合は、統合のタイムアウト（HTTP API は最大 30 秒、REST API はデフォルト 29 秒）にも注意してください。関数のタイムアウトを長くしても、先に API Gateway がエラーを返します。

タイムアウトまでの残り時間（ミリ秒）は、`$context->getRemainingTimeInMillis()` で取れます。期限を過ぎていれば 0 を返します。HTTP のハンドラーでは、`$request->getAttribute('lambda-context')` で `Context` を取り出せます。
件数の多い処理は、残り時間を見ながら、タイムアウトになる前に打ち切れます。

```php
use Phambda\Context;
use Phambda\Sqs\SqsHandler;
use Phambda\Sqs\SqsRecord;

final class ImportHandler extends SqsHandler
{
    protected function handleRecord(SqsRecord $record, Context $context): void
    {
        // 1 件に最大 2 秒かかるなら、残りが 2 秒を切ったら処理しない
        if ($context->getRemainingTimeInMillis() < 2000) {
            throw new \RuntimeException('Not enough time left'); // 失敗として返し、後で処理し直す
        }

        // ...
    }
}
```

この例は、部分的なバッチ応答（`reportBatchItemFailures: true`）を有効にしている場合のものです。

期限は `$context->deadlineMs`（UNIX 時間のミリ秒）でも取れます。`deadlineMs` が空の `Context`（テストで自分で作った場合など）では、`getRemainingTimeInMillis()` は `LogicException` を投げます。
長い処理は、複数の関数に分けるか、Step Functions などを使うことを検討してください。

## プロセスを入れ替える

Phambda は 1 つの PHP のプロセスで呼び出しを処理し続けるので、メモリリークがあると、呼び出しのたびにメモリの使用量が増えていきます。
直せないリークがある場合は、決まった回数の呼び出しを処理したとき、またはメモリの使用量が決まった量を超えたときに、プロセスを入れ替えられます。

| 環境変数 | 内容 |
|----------|------|
| `PHAMBDA_MAX_INVOCATIONS` | この回数の呼び出しを処理したら、プロセスを入れ替える（失敗した呼び出しも数える） |
| `PHAMBDA_MAX_MEMORY_MB` | PHP が確保したメモリ（`memory_get_usage(true)`）がこの値（MB）以上になったら、プロセスを入れ替える |

どちらも、指定しなければ入れ替えません。正の整数でない値を指定すると、初期化エラーになります。
コードで指定する場合は、`new Runtime($handler, $logger, limits: new ProcessLimits(maxInvocations: 100))` のように `Phambda\ProcessLimits` を渡します。

上限に達すると、`run()` は応答を返した後、次の呼び出しを受け取る前に終わり、PHP のプロセスが終了します。
**このとき `bootstrap` が PHP を起動し直さなければなりません。** `bootstrap` まで終了すると、Lambda はその呼び出しを `Runtime.ExitError` として記録し、実行環境を作り直します。

```sh
#!/bin/sh
set -e
while true; do
    /opt/bin/php /var/task/main.php
done
```

`set -e` を付けているので、PHP がエラーで終了した場合（終了コードが 0 以外）は、`bootstrap` も終了します。この場合は、これまでどおり Lambda が実行環境を作り直します。

入れ替えには、次の点に注意してください。

- 新しいプロセスを起動して `boot()` を呼ぶ時間は、上限に達した呼び出しの実行時間（課金の対象）に含まれます。呼び出し元には応答を返した後なので、応答が遅くなることはありません。
- 新しいプロセスで `boot()` が失敗した場合、Lambda の初期化フェーズは終わっているので、初期化エラーとしては記録されません。プロセスがエラーで終了し、Lambda が実行環境を作り直します。

## 環境変数

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
| `PHAMBDA_MAX_INVOCATIONS`         | この回数の呼び出しを処理したら、プロセスを入れ替える（[プロセスを入れ替える](#プロセスを入れ替える)） | -                |
| `PHAMBDA_MAX_MEMORY_MB`           | PHP が確保したメモリがこの値（MB）以上になったら、プロセスを入れ替える（同上） | -                |

次の環境変数は、Phambda が呼び出しを受け取るたびに設定します。次の呼び出しを待つ間は削除します。

| 環境変数                 | 内容 |
| ------------------------ | ---- |
| `_X_AMZN_TRACE_ID`       | X-Ray のトレースヘッダー（Runtime API の `Lambda-Runtime-Trace-Id`）。Lambda から届かなかった呼び出しでは設定しない。X-Ray や OpenTelemetry の SDK は、この値を読んでトレースをつなぐ |
| `PHAMBDA_AWS_REQUEST_ID` | 呼び出しのリクエスト ID。`JsonLogger` と `LtsvLogger` が、ログの `request_id` に使う |

同じ値は、`$context->traceId` と `$context->awsRequestId` でも取れます。

## 使う PSR の実装を指定する

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
