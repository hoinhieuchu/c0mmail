<?php
declare(strict_types=1);
namespace App\Core;
final class Config
{
    public function __construct(private array $values) {}
    public static function load(string $path): self
    {
        $values=[];
        if (is_file($path)) foreach (file($path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line=trim($line); if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
            [$key,$value]=explode('=',$line,2); $values[trim($key)]=trim(trim($value), " \t\n\r\0\x0B\"'");
        }
        $environment=getenv();
        if(!is_array($environment))$environment=[];
        return new self(array_merge($values,$environment,$_ENV));
    }
    public function get(string $key, ?string $default=null): ?string { return $this->values[$key] ?? $default; }
    public function required(string $key): string
    {
        $value=$this->get($key); if ($value===null || $value==='') throw new \RuntimeException("Missing configuration: {$key}"); return $value;
    }
}
