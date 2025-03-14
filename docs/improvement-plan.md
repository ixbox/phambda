# Phambda 改善計画

## 基本方針

Phambda は、AWS Lambda Function を PHP で簡単に実装するためのライブラリです。以下の方針に基づいて改善を進めます：

- シンプルな実装の維持
- 最小限の依存関係
- AWS Lambda 環境での最適化
- PSR 標準への準拠（PSR-7, PSR-15, PSR-11）

## アーキテクチャ

### 1. コアライブラリ（src/直下）

- 基本的な Lambda イベント処理
- SQS、EventBridge などのイベントソースとの連携
- Lambda 実行環境情報の管理

### 2. HTTP ライブラリ（src/Http/）

- API Gateway、Lambda Function URL、ALB との統合
- PSR-7/PSR-15 準拠のインターフェース
- リクエスト/レスポンスの変換処理

## 改善内容

### 1. エラーハンドリング強化

#### 目的

- Lambda 環境での例外を適切にハンドリング
- クライアントへの明確なエラー情報の提供
- デバッグ効率の向上

#### エラーの分類

1. ライブラリ内部のエラー

   - Lambda Runtime API との通信エラー
   - イベント/レスポンスの変換エラー

2. ユーザーアプリケーションのエラー
   - ハンドリングされていない例外
   - 500 エラーとして処理

#### コンポーネント構成

1. Exception クラス群

```php
namespace Phambda\Exception;

abstract class PhambdaException extends \RuntimeException
{
    public function __construct(
        string $message,
        private array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

2. エラーハンドラー

```php
namespace Phambda\ErrorHandler;

interface ErrorHandlerInterface
{
    /**
     * エラーを処理し、適切なレスポンスを生成
     *
     * @param \Throwable $error 発生したエラー
     * @param array $context 追加のコンテキスト情報
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handle(\Throwable $error, array $context = []): ResponseInterface;
}
```

#### 実装方針

1. エラー処理の一元化

   - エラーハンドラーによる統一的な処理
   - 環境に応じた出力制御
   - コンテキスト情報の体系的な収集

2. エラーレスポンスの標準化

   ```php
   {
       "error": {
           "code": "ERROR_CODE",
           "message": "エラーメッセージ",
           "context": {
               // 開発環境でのみ表示
               "error_details": "..."
           }
       }
   }
   ```

3. カスタマイズのサポート
   - ユーザー定義のエラーハンドラーが可能
   - 環境に応じた出力制御
   - エラー処理の拡張性

### 2. ログレベルの最適化

#### PSR-3 準拠のログレベル使用方針

- EMERGENCY: システム全体が機能停止する重大な状況
- ALERT: Lambda Runtime API との通信断絶
- CRITICAL: 初期化エラーの通知失敗
- ERROR: レスポンス送信失敗、変換失敗等
- WARNING: 想定外だが致命的でない問題
- NOTICE: 重要な処理の開始・完了
- INFO: 通常の処理の記録
- DEBUG: 詳細なデバッグ情報

#### ログ出力の基本方針

- 構造化ログフォーマットの使用
- コンテキスト情報の適切な付与
- トレーサビリティの確保

### 3. PSR 標準の活用

#### PSR-15: HTTP Server Request Handlers

- RequestHandlerInterface の実装
- 標準的なリクエスト処理フロー
- ミドルウェアチェーンのサポート

#### PSR-7: HTTP Message Interface

- ServerRequestInterface によるリクエスト処理
- ResponseInterface によるレスポンス生成
- Lambda 固有情報のリクエスト属性としての提供

#### PSR-11: Container Interface

- 依存性注入のサポート
- コンテナによる依存関係の解決
- テスト容易性の向上

### 4. ドキュメント整備

#### 実装ガイド

- エラーハンドリングの方法
- ログレベルの使用ガイドライン
- PSR インターフェースの活用例

#### トラブルシューティング

- 一般的なエラーと解決方法
- ログの見方と活用方法
- デバッグのベストプラクティス

## 技術的な制約事項

### 1. メモリ使用量と実行時間

- Lambda 最大実行時間: 15 分以内での処理完了

### 2. 依存関係

- PSR 関連パッケージの最小限の使用
- サードパーティライブラリの厳選
- アプリケーションレイヤーとの明確な責務分離

## セキュリティ考慮事項

### 1. エラー処理とログ出力

- 機密情報の適切なマスキング
- CloudWatch Logs での安全なログ管理
- エラーメッセージの適切な抽象化

### 2. リクエストコンテキストの活用

#### 概要

- ServerRequestInterface のアトリビュートとして Lambda コンテキストを提供
  - `awsRequestId`: リクエストの一意識別子
  - `lambda-context`: Context オブジェクト全体

#### 設計方針

- シンプルなアクセス方法の提供
- PSR-7 標準の活用
- ユーザー側での柔軟な実装をサポート

## 運用考慮事項

### 1. モニタリングと可観測性

- CloudWatch Metrics による基本的なメトリクス収集
- 構造化ログによる効率的なログ分析

### 2. デバッグとトラブルシューティング

- 環境変数による動的なログレベル制御
- リクエスト ID による分散トレーシング
- エラースタックトレースの適切な取り扱い
