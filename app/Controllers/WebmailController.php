<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\MailboxCredentialService;
use App\Services\MailboxWebmailService;
use App\Services\HtmlEmailSanitizer;
final class WebmailController
{
 public function __construct(private Auth $auth,private View $view,private MailboxCredentialService $credentials,private MailboxWebmailService $webmail,private int $maxAttachmentBytes=10485760,private int $maxTotalBytes=20971520){}
 public function index(int $id):void
 {
  $user=$this->auth->requireLogin();$creds=$this->credentials->forUser($id,(int)$user['id']);$folders=$this->webmail->folders($creds);
  $folder=(string)($_GET['folder']??$this->folderByRole($folders,'inbox')??'INBOX');$page=max(1,(int)($_GET['page']??1));$query=trim((string)($_GET['q']??''));$listing=$this->webmail->messages($creds,$folder,$page,$query?:null);
  $compose=$this->composeDefaults($creds,$folder);
  if(isset($_GET['edit_draft'])){$draftUid=(int)$_GET['edit_draft'];$draft=$this->webmail->message($creds,$folder,$draftUid);$compose=['to'=>$draft['to'],'cc'=>$draft['cc'],'bcc'=>$draft['bcc']??'','subject'=>$draft['subject'],'body'=>$draft['body'],'draft_uid'=>$draftUid,'attachments'=>$draft['attachments']];}
  if(isset($_GET['reply'])||isset($_GET['forward'])){$uid=(int)($_GET['reply']??$_GET['forward']);$original=$this->webmail->message($creds,$folder,$uid);if(isset($_GET['reply'])){$compose['to']=$original['from'];$compose['subject']='Re: '.$original['subject'];$compose['body']="\n\nOn ".$original['date'].", ".$original['from']." wrote:\n> ".str_replace("\n","\n> ",$original['body']);}else{$compose['subject']='Fwd: '.$original['subject'];$compose['body']="\n\n---------- Forwarded message ----------\nFrom: ".$original['from']."\nDate: ".$original['date']."\nSubject: ".$original['subject']."\n\n".$original['body'];}}
  $this->view->render('webmail',['title'=>$creds['email'],'user'=>$user,'mailbox'=>$creds,'folders'=>$folders,'folder'=>$folder,'listing'=>$listing,'message'=>null,'compose'=>$compose,'error'=>null]);
 }
 public function showMessage(int $id,int $uid):void
 {
  $user=$this->auth->requireLogin();$creds=$this->credentials->forUser($id,(int)$user['id']);$folders=$this->webmail->folders($creds);$folder=(string)($_GET['folder']??'INBOX');$message=$this->webmail->message($creds,$folder,$uid);
  $message['html']=(new HtmlEmailSanitizer())->sanitize((string)($message['html']??''));
  $this->view->render('webmail',['title'=>$creds['email'],'user'=>$user,'mailbox'=>$creds,'folders'=>$folders,'folder'=>$folder,'listing'=>['messages'=>[],'total'=>0,'page'=>1,'pages'=>1],'message'=>$message,'compose'=>['to'=>'','cc'=>'','bcc'=>'','subject'=>'','body'=>''],'error'=>null]);
 }
 public function action(int $id):void
 {
  $user=$this->auth->requireLogin();Csrf::verify();$creds=$this->credentials->forUser($id,(int)$user['id']);$folder=(string)($_POST['folder']??'INBOX');$uid=(int)($_POST['uid']??0);$action=(string)($_POST['action']??'');
  if($uid<1)throw new \InvalidArgumentException('Invalid message identifier.');
  match($action){'read'=>$this->webmail->flag($creds,$folder,$uid,'seen',true),'unread'=>$this->webmail->flag($creds,$folder,$uid,'seen',false),'star'=>$this->webmail->flag($creds,$folder,$uid,'flagged',true),'unstar'=>$this->webmail->flag($creds,$folder,$uid,'flagged',false),'delete'=>$this->webmail->delete($creds,$folder,$uid),'move'=>$this->webmail->move($creds,$folder,$uid,(string)($_POST['destination']??'')),default=>throw new \InvalidArgumentException('Unknown mail action.')};
  header('Location: /mail/'.$id.'?folder='.rawurlencode($folder));exit;
 }
 public function send(int $id):void
 {
  $user=$this->auth->requireLogin();Csrf::verify();$creds=$this->credentials->forUser($id,(int)$user['id']);$attachments=$this->uploads();
  $message=['from'=>$creds['email'],'to'=>(string)($_POST['to']??''),'cc'=>(string)($_POST['cc']??''),'bcc'=>(string)($_POST['bcc']??''),'subject'=>(string)($_POST['subject']??''),'body'=>(string)($_POST['body']??''),'attachments'=>$attachments];
  if(strlen($message['body'])>1000000||strlen($message['subject'])>998)throw new \InvalidArgumentException('Message content exceeds the allowed size.');
  $draftUid=(int)($_POST['draft_uid']??0);$draftFolder=(string)($_POST['folder']??'Drafts');
  if(($_POST['mode']??'send')==='draft'){if($draftUid>0)$this->webmail->replaceDraft($creds,$draftFolder,$draftUid,$message);else $this->webmail->saveDraft($creds,$message);header('Location: /mail/'.$id.'?draft=1');exit;}
  if($draftUid>0)$this->webmail->sendDraft($creds,$draftFolder,$draftUid,$message);else $this->webmail->send($creds,$message);header('Location: /mail/'.$id.'?sent=1');exit;
 }
 public function saveDraft(int $id):void
 {
  $user=$this->auth->requireLogin();Csrf::verify();$creds=$this->credentials->forUser($id,(int)$user['id']);
  $message=['from'=>$creds['email'],'to'=>(string)($_POST['to']??''),'cc'=>(string)($_POST['cc']??''),'bcc'=>(string)($_POST['bcc']??''),'subject'=>(string)($_POST['subject']??''),'body'=>(string)($_POST['body']??''),'attachments'=>$this->uploads()];
  $this->webmail->saveDraft($creds,$message);header('Location: /mail/'.$id.'?draft=1');exit;
 }
 public function createFolder(int $id):void{$user=$this->auth->requireLogin();Csrf::verify();$creds=$this->credentials->forUser($id,(int)$user['id']);$name=trim((string)($_POST['name']??''));if(strlen($name)>100)throw new \InvalidArgumentException('Folder name is too long.');$this->webmail->createFolder($creds,$name);header('Location: /mail/'.$id.'?folder='.rawurlencode($name));exit;}
 public function download(int $id,int $uid):void
 {
  $user=$this->auth->requireLogin();$creds=$this->credentials->forUser($id,(int)$user['id']);$file=$this->webmail->download($creds,(string)($_GET['folder']??'INBOX'),$uid,(string)($_GET['part']??''));
  header('X-Content-Type-Options: nosniff');header('Content-Type: application/octet-stream');header('Content-Length: '.strlen($file['data']));header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(basename($file['name'])));echo $file['data'];exit;
 }
 private function uploads():array
 {
  $files=$_FILES['attachments']??null;if(!$files||!is_array($files['name']??null))return [];$out=[];$total=0;$max=(int)$this->maxAttachmentBytes;$totalMax=(int)$this->maxTotalBytes;
  foreach($files['name'] as $i=>$name){if(($files['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;if(($files['error'][$i]??-1)!==UPLOAD_ERR_OK)throw new \RuntimeException('Attachment upload failed.');$size=(int)$files['size'][$i];$total+=$size;if($size<1||$size>$max||$total>$totalMax)throw new \InvalidArgumentException('Attachment size limit exceeded.');$tmp=$files['tmp_name'][$i];if(!is_uploaded_file($tmp))throw new \RuntimeException('Invalid uploaded attachment.');$safe=basename((string)$name);if(preg_match('/\.(php[0-9]?|phtml|phar|cgi|exe|js|html?|svg)$/i',$safe))throw new \InvalidArgumentException('This attachment file type is not allowed.');$out[]=['name'=>$safe,'path'=>$tmp,'type'=>function_exists('mime_content_type')?(mime_content_type($tmp)?:'application/octet-stream'):'application/octet-stream'];if(count($out)>5)throw new \InvalidArgumentException('A maximum of five attachments is allowed.');}
  return $out;
 }
 private function composeDefaults(array $creds,string $folder):array{return ['to'=>'','cc'=>'','bcc'=>'','subject'=>'','body'=>''];}
 private function folderByRole(array $folders,string $role):?string{foreach($folders as $f)if(($f['role']??'')===$role)return $f['path'];return null;}
}
