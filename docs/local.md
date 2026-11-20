# ローカルで動かす

Lambda の [Runtime Interface Emulator](https://github.com/aws/aws-lambda-runtime-interface-emulator)（RIE）を使うと、デプロイする前に、関数を手元の Docker で動かせます。
RIE は Runtime API を再現するので、Lambda と同じ `bootstrap` と `main.php` が、Phambda の処理を通って動きます。

RIE の使い方は 2 つあります。

- **Docker で直接動かす**: Docker だけで動かせます。[customer-managed runtime](deployment.md#customer-managed-runtime-で動かす) のイメージも、この方法で動かします。
- **[AWS SAM で動かす](#aws-sam-で動かす)**: [static-php-cli](deployment.md#static-php-cli-で動かす) で動かす関数を、本番と同じ PHP のバイナリと Layer の構成で動かせます。`sam local start-api` で、API Gateway を通した HTTP のリクエストも試せます。

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

## AWS SAM で動かす

[static-php-cli](deployment.md#static-php-cli-で動かす) で動かす関数は、[AWS SAM CLI](https://docs.aws.amazon.com/serverless-application-model/latest/developerguide/install-sam-cli.html) の `sam local` で動かせます。
SAM は `provided.al2023` のイメージで RIE を動かし、Layer を `/opt`、関数のコードを `/var/task` に置くので、デプロイと同じ PHP のバイナリと `bootstrap` がそのまま動きます。

```
template.yaml
layer/            ← Layer（/opt に置かれる）
└── bin/php       ← static-php-cli のバイナリ（関数のアーキテクチャに合わせる）
app/              ← 関数（/var/task に置かれる）
├── bootstrap
├── main.php
└── vendor/
```

```yaml
# template.yaml
AWSTemplateFormatVersion: '2010-09-09'
Transform: AWS::Serverless-2016-10-31
Resources:
  PhpLayer:
    Type: AWS::Serverless::LayerVersion
    Properties:
      ContentUri: layer/
  Function:
    Type: AWS::Serverless::Function
    Properties:
      CodeUri: app/
      Handler: bootstrap
      Runtime: provided.al2023
      Architectures: [arm64]
      Layers: [!Ref PhpLayer]
      Events:
        Api:
          Type: HttpApi
```

イベントを 1 件渡して呼び出すには、`sam local invoke` を使います。イベントの JSON は `sam local generate-event` で作れます。

```console
sam local generate-event apigateway http-api-proxy > event.json
sam local invoke Function -e event.json
```

HTTP のリクエストで試すには、`sam local start-api` で API Gateway（HTTP API）を手元で動かします。

```console
sam local start-api --warm-containers EAGER
curl "http://127.0.0.1:3000/hello?name=Phambda"
```

`--warm-containers` を付けないと、SAM は呼び出しのたびにコンテナを作り直します。
そのたびに PHP のプロセスが起動し、`boot()` も毎回呼ばれるので、本番のように 1 つのプロセスで呼び出しを続けて処理する動き（[初期化を 1 回だけにする](runtime.md#初期化を-1-回だけにする)、[プロセスを入れ替える](runtime.md#プロセスを入れ替える)）は確かめられません。

- Colima など、Docker Desktop 以外で Docker を動かしている場合は、SAM が Docker を見つけられないことがあります。`DOCKER_HOST` に Docker のソケット（`docker context inspect --format '{{.Endpoints.docker.Host}}'` で確かめられる）を設定してください。
- customer-managed runtime の関数は、SAM CLI 1.173 の時点では `sam local` で動かせません。テンプレートの `RuntimeConfig` を受け付けず、`--invoke-image` でランタイムイメージを指定しても、ハンドラー（`main.php`）を実行ファイルとして起動しようとして失敗します。[customer-managed runtime のイメージで動かす](#customer-managed-runtime-のイメージで動かす)の手順を使ってください。

## Lambda との違い

- 「イメージを作る」の手順では、PHP は公式の Docker イメージのものです。デプロイに使う static-php-cli のバイナリとは、入っている拡張が違うことがあります。使う拡張が本番のバイナリに入っているかは、別に確認してください。
- メモリの上限は再現されません。Docker で直接動かした場合、RIE のログの `Memory Size` は、関数の設定とは関係のない値です。
- IAM ロールの認証情報は渡されません。Docker で直接動かす場合に AWS のサービスを呼ぶときは、`-e AWS_ACCESS_KEY_ID=...` などで環境変数を渡します。
