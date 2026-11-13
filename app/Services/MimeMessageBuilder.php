<?php
declare(strict_types=1);
namespace App\Services;
final class MimeMessageBuilder
{
 public function build(array $m,bool $allowEmptyTo=false):string
 {
  $from=$this->address((string)($m['from']??''));$to=$this->addresses($m['to']??'');$cc=$this->addresses($m['cc']??'');
  if(!$to&&!$allowEmptyTo)throw new \InvalidArgumentException('At least one To recipient is required.');
  $boundary='=_c0m_'.bin2hex(random_bytes(18));$headers=['Date: '.date(DATE_RFC2822),'From: '.$from];if($to)$headers[]='To: '.implode(', ',$to);
  if($cc)$headers[]='Cc: '.implode(', ',$cc);
  $domain=substr(strrchr((string)$m['from'],'@')?:'@localhost',1);$headers[]='Subject: =?UTF-8?B?'.base64_encode((string)($m['subject']??'')).'?=';$headers[]='Message-ID: <'.bin2hex(random_bytes(16)).'@'.$domain.'>';
  $headers[]='MIME-Version: 1.0';$headers[]='Content-Type: multipart/mixed; boundary="'.$boundary.'"';
  $body="--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode((string)($m['body']??'')));
  foreach(($m['attachments']??[]) as $a){$name=basename((string)$a['name']);$type=preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i',(string)($a['type']??''))?$a['type']:'application/octet-stream';$data=file_get_contents($a['path']);if($data===false)throw new \RuntimeException('Unable to read an attachment.');$encoded=rawurlencode($name);$body.="--{$boundary}\r\nContent-Type: {$type}; name*=UTF-8''{$encoded}\r\nContent-Disposition: attachment; filename*=UTF-8''{$encoded}\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($data));}
  return implode("\r\n",$headers)."\r\n\r\n".$body."--{$boundary}--\r\n";
 }
 private function addresses(string|array $v):array{$items=is_array($v)?$v:preg_split('/[,;]+/',$v,-1,PREG_SPLIT_NO_EMPTY);$out=[];foreach($items as $x){$x=trim((string)$x);if($x!=='')$out[]=$this->address($x);}return $out;}
 private function address(string $v):string{$v=trim($v);if(preg_match('/[\r\n]/',$v)||!preg_match('/<?([^<>\s]+@[^<>\s]+)>?$/',$v,$m)||!filter_var($m[1],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid email address.');return $v;}
}
