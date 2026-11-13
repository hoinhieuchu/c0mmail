<?php
declare(strict_types=1);
namespace App\Core;
final class Router
{
    private array $routes=['GET'=>[],'POST'=>[]];
    public function get(string $path,callable $handler):void{$this->routes['GET'][$path]=$handler;}
    public function post(string $path,callable $handler):void{$this->routes['POST'][$path]=$handler;}
    public function dispatch(string $method,string $uri):void
    {
        foreach($this->routes[$method]??[] as $route=>$handler){
            $pattern='#^'.preg_replace('#\\{([a-zA-Z_][a-zA-Z0-9_]*)\\}#','(?P<$1>[^/]+)',$route).'$#';
            if(preg_match($pattern,$uri,$matches)){ $params=array_filter($matches,'is_string',ARRAY_FILTER_USE_KEY);$handler($params);return; }
        }
        http_response_code(404);echo '404 Not Found';
    }
}
