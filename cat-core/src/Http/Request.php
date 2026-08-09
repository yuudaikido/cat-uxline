<?php
declare(strict_types=1);

namespace Cat\Http;

/**
 * v0.2 HTTP核：Request。
 *
 * MVC対応表の「Adaptの入出力型」。symfony/http-foundation の骨格は輸入せず、
 * スーパーグローバル解析の知恵だけ回収して、不変Record（readonly）として自作。
 * 関係関数の葉から葉へ流れる「値」なので、生成後は誰にも書き換えられない。
 */
final class Request
{
    /**
     * @param array<string,mixed>  $query        $_GET
     * @param array<string,mixed>  $body         $_POST
     * @param array<string,string> $headers      小文字キーに正規化済み
     * @param array<string,mixed>  $cookies      $_COOKIE
     * @param array<string,string> $routeParams  Router が付与（/{id} など）
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $routeParams = [],
    ) {}

    /** 観測ゲートウェイ（public/index.php）専用の生成口 */
    public static function fromGlobals(): self
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        // HTTP_* ヘッダを小文字ハイフン形式へ正規化（http-foundationから回収した知恵）
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']))   { $headers['content-type']   = (string) $_SERVER['CONTENT_TYPE']; }
        if (isset($_SERVER['CONTENT_LENGTH'])) { $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH']; }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $headers,
            $_COOKIE,
        );
    }

    /** 不変性の維持：ルートパラメータ付与は新しいRequestを返す */
    public function withRouteParams(array $params): self
    {
        return new self(
            $this->method, $this->path, $this->query, $this->body,
            $this->headers, $this->cookies, $params,
        );
    }

    /** 入力の取得：body → query の優先順（route params は明示アクセスのみ） */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }
}
