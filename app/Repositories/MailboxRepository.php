<?php
declare(strict_types=1);
namespace App\Repositories;
use PDO;
final class MailboxRepository
{
    public function __construct(private PDO $db){}
    public function create(array $m):int{$s=$this->db->prepare('INSERT INTO mailboxes(user_id,email,display_name,provider,provider_mailbox_id,status,password_ciphertext,password_nonce,password_tag) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$m['user_id'],$m['email'],$m['display_name'],$m['provider'],$m['provider_id'],$m['status'],$m['ciphertext'],$m['nonce'],$m['tag']]);return (int)$this->db->lastInsertId();}
    public function forUser(int $id):array{$s=$this->db->prepare("SELECT id,email,display_name,provider,status,created_at FROM mailboxes WHERE user_id=? AND status<>'deleted' ORDER BY id DESC");$s->execute([$id]);return $s->fetchAll();}
    public function all():array{return $this->db->query("SELECT m.id,m.email,m.display_name,m.provider,m.provider_mailbox_id,m.status,m.created_at,u.email AS owner_email FROM mailboxes m JOIN users u ON u.id=m.user_id WHERE m.status<>'deleted' ORDER BY m.id DESC")->fetchAll();}
    public function findForUser(int $id,int $userId):?array{$s=$this->db->prepare('SELECT * FROM mailboxes WHERE id=? AND user_id=?');$s->execute([$id,$userId]);return $s->fetch()?:null;}
    public function find(int $id):?array{$s=$this->db->prepare('SELECT * FROM mailboxes WHERE id=?');$s->execute([$id]);return $s->fetch()?:null;}
    public function setStatus(int $id,string $status):void{$s=$this->db->prepare('UPDATE mailboxes SET status=? WHERE id=?');$s->execute([$status,$id]);}
}
