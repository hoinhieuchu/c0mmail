<?php
declare(strict_types=1);
namespace App\Contracts;
interface MemberProviderInterface { /** Return a verified principal or null. */ public function currentMember():?array; }
