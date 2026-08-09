# CAT / UXライン — 成果物バンドル

独自考案アーキテクチャ **UXライン**（マクロ：ジャーニーで縦切り）× **CAT**（ミクロ：ノードとN枚のグラフ）と、
その実装であるフレームワークのタネ一式。2026-07-31〜08-04 の探求セッションの成果。

## 同梱物

```
cat-uxline/
├── README.md                        ← 本書
├── docs/
│   ├── uxline_cat_architecture.svg  ← アーキテクチャ一式の説明図
│   └── uxline_cat_rebuild_指示書.md ← Cowork向け再構築実験の指示書（Phase 0承認済み）
├── cat-core/                        ← フレームワーク本体（v0.2）
│   ├── src/
│   │   ├── Registry.php             ← 原理①住所式命名の門番＋台帳自動生成
│   │   ├── Relation.php             ← 原理③関係関数（共有ありDAG）＋猫の規則の機械執行
│   │   ├── Overlay.php              ← 原理②網（retry/tx）＋関係レイヤー（compose）
│   │   ├── Inject.php               ← 注入関数（トレイト実行時注入の公式の一箇所）
│   │   └── Http/
│   │       ├── Request.php          ← 不変Record（fromGlobals / withRouteParams）
│   │       ├── Response.php         ← 不変Record（html/json/text）
│   │       └── Router.php           ← ルート＝観測点。ルート名も住所式強制、Adapt契約執行
│   ├── tests/
│   │   ├── run.php                  ← コア9テスト
│   │   └── http_run.php             ← HTTP核7テスト
│   └── demo/public/index.php        ← 観測ゲートウェイ（実HTTP応答デモ）
└── cat-stan/                        ← 静的検査（PHPStanカスタムルール）
    ├── rules/
    │   ├── AddressNamingRule.php    ← 住所式命名の静的検査（変数経由の名前も検出）
    │   └── AdaptSizeRule.php        ← Adapt 10行規約（指揮ロジック混入の検出）
    ├── fixture/                     ← 違反サンプル（4違反を全検出）
    ├── fixture_ok/                  ← クリーンサンプル（誤検知ゼロ確認用）
    ├── phpstan.neon / autoload.php
    └── （phpstan.phar は同梱せず。下記コマンドで取得）
```

## 動かし方（PHP 8.3+ のみ。composer / Docker / Laravel 不要）

```bash
# テスト（16本）
cd cat-core
php tests/run.php        # コア: 9 passed
php tests/http_run.php   # HTTP核: 7 passed

# 実HTTPデモ
php -S 127.0.0.1:8123 -t demo/public
curl http://127.0.0.1:8123/                                        # → it's alive 🐈‍⬛
curl -X POST http://127.0.0.1:8123/readings -d "curr=120.5&prev=100.0"
#   → {"usage":20.5,"persisted":true}（F1使用量計算の簡易形＋retry外TX内の網）
curl http://127.0.0.1:8123/registry                                # → 台帳の自己申告

# 静的検査
cd ../cat-stan
curl -sL -o phpstan.phar https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar
php phpstan.phar analyse -c phpstan.neon -a autoload.php --no-progress
#   → fixture/ の4違反を検出（fixture_ok/ は No errors）
```

## 設計思想の最小要約

- **UXライン**：ジャーニー（ユーザーの物語）で縦切り。共有ロジックは事実系（DI契約・一斉切替）／体験系（enum・各自乗換）に二分
- **CAT**：様式は存在しない。1つのノード集合とN枚のグラフがあるだけ
  - 原理① 住所式命名（一意性＝機械、意味＝台帳）
  - 原理② オーバーレイグラフ（大域不変条件は上から敷く網）
  - 原理③ エッジ実体化（関係関数DAG。上層＝組み立てのみ、葉＝仕事のみ）
  - 猫の規則：重ね順は観測点が確定する。頻出順は関係レイヤーに実体化
- **規約の二段防衛**：静的（cat-stan：書く瞬間）＋動的（Registry：動く瞬間）
- **MVC対応**：Controller→Adapt（変換のみ・10行規約）、Model→三分割（Records/Data/Services）

## 原典（Notion）

- 🏗️ UXラインアーキテクチャ： https://app.notion.com/p/3aeb359164e3819cb32edd954bebcb63
- 🐈‍⬛ CATアーキテクチャ＋実装ステータス： https://app.notion.com/p/3afb359164e38122bd0bf4d5a20de36c
- 🔬 CAT独立計画書（MVC対応表・大原則）： https://app.notion.com/p/3b2b359164e381abbc79fa18b087e001
- 📋 PACKAGE_LEDGER（Laravel 12全依存の判定75件＋エコシステム編入候補）： https://app.notion.com/p/3b3b359164e381079330e4ac8cad5720

## 現在地とロードマップ

- [x] v0.1 コア（Relation / Overlay / Inject / Registry）— 9テスト
- [x] cat-stan v0.1（静的検査2ルール）
- [x] v0.2 HTTP核（Request/Response/Router＝観測点）— 実HTTP応答
- [ ] v0.3 Data+TX（PdoTxDriver・PDO封印地・Records）
- [ ] v0.4 View＋セキュリティ4点（自動エスケープ・CSRF網・セッション硬化・プリペアド強制）
- [ ] v0.5 J1移植（検針登録ジャーニー＝卒業試験）
- [ ] v0.6 FactBind＋cat CLI（make:line / registry:dump）
