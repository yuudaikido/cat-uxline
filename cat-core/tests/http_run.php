<?php
declare(strict_types=1);

require __DIR__ . '/../src/Registry.php';
require __DIR__ . '/../src/Overlay.php';
require __DIR__ . '/../src/Relation.php';
require __DIR__ . '/../src/Inject.php';
require __DIR__ . '/../src/Http/Request.php';
require __DIR__ . '/../src/Http/Response.php';
require __DIR__ . '/../src/Http/Router.php';

use Cat\{Registry, Relation, Overlay, CatRuleViolation, TxDriver};
use Cat\Http\{Request, Response, Router};

$pass = 0; $fail = 0;
function test(string $label, callable $fn): void {
    global $pass, $fail;
    Registry::reset();
    try { $fn(); echo "  ✅ {$label}\n"; $pass++; }
    catch (\Throwable $e) { echo "  ❌ {$label}\n     → {$e->getMessage()}\n"; $fail++; }
}
function assertSame(mixed $exp, mixed $got): void {
    if ($exp !== $got) throw new \RuntimeException(
        '期待 ' . var_export($exp, true) . ' / 実際 ' . var_export($got, true));
}
function assertThrows(string $class, callable $fn): void {
    try { $fn(); } catch (\Throwable $e) {
        if ($e instanceof $class) return;
        throw new \RuntimeException("期待 {$class} でなく " . $e::class);
    }
    throw new \RuntimeException("例外 {$class} が投げられなかった");
}

echo "\n== CAT v0.2 — HTTP核（ルート＝観測点）の検証 ==\n\n";

// 1. Request: 不変Record、withRouteParams は新インスタンス
test('Request — 不変性とルートパラメータ付与', function () {
    $r1 = new Request('GET', '/readings/42', query: ['tab' => 'done']);
    $r2 = $r1->withRouteParams(['id' => '42']);
    assertSame([], $r1->routeParams);          // 元は汚れない
    assertSame('42', $r2->param('id'));
    assertSame('done', $r2->input('tab'));
});

// 2. Router: パスパラメータ抽出とディスパッチ
test('Router — {id} 抽出とディスパッチ', function () {
    $router = new Router();
    $router->get('readingShow_route_1', '/readings/{id}', function (Request $req): Response {
        return Response::json(['id' => $req->param('id')]);
    });
    $res = $router->dispatch(new Request('GET', '/readings/42'));
    assertSame(200, $res->status);
    assertSame('{"id":"42"}', $res->body);
});

// 3. Router: 観測点にも住所式命名が強制される
test('Router — ルート名の住所式強制', function () {
    $router = new Router();
    assertThrows(CatRuleViolation::class,
        fn() => $router->get('showReading', '/readings/{id}', fn(Request $r) => Response::text('x')));
});

// 4. Router: 404
test('Router — 未登録パスは404', function () {
    $router = new Router();
    $router->get('home_route_1', '/', fn(Request $r) => Response::text('home'));
    $res = $router->dispatch(new Request('GET', '/nothing'));
    assertSame(404, $res->status);
});

// 5. Adapt契約: Response以外を返すと違反
test('Adapt契約 — Response以外の返却は CatRuleViolation', function () {
    $router = new Router();
    $router->get('bad_route_1', '/bad', fn(Request $r) => ['not' => 'response']);
    assertThrows(CatRuleViolation::class,
        fn() => $router->dispatch(new Request('GET', '/bad')));
});

// 6. 統合: 観測点 → Adapt → 関係関数DAG（網つき） → Response
final class LogTx implements TxDriver {
    public array $log = [];
    public function begin(): void    { $this->log[] = 'begin'; }
    public function commit(): void   { $this->log[] = 'commit'; }
    public function rollback(): void { $this->log[] = 'rollback'; }
}
test('統合 — 観測点でwithin束縛したDAGがHTTPに応答', function () {
    // 葉（純粋な仕事）: F1使用量計算の簡易形（今回<前回なら1周とみなす）
    $computeUsage = function (array $in): array {
        $curr = $in['curr']; $prev = $in['prev'];
        $usage = $curr >= $prev ? $curr - $prev : (10000 + $curr) - $prev;
        return $in + ['usage' => round($usage, 1)];
    };
    $persist = fn(array $in) => $in + ['persisted' => true];

    $store = Relation::seq('readingStore_seq_1', [$computeUsage, $persist]);
    $tx    = new LogTx();
    $net   = Overlay::compose('retryOutsideTx_rel_1', [
        Overlay::retry('retryNet_ovl_1', 3),
        Overlay::tx('txNet_ovl_1', $tx),
    ]);

    $router = new Router();
    // 観測点＝ルート。ここで within を宣言（猫の規則がHTTP層の文法に）
    $router->post('readingStore_route_1', '/readings', function (Request $req) use ($store, $net): Response {
        $record = ['curr' => (float) $req->input('curr'), 'prev' => (float) $req->input('prev')];
        $result = $store->within($net)($record);
        return Response::json(['usage' => $result['usage'], 'persisted' => $result['persisted']]);
    });

    // 通常ケース
    $res = $router->dispatch(new Request('POST', '/readings', body: ['curr' => '120.5', 'prev' => '100.0']));
    assertSame('{"usage":20.5,"persisted":true}', $res->body);
    // メーター1周ケース（今回<前回）
    $res2 = $router->dispatch(new Request('POST', '/readings', body: ['curr' => '5.0', 'prev' => '9990.0']));
    assertSame('{"usage":15,"persisted":true}', $res2->body);
    // TX網も動いていた
    assertSame(['begin', 'commit', 'begin', 'commit'], $tx->log);
});

// 7. Registry: 観測点も台帳に載る
test('Registry — ルート（観測点）の自動登録', function () {
    $router = new Router();
    $router->get('readingShow_route_1', '/readings/{id}', fn(Request $r) => Response::text('x'));
    assertSame(true, str_contains(Registry::dump(), '| readingShow_route_1 | route | GET /readings/{id} |'));
});

echo "\n結果: {$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
