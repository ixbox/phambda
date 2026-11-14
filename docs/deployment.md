# デプロイ

Phambda は Lambda のカスタムランタイム（`provided.al2023`）で動きます。[static-php-cli](https://static-php.dev/) の静的ビルドを使えば、コンテナイメージを作らずに zip でデプロイできます。

関数に必要なものは次の 3 つです。

- **PHP バイナリ**: static-php-cli の静的ビルド（例: `https://dl.static-php.dev/static-php-cli/common/php-8.5.8-cli-linux-aarch64.tar.gz`）。関数のアーキテクチャ（arm64 なら `aarch64`、x86_64 なら `x86_64`）に合わせます。Layer に置くと、関数のコードと分けて更新できます。
- **アプリケーション**: エントリーポイントの PHP ファイルと `vendor/`。`composer install --no-dev --optimize-autoloader` で作ります。Phambda は PSR-7 / PSR-17 / PSR-18 の実装を同梱しないので、使う実装（例: `nyholm/psr7` と `php-http/curl-client`）をアプリケーションの `require` に追加してください。使う実装に必要な拡張（`php-http/curl-client` なら `curl`）がバイナリに含まれていることも確認してください。アプリケーションで php-http/discovery の Composer プラグインを許可している場合（`composer config allow-plugins.php-http/discovery true`）は、実装が入っていなければ自動でインストールされます。
- **`bootstrap`**: Lambda が起動する実行可能ファイルです（`chmod +x` が必要）。

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

関数の設定:

- ランタイム: `provided.al2023`
- ハンドラー: カスタムランタイムでは使われないので、任意の値（例: `bootstrap`）
- 環境変数: `AWS_LAMBDA_RUNTIME_API` などは Lambda が設定します。必要に応じて `LOG_TIMEZONE`（ログのタイムゾーン、デフォルト: UTC）を設定してください

## カスタムランタイムで起きやすいエラー

| エラー                      | 考えられる原因                                                       | 確認すること                                                 |
| --------------------------- | -------------------------------------------------------------------- | ------------------------------------------------------------ |
| `Runtime.InvalidEntrypoint` | `bootstrap` が無い、または実行権限が無い                             | `bootstrap` が zip の直下にあり、`chmod +x` されているか確認 |
| `Runtime.ExitError`         | PHP のプロセスが終了した（PHP バイナリのパス違い、致命的エラーなど） | `bootstrap` のパスと CloudWatch Logs を確認                  |

Phambda がどのエラーを Lambda にどう通知するかは、[エラー処理](error-handling.md)にまとめています。
