<?php
declare(strict_types=1);
namespace App\Core;
final class View
{
    public function __construct(private string $directory){}
    public function render(string $template,array $data=[]):void
    {
        extract($data,EXTR_SKIP);ob_start();require $this->directory.'/'.$template.'.php';$content=ob_get_clean();require $this->directory.'/layout.php';
    }
}
