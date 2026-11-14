# Phambda

PHP で AWS Lambda の関数を動かすためのランタイムです。
Lambda のカスタムランタイム（`provided.al2023`）上で Runtime API と直接やり取りし、Web サーバーやアダプターを挟まずに PHP のコードを動かします。

- HTTP のイベント（API Gateway、関数 URL、ALB）を PSR-7 のリクエストに変換し、PSR-15 のハンドラーで処理する
- SQS、EventBridge、S3 のイベントを型付きで受け取れる（SQS の部分的なバッチ応答に対応）
- [static-php-cli](https://static-php.dev/) の静的ビルドと組み合わせて、コンテナイメージを作らずに zip でデプロイできる
- PSR-7 / PSR-17 / PSR-18 の実装は同梱せず、アプリケーションが選ぶ

## ランタイム方式の比較

AWS Lambda で PHP を動かす主な方法と、Phambda との違いです。

| 方法 | 仕組み | 扱えるイベント | 向いている用途 |
|------|--------|----------------|----------------|
| **Phambda**（本ライブラリ） | カスタムランタイム（`provided.al2023`）上の PHP が Runtime API と直接やり取りする。HTTP のイベントは PSR-7 のリクエストに変換し、PSR-15 のハンドラに渡す | HTTP（API Gateway、関数 URL、ALB）と HTTP 以外（SQS など） | Web サーバーを置かずに、PSR-15 のハンドラやイベント処理を軽い構成で動かしたい |
| [Bref](https://bref.sh/) | PHP のランタイムを Layer で提供する。PHP-FPM ランタイムを使えば、既存のアプリをそのまま動かせる | HTTP と HTTP 以外 | Laravel や Symfony のアプリを、デプロイ用のツールも含めて揃った環境で運用したい |
| [Lambda Web Adapter](https://github.com/aws/aws-lambda-web-adapter)（LWA） | Lambda の拡張機能がイベントを HTTP リクエストに変換し、関数の中で動く Web サーバー（nginx + PHP-FPM、FrankenPHP など）に転送する | HTTP（API Gateway、関数 URL、ALB）。HTTP 以外のイベントは、指定したパスへの POST として届く | 既存の Web アプリを、Lambda 向けに書き換えずに動かしたい |
| [Lambda Web Functions](https://docs.aws.amazon.com/lambda/latest/dg/web-functions.html) | 2026 年 10 月に追加された関数の形態。アプリが listen するポートに、Lambda が HTTP リクエストを直接渡す。1 つの実行環境が複数のリクエストを同時に処理し、HTTPS のエンドポイントも Lambda が用意する | HTTP | 同時リクエストの多い Web アプリや API。ただし公式に対応しているランタイムは、現時点で Node.js だけ |

FrankenPHP や nginx + PHP-FPM は Runtime API を扱わないので、LWA と組み合わせて動かします。
PHP は Web Functions に公式には対応していません（非公開 API に依存した PoC として [ixbox/lambda-web-function-php](https://github.com/ixbox/lambda-web-function-php) があります）。

## インストール

```console
composer require ixbox/phambda
composer require nyholm/psr7 php-http/curl-client   # PSR-7 / PSR-17 / PSR-18 の実装の例
```

PHP 8.2 以上が必要です。

## 使い方

HTTP のイベントは、PSR-15 の `RequestHandlerInterface` を `Phambda\Http\Runtime` に渡して処理します。

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Phambda\Http\Runtime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

Runtime::execute(new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'Hello World!');
    }
});
```

HTTP 以外のイベントは、`Phambda\HandlerInterface` を `Phambda\Runtime` に渡して処理します。SQS、EventBridge、S3 には型付きのハンドラー（`SqsHandler` など）があります。

```php
use Phambda\Context;
use Phambda\Event;
use Phambda\HandlerInterface;
use Phambda\Runtime;

Runtime::execute(new class implements HandlerInterface {
    public function handle(Event $event, Context $context): string
    {
        return json_encode(['received' => $event['message'] ?? null]); // Lambda に返す JSON
    }
});
```

## デプロイ

関数には、PHP のバイナリ（static-php-cli の静的ビルドを Layer に置くなど）、アプリケーションのコードと `vendor/`、それに PHP を起動する実行可能な `bootstrap` を入れます。

```sh
#!/bin/sh
exec /opt/bin/php /var/task/main.php
```

詳しくは[デプロイ](https://github.com/ixbox/phambda/blob/main/docs/deployment.md)を参照してください。

## ドキュメント

- [デプロイ](https://github.com/ixbox/phambda/blob/main/docs/deployment.md): 関数の構成、`bootstrap`、起きやすいエラー
- [HTTP のイベントを扱う](https://github.com/ixbox/phambda/blob/main/docs/http.md): リクエストの属性、レスポンスの変換、ログ、ミドルウェア
- [SQS、EventBridge、S3 のイベントを扱う](https://github.com/ixbox/phambda/blob/main/docs/events.md): 型付きのハンドラーと、SQS の部分的なバッチ応答
- [ランタイムの動き](https://github.com/ixbox/phambda/blob/main/docs/runtime.md): 初期化（`boot()`）、タイムアウト、環境変数、使う PSR の実装の指定
- [エラー処理](https://github.com/ixbox/phambda/blob/main/docs/error-handling.md): どのエラーを Lambda にどう通知するか
- [CHANGELOG](https://github.com/ixbox/phambda/blob/main/CHANGELOG.md)

## ライセンス

MIT ライセンスです。

## コントリビューション

バグ報告や機能提案は、GitHub の Issue で受け付けています。プルリクエストを送る前に、次を確認してください。

1. テストを追加していること
2. すべてのテストが通ること（`composer test`）
3. 静的解析が通ること（`composer analyze`）
4. コードスタイルが守られていること（`vendor/bin/pint --test`）
