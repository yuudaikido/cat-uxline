<?php
declare(strict_types=1);

namespace Cat;

/**
 * 原理③：エッジ実体化（関係関数）。
 * 「常にこの順・このセットで呼ばれる」列に名前を与えて一級の値にする。
 *
 * - 上層＝純粋な組み立て（このクラス）、葉＝純粋な仕事（callable）
 * - ステップに Relation を入れられる＝ネスト可（共有ありDAG）
 * - within() は Overlay を1枚しか受けない：
 *   2枚以上を重ねたければ Overlay::compose() で関係レイヤーに実体化せよ（猫の規則）
 */
final class Relation
{
    /** @var array<callable|Relation> */
    private array $steps;
    private ?Overlay $overlay = null;

    private function __construct(
        public readonly string $name,
        array $steps,
    ) {
        $this->steps = $steps;
    }

    /** 順次関係：steps を先頭から適用し、前段の戻り値を次段へ流す */
    public static function seq(string $name, array $steps): self
    {
        if ($steps === []) {
            throw new CatRuleViolation("関係関数 '{$name}' にステップがない");
        }
        foreach ($steps as $i => $s) {
            if (!is_callable($s) && !$s instanceof self) {
                throw new CatRuleViolation("関係関数 '{$name}' のステップ{$i}が callable でも Relation でもない");
            }
        }
        $meta = count($steps) . ' steps'
            . (($nested = array_filter($steps, fn($s) => $s instanceof self))
                ? ' (nested: ' . implode(',', array_map(fn(self $r) => $r->name, $nested)) . ')'
                : '');
        Registry::register($name, 'relation.seq', $meta);
        return new self($name, $steps);
    }

    /**
     * 観測点が合成を確定する：この Relation をどの網の下で実行するかは
     * 定義側でなく呼び出し文脈が within() で宣言する。
     *
     * 猫の規則の機械執行：Overlay は1枚のみ。
     * 重ねたい場合は Overlay::compose() で関係レイヤーとして名前を付けてから渡す。
     */
    public function within(Overlay ...$overlays): self
    {
        if (count($overlays) !== 1) {
            throw new CatRuleViolation(
                "猫の規則違反: within() に" . count($overlays) . "枚。重ね順は Overlay::compose() で"
                . "関係レイヤーに実体化して1枚で渡す"
            );
        }
        $bound = clone $this;              // 定義は不変、観測ごとの束縛は複製に持たせる
        $bound->overlay = $overlays[0];
        return $bound;
    }

    public function __invoke(mixed $input = null): mixed
    {
        $core = function (mixed $in): mixed {
            foreach ($this->steps as $step) {
                $in = $step($in);
            }
            return $in;
        };
        $fn = $this->overlay?->apply($core) ?? $core;
        return $fn($input);
    }
}
