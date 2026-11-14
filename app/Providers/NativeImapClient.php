<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\ImapClientInterface;
final class NativeImapClient implements ImapClientInterface
{
 private $stream;
 private string $base;
 private int $maxAttachmentBytes;
 private array $folderRoles;
 public function __construct(string $host,int $port,string $encryption,string $username,string $password,int $maxAttachmentBytes=10485760,array $folderRoles=[],int $timeout=20)
 {
  if(!function_exists('imap_open'))throw new \RuntimeException('PHP IMAP extension is required on this hosting account.');
  if($timeout<1||$timeout>120)throw new \InvalidArgumentException('Invalid IMAP timeout.');
  if(function_exists('imap_timeout')){imap_timeout(IMAP_OPENTIMEOUT,$timeout);imap_timeout(IMAP_READTIMEOUT,$timeout);imap_timeout(IMAP_WRITETIMEOUT,$timeout);imap_timeout(IMAP_CLOSETIMEOUT,min(10,$timeout));}
  if($port<1||$port>65535)throw new \InvalidArgumentException('Invalid IMAP port.');
  $suffix=match($encryption){'ssl'=>'/imap/ssl/validate-cert','starttls'=>'/imap/tls/validate-cert','none'=>'/imap/notls',default=>throw new \InvalidArgumentException('Unsupported IMAP encryption mode.')};
  if($host===''||preg_match('/[\s{}\/\\\\\x00]/',$host))throw new \InvalidArgumentException('Invalid IMAP host.');
  $imapHost=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false?'['.$host.']':$host;$this->base='{'.$imapHost.':'.$port.$suffix.'}';$this->maxAttachmentBytes=max(1,$maxAttachmentBytes);$this->folderRoles=$folderRoles;
  $this->stream=@imap_open($this->base.'INBOX',$username,$password,0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
  if(!$this->stream)throw new \RuntimeException('IMAP connection failed: '.(imap_last_error()?:'authentication or network error'));
 }
 public function folders():array
 {
  $names=imap_list($this->stream,$this->base,'*');if($names===false)throw new \RuntimeException('Unable to list IMAP folders.');$out=[];
  foreach($names as $name){$short=substr($name,strlen($this->base));$decoded=imap_utf7_decode($short);$out[]=['name'=>$decoded,'path'=>$decoded,'role'=>$this->role($decoded)];}
  return $out;
 }
 public function messages(string $folder,int $page,int $perPage,?string $query=null):array
 {
  $this->select($folder);$criteria='ALL';
  if($query!==null&&trim($query)!==''){$q=trim($query);if(strlen($q)>200)throw new \InvalidArgumentException('Search query is too long.');if(preg_match('/[\x00-\x1F\x7F]/',$q))throw new \InvalidArgumentException('Search query contains unsupported control characters.');$criteria='TEXT "'.addcslashes($q,'\\\"').'"';}
  $uids=imap_search($this->stream,$criteria,SE_UID,'UTF-8')?:[];rsort($uids,SORT_NUMERIC);$total=count($uids);$slice=array_slice($uids,max(0,($page-1)*$perPage),$perPage);$rows=[];
  foreach($slice as $uid){$o=imap_fetch_overview($this->stream,(string)$uid,FT_UID);if(!$o||!isset($o[0]))continue;$v=$o[0];$rows[]=['uid'=>(int)$uid,'subject'=>$this->decode((string)($v->subject??'')),'from'=>$this->decode((string)($v->from??'')),'date'=>(string)($v->date??''),'seen'=>(bool)($v->seen??false),'flagged'=>(bool)($v->flagged??false),'size'=>(int)($v->size??0)];}
  return ['messages'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>(int)ceil($total/max(1,$perPage))];
 }
 public function message(string $folder,int $uid):array
 {
  $this->select($folder);$overview=imap_fetch_overview($this->stream,(string)$uid,FT_UID);if(!$overview||!isset($overview[0]))throw new \RuntimeException('Message not found.');
  $structure=imap_fetchstructure($this->stream,$uid,FT_UID);$plain='';$html='';$attachments=[];if($structure)$this->walk($uid,$structure,'',$plain,$html,$attachments);
  $header=imap_fetchheader($this->stream,$uid,FT_UID);$unfolded=preg_replace("/\\r?\\n[ \\t]+/"," ",$header?:'');$draftBcc='';if(preg_match('/^X-C0m-Draft-Bcc:\\s*(.+)$/mi',$unfolded,$bccMatch))$draftBcc=$this->decode(trim($bccMatch[1]));
  imap_setflag_full($this->stream,(string)$uid,'\\Seen',ST_UID);$v=$overview[0];
  return ['uid'=>$uid,'subject'=>$this->decode((string)($v->subject??'')),'from'=>$this->decode((string)($v->from??'')),'to'=>$this->decode((string)($v->to??'')),'cc'=>$this->decode((string)($v->cc??'')),'bcc'=>$draftBcc,'date'=>(string)($v->date??''),'body'=>$plain!==''?$plain:strip_tags($html),'html'=>$html,'attachments'=>$attachments];
 }
 public function setFlag(string $folder,int $uid,string $flag,bool $enabled):void
 {
  $allowed=['seen'=>'\\Seen','flagged'=>'\\Flagged','answered'=>'\\Answered'];if(!isset($allowed[$flag]))throw new \InvalidArgumentException('Unsupported message flag.');$this->select($folder);$fn=$enabled?'imap_setflag_full':'imap_clearflag_full';if(!$fn($this->stream,(string)$uid,$allowed[$flag],ST_UID))throw new \RuntimeException('Unable to update message flag.');
 }
 public function move(string $folder,int $uid,string $destination):void{$this->select($folder);if(!$this->validFolder($destination)||!imap_mail_move($this->stream,(string)$uid,imap_utf7_encode($destination),CP_UID)||!imap_expunge($this->stream))throw new \RuntimeException('Unable to move message.');}
 public function delete(string $folder,int $uid):void{$this->select($folder);if(!imap_setflag_full($this->stream,(string)$uid,'\\Deleted',ST_UID)||!imap_expunge($this->stream))throw new \RuntimeException('Unable to delete message.');}
 public function createFolder(string $folder):void{if(!$this->validFolder($folder)||!imap_createmailbox($this->stream,imap_utf7_encode($this->mailbox($folder))))throw new \RuntimeException('Unable to create folder.');}
 public function append(string $folder,string $rawMessage,string $flags=''):void{if(!$this->validFolder($folder)||!imap_append($this->stream,imap_utf7_encode($this->mailbox($folder)),$rawMessage,trim($flags)))throw new \RuntimeException('Unable to save message to folder.');}
 public function downloadPart(string $folder,int $uid,string $part):array
 {
  $this->select($folder);$s=imap_fetchstructure($this->stream,$uid,FT_UID);$meta=$s?$this->findPart($s,$part):null;if(!$meta)throw new \RuntimeException('Attachment not found.');
  if((int)($meta->bytes??0)>$this->maxAttachmentBytes)throw new \RuntimeException('Attachment exceeds the configured download limit.');$raw=imap_fetchbody($this->stream,$uid,$part,FT_UID);$data=match((int)($meta->encoding??0)){3=>base64_decode($raw,true),4=>quoted_printable_decode($raw),default=>$raw};if($data===false)throw new \RuntimeException('Invalid attachment encoding.');if(strlen($data)>$this->maxAttachmentBytes)throw new \RuntimeException('Attachment exceeds the configured download limit.');
  return ['name'=>$this->partName($meta)?:'attachment','type'=>$this->type($meta),'data'=>$data];
 }
 public function close():void{if($this->stream){imap_close($this->stream);$this->stream=null;}}
 private function select(string $folder):void{if(!$this->validFolder($folder)||!imap_reopen($this->stream,$this->mailbox($folder)))throw new \RuntimeException('Unable to open IMAP folder.');}
 private function mailbox(string $folder):string{return $this->base.imap_utf7_encode($folder);}
 private function validFolder(string $f):bool{return $f!==''&&!preg_match('/[{}\x00-\x1F\x7F]/',$f);}
 private function role(string $f):string
 {
  foreach($this->folderRoles as $role=>$path){$path=trim((string)$path);if($path!==''&&strcasecmp($f,$path)===0)return (string)$role;}
  $segments=preg_split('/[\\\\/.>]+/',strtolower(trim($f)))?:[];$leaf=trim((string)end($segments));$leaf=preg_replace('/[_-]+/',' ',$leaf)??$leaf;
  return match($leaf){
   'inbox'=>'inbox',
   'sent','sent items','sent mail','sent messages'=>'sent',
   'draft','drafts'=>'drafts',
   'spam','junk','junk email','junk e-mail','bulk mail'=>'spam',
   'trash','deleted','deleted items','deleted messages'=>'trash',
   default=>'custom'
  };
 }
 private function decode(string $v):string
 {
  $parts=imap_mime_header_decode($v);if(!$parts)return $v;$out='';
  foreach($parts as $part){$text=(string)$part->text;$charset=strtoupper(trim((string)($part->charset??'DEFAULT')));if(!in_array($charset,['DEFAULT','UTF-8','US-ASCII','ASCII'],true)){if(function_exists('iconv')){$converted=@iconv($charset,'UTF-8//IGNORE',$text);if($converted!==false)$text=$converted;}elseif(function_exists('mb_convert_encoding')){$text=mb_convert_encoding($text,'UTF-8',$charset);}}$out.=$text;}
  return $out;
 }
 private function toUtf8(string $text,object $part):string
 {
  $charset='';foreach(($part->parameters??[]) as $parameter){if(strtolower((string)($parameter->attribute??''))==='charset'){$charset=trim((string)$parameter->value);break;}}
  if($charset===''||in_array(strtoupper($charset),['UTF-8','US-ASCII','ASCII','DEFAULT'],true)){return $text;}
  if(function_exists('iconv')){$converted=@iconv($charset,'UTF-8//IGNORE',$text);return $converted===false?$text:$converted;}
  return function_exists('mb_convert_encoding')?mb_convert_encoding($text,'UTF-8',$charset):$text;
 }
 private function walk(int $uid,object $s,string $prefix,string &$plain,string &$html,array &$atts):void
 {
  foreach(($s->parts??[]) as $i=>$part){$n=$prefix===''?(string)($i+1):$prefix.'.'.($i+1);$disp=strtolower((string)($part->disposition??''));$name=$this->partName($part);
   if($name!==''||$disp==='attachment'){$atts[]=['part'=>$n,'name'=>$name?:'attachment','type'=>$this->type($part),'size'=>(int)($part->bytes??0)];continue;}
   if(isset($part->parts)){$this->walk($uid,$part,$n,$plain,$html,$atts);continue;}
   $body=imap_fetchbody($this->stream,$uid,$n,FT_UID);$body=$this->toUtf8($this->decodeBody($body,(int)($part->encoding??0)),$part);if((int)$part->type===0)$plain.=$body;elseif((int)$part->type===1&&strtolower((string)($part->subtype??''))==='html')$html.=$body;
  }
  if(!$s->parts&&$prefix===''){$body=imap_body($this->stream,$uid,FT_UID);$plain=$this->toUtf8($this->decodeBody($body,(int)($s->encoding??0)),$s);}
 }
 private function decodeBody(string $v,int $e):string{return match($e){3=>base64_decode($v,true)?:'',4=>quoted_printable_decode($v),default=>$v};}
 private function findPart(object $s,string $part):?object{$node=$s;foreach(explode('.',$part) as $i){$idx=(int)$i-1;if(!isset($node->parts[$idx]))return null;$node=$node->parts[$idx];}return $node;}
 private function partName(object $p):string
 {
  $fallback='';
  foreach(['dparameters','parameters'] as $key)foreach(($p->$key??[]) as $parameter){
   $attribute=strtolower((string)($parameter->attribute??''));$value=$this->decode((string)($parameter->value??''));
   if(in_array($attribute,['filename*','name*'],true)){
    if(preg_match("/^[^']*''(.*)$/",$value,$match))return rawurldecode($match[1]);
    return rawurldecode($value);
   }
   if(in_array($attribute,['filename','name'],true)&&$fallback==='')$fallback=$value;
  }
  return $fallback;
 }
 private function type(object $p):string{$types=['TEXT','MULTIPART','MESSAGE','APPLICATION','AUDIO','IMAGE','VIDEO','OTHER'];return strtolower($types[$p->type??7]??'application').'/'.strtolower((string)($p->subtype??'octet-stream'));}
}
