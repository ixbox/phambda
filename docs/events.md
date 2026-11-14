# SQS、EventBridge、S3 のイベントを扱う

HTTP 以外のイベントは、`Phambda\Runtime` に `Phambda\HandlerInterface` を実装したハンドラーを渡して処理します。
SQS、EventBridge、S3 には、イベントを型付きで受け取れるハンドラーを用意しています。

## SQS

```php
use Phambda\Context;
use Phambda\Runtime;
use Phambda\Sqs\SqsHandler;
use Phambda\Sqs\SqsRecord;

$handler = new class (reportBatchItemFailures: true) extends SqsHandler {
    protected function handleRecord(SqsRecord $record, Context $context): void
    {
        $order = $record->json(); // 本文を JSON としてデコードする
        // 例外を投げると、そのメッセージが失敗になる
    }
};

Runtime::execute($handler);
```

`SqsHandler` は、バッチのメッセージを 1 件ずつ `handleRecord()` に渡します。

- `reportBatchItemFailures: true` を指定すると、失敗したメッセージだけを再配信させる部分的なバッチ応答を返します。イベントソースマッピングで `ReportBatchItemFailures` を有効にしているときだけ指定してください。有効にしていないと、Lambda は応答を無視してバッチ全体を成功とみなし、失敗したメッセージも削除します。
- 指定しない場合は、1 件でも失敗するとバッチ全体が失敗になり、すべてのメッセージが再配信されます。
- FIFO キューで部分的なバッチ応答を使う場合は、順序を保つため、最初に失敗したメッセージ以降を処理せずに失敗として返します。

## EventBridge

EventBridge のイベント（スケジュール実行を含む）は、`EventBridgeHandler` で受け取れます。

```php
use Phambda\EventBridge\EventBridgeEvent;
use Phambda\EventBridge\EventBridgeHandler;

$handler = new class extends EventBridgeHandler {
    protected function handleEvent(EventBridgeEvent $event, Context $context): void
    {
        // $event->detailType, $event->source, $event->detail, $event->time など
    }
};
```

## S3

S3 のイベント通知は、`S3Handler` で受け取れます。通知に入っているオブジェクトキーは URL エンコードされている（`red flower.jpg` は `red+flower.jpg` になる）ので、`$record->key` ではデコードしたキーを返します。通知を設定したときに S3 が送るテストメッセージ（`s3:TestEvent`）は無視します。

```php
use Phambda\S3\S3Handler;
use Phambda\S3\S3Record;

$handler = new class extends S3Handler {
    protected function handleRecord(S3Record $record, Context $context): void
    {
        // $record->bucketName, $record->key, $record->size, $record->isObjectCreated() など
    }
};
```

S3 の通知を SQS 経由で受け取る場合は、`SqsHandler` の中で `S3Event::fromJson()` にメッセージの本文を渡します。

```php
protected function handleRecord(SqsRecord $record, Context $context): void
{
    foreach (S3Event::fromJson($record->body)->records as $s3) {
        // テストメッセージの場合、records は空になる
    }
}
```
