<?php
declare(strict_types=1);

namespace Cat\Http;

use Cat\CatRuleViolation;
use Cat\Registry;

/**
 * v0.2 HTTP核：Router。
 *
 * ルート＝観測点。URLとは「どの関係関数を、どの網の下で観測するか」の宣言。
 * だからルートにも住所式名を強制し、Registryに登録する——観測点も台帳に載る。
 *
 * ハンドラ（Adapt）の契約：fn(Request): Response
 *   - Request→入力変換、Relation起動、結果→Response変換のみ（10行規約はcat-stanが検査）
 *   - Response以外を返したら CatRuleViolation
 */
final class Router
{
    /** @var list<array{name:string, method:string, regex:string, handler:callable}> */
    private array $routes = [];

    public function map(string $name, string $method, string $pattern, callable $handler): void
    {
        Registry::register($name, 'route', strtoupper($method) . ' ' . $pattern);

        $normalized = rtrim($pattern, '/');
        $normalized = $normalized === '' ? '/' : $normalized;
        $regex = '#^' . preg_replace(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            '(?P<$1>[^/]+)',
            $normalized,
        ) . '$#';

        $this->routes[] = [
            'name'    => $name,
            'method'  => strtoupper($method),
            'regex'   => $regex,
            'handler' => $handler,
        ];
    }

    public function get(string $name, string $pattern, callable $handler): void
    {
        $this->map($name, 'GET', $pattern, $handler);
    }

    public function post(string $name, string $pattern, callable $handler): void
    {
        $this->map($name, 'POST', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $path = rtrim($request->path, '/');
        $path = $path === '' ? '/' : $path;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $response = ($route['handler'])($request->withRouteParams($params));
            if (!$response instanceof Response) {
                throw new CatRuleViolation(
                    "観測点 '{$route['name']}' のAdaptが Response 以外を返した（Adaptの契約違反）"
                );
            }
            return $response;
        }

        return Response::text('404 Not Found', 404);
    }
}
