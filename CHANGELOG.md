# Changelog

このプロジェクトの主な変更を記録します。
形式は [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に、バージョンは [Semantic Versioning](https://semver.org/lang/ja/) に従います。
v0.3.0 以前の変更は Git のタグとコミット履歴を参照してください。

## [Unreleased]

### 破壊的変更

- クエリパラメータを、すべてのイベント形式で `$_GET` と同じ規則（`parse_str()`）で解析します。v0.4.0 では REST API（ペイロード 1.0）と ALB の複数値ヘッダーモードで値が 1 つでも配列（`['page' => ['2']]`）になりましたが、ほかの形式と同じく文字列（`['page' => '2']`）になります。
  - 重複したキーは最後の値になります（REST API の `queryStringParameters` と同じ）。すべての値が必要なときは `getUri()->getQuery()` を参照するか、`tags[]=a&tags[]=b` の配列表記を使ってください。
  - `tags[]` や `filter[status]` などの配列表記を解釈します。

### 修正

- HTTP API（ペイロード 2.0）と Lambda 関数 URL では、クエリパラメータを `queryStringParameters` ではなく `rawQueryString` から読みます。`queryStringParameters` は重複したキーの値をカンマで連結するため、`q=x%2Cy&q=z` と `q=x&q=y&q=z` を区別できませんでした。`rawQueryString` が無いイベントでは `queryStringParameters` を使います。
- すべてのイベント形式で、`getUri()->getQuery()` にクエリ文字列が入ります。REST API と ALB では、イベントのパラメータから組み立てます。

## [0.4.0] - 2026-10-19

### 破壊的変更

- PHP の要件を 8.1 から 8.2 に上げました。
- 次のインターフェースを削除しました。型宣言や DI コンテナの登録は具象クラスに置き換えてください。
  - `Phambda\WorkerInterface` → `Phambda\Worker`
  - `Phambda\Http\HttpWorkerInterface` → `Phambda\Http\Worker`
  - `Phambda\Http\RequestTransformerInterface` → `Phambda\Http\RequestTransformer`
  - `Phambda\Http\ResponseTransformerInterface` → `Phambda\Http\ResponseTransformer`
- `Phambda\Http\HttpWorker` を `Phambda\Http\Worker` に名前変更しました。
  - `nextRequest()` は `ServerRequestInterface` ではなく `Phambda\Http\Invocation` を返します。リクエストは `$invocation->request` で取り出せます。
  - `respond()` はリクエスト ID の文字列ではなく `Invocation` を受け取ります。
- `RequestTransformer::transform()` と `ResponseTransformer::transform()` は、第 3 引数と第 2 引数に `Phambda\Http\EventFormat` を必須で受け取ります。`ResponseTransformer::transform()` の `$context` 引数は削除しました。
- API Gateway REST API（ペイロード 1.0）と ALB の複数値ヘッダーモードでは、クエリパラメータを `multiValueQueryStringParameters` から読みます（REST API でこのキーが無いときだけ `queryStringParameters` を使います）。値が 1 つでも配列（`['page' => ['2']]`）になります。HTTP API（ペイロード 2.0）と Lambda 関数 URL では、従来どおり文字列です。
- `Event` の `isset($event['key'])` は、値が `null` でもキーがあれば `true` を返します。

### 追加

- イベントの形式を呼び出しごとに判定し、レスポンスも同じ形式で返します。対応する形式は API Gateway HTTP API（2.0）、REST API（1.0）、Lambda 関数 URL、ALB（単一値・複数値ヘッダー）です。
- `Phambda\Http\Worker::respondWithError()` を追加しました。
- `Phambda\Runtime::execute()` と `Phambda\Http\Runtime::execute()` にロガーを渡せます。
- static-php-cli を使ったコンテナ不要のデプロイに対応しました。

### 修正

- HTTP として変換できないイベントは Lambda にエラーとして報告し、次の呼び出しを続けます。以前はプロセスが終了していました。
- `Lambda-Runtime-Aws-Request-Id` が空の呼び出しは、ハンドラを実行する前に初期化エラーにします。
- ALB のクエリ文字列を URL デコードします（`+` は空白になります）。
- `multiValueHeaders` が `null` の ALB イベントを単一値ヘッダーとして扱います。
- UTF-8 として不正なレスポンス本文は、Content-Type にかかわらず base64 で返します。
- エラー報告の JSON は不正な UTF-8 を置換し、エンコードに失敗しても固定のエラーを送ります。
- Cookie 配列の変換とレスポンスの base64 判定を修正しました。
- エラー応答の生成から Nyholm への直接依存を外しました。

[Unreleased]: https://github.com/ixbox/phambda/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/ixbox/phambda/compare/v0.3.0...v0.4.0
