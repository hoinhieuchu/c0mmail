<?php
declare(strict_types=1);
namespace App\Repositories;
use PDO;
final class UserRepository
{
    public function __construct(private PDO $db){}
    public function findByEmail(string $email):?array{$s=$this->db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');$s->execute([$email]);return $s->fetch()?:null;}
    public function findById(int $id):?array{$s=$this->db->prepare('SELECT id,email,display_name,role,status,auth_version FROM users WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;}
    public function allMembers():array{return $this->db->query("SELECT id,email,display_name FROM users WHERE status='active' ORDER BY email")->fetchAll();}
    public function create(string $email,string $name,string $hash,string $role='member'):int{$s=$this->db->prepare('INSERT INTO users(email,display_name,password_hash,role) VALUES(?,?,?,?)');$s->execute([$email,$name,$hash,$role]);return (int)$this->db->lastInsertId();}
    public function allAccounts():array{return $this->db->query('SELECT id,email,display_name,role,status,created_at FROM users ORDER BY role,email')->fetchAll();}
    public function setMemberStatus(int $id,string $status):void{if(!in_array($status,['active','suspended'],true))throw new \InvalidArgumentException('Invalid member status.');$s=$this->db->prepare("UPDATE users SET status=?, auth_version=auth_version+1 WHERE id=? AND role='member'");$s->execute([$status,$id]);}
    public function passwordHashForId(int $id):?string{$s=$this->db->prepare("SELECT password_hash FROM users WHERE id=? AND status='active'");$s->execute([$id]);$hash=$s->fetchColumn();return is_string($hash)?$hash:null;}
    public function updatePasswordHash(int $id,string $expectedHash,string $newHash):bool{$s=$this->db->prepare("UPDATE users SET password_hash=?, auth_version=auth_version+1 WHERE id=? AND password_hash=? AND status='active'");$s->execute([$newHash,$id,$expectedHash]);return $s->rowCount()===1;}
    public function authVersionForId(int $id):?int{$s=$this->db->prepare("SELECT auth_version FROM users WHERE id=? AND status='active'");$s->execute([$id]);$version=$s->fetchColumn();return $version===false?null:(int)$version;}
}
