<?php
declare(strict_types=1);
namespace App\Providers;
use App\Contracts\MailProviderInterface;
use App\Core\Config;
final class CraneMailProvider implements MailProviderInterface
{
    public function __construct(private Config $config){}
    public function provision(array $mailbox):array
    {
        $data=$this->request('POST',$this->config->required('CRANEMAIL_PROVISION_PATH'),['email'=>$mailbox['email'],'password'=>$mailbox['password'],'display_name'=>$mailbox['display_name']]);
        $id=$data['id']??null;if(!is_string($id)||$id==='')throw new \RuntimeException('CraneMail response did not contain a mailbox id.');
        return ['status'=>'active','provider_id'=>$id];
    }
    public function suspend(string $id):void{$this->request('POST',str_replace('{id}',rawurlencode($id),$this->config->required('CRANEMAIL_SUSPEND_PATH')),[]);}
    public function delete(string $id):void{$this->request('DELETE',str_replace('{id}',rawurlencode($id),$this->config->required('CRANEMAIL_DELETE_PATH')),null);}
    public function connectionSettings():array{return [
        'smtp_host'=>$this->config->get('MAIL_SMTP_HOST',''),'smtp_port'=>$this->config->get('MAIL_SMTP_PORT','587'),'smtp_encryption'=>$this->config->get('MAIL_SMTP_ENCRYPTION','starttls'),
        'imap_host'=>$this->config->get('MAIL_IMAP_HOST',''),'imap_port'=>$this->config->get('MAIL_IMAP_PORT','993'),'imap_encryption'=>$this->config->get('MAIL_IMAP_ENCRYPTION','ssl'),
    ];}
    private function request(string $method,string $path,?array $body):array
    {
        $base=rtrim($this->config->required('CRANEMAIL_API_BASE_URL'),'/');$token=$this->config->required('CRANEMAIL_API_TOKEN');
        if(!str_starts_with($base,'https://'))throw new \RuntimeException('CraneMail API URL must use HTTPS.');
        $headers="Authorization: Bearer {$token}\r\nAccept: application/json\r\n";$content='';
        if($body!==null){$headers.="Content-Type: application/json\r\n";$content=json_encode($body,JSON_THROW_ON_ERROR);}
        $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$content,'timeout'=>(int)$this->config->get('CRANEMAIL_TIMEOUT','15'),'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $response=@file_get_contents($base.'/'.ltrim($path,'/'),false,$ctx);$line=$http_response_header[0]??'';preg_match('/\s(\d{3})\s/',$line,$m);$status=(int)($m[1]??0);
        if($response===false||$status<200||$status>=300)throw new \RuntimeException('CraneMail API request failed (HTTP '.($status?:'connection error').').');
        if($response==='')return [];$decoded=json_decode($response,true);if(!is_array($decoded))throw new \RuntimeException('CraneMail API returned invalid JSON.');return $decoded;
    }
}
