<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Router;use App\Core\Config;use App\Providers\ManualMailProvider;use App\Providers\CraneMailProvider;
$count=0;
function test(string $name,callable $fn):void{global $count;$fn();$count++;echo "PASS {$name}\n";}
function same($expected,$actual):void{if($expected!==$actual)throw new RuntimeException('Expected '.var_export($expected,true).', got '.var_export($actual,true));}
test('autoload resolves application classes',fn()=>same(true,class_exists(Router::class)));
test('manual mode never reports remote provisioning as active',function(){$p=new ManualMailProvider(new Config(['MAIL_SMTP_HOST'=>'smtp.example.test']));$r=$p->provision(['email'=>'u@example.test','password'=>'secret','display_name'=>'User']);same('manual_pending',$r['status']);same(null,$r['provider_id']);same('smtp.example.test',$p->connectionSettings()['smtp_host']);});
test('manual provider refuses pretend remote suspension',function(){$p=new ManualMailProvider(new Config([]));try{$p->suspend('');throw new RuntimeException('Expected refusal');}catch(LogicException $e){same(true,str_contains($e->getMessage(),'control panel'));}});
test('CraneMail adapter rejects non-HTTPS endpoints',function(){$p=new CraneMailProvider(new Config(['CRANEMAIL_API_BASE_URL'=>'http://invalid.example','CRANEMAIL_API_TOKEN'=>'test','CRANEMAIL_PROVISION_PATH'=>'/mailboxes']));try{$p->provision(['email'=>'x@example.test','password'=>'secret','display_name'=>'X']);throw new RuntimeException('Expected HTTPS guard');}catch(RuntimeException $e){same(true,str_contains($e->getMessage(),'HTTPS'));}});
test('router extracts named route parameters',function(){$r=new Router();$seen=null;$r->get('/mailboxes/{id}',function($p)use(&$seen){$seen=$p['id'];});ob_start();$r->dispatch('GET','/mailboxes/123');ob_end_clean();same('123',$seen);});
echo "{$count} tests passed.\n";
