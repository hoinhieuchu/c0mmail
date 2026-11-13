<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class Database
{
    public static function connect(Config $c): PDO
    {
        $dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$c->required('DB_HOST'),$c->get('DB_PORT','3306'),$c->required('DB_DATABASE'));
        return new PDO($dsn,$c->required('DB_USERNAME'),$c->get('DB_PASSWORD','') ?? '',[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
}
