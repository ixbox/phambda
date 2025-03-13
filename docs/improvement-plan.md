# Phambda 改善計画

## 基本方針

Phambda は、AWS Lambda Function を PHP で簡単に実装するためのライブラリです。以下の方針に基づいて改善を進めます：

- シンプルな実装の維持
- 最小限の依存関係
- AWS Lambda 環境での最適化
- PSR 標準への準拠（PSR-7, PSR-11, PSR-15）

## 改善内容

### 1. エラーハンドリング強化

#### 目的

- Lambda 環境での例外を適切にハンドリング
- クライアントへの明確なエラー情報の提供
- デバッグ効率の向上

#### 実装方針

```php
// シンプルなエラーレスポンス構造
class ErrorResponse
{
    public function __construct(
        private int $statusCode,
        private string $message,
        private ?string $requestId = null
    ) {}
}

// 基本的なエラー種別
final class ErrorCode
{
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
}

// 最小限のエラーログ実装
class ErrorLogger
{
    public function logError(
        string $message,
        array $context = []
    ): void {
        error_log(json_encode([
            'message' => $message,
            'context' => $context,
        ]));
    }
}
```

### 2. ロギング機能

#### 目的

- CloudWatch Logs との効率的な連携
- トラブルシューティングの容易化
- パフォーマンス影響の最小化
- PSR-3 規格への完全準拠

#### 実装済みの機能

1. フォーマッター層の抽象化

   - LogFormatterInterface による出力形式の標準化
   - JSON 形式と LTSV 形式のサポート
   - 拡張可能なフォーマッター設計
   - ISO 8601 形式でのタイムスタンプ出力 (date('c'))

2. ログレベル管理

   - PSR-3 準拠のログレベル（DEBUG ～ EMERGENCY）
   - 最小ログレベルによるフィルタリング
   - ログレベルの数値化による効率的な比較

3. コンテキスト管理

   - デフォルトコンテキストのサポート
   - コンテキストのマージ機能
   - 構造化データの適切な処理

4. 実装クラス
   - AbstractLogger: 基本実装の提供
   - JsonLogger: JSON 形式での出力
   - LtsvLogger: LTSV 形式での出力

#### 設計上の利点

1. 拡張性

   - フォーマッターの追加が容易
   - PSR-3 互換の任意のロガーとの統合が可能
   - カスタムログレベルの追加サポート

2. 性能

   - 条件付きログ出力による処理の最適化
   - フォーマッター層での効率的なデータ変換
   - メモリ使用量の最小化

3. 運用性
   - 構造化ログによる検索性の向上
   - ログレベルによる重要度の明確化
   - 標準的なタイムスタンプフォーマット

#### 今後の展開

1. 出力先の多様化

   - CloudWatch Logs 以外の出力先対応
   - 非同期ログ出力のサポート
   - ログローテーション機能の検討

2. コンテキスト機能の拡張

   - リクエスト ID の自動付与
   - エラートレース情報の構造化
   - カスタムメタデータの柔軟な追加
   - タイムゾーン対応の強化
     - 設定可能なタイムゾーン
     - マルチリージョン対応
     - ログ集約時のタイムゾーン正規化

3. パフォーマンス最適化

   - バッファリング機能の実装
   - 高負荷時の出力制御
   - メモリ使用量の監視と制御

4. タイムスタンプの改善
   - ✅ カスタマイズ可能なタイムスタンプフォーマット (LoggerConfiguration)
   - ✅ タイムゾーン情報の明示的な付与 (RFC3339_EXTENDED)
   - ✅ システムのデフォルトタイムゾーンに依存しない実装 (UTC/環境変数/設定の優先順位付き)
   - マイクロ秒精度のサポート

#### 運用考慮事項

1. タイムゾーン管理
   - AWS Lambda 実行環境のタイムゾーン設定
   - アプリケーションログとシステムログの時刻同期
   - 異なるリージョン間でのログ統合時の時刻調整

### 3. パフォーマンス最適化

#### 目的

- コールドスタート時間の短縮
- メモリ使用量の最適化
- レスポンスタイムの改善

#### 実装方針

```php
// 軽量なレスポンス変換
class OptimizedResponseTransformer
{
    public function transform(ResponseInterface $response): array
    {
        return [
            'statusCode' => $response->getStatusCode(),
            'headers' => $this->transformHeaders($response->getHeaders()),
            'body' => (string) $response->getBody(),
        ];
    }
}

// メモリ効率の良いストリーム処理
class EfficientStreamHandler
{
    private const CHUNK_SIZE = 8192;

    public function handleStream(StreamInterface $stream): string
    {
        return $stream->getContents();
    }
}
```

### 4. テスト強化

#### 目的

- 信頼性の確保
- Lambda 環境での動作保証
- パフォーマンス特性の把握

#### テスト方針

```php
// Lambda環境を模擬した基本テスト
class LambdaEnvironmentTest extends TestCase
{
    public function testResponseGeneration(): void
    {
        $response = $this->handler->handle($request);
        $this->assertLessThan(256 * 1024, strlen(json_encode($response)));
    }
}

// メモリ使用量テスト
class MemoryUsageTest extends TestCase
{
    public function testMemoryEfficiency(): void
    {
        $initialMemory = memory_get_usage();
        $this->handler->handle($request);
        $usedMemory = memory_get_usage() - $initialMemory;
        $this->assertLessThan(50 * 1024 * 1024, $usedMemory);
    }
}
```

### 5. PSR 標準の活用

#### PSR-7: HTTP Message Interface

- リクエスト・レスポンスの標準化されたインターフェース
- Stream によるデータ処理の統一
- Immutable なメッセージ処理

#### PSR-11: Container Interface

- 依存性の注入と管理
- サービスコンテナの標準化
- 柔軟な拡張性の提供

#### PSR-15: HTTP Server Request Handlers

- ミドルウェアチェーンの実装（ユーザー側で実装可能）
- リクエストハンドラの標準化
- 認証、ログ、キャッシュなどの機能拡張

## 技術的な制約事項

### 1. メモリ使用量と実行時間

- 実行時の最大メモリ使用量: 128MB 以下
- 常駐メモリ: 50MB 以下
- Lambda 最大実行時間: 15 分以内での処理完了

### 2. パフォーマンス目標

- コールドスタート: 500ms 以下
- ウォームスタート: 100ms 以下
- メモリ解放の適切な実施

### 3. 依存関係

- PSR 関連パッケージの最小限の使用
- サードパーティライブラリの厳選
- アプリケーションレイヤーとの明確な責務分離

## セキュリティ考慮事項

### 1. 入力データの検証

- リクエストパラメータの適切なバリデーション
- エスケープ処理の徹底
- バイナリデータの安全な取り扱い

### 2. エラー処理とログ出力

- 機密情報の適切なマスキング
- CloudWatch Logs での安全なログ管理
- エラーメッセージの適切な抽象化

## 運用考慮事項

### 1. モニタリングと可観測性

- CloudWatch Metrics による基本的なメトリクス収集
  - メモリ使用量
  - 実行時間
  - エラー発生率
- 構造化ログによる効率的なログ分析
- X-Ray 連携のための基盤提供

### 2. デバッグとトラブルシューティング

- 環境変数による動的なログレベル制御
- リクエスト ID による分散トレーシング
- エラースタックトレースの適切な取り扱い
