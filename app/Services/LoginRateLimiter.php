<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Config;
use PDO;

final class LoginRateLimiter
{
    private string $key;
    private bool $ipWideEnabled;

    public function __construct(private PDO $db,Config $config)
    {
        $raw=$config->required('APP_KEY');
        $key=str_starts_with($raw,'base64:')?base64_decode(substr($raw,7),true):false;
        if(!is_string($key)||strlen($key)!==32)throw new \RuntimeException('Invalid application encryption key.');
        $this->key=hash_hmac('sha256','c0mmail/login-rate-limit/v1',$key,true);
        $this->ipWideEnabled=strtolower((string)$config->get('LOGIN_RATE_LIMIT_IP_WIDE','false'))==='true';
    }

    public function isBlocked(string $email,string $ip):bool
    {
        $emailHash=$this->hash(strtolower(trim($email)));
        $ipHash=$this->hash($ip!==''?$ip:'unknown');
        $pair=$this->count('email_hash=? AND ip_hash=?',[$emailHash,$ipHash]);
        if($pair>=10)return true;
        $byEmail=$this->count('email_hash=?',[$emailHash]);
        if($byEmail>=30)return true;
        $byIp=$this->count('ip_hash=?',[$ipHash]);
        return $byIp>=120;
    }

    public function recordFailure(string $email,string $ip):void
    {
        $statement=$this->db->prepare('INSERT INTO login_attempts(email_hash,ip_hash,attempted_at) VALUES(?,?,UTC_TIMESTAMP())');
        $statement->execute([$this->hash(strtolower(trim($email))),$this->hash($ip!==''?$ip:'unknown')]);
        if(random_int(1,100)===1){
            $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
        }
    }

    public function recordSuccess(string $email):void
    {
        $statement=$this->db->prepare('DELETE FROM login_attempts WHERE email_hash=?');
        $statement->execute([$this->hash(strtolower(trim($email)))]);
    }

    private function count(string $where,array $values):int
    {
        $statement=$this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE '.$where.' AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)');
        $statement->execute($values);
        return (int)$statement->fetchColumn();
    }

    private function hash(string $value):string
    {
        return hash_hmac('sha256',$value,$this->key);
    }
}
