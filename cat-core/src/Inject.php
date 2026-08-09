<?php
declare(strict_types=1);

namespace Cat;

/**
 * 注入関数：能力エッジの実体化。
 * 「関数内・変数内にトレイトを敷く」要求への公式回答。
 *
 * 無名クラス＋トレイトの実行時合成を、この一箇所にだけ許可する。
 * - 出所が常に住所式の名前で追える（Registry 強制登録）
 * - 野放しの実行時注入（Closure::bind 芸・任意 eval）はフレームワーク外＝規約違反
 */
final class Inject
{
    /** trait名 → 生成ファクトリ のキャッシュ（evalは trait につき1回だけ走る） */
    private static array $factories = [];

    /**
     * トレイトを焼き込んだ即席オブジェクトを生成し、変数に入る「値」として返す。
     *
     *   $logger = Inject::with('logger_inj_1', LoggerTrait::class);
     *   $logger->log('...');
     */
    public static function with(string $name, string $trait): object
    {
        Registry::register($name, 'inject', $trait);

        if (!trait_exists($trait)) {
            throw new CatRuleViolation("注入対象 '{$trait}' はトレイトとして存在しない");
        }
        // eval に渡す識別子を厳格に検証（英数・アンダースコア・名前空間区切りのみ）
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $trait)) {
            throw new CatRuleViolation("不正なトレイト名: {$trait}");
        }

        self::$factories[$trait] ??= eval(
            'return static fn(): object => new class { use \\' . $trait . '; };'
        );

        return (self::$factories[$trait])();
    }
}
