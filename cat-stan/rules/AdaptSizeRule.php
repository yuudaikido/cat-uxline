<?php
declare(strict_types=1);

namespace Cat\Stan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * CAT規約②：Adaptの縮退執行（10行規約）。
 *
 * Adapt（Controller後継）は Request→Record変換・Relation起動・Response変換のみ。
 * メソッド本体の文（statement）が10を超えたら設計違反 ＝
 * 指揮ロジックが混入している兆候（指揮は関係関数DAGの仕事）。
 *
 * 対象：名前空間に \Adapts\ を含む、またはクラス名が Adapt で終わるクラス。
 */
final class AdaptSizeRule implements Rule
{
    private const MAX_STATEMENTS = 10;

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();
        if ($class === null) {
            return [];
        }
        $fqcn = $class->getName();
        $isAdapt = str_contains($fqcn, '\\Adapts\\') || str_ends_with($fqcn, 'Adapt');
        if (!$isAdapt || $node->stmts === null) {
            return [];
        }

        // ネストも含めた文の総数（if/foreach の中身も勘定に入れる）
        $count = count((new NodeFinder())->findInstanceOf($node->stmts, Node\Stmt::class));

        if ($count > self::MAX_STATEMENTS) {
            return [RuleErrorBuilder::message(
                "CAT規約: Adapt '{$fqcn}::{$node->name->toString()}()' が {$count} 文（上限 " . self::MAX_STATEMENTS . "）。"
                . "指揮ロジックの混入疑い——処理の指揮は関係関数DAGへ、変換だけを残す"
            )->identifier('cat.adapt.size')->build()];
        }

        return [];
    }
}
