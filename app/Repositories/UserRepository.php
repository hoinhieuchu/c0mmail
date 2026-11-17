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
    public function updatePasswordHash(int $id,string $expectedHash,string $newHash):?int
    {
        $this->db->beginTransaction();
        try {
            $update=$this->db->prepare("UPDATE users SET password_hash=?, auth_version=auth_version+1 WHERE id=? AND password_hash=? AND status='active'");
            $update->execute([$newHash,$id,$expectedHash]);
            if($update->rowCount()!==1){$this->db->commit();return null;}
            $select=$this->db->prepare('SELECT auth_version FROM users WHERE id=?');
            $select->execute([$id]);
            $version=$select->fetchColumn();
            if($version===false)throw new \RuntimeException('Updated account version could not be read.');
            $this->db->commit();
            return (int)$version;
        } catch (\Throwable $error) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    /**
     * Replace an active account password from a trusted server-side CLI
     * operation. Incrementing auth_version revokes every existing session.
     */
    public function resetPasswordHash(int $id, string $newHash): ?int
    {
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("UPDATE users SET password_hash=?, auth_version=auth_version+1 WHERE id=? AND status='active'");
            $update->execute([$newHash, $id]);
            if ($update->rowCount() !== 1) {
                $this->db->commit();
                return null;
            }
            $select = $this->db->prepare('SELECT auth_version FROM users WHERE id=?');
            $select->execute([$id]);
            $version = $select->fetchColumn();
            if ($version === false) throw new \RuntimeException('Updated account version could not be read.');
            $this->db->commit();
            return (int)$version;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
