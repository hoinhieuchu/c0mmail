<?php
declare(strict_types=1);
namespace App\Repositories;
use PDO;
final class UserRepository
{
    public function __construct(private PDO $db){}
    public function findByEmail(string $email):?array{$s=$this->db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');$s->execute([$email]);return $s->fetch()?:null;}
    public function findById(int $id):?array{$s=$this->db->prepare('SELECT id,email,display_name,role,status FROM users WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;}
    public function allMembers():array{return $this->db->query("SELECT id,email,display_name FROM users WHERE status='active' ORDER BY email")->fetchAll();}
    public function create(string $email,string $name,string $hash,string $role='member'):int{$s=$this->db->prepare('INSERT INTO users(email,display_name,password_hash,role) VALUES(?,?,?,?)');$s->execute([$email,$name,$hash,$role]);return (int)$this->db->lastInsertId();}
}
