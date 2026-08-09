<?php
declare(strict_types=1);

namespace Cat;

/** CATの規約違反。規約は文書でなくエラーで語る */
final class CatRuleViolation extends \LogicException {}

/**
 * 原理①：住所式命名のレジストリ。
 * すべての実体化（Relation / Overlay / Inject）は構築時にここへ強制登録される。
 * 名前なしの関係は構築できない＝REGISTRY.md の手動更新（と陸腐）が消滅する。
 */
final class Registry
{
    /** @var array<string, array{kind:string, meta:string}> */
    private static array $entries = [];

    /** 住所式：name_type_number（例 readData_seq_1, txOutsideRetry_rel_1） */
    private const NAME_PATTERN = '/^[a-z][A-Za-z0-9]*_[a-z]+_[0-9]+$/';

    public static function register(string $name, string $kind, string $meta = ''): void
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new CatRuleViolation(
                "住所式命名違反: '{$name}' は name_type_number 形式ではない"
            );
        }
        if (isset(self::$entries[$name])) {
            throw new CatRuleViolation("名前重複: '{$name}' は登録済み（一意性は機械が保証する）");
        }
        self::$entries[$name] = ['kind' => $kind, 'meta' => $meta];
    }

    /** REGISTRY.md を自動生成する（cat registry:dump 相当） */
    public static function dump(): string
    {
        $md = "| 名前 | 種別 | メタ |\n|---|---|---|\n";
        foreach (self::$entries as $name => $e) {
            $md .= "| {$name} | {$e['kind']} | {$e['meta']} |\n";
        }
        return $md;
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::$entries);
    }

    /** テスト用 */
    public static function reset(): void
    {
        self::$entries = [];
    }
}
