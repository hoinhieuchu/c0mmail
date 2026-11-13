<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\MemberProviderInterface;
use App\Repositories\UserRepository;
final class LocalSessionMemberProvider implements MemberProviderInterface
{
    public function __construct(private UserRepository $users){}
    public function currentMember():?array{$id=$_SESSION['user_id']??null;if(!$id)return null;$u=$this->users->findById((int)$id);return $u&&$u['status']==='active'?$u:null;}
}
