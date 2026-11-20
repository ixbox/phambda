# デプロイ

関数のコード（エントリーポイントの PHP ファイルと `vendor/`）は、どの方式でも zip でデプロイします。
方式によって違うのは、PHP の本体と `bootstrap` をどこに用意するかです。

| 方式 | PHP と `bootstrap` の置き場所 | 向いている場合 |
|------|-------------------------------|----------------|
| [customer-managed runtime](#customer-managed-runtime-で動かす)（推奨） | ECR のコンテナイメージから作るランタイム。PHP の [Docker 公式イメージ](https://hub.docker.com/_/php)を土台にできる | PHP の新しいバージョンやセキュリティ修正に追従したい。拡張を足したい。多くの関数で同じ PHP を使いたい |
| [static-php-cli](#static-php-cli-で動かす) | static-php-cli の静的ビルドを Layer に置き、`bootstrap` を関数の zip に入れる | ECR や Docker を使わずに、手軽に試したい |

## アプリケーション

エントリーポイントの PHP ファイルと `vendor/` を zip にします。
`vendor/` は `composer install --no-dev --optimize-autoloader` で作ります。

Phambda は PSR-7 / PSR-17 / PSR-18 の実装を同梱しないので、使う実装（例: `nyholm/psr7` と `php-http/curl-client`）をアプリケーションの `require` に追加してください。
使う実装に必要な拡張（`php-http/curl-client` なら `curl`）が PHP に含まれていることも確認してください。
アプリケーションで php-http/discovery の Composer プラグインを許可している場合（`composer config allow-plugins.php-http/discovery true`）は、実装が入っていなければ自動でインストールされます。

```
/var/task         ← 関数の zip
├── main.php
└── vendor/
```

## customer-managed runtime で動かす

[customer-managed runtime](https://docs.aws.amazon.com/lambda/latest/dg/runtimes-customer-managed.html) は、2026 年 11 月に Lambda に追加された仕組みです。
PHP の本体と `bootstrap` をコンテナイメージにして ECR に置き、Lambda のランタイムとして登録します。
関数はそのランタイムを `python3.14` などと同じように指定し、コードは zip でデプロイします。

PHP を更新するときは、新しいイメージをランタイムの新しいバージョンとして登録し、関数が参照しているエイリアスを切り替えます。
関数は次のコールドスタートで新しいバージョンを使うので、関数ごとに再デプロイする必要はありません。

### ランタイムイメージを作る

PHP の Docker 公式イメージを土台にします。
公式イメージは Lambda 向けではないので、次の 3 点を補います。

- Lambda が起動時に上書きするパス（`/etc/hosts`、`/etc/resolv.conf`、`/var/task`）を、イメージに含める。Debian 版のイメージには `/etc/hosts` が、Alpine 版には `/etc/resolv.conf` が、どちらにも `/var/task` がありません。`/etc/hosts` と `/etc/resolv.conf` は、Docker がコンテナの起動時にしか作らないので、`RUN` ではなく `COPY` で入れます。
- `ENTRYPOINT` を `bootstrap` に差し替える。公式イメージの `docker-php-entrypoint` は受け取った引数をそのまま実行するので、Lambda が渡すハンドラー（`main.php`）を実行しようとして失敗します。
- `bootstrap` で、最後の引数からハンドラーを読む。Lambda はハンドラーを `ENTRYPOINT` の最後の引数として渡します。Lambda のベースイメージ以外では、環境変数 `_HANDLER` は設定されません。

```dockerfile
# Dockerfile
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

# 拡張を足す場合は、ここで docker-php-ext-install などを使う

COPY hosts /etc/hosts
COPY resolv.conf /etc/resolv.conf
RUN mkdir -p /var/task \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --chmod=755 bootstrap /var/runtime/bootstrap

# exec 形式（JSON の配列）で書く。シェル形式では、ハンドラーが bootstrap に渡らない
ENTRYPOINT ["/var/runtime/bootstrap"]
```

```sh
#!/bin/sh
# bootstrap
set -e
# Lambda は関数のハンドラー（例: main.php）を最後の引数として渡す
for handler; do :; done
while true; do
    /usr/local/bin/php "${LAMBDA_TASK_ROOT}/${handler}"
done
```

`bootstrap` が PHP を `while` ループで起動するのは、[プロセスを入れ替える](runtime.md#プロセスを入れ替える)場合に備えるためです。
入れ替えを使わない場合、PHP のプロセスは終了しないので、ループは 1 周目から先に進みません。

`hosts` と `resolv.conf` は、ビルドコンテキストに置くファイルです。
`resolv.conf` は空で構いません（Lambda が DNS の設定を入れます）。

```
# hosts
127.0.0.1	localhost
::1	localhost ip6-localhost ip6-loopback
```

arm64 と x86_64 の両方の関数で使う場合は、両方のプラットフォームを含むイメージを作ります。
Lambda は、関数のアーキテクチャに合うイメージを選びます。

```console
docker buildx build --platform linux/amd64,linux/arm64 \
  -t 123456789012.dkr.ecr.ap-northeast-1.amazonaws.com/php-runtime:8.5-1 --push .
```

イメージには、PHP の本体と `bootstrap` のほかに、すべての関数で使う拡張や証明書を入れられます。
`/opt` は関数の Layer、`/var/task` は関数のコードのために予約されているので、何も置かないでください。
関数のコードは、イメージに含まれるものをすべて読めます。パスワードやトークンなどの秘密の情報は入れないでください。

### ランタイムを登録する

まず、Lambda がイメージを読めるように、ECR のリポジトリにポリシーを設定します。
`aws:SourceArn` に書くのは、ランタイムの ARN です（ランタイムのバージョンの ARN ではありません）。

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "LambdaReadAccess",
      "Effect": "Allow",
      "Principal": { "Service": "lambda.amazonaws.com" },
      "Action": ["ecr:BatchGetImage", "ecr:DescribeImages", "ecr:GetDownloadUrlForLayer"],
      "Condition": {
        "StringEquals": { "aws:SourceAccount": "123456789012" },
        "ArnLike": { "aws:SourceArn": "arn:aws:lambda:ap-northeast-1:123456789012:runtime/*" }
      }
    }
  ]
}
```

```console
aws ecr set-repository-policy --repository-name php-runtime --policy-text file://ecr-policy.json
```

次に、ランタイム、バージョン、エイリアスを作ります。
バージョンは、イメージの内容をその時点で固定したもので、後から変更できません。
関数は、エイリアスを通してバージョンを参照します。

```console
aws lambda-core create-runtime --name php --description "PHP for Phambda" --license-info "PHP License"
aws lambda-core create-runtime-version --runtime-name php --name php85-1 \
  --image-uri 123456789012.dkr.ecr.ap-northeast-1.amazonaws.com/php-runtime:8.5-1
aws lambda-core create-runtime-alias --runtime-name php --name prod --runtime-version php85-1
```

バージョンは、作った直後は `CREATING` の状態です。
`aws lambda-core get-runtime-version` で `ACTIVE` になったことを確かめてから、エイリアスを作ります。

### 関数を作る

ランタイムに `custom` を指定し、`--runtime-config` でランタイムの ARN とエイリアスを指定します。
ハンドラーには、エントリーポイントの PHP ファイルを `/var/task` からの相対パスで指定します。

```console
aws lambda create-function --function-name my-function \
  --runtime custom \
  --runtime-config RuntimeArn=arn:aws:lambda:ap-northeast-1:123456789012:runtime/php,RuntimeAlias=prod \
  --handler main.php \
  --architectures arm64 \
  --zip-file fileb://function.zip \
  --role arn:aws:iam::123456789012:role/my-function-role
```

CloudFormation と AWS SAM では、`Runtime: custom` と `RuntimeConfig`（`RuntimeArn` と `RuntimeAlias`）を指定します。

### PHP を更新する

新しいイメージを作って ECR に push し、ランタイムの新しいバージョンとして登録して、エイリアスを切り替えます。

```console
aws lambda-core create-runtime-version --runtime-name php --name php85-2 \
  --image-uri 123456789012.dkr.ecr.ap-northeast-1.amazonaws.com/php-runtime:8.5-2
aws lambda-core update-runtime-alias --runtime-name php --name prod --runtime-version php85-2
```

すでに動いている実行環境は、そのまま古いバージョンで呼び出しを処理します。
新しいバージョンが使われるのは、次に実行環境が作られたとき（コールドスタート）からです。
問題があれば、エイリアスを元のバージョンに戻せば元に戻ります。

PHP のマイナーバージョンを上げる場合（8.4 から 8.5 など）は、アプリケーションが動かなくなる可能性があります。
エイリアスを `php84` と `php85` のように分け、関数ごとに切り替えるほうが安全です。

### 注意点

- PHP と OS のセキュリティ修正は、Lambda が当ててくれません。公式イメージが更新されたら、イメージを作り直して新しいバージョンを登録してください。
- ランタイムのバージョンが参照しているイメージを ECR から消すと、そのバージョンを使う関数は、コールドスタートのときに失敗するようになります。バージョンを削除するまでイメージを残してください。
- 新しいイメージを使う最初のコールドスタートは、Lambda がイメージを取得するので長くなります。arm64 と x86_64 の関数で試したところ、最初の初期化は約 3 秒、2 回目以降は 130〜220 ms でした（512 MB、PHP 8.4 の Debian 版イメージ）。
- Lambda Managed Instances と Lambda Web Functions では使えません。Managed Instances は、1 つの実行環境で複数の呼び出しを同時に処理できるランタイムを求めますが、Phambda は呼び出しを 1 件ずつ処理します。
- ランタイムは、リージョンごとに作ります。イメージは、ランタイムと同じリージョンの ECR に置きます。

## static-php-cli で動かす

[static-php-cli](https://static-php.dev/) の静的ビルドを使うと、コンテナイメージを作らずに、Lambda の OS だけのランタイム（`provided.al2023`）で動かせます[^byol]。
関数に必要なものは、アプリケーションのほかに次の 2 つです。

- **PHP バイナリ**: static-php-cli の静的ビルド（例: `https://dl.static-php.dev/static-php-cli/common/php-8.5.8-cli-linux-aarch64.tar.gz`）。関数のアーキテクチャ（arm64 なら `aarch64`、x86_64 なら `x86_64`）に合わせます。Layer に置くと、関数のコードと分けて更新できます。
- **`bootstrap`**: Lambda が起動する実行可能ファイルです（`chmod +x` が必要）。

[^byol]: Lambda のドキュメントでは、customer-managed runtime の追加に合わせて、この方式の名前が「カスタムランタイム」から「bring your own language」に変わりました。

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

`PHAMBDA_MAX_INVOCATIONS` などでプロセスを入れ替える場合は、PHP を `while` ループで起動し直す `bootstrap` にします（[プロセスを入れ替える](runtime.md#プロセスを入れ替える)）。

関数の設定:

- ランタイム: `provided.al2023`
- ハンドラー: この方式では使われないので、任意の値（例: `bootstrap`）

PHP を更新するには、新しいバイナリで Layer を作り直し、関数ごとに新しい Layer のバージョンを指定し直します。

## 環境変数

`AWS_LAMBDA_RUNTIME_API` などは Lambda が設定します。
必要に応じて `LOG_TIMEZONE`（ログのタイムゾーン、デフォルト: UTC）などを設定してください。設定できる環境変数は、[ランタイムの動き](runtime.md#環境変数)にまとめています。

## 起きやすいエラー

| エラー | 考えられる原因 | 確認すること |
| ------ | -------------- | ------------ |
| `create-runtime-version` で `Lambda does not have permission to access the ECR image` | ECR のリポジトリのポリシーで、Lambda に読み取りを許可していない | ポリシーの `Action` と、`aws:SourceArn` がランタイムの ARN（`arn:aws:lambda:<リージョン>:<アカウント>:runtime/*`）になっているか確認 |
| `Runtime.InvalidEntrypoint` | `bootstrap` が無い、実行権限が無い、または `ENTRYPOINT` がシェル形式 | static-php-cli の場合は、`bootstrap` が zip の直下にあり、`chmod +x` されているか確認。customer-managed runtime の場合は、イメージの `/var/runtime/bootstrap` の実行権限と、`ENTRYPOINT` が exec 形式（JSON の配列）か確認 |
| `Runtime.ExitError` | PHP のプロセスが終了した（PHP のパス違い、ハンドラーの指定違い、致命的エラーなど） | `bootstrap` の PHP のパス、関数のハンドラー、CloudWatch Logs を確認。プロセスの入れ替えを有効にしている場合は、`bootstrap` が PHP をループで起動し直しているか確認 |

Phambda がどのエラーを Lambda にどう通知するかは、[エラー処理](error-handling.md)にまとめています。
デプロイする前に手元で動かす方法は、[ローカルで動かす](local.md)を参照してください。
