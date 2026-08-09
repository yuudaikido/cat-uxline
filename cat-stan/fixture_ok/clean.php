<?php
declare(strict_types=1);

namespace App\Lines\MonthlyReading\Adapts;

use Cat\Relation;
use Cat\Overlay;

final class CleanAdapt
{
    public function store(array $request): array
    {
        $record = ['consumer_id' => (int) $request['consumer_id']];
        $result = ['ok' => true];
        return ['status' => 200, 'body' => $result];
    }
}

$read  = fn($in) => ['raw' => '120.5'];
$parse = fn($in) => ['value' => (float) $in['raw']];
$ok1 = Relation::seq('fetchParse_seq_1', [$read, $parse]);
$ok2 = Overlay::retry('retryNet_ovl_1', 3);
