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

## タイムアウト

タイムアウトは Lambda 関数の設定で決まります。デフォルトは 3 秒、最大は 900 秒です。Phambda はタイムアウトを独自に設定しません。
API Gateway を前に置く場合は、統合のタイムアウト（HTTP API は最大 30 秒、REST API はデフォルト 29 秒）にも注意してください。関数のタイムアウトを長くしても、先に API Gateway がエラーを返します。

呼び出しの期限は `$context->deadlineMs`（UNIX 時間のミリ秒）で取れます。HTTP のハンドラーでは、`$request->getAttribute('lambda-context')` で `Context` を取り出せます。
長い処理は、複数の関数に分けるか、Step Functions などを使うことを検討してください。

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
