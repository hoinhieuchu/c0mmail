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
                try{$this->provider->delete($result['provider_id']);}catch(\Throwable $cleanup){error_log('Mailbox provider compensation failed: '.get_class($cleanup));}
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
    /** Returns credentials only after verifying ownership and active status. */
    public function credentialsForUser(int $mailboxId, int $userId): array
    {
        $mailbox = $this->repo->findForUser($mailboxId, $userId);
        if (!$mailbox || $mailbox['status'] !== 'active') throw new \RuntimeException('Active mailbox not found.');
        if (empty($mailbox['password_ciphertext']) || empty($mailbox['password_nonce']) || empty($mailbox['password_tag'])) {
            throw new \RuntimeException('Mailbox credentials are unavailable. Re-provision the mailbox password before using webmail.');
        }
        $password = openssl_decrypt(
            base64_decode($mailbox['password_ciphertext'], true) ?: '',
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            hex2bin($mailbox['password_nonce']) ?: '',
            hex2bin($mailbox['password_tag']) ?: ''
        );
        if ($password === false) throw new \RuntimeException('Unable to decrypt mailbox credentials. Check APP_KEY.');
        return [
            'mailbox' => ['id' => (int)$mailbox['id'], 'email' => $mailbox['email'], 'display_name' => $mailbox['display_name']],
            'credentials' => ['email' => $mailbox['email'], 'password' => $password],
            'settings' => $this->provider->connectionSettings(),
        ];
    }
    private function encryptionKey():string
    {
        $raw=$this->config->required('APP_KEY');if(!str_starts_with($raw,'base64:'))throw new \RuntimeException('APP_KEY must use base64: prefix.');
        $key=base64_decode(substr($raw,7),true);if($key===false||strlen($key)!==32)throw new \RuntimeException('APP_KEY must decode to exactly 32 bytes.');return $key;
    }
}
