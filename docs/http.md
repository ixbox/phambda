# HTTP のイベントを扱う

`Phambda\Http\Runtime` は、HTTP のイベントを PSR-7 のリクエストに変換して PSR-15 のハンドラーに渡し、返ってきたレスポンスをイベントの形式に合わせて Lambda に返します。

## 対応しているイベント

- API Gateway HTTP API（ペイロード 2.0）と Lambda 関数 URL
- API Gateway REST API（ペイロード 1.0）
- ALB（単一値ヘッダーと複数値ヘッダーの両方）

形式は呼び出しごとに判定し、レスポンスも同じ形式で返します。

## リクエスト

| 取り出し方 | 内容 |
|------------|------|
| `$request->getAttribute('awsRequestId')` | 呼び出しのリクエスト ID |
| `$request->getAttribute('lambda-context')` | `Phambda\Context`（関数名、メモリ、期限 `deadlineMs` など） |
| `$request->getAttribute('pathParameters')` | API Gateway のパスパラメータ（ある場合） |
| `$request->getAttribute('stageVariables')` | API Gateway のステージ変数（ある場合） |
| `$request->getQueryParams()` | クエリパラメータ。どの形式でも `$_GET` と同じ規則で解析する（重複したキーは最後の値、`tags[]` は配列） |
| `$request->getCookieParams()` | Cookie。`$_COOKIE` と同じく URL デコードし、同じ名前は最初の値を使う |

`isBase64Encoded` が `true` の本文は、デコードしてから渡します。

## レスポンス

- `Set-Cookie` は、イベントの形式に合わせて返します（HTTP API と関数 URL は `cookies`、REST API と ALB の複数値ヘッダーは `multiValueHeaders`）。
- 本文が UTF-8 として正しくない場合と、`Content-Type` がテキスト系（`text/*`、JSON、XML、JavaScript）以外の場合は、base64 で返します。`Content-Type` がない場合は、そのまま返します。
- ハンドラーが例外を投げると、`{"message":"Internal Server Error"}` の 500 を返し、詳細はログに出します。環境変数 `PHAMBDA_DEBUG=1` を設定すると、例外のメッセージとスタックトレースを返します。

## ログ

ランタイムにロガーを渡すと、ランタイム自身のログも同じロガーに出ます。

```php
use Phambda\Http\Runtime;
use Phambda\Logger\JsonLogger;

$logger = new JsonLogger('info'); // JSON で標準エラー出力（CloudWatch Logs）に書く。info 以上を出力
Runtime::execute(new MyHandler($logger), $logger);
```

用意しているロガーは `JsonLogger`、`LtsvLogger`、`NanoLogger`（JSON、最小限）です。どれも PSR-3 の `LoggerInterface` なので、Monolog などに置き換えられます。

## ミドルウェアでヘッダーを付ける

`Http\Runtime` は `RequestHandlerInterface` を 1 つ受け取るだけなので、PSR-15 のミドルウェアを重ねたハンドラーをそのまま渡せます。
すべての応答に付けたいヘッダーは、ハンドラーごとに書かずにミドルウェアで付けてください。
`MiddlewareInterface` を使うには、アプリケーションで `psr/http-server-middleware` を追加します（[relay/relay](https://github.com/relayphp/Relay.Relay) などのディスパッチャーを使ってもかまいません）。

```php
use Phambda\Http\Runtime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('Cache-Control', 'no-store');
    }
}

// ミドルウェアとハンドラーを、1 つのハンドラーにまとめる
final class MiddlewareHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly MiddlewareInterface $middleware,
        private readonly RequestHandlerInterface $next,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}

Runtime::execute(new MiddlewareHandler(new SecurityHeaders(), new MyHandler()));
```

ハンドラーが例外を投げたときに Phambda が返す 500 応答は、ミドルウェアを通らないので、これらのヘッダーは付きません。
500 応答にも付けたい場合は、ミドルウェアの中で例外を受け止めて応答を返すか、CloudFront の応答ヘッダーポリシーで付けてください。

## ALB で同じ名前のヘッダーを複数返す

ALB のターゲットグループで複数値ヘッダーを有効にしていない場合、ALB は同じ名前のヘッダーを 1 つしか受け付けません。
このとき Phambda は最後の値だけを返し、落とした値があることを warning のログに記録します。
複数の `Set-Cookie` を返したい場合などは、ターゲットグループの属性で複数値ヘッダー（`lambda.multi_value_headers.enabled`）を有効にしてください。
