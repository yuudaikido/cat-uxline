<?php
declare(strict_types=1);

namespace Cat;

/**
 * 原理②：オーバーレイグラフ。
 * 大域不変条件（TX・リトライ・検証…）を、基底コードに混ぜず上から敷く網。
 * 実体は「callable を包んで callable を返す」名前付きラッパー。
 *
 * 猫の規則：重ね順は Overlay::compose() で関係レイヤーに実体化して名前を付ける。
 * 野放しの多段重ねは Relation::within() 側で拒否される。
 */
final class Overlay
{
    /** @param \Closure(callable):callable $wrapper */
    private function __construct(
        public readonly string $name,
        private readonly \Closure $wrapper,
    ) {}

    /** 汎用：任意の網を定義する */
    public static function wrap(string $name, callable $wrapper): self
    {
        Registry::register($name, 'overlay', 'custom');
        return new self($name, $wrapper(...));
    }

    /** リトライ網 */
    public static function retry(string $name, int $max): self
    {
        Registry::register($name, 'overlay', "retry(max={$max})");
        return new self($name, function (callable $next) use ($max): callable {
            return function (mixed $in) use ($next, $max): mixed {
                $attempt = 0;
                while (true) {
                    try {
                        return $next($in);
                    } catch (\Throwable $e) {
                        if (++$attempt >= $max) {
                            throw $e;
                        }
                    }
                }
            };
        });
    }

    /**
     * TX境界網（デモ用の擬似実装：begin/commit/rollback をログに記録する）。
     * 本物では PDO などのドライバを注入する。
     */
    public static function tx(string $name, TxDriver $driver): self
    {
        Registry::register($name, 'overlay', 'tx');
        return new self($name, function (callable $next) use ($driver): callable {
            return function (mixed $in) use ($next, $driver): mixed {
                $driver->begin();
                try {
                    $result = $next($in);
                    $driver->commit();
                    return $result;
                } catch (\Throwable $e) {
                    $driver->rollback();
                    throw $e;
                }
            };
        });
    }

    /**
     * 関係レイヤー：頻出の重ね順を実体化して名前を付ける（猫の規則の出口）。
     * $overlays は外側→内側の順で渡す。
     * 例 compose('retryOutsideTx_rel_1', [$retry, $tx]) → retry( tx( core ) )
     */
    public static function compose(string $name, array $overlays): self
    {
        if (count($overlays) < 2) {
            throw new CatRuleViolation("関係レイヤー '{$name}' は2枚以上の重ねを実体化するためのもの");
        }
        foreach ($overlays as $o) {
            if (!$o instanceof self) {
                throw new CatRuleViolation("関係レイヤー '{$name}' に Overlay 以外が混入");
            }
        }
        $parts = implode(' > ', array_map(fn(self $o) => $o->name, $overlays));
        Registry::register($name, 'overlay.rel', $parts);

        return new self($name, function (callable $next) use ($overlays): callable {
            // 内側（配列末尾）から巻いていく＝配列先頭が最外殻になる
            foreach (array_reverse($overlays) as $o) {
                $next = $o->apply($next);
            }
            return $next;
        });
    }

    /** コア処理を網で包む */
    public function apply(callable $core): callable
    {
        return ($this->wrapper)($core);
    }
}

/** TXドライバの契約（事実系はインターフェースで注入、の縮小版） */
interface TxDriver
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
}
