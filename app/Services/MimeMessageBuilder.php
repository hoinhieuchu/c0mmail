<?php
declare(strict_types=1);
namespace App\Services;
final class MimeMessageBuilder
{
 public function build(array $m,bool $allowEmptyTo=false):string
 {
  $from=$this->address((string)($m['from']??''));$to=$this->addresses($m['to']??'');$cc=$this->addresses($m['cc']??'');$bcc=$this->addresses($m['bcc']??'');\n  if(count($to)+count($cc)+count($bcc)>50)throw new \\InvalidArgumentException('A maximum of 50 recipients is allowed per message.');
  if(!$to&&!$allowEmptyTo)throw new \InvalidArgumentException('At least one To recipient is required.');
  $boundary='=_c0m_'.bin2hex(random_bytes(18));$headers=['Date: '.date(DATE_RFC2822),$this->foldAddresses('From',[$from])];
  if($to)$headers[]=$this->foldAddresses('To',$to);
  if($cc)$headers[]=$this->foldAddresses('Cc',$cc);
  if($allowEmptyTo&&trim((string)($m['bcc']??''))!=='')$headers[]=$this->foldAddresses('X-C0m-Draft-Bcc',$bcc);
  $domain=substr(strrchr((string)$m['from'],'@')?:'@localhost',1);
  $headers[]='Subject: '.$this->encodedSubject((string)($m['subject']??''));
  $headers[]='Message-ID: <'.bin2hex(random_bytes(16)).'@'.$domain.'>';
  $headers[]='MIME-Version: 1.0';$headers[]='Content-Type: multipart/mixed; boundary="'.$boundary.'"';
  $body="--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode((string)($m['body']??'')));
  foreach(($m['attachments']??[]) as $a){
   $name=basename((string)$a['name']);$type=preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i',(string)($a['type']??''))?$a['type']:'application/octet-stream';
   $data=array_key_exists('data',$a)?(string)$a['data']:(file_get_contents((string)($a['path']??''))?:false);if($data===false)throw new \RuntimeException('Unable to read an attachment.');
   $encoded=rawurlencode($name);$fallback=preg_replace('/[^A-Za-z0-9._-]/','_',$name);
   $body.="--{$boundary}\r\nContent-Type: {$type}; name=\"{$fallback}\"; name*=UTF-8''{$encoded}\r\nContent-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''{$encoded}\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($data));
  }
  return implode("\r\n",$headers)."\r\n\r\n".$body."--{$boundary}--\r\n";
 }
 private function encodedSubject(string $subject):string
 {
  if($subject==='')return '';
  $characters=preg_split('//u',$subject,-1,PREG_SPLIT_NO_EMPTY);
  if($characters===false)$characters=str_split($subject,42);
  $chunks=[];$buffer='';
  foreach($characters as $character){if($buffer!==''&&strlen($buffer)+strlen($character)>42){$chunks[]=$buffer;$buffer='';}$buffer.=$character;}
  if($buffer!=='')$chunks[]=$buffer;
  return implode("\r\n ",array_map(static fn(string $chunk):string=>'=?UTF-8?B?'.base64_encode($chunk).'?=',$chunks));
 }
 private function foldAddresses(string $name,array $addresses):string
 {
  $line=$name.':';$lines=[];
  foreach($addresses as $address){
   $candidate=$line.($line===$name.':'?' ':', ').$address;
   if(strlen($candidate)>78&&$line!==$name.':'){$lines[]=$line.',';$line=' '.$address;}else{$line=$candidate;}
  }
  $lines[]=$line;return implode("\r\n",$lines);
 }
 private function addresses(string|array $v):array
 {
  $items=is_array($v)?$v:preg_split('/[,;]+/',$v,-1,PREG_SPLIT_NO_EMPTY);$out=[];
  foreach($items as $x){$x=trim((string)$x);if($x!=='')$out[]=$this->address($x);}
  if(count($out)>50)throw new \InvalidArgumentException('A maximum of 50 recipients is allowed per message.');
  return $out;
 }
 private function address(string $v):string
 {
  $v=trim($v);
  if($v===''||strlen($v)>320||preg_match('/[\r\n\x00-\x1F\x7F]/',$v)||!preg_match('/<?([^<>\s]+@[^<>\s]+)>?$/',$v,$m)||!filter_var($m[1],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid email address.');
  return $v;
 }
}
