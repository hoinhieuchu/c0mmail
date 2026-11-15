<?php
declare(strict_types=1);
namespace App\Contracts;
interface ImapClientInterface
{
 public function folders():array;
 public function messages(string $folder,int $page,int $perPage,?string $query=null):array;
 public function message(string $folder,int $uid):array;
 public function setFlag(string $folder,int $uid,string $flag,bool $enabled):void;
 public function move(string $folder,int $uid,string $destination):void;
 public function delete(string $folder,int $uid):void;
 public function createFolder(string $folder):void;
 public function append(string $folder,string $rawMessage,string $flags=''):void;
 public function downloadPart(string $folder,int $uid,string $part):array;
 public function close():void;
}
