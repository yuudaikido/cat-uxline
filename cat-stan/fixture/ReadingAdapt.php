<?php
declare(strict_types=1);

namespace App\Lines\MonthlyReading\Adapts;

final class ReadingAdapt
{
    /** OK: 変換だけの薄いAdapt（4文） */
    public function store(array $request): array
    {
        $record = ['consumer_id' => (int) $request['consumer_id']];
        $result = ['ok' => true];
        $response = ['status' => 200, 'body' => $result];
        return $response;
    }

    /** NG: 指揮ロジックが混入した太ったAdapt（10文超） */
    public function fatStore(array $request): array
    {
        $record = ['consumer_id' => (int) $request['consumer_id']];
        $prev = 100.0;
        $curr = (float) $request['value'];
        if ($curr < $prev) {
            $curr += 10000;
        }
        $usage = $curr - $prev;
        if ($usage > 500) {
            $flag = true;
        } else {
            $flag = false;
        }
        $result = ['usage' => $usage, 'flag' => $flag];
        $response = ['status' => 200, 'body' => $result];
        return $response;
    }
}
