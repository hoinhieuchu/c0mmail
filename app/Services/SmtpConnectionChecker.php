<?php
declare(strict_types=1);
namespace App\Services;

/** Authenticates to SMTP without sending a message. */
final class SmtpConnectionChecker
{
    public function verify(array $settings, array $credentials): void
    {
        $host=(string)($settings['smtp_host']??'');
        $port=(int)($settings['smtp_port']??587);
        $mode=strtolower((string)($settings['smtp_encryption']??'starttls'));
        $email=(string)($credentials['email']??'');
        $password=(string)($credentials['password']??'');
        if($host===''||!preg_match('/^[a-zA-Z0-9.-]+$/',$host)||$port<1||$port>65535||!in_array($mode,['ssl','starttls'],true)||$email===''||$password===''){
            throw new \InvalidArgumentException('SMTP connection settings or mailbox credentials are incomplete.');
        }
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true]]);
        $socket=@stream_socket_client(($mode==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$error,15,STREAM_CLIENT_CONNECT,$context);
        if(!$socket) throw new \RuntimeException('SMTP connection failed. Check host, port, firewall and TLS certificate.');
        stream_set_timeout($socket,15);
        try{
            $this->expect($socket,[220]);
            $this->command($socket,'EHLO c0m.me',[250]);
            if($mode==='starttls'){
                $this->command($socket,'STARTTLS',[220]);
                if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
                $this->command($socket,'EHLO c0m.me',[250]);
            }
            $this->command($socket,'AUTH LOGIN',[334]);
            $this->command($socket,base64_encode($email),[334]);
            $this->command($socket,base64_encode($password),[235]);
            $this->command($socket,'NOOP',[250]);
            try{$this->command($socket,'QUIT',[221]);}catch(\Throwable $ignored){}
        }finally{fclose($socket);}
    }

    private function command($socket,string $command,array $expected):string
    {
        $this->writeAll($socket,$command."\r\n");
        return $this->expect($socket,$expected);
    }
    private function writeAll($socket,string $data):void
    {
        while($data!==''){
            $written=fwrite($socket,$data);
            if($written===false||$written===0) throw new \RuntimeException('SMTP connection test could not write to the server.');
            $data=(string)substr($data,$written);
        }
    }
    private function expect($socket,array $expected):string
    {
        $response='';
        do{
            $line=fgets($socket,515);
            if($line===false){
                $meta=stream_get_meta_data($socket);
                throw new \RuntimeException(!empty($meta['timed_out'])?'SMTP connection test timed out.':'SMTP server closed the connection unexpectedly.');
            }
            $response.=$line;
        }while(isset($line[3])&&$line[3]==='-');
        $code=(int)substr($response,0,3);
        if(!in_array($code,$expected,true)) throw new \RuntimeException('SMTP connection test failed at protocol response '.$code.'.');
        return $response;
    }
}
