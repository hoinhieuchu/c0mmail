<?php
declare(strict_types=1);
namespace App\Core;
use App\Contracts\MemberProviderInterface;
final class Auth
{
    public function __construct(private MemberProviderInterface $members){}
    public function user():?array{return $this->members->currentMember();}
    public function check():bool{return $this->user()!==null;}
    public function requireLogin():array{$u=$this->user();if(!$u){header('Location: /login');exit;}return $u;}
    public function requireAdmin():array{$u=$this->requireLogin();if(($u['role']??'')!=='admin'){http_response_code(403);exit('Forbidden');}return $u;}
}
