<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\SmtpClientInterface;
use App\Services\MimeMessageBuilder;
final class SocketSmtpClient implements SmtpClientInterface
{
 public function __construct(private string $host,private int $port,private string $encryption,private string $username,private string $password,private int $timeout=20,private ?MimeMessageBuilder $mime=null,private bool $allowInsecureAuth=false){}
 public function send(array $message):void
 {
  if(preg_match('/[\r\n]/',$this->host))throw new \InvalidArgumentException('Invalid SMTP host.');
  $remote=($this->encryption==='ssl'?'ssl://':'tcp://').$this->host.':'.$this->port;$errno=0;$errstr='';
  $s=@stream_socket_client($remote,$errno,$errstr,$this->timeout,STREAM_CLIENT_CONNECT,stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]));
  if(!$s)throw new \RuntimeException('SMTP connection failed ('.$errno.').');stream_set_timeout($s,$this->timeout);
  try{
   $this->expect($s,[220]);$this->command($s,'EHLO c0m.me',[250]);
   if($this->encryption==='starttls'){$this->command($s,'STARTTLS',[220]);if(!stream_socket_enable_crypto($s,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('SMTP TLS negotiation failed.');$this->command($s,'EHLO c0m.me',[250]);}
   elseif(!in_array($this->encryption,['ssl','none'],true))throw new \InvalidArgumentException('Unsupported SMTP encryption mode.');
   if($this->username!==''){$this->command($s,'AUTH LOGIN',[334]);$this->command($s,base64_encode($this->username),[334]);$this->command($s,base64_encode($this->password),[235]);}
   $from=$this->extract((string)$message['from']);$recipients=[];
   foreach(['to','cc','bcc'] as $k){$v=$message[$k]??[];$items=is_array($v)?$v:preg_split('/[,;]+/',$v,-1,PREG_SPLIT_NO_EMPTY);foreach($items as $a)$recipients[]=$this->extract(trim((string)$a));}
   $recipients=array_values(array_unique($recipients));if(!$recipients)throw new \InvalidArgumentException('At least one recipient is required.');
   $this->command($s,'MAIL FROM:<'.$from.'>',[250]);foreach($recipients as $r)$this->command($s,'RCPT TO:<'.$r.'>',[250,251]);$this->command($s,'DATA',[354]);
   $raw=is_string($message['raw']??null)?$message['raw']:($this->mime??new MimeMessageBuilder())->build($message);$raw=preg_replace('/(?m)^\./','..',$raw);$this->writeAll($s,rtrim($raw,"\r\n")."\r\n.\r\n");$this->expect($s,[250]);$this->command($s,'QUIT',[221]);
  }finally{fclose($s);}
 }
 private function extract(string $v):string{if(!preg_match('/<?([^<>\s]+@[^<>\s]+)>?$/',$v,$m)||!filter_var($m[1],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid email address.');return $m[1];}
 private function command($s,string $line,array $codes):void{$this->writeAll($s,$line."\r\n");$this->expect($s,$codes);}
 private function writeAll($s,string $data):void{$length=strlen($data);$offset=0;while($offset<$length){$written=fwrite($s,substr($data,$offset));if($written===false||$written===0)throw new \RuntimeException('SMTP connection interrupted while sending data.');$offset+=$written;}}
 private function expect($s,array $codes):string
 {
  $response='';do{$line=fgets($s,515);if($line===false)throw new \RuntimeException('SMTP server closed the connection.');$response.=$line;}while(isset($line[3])&&$line[3]==='-');
  $code=(int)substr($response,0,3);if(!in_array($code,$codes,true))throw new \RuntimeException('SMTP command rejected ('.$code.').');return $response;
 }
}
