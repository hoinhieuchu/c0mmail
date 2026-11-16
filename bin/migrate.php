<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    $root=dirname(__DIR__);
    $config=Config::load($root.'/.env');
    $db=Database::connect($config);
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $applied=$db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    if(!$applied){
        $existing=$db->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if($existing){
            throw new RuntimeException('An application schema already exists but has no migration history. Back up the database and follow the legacy-install migration instructions; refusing to run CREATE TABLE migrations over existing data.');
        }
    }
    $files=glob($root.'/database/migrations/*.sql')?:[];
    sort($files,SORT_STRING);
    if(!$files) throw new RuntimeException('No SQL migration files were found.');
    $count=0;
    foreach($files as $file){
        $version=basename($file);
        if(in_array($version,$applied,true)){fwrite(STDOUT,"SKIP  {$version}\n");continue;}
        $sql=file_get_contents($file);
        if($sql===false||trim($sql)==='') throw new RuntimeException("Migration {$version} is empty or unreadable.");
        $statements=preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[];
        foreach($statements as $statement){
            if(trim($statement)!=='') $db->exec(trim($statement));
        }
        $insert=$db->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
        $insert->execute([$version]);
        fwrite(STDOUT,"APPLIED {$version}\n");
        $count++;
    }
    fwrite(STDOUT,"Migration check complete. {$count} migration(s) applied.\n");
}catch(Throwable $e){
    fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");
    fwrite(STDERR,"MySQL DDL may not roll back. Inspect the database before retrying; do not delete application data.\n");
    exit(1);
}
