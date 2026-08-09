<?php
declare(strict_types=1);

// 観測ゲートウェイ（Kernel/bootstrap の後継）
require __DIR__ . '/../../src/Registry.php';
require __DIR__ . '/../../src/Overlay.php';
require __DIR__ . '/../../src/Relation.php';
require __DIR__ . '/../../src/Inject.php';
require __DIR__ . '/../../src/Http/Request.php';
require __DIR__ . '/../../src/Http/Response.php';
require __DIR__ . '/../../src/Http/Router.php';

use Cat\{Relation, Overlay, Registry, TxDriver};
use Cat\Http\{Request, Response, Router};

// --- Domain（本来は Domain/ 配下。デモなので同居） ---
final class DemoTx implements TxDriver {
    public function begin(): void {} public function commit(): void {} public function rollback(): void {}
}
$computeUsage = function (array $in): array {   // 事実系F1の簡易形
    $usage = $in['curr'] >= $in['prev']
        ? $in['curr'] - $in['prev']
        : (10000 + $in['curr']) - $in['prev'];
    return $in + ['usage' => round($usage, 1)];
};
$persist = fn(array $in) => $in + ['persisted' => true];

// --- Lines/MonthlyReading（関係関数と網） ---
$store = Relation::seq('readingStore_seq_1', [$computeUsage, $persist]);
$net   = Overlay::compose('retryOutsideTx_rel_1', [
    Overlay::retry('retryNet_ovl_1', 3),
    Overlay::tx('txNet_ovl_1', new DemoTx()),
]);

// --- 観測点の宣言（routes.php 相当） ---
$router = new Router();

$router->get('home_route_1', '/', fn(Request $r) =>
    Response::text("CAT framework v0.2 — it's alive 🐈‍⬛"));

$router->post('readingStore_route_1', '/readings', function (Request $req) use ($store, $net): Response {
    $record = ['curr' => (float) $req->input('curr'), 'prev' => (float) $req->input('prev')];
    $result = $store->within($net)($record);
    return Response::json(['usage' => $result['usage'], 'persisted' => $result['persisted']]);
});

$router->get('registry_route_1', '/registry', fn(Request $r) =>
    Response::text(Registry::dump()));

// --- 観測 ---
$router->dispatch(Request::fromGlobals())->send();
