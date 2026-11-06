# Phambda のエラー処理

Phambda が、どこで起きたエラーを、Lambda にどう通知するかをまとめます。
ここに書いているのは v0.7.1 時点の挙動です。

## Lambda への通知の種類

| 通知 | Runtime API | 使う場面 | その後 |
|------|-------------|----------|--------|
| 初期化エラー | `POST /runtime/init/error` | 最初の `/runtime/invocation/next` より前の失敗 | プロセスを終了する。Lambda は次の呼び出しで実行環境を作り直す |
| 呼び出しのエラー | `POST /runtime/invocation/{requestId}/error` | 1 つの呼び出しの処理中の失敗 | 次の呼び出しを続けて処理する |
| HTTP の 500 応答 | `POST /runtime/invocation/{requestId}/response` | `Http\Runtime` で、ハンドラーが例外を投げたとき | 次の呼び出しを続けて処理する。Lambda から見ると成功した呼び出しになる |

`/runtime/init/error` は、初期化フェーズ（最初の `/next` より前）にだけ送ります。`/next` が Lambda に届いた後は、`Worker::initError()` を呼んでも送らず、warning のログだけを出します。

エラーの本文は、Runtime API が定める形式です。`errorType` には例外の完全なクラス名が入ります。

```json
{
  "errorMessage": "エラーメッセージ",
  "errorType": "Phambda\\Exception\\TransformationException",
  "stackTrace": ["..."]
}
```

`Lambda-Runtime-Function-Error-Type` ヘッダーは、Lambda が推奨する `<Category.Reason>` の形式です。

- `Function.<例外のクラス名>`: ハンドラーや `boot()` の例外（例: `Function.LogicException`）
- `Runtime.<例外のクラス名>`: Runtime API との通信やイベントの変換の失敗（例: `Runtime.TransformationException`）

## どこで起きたエラーが、どう扱われるか

### 初期化

| 起きたこと | 通知 | プロセス |
|------------|------|----------|
| `boot()` が例外を投げた | 初期化エラー（`Function.*`） | 終了 |
| 最初の `/next` が Lambda に届かなかった | 初期化エラー（`Runtime.*`） | 終了 |
| `Runtime::execute()` より前の失敗（`vendor/autoload.php` が無い、PSR-18 の実装が無い、ハンドラーのコンストラクタの例外など） | できない。PHP のエラーが標準エラー出力（CloudWatch Logs）に出て、Lambda には `Runtime.ExitError` と記録される | 終了 |

### 呼び出しの処理中

| 起きたこと | `Runtime` | `Http\Runtime` |
|------------|-----------|----------------|
| ハンドラーが例外を投げた | 呼び出しのエラー（`Function.*`） | HTTP の 500 応答 |
| HTTP のイベントとして変換できない | - | 呼び出しのエラー（`Runtime.TransformationException`） |
| レスポンスを HTTP の形式に変換できない | - | HTTP の 500 応答 |
| `/response` の送信に失敗した | 同じ呼び出しのエラーとして通知し直す | 同じ |
| `/error` を送れなかった（通信の失敗） | プロセスを終了する | 同じ |
| 2 回目以降の `/next` に失敗した | プロセスを終了する（初期化エラーは送らない） | 同じ |

HTTP の 500 応答の本文は、既定では `{"message":"Internal Server Error"}` だけです。環境変数 `PHAMBDA_DEBUG=1` を設定すると、例外のメッセージ、クラス名、スタックトレースを返します。どちらの場合も、詳細はログに出ます。

メモリ不足や Lambda のタイムアウトでは、Lambda がプロセスを止めます。Phambda はこれを検知できません。

## ログ

| 場面 | レベル |
|------|--------|
| `boot()` の失敗、プロセスを終了するエラー | critical |
| ハンドラーの例外、変換の失敗、送信の失敗 | error |
| ALB の単一値ヘッダーモードで、同じ名前のヘッダーの値を落とした | warning |
| 初期化フェーズの後に `initError()` が呼ばれた | warning |

## 例外クラス

すべて `Phambda\Exception\PhambdaException`（`\RuntimeException` を継承）のサブクラスです。`getContext()` で、デバッグ用の追加情報を取り出せます。

| クラス | 意味 |
|--------|------|
| `InitializationException` | 続けられない失敗。ランタイムはこれを投げて終了する |
| `RuntimeException` | 1 つの呼び出しの失敗。`getInvocationId()` でリクエスト ID を取れる |
| `TransformationException` | イベントとリクエスト、またはレスポンスの変換の失敗 |
