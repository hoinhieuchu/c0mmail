<?php
declare(strict_types=1);
namespace App\Services;
use App\Contracts\MailProviderInterface;
use App\Core\Config;
use App\Repositories\MailboxRepository;
final class MailboxService
{
    public function __construct(private MailboxRepository $repo,private MailProviderInterface $provider,private Config $config){}
    public function create(array $input):int
    {
        $email=strtolower(trim($input['local_part'])).'@'.strtolower($this->config->required('MAIL_DOMAIN'));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid mailbox address.');
        $key=$this->encryptionKey();$password=$input['password'];$nonce=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($password,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
        if($cipher===false)throw new \RuntimeException('Mailbox password encryption failed.');
        $result=$this->provider->provision(['email'=>$email,'password'=>$password,'display_name'=>$input['display_name']]);
        try {
            return $this->repo->create(['user_id'=>(int)$input['user_id'],'email'=>$email,'display_name'=>$input['display_name'],
                'provider'=>$this->config->get('MAIL_PROVIDER','manual'),'provider_id'=>$result['provider_id'],'status'=>$result['status'],
                'ciphertext'=>base64_encode($cipher),'nonce'=>bin2hex($nonce),'tag'=>bin2hex($tag)]);
        } catch (\Throwable $e) {
            if(is_string($result['provider_id']??null)&&$result['provider_id']!=='') {
                try{$this->provider->delete($result['provider_id']);}catch(\Throwable $cleanup){error_log('Mailbox provider compensation failed: '.$cleanup->getMessage());}
            }
            throw $e;
        }
    }
    public function activateManual(int $id):void
    {
        $m=$this->repo->find($id);
        if(!$m||$m['provider']!=='manual'||$m['status']!=='manual_pending')throw new \RuntimeException('Only pending manual mailboxes can be confirmed active.');
        $this->repo->setStatus($id,'active');
    }
    public function suspend(int $id):void
    {
        $m=$this->repo->find($id);if(!$m||$m['status']==='deleted')throw new \RuntimeException('Mailbox not found.');
        if($m['provider_mailbox_id'])$this->provider->suspend($m['provider_mailbox_id']);
        elseif($m['provider']!=='manual')throw new \RuntimeException('Provider mailbox ID is missing; refusing to claim remote suspension.');
        $this->repo->setStatus($id,'suspended');
    }
    public function updateManualPassword(int $id,string $password):void
    {
        if(strlen($password)<12)throw new \InvalidArgumentException('Mailbox password must be at least 12 characters.');
        $mailbox=$this->repo->find($id);
        if(!$mailbox||$mailbox['provider']!=='manual'||$mailbox['status']==='deleted')throw new \RuntimeException('Only existing manual mailboxes can have stored credentials updated here.');
        $key=$this->encryptionKey();$nonce=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($password,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
        if($cipher===false)throw new \RuntimeException('Mailbox password encryption failed.');
        $this->repo->updateCredentials($id,base64_encode($cipher),bin2hex($nonce),bin2hex($tag));
    }

    private function encryptionKey():string
    {
        $raw=$this->config->required('APP_KEY');if(!str_starts_with($raw,'base64:'))throw new \RuntimeException('APP_KEY must use base64: prefix.');
        $key=base64_decode(substr($raw,7),true);if($key===false||strlen($key)!==32)throw new \RuntimeException('APP_KEY must decode to exactly 32 bytes.');return $key;
    }
}
