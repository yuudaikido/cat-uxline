<?php
declare(strict_types=1);

require __DIR__ . '/../src/Registry.php';
require __DIR__ . '/../src/Overlay.php';
require __DIR__ . '/../src/Relation.php';
require __DIR__ . '/../src/Inject.php';

use Cat\{Registry, Relation, Overlay, Inject, CatRuleViolation, TxDriver};

// ---- 依存ゼロの極小テストランナー -----------------------------------------
$pass = 0; $fail = 0;
function test(string $label, callable $fn): void {
    global $pass, $fail;
    Registry::reset();
    try { $fn(); echo "  ✅ {$label}\n"; $pass++; }
    catch (\Throwable $e) { echo "  ❌ {$label}\n     → {$e->getMessage()}\n"; $fail++; }
}
function assertSame(mixed $exp, mixed $got, string $why = ''): void {
    if ($exp !== $got) throw new \RuntimeException(
        "期待 " . var_export($exp, true) . " / 実際 " . var_export($got, true) . " {$why}");
}
function assertThrows(string $class, callable $fn): \Throwable {
    try { $fn(); } catch (\Throwable $e) {
        if ($e instanceof $class) return $e;
        throw new \RuntimeException("期待した例外 {$class} でなく " . $e::class);
    }
    throw new \RuntimeException("例外 {$class} が投げられなかった");
}

// ---- デモ用の葉（純粋な仕事） -----------------------------------------------
$readData  = fn(?array $in) => ['raw' => '120.5'];                       // readData_seq_1 相当
$parseData = fn(array $in)  => ['value' => (float) $in['raw']];          // parseData_seq_2 相当
$persist   = fn(array $in)  => $in + ['persisted' => true];              // persistData_tx_1 相当

// TXドライバの偽物（記録するだけ）
final class FakeTx implements TxDriver {
    public array $log = [];
    public function begin(): void    { $this->log[] = 'begin'; }
    public function commit(): void   { $this->log[] = 'commit'; }
    public function rollback(): void { $this->log[] = 'rollback'; }
}

// 注入デモ用トレイト
trait LoggerTrait {
    public array $lines = [];
    public function log(string $m): string { $this->lines[] = $m; return "[log] {$m}"; }
}

echo "\n== CAT core — 原理①②③＋猫の規則の最小検証 ==\n\n";

// 1. 原理③：seq は順に流れ、前段の戻り値が次段に渡る
test('Relation::seq — 呼び出し順と値の連鎖', function () use ($readData, $parseData) {
    $r = Relation::seq('fetchParse_seq_1', [$readData, $parseData]);
    assertSame(120.5, $r(null)['value']);
});

// 2. 原理③：ネスト（共有ありDAG）— 下位関係関数を上位が部品として使う
test('Relation ネスト — 共有ありDAG', function () use ($readData, $parseData, $persist) {
    $fetchParse = Relation::seq('fetchParse_seq_1', [$readData, $parseData]);
    $journey    = Relation::seq('journeyTop_seq_1', [$fetchParse, $persist]);
    $out = $journey(null);
    assertSame(true, $out['persisted']);
    assertSame(120.5, $out['value']);
});

// 3. 原理①：住所式でない名前は構築自体が不可能
test('住所式命名の機械執行 — 違反名は構築できない', function () use ($readData) {
    assertThrows(CatRuleViolation::class,
        fn() => Relation::seq('fetchAndParse', [$readData]));   // 形式違反
});

// 4. 原理①：名前重複も構築不可（一意性は機械が保証）
test('名前重複の拒否', function () use ($readData) {
    Relation::seq('fetchParse_seq_1', [$readData]);
    assertThrows(CatRuleViolation::class,
        fn() => Relation::seq('fetchParse_seq_1', [$readData]));
});

// 5. 猫の規則：within() に2枚直渡しはエラー、composeで関係レイヤー化すれば通る
test('猫の規則 — 野放しの重ねは拒否、関係レイヤーは受理', function () use ($persist) {
    $tx    = Overlay::tx('txNet_ovl_1', new FakeTx());
    $retry = Overlay::retry('retryNet_ovl_1', 3);
    $r     = Relation::seq('persistOnly_seq_1', [$persist]);

    assertThrows(CatRuleViolation::class, fn() => $r->within($tx, $retry)); // 2枚直渡し

    $rel = Overlay::compose('txOutsideRetry_rel_1', [$tx, $retry]);          // 実体化
    assertSame(true, $r->within($rel)(['value' => 1.0])['persisted']);
});

// 6. 観測が合成を確定する — 同じ網でも重ね順で挙動（ログ）が物理的に変わる
test('重ね順の意味論 — retry外側TX内側 vs TX外側retry内側', function () {
    $flakyFactory = function (): callable {          // 2回失敗して3回目に成功する葉
        $n = 0;
        return function ($in) use (&$n) {
            if (++$n < 3) throw new \RuntimeException('flaky');
            return 'ok';
        };
    };

    // 観測点A：retryが外・TXが内 → 失敗のたびrollbackして丸ごとやり直す（安全型）
    $txA = new FakeTx();
    $ovlA = Overlay::compose('retryOutsideTx_rel_1', [
        Overlay::retry('retryA_ovl_1', 3),
        Overlay::tx('txA_ovl_1', $txA),
    ]);
    $rA = Relation::seq('flakyA_seq_1', [$flakyFactory()])->within($ovlA);
    assertSame('ok', $rA(null));
    assertSame(['begin','rollback','begin','rollback','begin','commit'], $txA->log);

    // 観測点B：TXが外・retryが内 → 1つのTXの中で重ね打ち（危険型：ログが全く別物）
    $txB = new FakeTx();
    $ovlB = Overlay::compose('txOutsideRetry_rel_2', [
        Overlay::tx('txB_ovl_1', $txB),
        Overlay::retry('retryB_ovl_1', 3),
    ]);
    $rB = Relation::seq('flakyB_seq_1', [$flakyFactory()])->within($ovlB);
    assertSame('ok', $rB(null));
    assertSame(['begin','commit'], $txB->log);
});

// 7. 観測ごとの束縛 — within は定義を汚さない（同じRelationを別文脈で別合成）
test('観測点ごとの束縛の独立性', function () use ($persist) {
    $tx1 = new FakeTx(); $tx2 = new FakeTx();
    $r = Relation::seq('persistShared_seq_1', [$persist]);
    $a = $r->within(Overlay::tx('txX_ovl_1', $tx1));
    $b = $r->within(Overlay::tx('txY_ovl_1', $tx2));
    $a(['value' => 1.0]); $a(['value' => 2.0]); $b(['value' => 3.0]);
    assertSame(['begin','commit','begin','commit'], $tx1->log);
    assertSame(['begin','commit'], $tx2->log);
});

// 8. 注入関数 — トレイトを「変数に入る値」へ。出所はRegistryで追える
test('Inject::with — トレイトの実行時注入（公式の一箇所）', function () {
    $logger = Inject::with('logger_inj_1', LoggerTrait::class);
    assertSame('[log] hello', $logger->log('hello'));
    assertSame(['hello'], $logger->lines);
    assertSame(true, in_array('logger_inj_1', Registry::names(), true));
});

// 9. REGISTRY.md 自動生成 — 手動更新の消滅
test('Registry::dump — 対応表の自動生成', function () use ($readData, $parseData) {
    $fp = Relation::seq('fetchParse_seq_1', [$readData, $parseData]);
    Relation::seq('journeyTop_seq_1', [$fp]);
    Overlay::retry('retryNet_ovl_1', 3);
    $md = Registry::dump();
    assertSame(true, str_contains($md, '| journeyTop_seq_1 | relation.seq | 1 steps (nested: fetchParse_seq_1) |'));
    assertSame(true, str_contains($md, '| retryNet_ovl_1 | overlay | retry(max=3) |'));
});

echo "\n結果: {$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
