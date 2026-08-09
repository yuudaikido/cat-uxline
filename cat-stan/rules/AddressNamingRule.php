<?php
declare(strict_types=1);

namespace Cat\Stan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * CAT規約①：住所式命名の静的検査。
 *
 * Relation::seq / Overlay::* / Inject::with の第1引数（住所式名）を
 * コンパイル前に検査する。ランタイムの Registry と二段構え：
 *   - 静的（本ルール）：エディタ/CIの段階で違反を検出
 *   - 動的（Registry）：実行時の最終防衛線＋重複検出
 */
final class AddressNamingRule implements Rule
{
    private const NAME_PATTERN = '/^[a-z][A-Za-z0-9]*_[a-z]+_[0-9]+$/';

    /** 検査対象：クラス => 住所式名を第1引数に取る静的メソッド群 */
    private const TARGETS = [
        'Cat\\Relation' => ['seq'],
        'Cat\\Overlay'  => ['wrap', 'retry', 'tx', 'compose'],
        'Cat\\Inject'   => ['with'],
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return [];
        }
        $class  = $scope->resolveName($node->class);
        $method = $node->name->toString();

        if (!isset(self::TARGETS[$class]) || !in_array($method, self::TARGETS[$class], true)) {
            return [];
        }

        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }
        $first = $args[0]->value;

        // 名前は文字列リテラルであること（変数経由は住所の追跡を壊す）
        if (!$first instanceof String_) {
            return [RuleErrorBuilder::message(
                "CAT規約: {$class}::{$method}() の住所式名は文字列リテラルで直接与える（変数・式は追跡不能）"
            )->identifier('cat.addressNaming.literal')->build()];
        }

        if (!preg_match(self::NAME_PATTERN, $first->value)) {
            return [RuleErrorBuilder::message(
                "CAT規約: '{$first->value}' は住所式命名 name_type_number に違反（例: readData_seq_1）"
            )->identifier('cat.addressNaming.pattern')->build()];
        }

        return [];
    }
}
