# ローカルで動かす

Lambda の [Runtime Interface Emulator](https://github.com/aws/aws-lambda-runtime-interface-emulator)（RIE）を使うと、デプロイする前に、関数を手元の Docker で動かせます。
RIE は Runtime API を再現するので、Lambda と同じ `bootstrap` と `main.php` が、Phambda の処理を通って動きます。

## イメージを作る

公式の PHP イメージに、Lambda のベースイメージから RIE をコピーします。
`/opt/bin/php` にリンクを張るので、[デプロイ](deployment.md)と同じ `bootstrap` をそのまま使えます。

```dockerfile
# Dockerfile.local
FROM php:8.5-cli
COPY --from=public.ecr.aws/lambda/provided:al2023 /usr/local/bin/aws-lambda-rie /usr/local/bin/aws-lambda-rie
RUN mkdir -p /opt/bin && ln -s /usr/local/bin/php /opt/bin/php
ENTRYPOINT ["/usr/local/bin/aws-lambda-rie", "/var/task/bootstrap"]
```

```console
docker build -t my-function-local -f Dockerfile.local .
```

## 起動する

`bootstrap`、`main.php`、`vendor/` があるディレクトリで、`/var/task` にマウントして起動します。
`bootstrap` には実行権限（`chmod +x bootstrap`）が必要です。無いと、呼び出したときに `Runtime.InvalidEntrypoint` になります。

```console
composer install
docker run --rm -p 9000:8080 -v "$PWD":/var/task:ro my-function-local
```

## 呼び出す

イベントの JSON を、次の URL に POST します。

```console
curl -XPOST http://localhost:9000/2015-03-31/functions/function/invocations \
  -d '{"version":"2.0","rawPath":"/hello","rawQueryString":"name=Phambda","headers":{"host":"localhost"},"requestContext":{"http":{"method":"GET","path":"/hello"}},"isBase64Encoded":false}'
```

HTTP のハンドラーなら、関数 URL（ペイロード 2.0）の形式のレスポンスが返ります。たとえば、クエリパラメータ `name` を使って `Hello <name>!` を返すハンドラーなら、次のようになります。

```json
{"statusCode":200,"statusDescription":"OK","headers":{"Content-Type":"text\/plain"},"cookies":[],"multiValueHeaders":{"Content-Type":["text\/plain"]},"body":"Hello Phambda!","isBase64Encoded":false}
```

SQS や S3 などのイベントの例は、AWS SAM CLI の `sam local generate-event` で作れます。
Phambda のログと RIE のログは、`docker run` の出力に出ます。

## customer-managed runtime のイメージで動かす

[customer-managed runtime](deployment.md#customer-managed-runtime-で動かす) で動かす場合は、ランタイムイメージに RIE を足したイメージを作ります。
本番と同じ PHP と `bootstrap` で動かせます。
`php-runtime` は、手元でビルドしたランタイムイメージのタグです。

```dockerfile
# Dockerfile.local
FROM php-runtime
COPY --from=public.ecr.aws/lambda/provided:al2023 /usr/local/bin/aws-lambda-rie /usr/local/bin/aws-lambda-rie
ENTRYPOINT ["/usr/local/bin/aws-lambda-rie", "/var/runtime/bootstrap", "main.php"]
```

最後の `main.php` は、関数のハンドラーです。Lambda では関数の設定から渡されます。
RIE は `LAMBDA_TASK_ROOT` を設定しないので、`docker run` で渡します。

```console
docker build -t my-function-local -f Dockerfile.local .
docker run --rm -p 9000:8080 -e LAMBDA_TASK_ROOT=/var/task -v "$PWD":/var/task:ro my-function-local
```

## Lambda との違い

- 「イメージを作る」の手順では、PHP は公式の Docker イメージのものです。デプロイに使う static-php-cli のバイナリとは、入っている拡張が違うことがあります。使う拡張が本番のバイナリに入っているかは、別に確認してください。
- メモリの上限は再現されません。RIE のログの `Memory Size` は、関数の設定とは関係のない値です。
- IAM ロールの認証情報は渡されません。AWS のサービスを呼ぶ場合は、`-e AWS_ACCESS_KEY_ID=...` などで環境変数を渡します。
