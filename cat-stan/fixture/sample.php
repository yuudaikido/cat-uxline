<?php
declare(strict_types=1);

namespace App\Lines\MonthlyReading;

use Cat\Relation;
use Cat\Overlay;
use Cat\Inject;

$read  = fn($in) => ['raw' => '120.5'];
$parse = fn($in) => ['value' => (float) $in['raw']];

// OK: 住所式
$ok = Relation::seq('fetchParse_seq_1', [$read, $parse]);

// NG①: 形式違反（キャメルのみ・連番なし）
$bad1 = Relation::seq('fetchAndParse', [$read, $parse]);

// NG②: 変数経由（追跡不能）
$name = 'fetchParse_seq_2';
$bad2 = Relation::seq($name, [$read, $parse]);

// NG③: Overlay側の形式違反
$bad3 = Overlay::retry('RETRY-NET', 3);

// NG④: Inject側の形式違反
// $bad4 = Inject::with('myLogger', SomeTrait::class);
