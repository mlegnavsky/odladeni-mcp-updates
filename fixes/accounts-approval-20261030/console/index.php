<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/assets/security.php';odlSecurityHeaders();
// API errors always return a JSON envelope, including failures before authentication.
$apiRequest=in_array((string)($_GET['action']??''),['fleet_overview_api','fleet_snapshots_api','fleet_operation_api','pair_status','fleet_report','installation_report'],true);
if($apiRequest){ini_set('display_errors','0');set_exception_handler(static function(Throwable $e){error_log('Odladeni API: '.$e->getMessage());http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'Server kontroly je dočasně vytížený. Poslední uložené výsledky zůstávají zachované.'],JSON_UNESCAPED_UNICODE);});}
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$embeddedPlugin=($_GET['action']??'')==='plugin_manager'&&($_GET['embedded']??'')==='1';
header('X-Frame-Options: '.($embeddedPlugin?'SAMEORIGIN':'DENY'));
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$scriptNonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-".$scriptNonce."'; style-src 'unsafe-inline'; img-src 'self' data: https://ps.w.org https://s.w.org https://ts.w.org https://s.wordpress.com https://s0.wp.com https://s0.wordpress.com; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src 'self'; frame-ancestors '".($embeddedPlugin?'self':'none')."'");
if (!is_file(__DIR__.'/config.php')) {http_response_code(503);exit('Administrace není nakonfigurována.');}
$c=require __DIR__.'/config.php';
foreach (['google_client_id','google_client_secret','redirect_uri','superadmin_email','database_path'] as $key) {
 if (!isset($c[$key]) || !is_string($c[$key]) || str_starts_with($c[$key],'DOPLNTE') || str_starts_with($c[$key],'/ABSOLUTNI')) {http_response_code(503);exit('Chybí bezpečná konfigurace.');}
}
$docroot=realpath($_SERVER['DOCUMENT_ROOT']??'') ?: '';
$dbdir=realpath(dirname($c['database_path']));
if (!$dbdir || ($docroot && ($dbdir===$docroot || str_starts_with($dbdir,$docroot.DIRECTORY_SEPARATOR)))) {http_response_code(503);exit('Databáze musí být mimo veřejný web.');}
if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl')) {http_response_code(503);exit('Chybí PHP PDO_SQLITE nebo CURL.');}
ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
session_name('ODLADENI_ADMIN');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);session_start();
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require_once dirname(__DIR__).'/assets/plugin-presentation.php';
require_once dirname(__DIR__).'/assets/accounts.php';
require_once dirname(__DIR__).'/assets/admin-login.php';
require_once dirname(__DIR__).'/assets/site-previews.php';
function randomToken():string{return bin2hex(random_bytes(32));}
function go(string $url):never{header('Location: '.$url, true,303);exit;}
function requestJson(string $url, array $post=[], string $bearer=''):array{
 $ch=curl_init($url);$headers=['Accept: application/json'];
 if($bearer!=='')$headers[]='Authorization: Bearer '.$bearer;
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true]);
 if($post){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
 $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 $json=is_string($body)?json_decode($body,true):null;
 if($status!==200||!is_array($json))throw new RuntimeException('Google authentication request failed');return $json;
}
try{$db=new PDO('sqlite:'.$c['database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=5000');$db->exec('CREATE TABLE IF NOT EXISTS users (email TEXT PRIMARY KEY, google_sub TEXT UNIQUE, role TEXT NOT NULL CHECK(role IN (\'superadmin\',\'admin\',\'viewer\')), enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login TEXT)');
 $super=strtolower(trim($c['superadmin_email']));
 $q=$db->prepare('SELECT role,enabled FROM users WHERE email=?');$q->execute([$super]);$superRow=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if(!$superRow){$q=$db->prepare('INSERT OR IGNORE INTO users(email,role,enabled) VALUES (?,\'superadmin\',1)');$q->execute([$super]);}
 elseif($superRow['role']!=='superadmin'||(int)$superRow['enabled']!==1){$q=$db->prepare('UPDATE users SET role=\'superadmin\', enabled=1 WHERE email=?');$q->execute([$super]);}

}catch(Throwable $e){error_log('Odladeni admin DB error: '.$e->getMessage());http_response_code(503);if($apiRequest){header('Content-Type: application/json; charset=utf-8');exit(json_encode(['ok'=>false,'error'=>'Databáze kontroly je dočasně vytížená. Zkuste to později.'],JSON_UNESCAPED_UNICODE));}exit('Administrace momentálně není dostupná.');}
// WordPress pairing registry. The first supported host is intentionally pinned to prevent SSRF.
$db->exec("CREATE TABLE IF NOT EXISTS connected_sites (site_url TEXT PRIMARY KEY, site_name TEXT NOT NULL, shared_key TEXT NOT NULL, approved_by TEXT NOT NULL, connected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen TEXT)");
odlAccountsSchema($db);
// Shared policy with MCP: /private/mcp-validation-rules.json outside the public document root.
// This file is the single source of truth for labels, descriptions and required flags.
function sharedQualityPolicyPath(): string {
 $private=dirname(__DIR__,2).'/private';
 if(!is_dir($private)||is_link($private))throw new RuntimeException('Soukromé úložiště pravidel není dostupné.');
 return $private.'/mcp-validation-rules.json';
}
function sharedQualityPolicy(): array {
 $path=sharedQualityPolicyPath();
 if(is_link($path)||!is_file($path)||!is_readable($path))throw new RuntimeException('Pravidla MCP nejsou dostupná.');
 $raw=file_get_contents($path);$data=is_string($raw)?json_decode($raw,true):null;
 if(!is_array($data)||!isset($data['rules'])||!is_array($data['rules']))throw new RuntimeException('Neplatný formát pravidel MCP.');
 $expected=['main_php','version','author','update_uri','manifest','readme','php_syntax','signature'];
 $rules=[];
 foreach($data['rules'] as $r){
  if(!is_array($r)||!is_string($r['key']??null)||!in_array($r['key'],$expected,true)||isset($rules[$r['key']])||
   !is_string($r['label']??null)||!is_string($r['description']??null)||!is_bool($r['required']??null))throw new RuntimeException('Neplatná položka pravidel MCP.');
  $rules[$r['key']]=$r;
 }
 if(count($rules)!==count($expected))throw new RuntimeException('Neúplná pravidla MCP.');
 return $data;
}
function sharedQualitySave(array $required): void {
 $path=sharedQualityPolicyPath();$lock=fopen(dirname($path).'/mcp-validation-rules.lock','c');
 if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Pravidla nelze uzamknout.');
 try {
  $policy=sharedQualityPolicy();
  foreach($policy['rules'] as &$rule){$rule['required']=!empty($required[$rule['key']]);}unset($rule);
  $backup=dirname($path).'/mcp-validation-rules-backup-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)).'.json';
  if(!copy($path,$backup))throw new RuntimeException('Zálohu pravidel nelze vytvořit.');
  chmod($backup,0600);
  $policy['updated_utc']=gmdate('c');
  $tmp=$path.'.'.bin2hex(random_bytes(6));
  $json=json_encode($policy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Pravidla nelze zapsat.');
  chmod($tmp,0600);
  if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Pravidla nelze uložit.');}
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
$qualityPolicy=sharedQualityPolicy();
$qualityRules=[];
foreach($qualityPolicy['rules'] as $r){$qualityRules[$r['key']]=[$r['label'],$r['description']];}
function qualitySettings(PDO $db):array {
 $policy=sharedQualityPolicy();$out=[];
 foreach($policy['rules'] as $r){$out[$r['key']]=$r['required']?1:0;}
 return $out;
}
function inspectPluginZip(string $file, array $rules):array {
 require_once dirname(__DIR__).'/api/wordpress-catalog.php';
 require_once dirname(__DIR__).'/api/validator.php';
 try{$identity=odlZipIdentity($file);$report=odladeniValidateZip($file,$identity['version']);$report['checks']['signature']=null;return $report+['name'=>$identity['name'],'sha256'=>hash_file('sha256',$file)];}
 catch(Throwable $e){return ['checks'=>[],'errors'=>[$e->getMessage()],'name'=>'—','version'=>'—','sha256'=>hash_file('sha256',$file)];}
}

function pairingSiteUrl(string $raw): ?string {
 $parts=parse_url($raw);
 if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment'])||!in_array((string)($parts['path']??''),['','/'],true))return null;
 $host=strtolower((string)($parts['host']??''));
 if(strlen($host)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/D',$host))return null;
 $records=dns_get_record($host,DNS_A|DNS_AAAA);
 if(!is_array($records)||!$records)return null;
 foreach($records as $record){
  $ip=(string)($record['ip']??$record['ipv6']??'');
  if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
 }
 return 'https://'.$host;
}
function pairingRequest(string $url,array $payload):array {
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 $data=is_string($body)?json_decode($body,true):null;
 if($status!==200||!is_array($data)||empty($data['ok']))throw new RuntimeException('WordPress pairing confirmation failed (HTTP '.$status.')');
 return $data;
}
$action=(string)($_GET['action']??'');
require_once __DIR__.'/pairing-service-v2.php';
require_once __DIR__.'/fleet-workspace.php';
require_once __DIR__.'/installation-registry.php';
require_once __DIR__.'/design-library.php';
if($action==='installation_report')odlInstallationReport($db);
if($action==='fleet_report')odlFleetReport($db);
if($action==='pair_status')odlPairStatus($db);
// A completed pairing is idempotent: never reuse a consumed token to rotate keys.

if($action==='login'){
 odlSecurityThrottle($db,'google-login',(string)($_SERVER['REMOTE_ADDR']??'unknown'),$c['google_client_secret'],60,600);
 $state=randomToken();$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
 $pairId=(string)($_GET['pair_id']??'');$pending=$_SESSION['pair_requests'][$pairId]??null;
 if(!$pending&&is_array($_SESSION['pair_request']??null)&&($_SESSION['pair_request']['id']??'')===$pairId)$pending=$_SESSION['pair_request'];
 $pair=is_array($pending)&&(int)($pending['expires']??0)>=time()?['id'=>$pairId]:null;
 $mcpId=(string)($_GET['mcp_request']??'');$mcpFlow=isset($_SESSION['mcp_pending'][$mcpId])?$mcpId:null;
 $_SESSION['oauth_flows'][$state]=['verifier'=>$verifier,'started'=>time(),'pair'=>$pair,'mcp'=>$mcpFlow];
 foreach($_SESSION['oauth_flows'] as $k=>$v)if((int)($v['started']??0)<time()-600)unset($_SESSION['oauth_flows'][$k]);
 if(count($_SESSION['oauth_flows'])>80)array_shift($_SESSION['oauth_flows']);
 $params=['client_id'=>$c['google_client_id'],'redirect_uri'=>$c['redirect_uri'],'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account'];
 go('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));
}
if($action==='callback'){
 $state=(string)($_GET['state']??'');$code=(string)($_GET['code']??'');
 $flow=$_SESSION['oauth_flows'][$state]??null;
 if(!$state||!is_array($flow)||!$code||time()-(int)($flow['started']??0)>600){http_response_code(403);exit('Přihlášení vypršelo. Vraťte se na párovací stránku a přihlaste se znovu.');}
 $verifier=(string)$flow['verifier'];$pair=$flow['pair']??null;unset($_SESSION['oauth_flows'][$state]);
 try{
  $token=requestJson('https://oauth2.googleapis.com/token',['client_id'=>$c['google_client_id'],'client_secret'=>$c['google_client_secret'],'code'=>$code,'code_verifier'=>$verifier,'grant_type'=>'authorization_code','redirect_uri'=>$c['redirect_uri']]);
  $profile=requestJson('https://openidconnect.googleapis.com/v1/userinfo',[],(string)($token['access_token']??''));
  $email=strtolower(trim((string)($profile['email']??'')));$sub=(string)($profile['sub']??'');
  if(!$email||!$sub||($profile['email_verified']??false)!==true)throw new RuntimeException('Unverified Google identity');
  $user=odlAccountsGoogle($db,$profile);
  session_regenerate_id(true);if(empty($user['enabled'])){unset($_SESSION['email'],$_SESSION['sub'],$_SESSION['csrf']);$_SESSION['pending_account']=['email'=>$email,'sub'=>$sub,'at'=>time()];go('/console/?action=pending');}unset($_SESSION['pending_account']);$_SESSION['email']=$email;$_SESSION['sub']=$sub;$_SESSION['csrf']=randomToken();$_SESSION['last_activity']=time();if(!empty($flow['mcp'])&&isset($_SESSION['mcp_pending'][$flow['mcp']]))go('/console/?action=mcp_authorize&request='.rawurlencode($flow['mcp']));if(is_array($pair)&&isset($pair['id']))go('/console/?action=pair&id='.rawurlencode((string)$pair['id']));if(is_array($pair)&&isset($pair['site'],$pair['token']))go('/console/?action=pair&'.http_build_query($pair));go('/console/');
 }catch(Throwable $e){error_log('Odladeni login failed: '.$e->getMessage());if(is_array($pair)&&isset($pair['id'])){unset($_SESSION['email'],$_SESSION['sub'],$_SESSION['csrf']);$_SESSION['pair_login_error']='Google účet není povolený v Odladění nebo přihlášení nebylo dokončeno. Použijte účet zaregistrovaný v Odladění.';go('/console/?action=pair&id='.rawurlencode((string)$pair['id']));}http_response_code(403);exit('Přihlášení se nezdařilo nebo nemáte přístup.');}
}
if($action==='pending'){
 $pending=$_SESSION['pending_account']??null;$waiting=is_array($pending)&&time()-(int)($pending['at']??0)<3600?odlAccountsUser($db,(string)($pending['email']??'')):null;
 if(!$waiting||!hash_equals((string)($waiting['google_sub']??''),(string)($pending['sub']??'')))go('/console/');
 $q=$db->prepare('SELECT status FROM account_approvals WHERE email=?');$q->execute([$waiting['email']]);$review=$q->fetchColumn();
 $heading=!empty($waiting['enabled'])?'Účet je schválený':($review==='rejected'?'Přístup nebyl povolen':'Čekáte na schválení');
 ?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($heading)?> — Odladění</title><style>body{font:16px/1.6 system-ui;background:#eef3ff;color:#172343;margin:0}main{max-width:560px;margin:8vh auto;padding:28px;background:white;border-radius:20px}a{display:inline-block;padding:12px 18px;border-radius:10px;background:#2163e9;color:white;text-decoration:none}</style></head><body><main><p>ODLADĚNÍ · GOOGLE ÚČET</p><h1><?=h($heading)?></h1><p><?=h($waiting['email'])?></p><p><?=!empty($waiting['enabled'])?'Superadministrátor váš účet schválil. Pokračujte přihlášením.':'O přístupu rozhoduje superadministrátor. Do schválení nemáte přístup k webům ani k jejich správě přes ChatGPT.'?></p><a href="/console/?action=login">Znovu ověřit přístup přes Google</a> <p><a href="/">Úvodní stránka</a></p></main></body></html><?php exit;
}
$email=(string)($_SESSION['email']??'');$user=null;
if($email && time()-(int)($_SESSION['last_activity']??0)<3600){$q=$db->prepare('SELECT email,google_sub,role,enabled FROM users WHERE email=?');$q->execute([$email]);$user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$user||!$user['enabled']||!hash_equals((string)$user['google_sub'],(string)($_SESSION['sub']??'')))$user=null;}
if(!$user){unset($_SESSION['email'],$_SESSION['sub'],$_SESSION['csrf']);}
if($user){$_SESSION['last_activity']=time();odlSecurityThrottle($db,'console-account',$user['email'],$c['google_client_secret'],240,60);}
// Enforce before any action handler, including image, JSON and iframe endpoints.
if($user&&!odlAccountsConsoleAllowed($db,$user,$action,(string)$_SERVER['REQUEST_METHOD'],$_GET,$_POST)){http_response_code(403);exit('K této oblasti nebo webu nemáte oprávnění.');}
if($user&&$user['role']!=='superadmin'&&$action==='site'&&$_SERVER['REQUEST_METHOD']==='GET')go('/console/?open='.rawurlencode((string)($_GET['site']??'')));
if($action==='mcp_authorize'){require __DIR__.'/accounts-oauth.php';exit;}
if($user&&in_array($action,['users','chatgpt'],true)){require __DIR__.'/accounts.php';exit;}
require_once __DIR__.'/fleet-workspace.php';
if($action==='fleet_overview_api')odlFleetApi($db,$user);
if($action==='fleet_operation_api')odlFleetOperationApi($db,$user);
if($action==='fleet_snapshots_api')odlFleetSnapshotsApi($db,$user);
if($action==='templately')odlDesignHandle($db,$user);
if($action==='installations'&&(!$user||$user['role']!=='superadmin')){http_response_code(403);exit('Přehled instalací je dostupný pouze superadministrátorovi.');}
if($action==='pair'){require __DIR__.'/pairing-connect-v2.php';exit;}
if($action==='fleet_preview')odlPreviewServe($db,$user,(string)($_GET['site']??''));
if($action==='wp_admin_login'){require __DIR__.'/admin-login.php';exit;}
if($action==='plugin_trash'){require __DIR__.'/plugin-trash.php';exit;}
if($action==='plugin_library'){require __DIR__.'/plugin-library.php';exit;}
if($action==='fleet_bulk'){require __DIR__.'/fleet-bulk.php';exit;}
if($action==='plugin_manager'){require __DIR__.'/plugin-manager.php';exit;}
// Read-only diagnostic requests are authenticated with a per-site HMAC.
function bridgeDiagnostics(string $site,string $secret):array {
 require_once dirname(__DIR__).'/assets/fleet-management.php';
 return fmDiagnostics($site);
}

function odladeniUpdate(string $site,string $secret,string $plugin):array {
 $path='/wp-json/odladeni-bridge/v1/update-plugin';
 $ts=(string)time();$nonce=bin2hex(random_bytes(12));
 $sig=hash_hmac('sha256',"POST\n".$path."\n".$ts."\n".$nonce,$secret);
 $ch=curl_init($site.$path);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['plugin'=>$plugin]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Odladeni-Time: '.$ts,'X-Odladeni-Nonce: '.$nonce,'X-Odladeni-Signature: '.$sig]]);
 $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 $data=is_string($body)?odlBridgeJsonDecode($body):null;
 if($status!==200||!is_array($data)||empty($data['ok']))throw new RuntimeException('Aktualizace nebyla potvrzena, HTTP '.$status);
 return $data;
}
require __DIR__.'/diagnostics.php';
if($action==='site_auto_update' && $_SERVER['REQUEST_METHOD']==='POST'){
 if(!$user||!in_array($user['role'],['admin','superadmin'],true)||!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Pristup odepren');}
 $site=pairingSiteUrl((string)($_POST['site']??''));$plugin=(string)($_POST['plugin']??'');$enabled=($_POST['enabled']??'')==='1';
 if(!$site||!preg_match('~^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\\.php$~D',$plugin)){http_response_code(400);exit('Neplatny pozadavek');}
 $q=$db->prepare('SELECT shared_key FROM connected_sites WHERE site_url=?');$q->execute([$site]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if(!$row){http_response_code(404);exit('Web nenalezen');}
 try{
  $path='/wp-json/odladeni-bridge/v1/auto-update';$ts=(string)time();$nonce=bin2hex(random_bytes(12));
  $sig=hash_hmac('sha256',"POST\n".$path."\n".$ts."\n".$nonce,(string)$row['shared_key']);
  $ch=curl_init($site.$path);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['plugin'=>$plugin,'enabled'=>$enabled]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Odladeni-Time: '.$ts,'X-Odladeni-Nonce: '.$nonce,'X-Odladeni-Signature: '.$sig]]);
  $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
  $data=is_string($body)?json_decode($body,true):null;
  if($http!==200||!is_array($data)||empty($data['ok'])||($data['auto_update']??null)!==$enabled)throw new RuntimeException('WordPress nepotvrdil nastaveni, HTTP '.$http);
  $_SESSION['update_notice']='Nastaveni automatickych aktualizaci bylo potvrzeno.';
 }catch(Throwable $e){error_log('Odladeni auto: '.$e->getMessage());$_SESSION['update_notice']='Zmena se neprovedla: '.$e->getMessage();}
 go('/console/?action=site&site='.rawurlencode($site).'#web-plugins');
}
if($action==='site_plugin_state' && $_SERVER['REQUEST_METHOD']==='POST'){
 if(!$user||!in_array($user['role'],['admin','superadmin'],true)||!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Access denied');}
 $site=pairingSiteUrl((string)($_POST['site']??''));$plugin=(string)($_POST['plugin']??'');$enabled=($_POST['enabled']??'')==='1';
 if(!$site||!preg_match('~^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\\.php$~D',$plugin)){http_response_code(400);exit('Invalid request');}
 if($plugin==='prvotni-instalace/prvotni-instalace.php'){http_response_code(409);exit('Hlavni instalace je chranena proti deaktivaci z Odladeni.');}
 $q=$db->prepare('SELECT shared_key FROM connected_sites WHERE site_url=?');$q->execute([$site]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if(!$row){http_response_code(404);exit('Site not found');}
 try{
  require_once dirname(__DIR__).'/assets/fleet-management.php';
  $receipt=fmManage(['site_url'=>$site,'action'=>$enabled?'activate':'deactivate','plugin'=>$plugin,'request_id'=>(string)($_POST['request_id']??'')]);
  $data=$receipt['result'];
  if(($data['active']??null)!==$enabled)throw new RuntimeException('WordPress nepotvrdil požadovaný stav pluginu.');
  $_SESSION['update_notice']='Stav pluginu potvrzen WordPressem.';
 }catch(Throwable $e){error_log('Odladeni plugin state: '.$e->getMessage());$_SESSION['update_notice']='Zmena se neprovedla: '.$e->getMessage();}
 go('/console/?action=site&site='.rawurlencode($site).'#web-plugins');
}
if($action==='site_plugin_update' && $_SERVER['REQUEST_METHOD']==='POST'){
 if(!$user||!in_array($user['role'],['superadmin','admin'],true)||!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Pristup odepren');}
 $site=pairingSiteUrl((string)($_POST['site']??''));$plugin=(string)($_POST['plugin']??'');
 if(!$site||!preg_match('~^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\\.php$~D',$plugin)){http_response_code(400);exit('Neplatny pozadavek');}
 $q=$db->prepare('SELECT shared_key FROM connected_sites WHERE site_url=?');$q->execute([$site]);$record=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if(!$record){http_response_code(404);exit('Web nenalezen');}
 try{require_once dirname(__DIR__).'/assets/fleet-management.php';$receipt=fmManage(['site_url'=>$site,'action'=>'update','plugin'=>$plugin,'request_id'=>(string)($_POST['request_id']??'')]);$result=$receipt['result'];$_SESSION['update_notice']='Aktualizováno: '.$plugin.' '.(string)($result['version']??'');}
 catch(Throwable $e){error_log($e->getMessage());$_SESSION['update_notice']='Aktualizace nebyla potvrzena: '.$e->getMessage();}
 go('/console/?action=site&site='.rawurlencode($site).'#web-plugins');
}
function bridgeDisconnect(string $site,string $secret):void {
 $path='/wp-json/odladeni-bridge/v1/disconnect';
 $ts=(string)time();$nonce=bin2hex(random_bytes(12));
 $signature=hash_hmac('sha256',"POST\n".$path."\n".$ts."\n".$nonce,$secret);
 $ch=curl_init($site.$path);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','X-Odladeni-Time: '.$ts,'X-Odladeni-Nonce: '.$nonce,'X-Odladeni-Signature: '.$signature]]);
 $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 $json=is_string($body)?json_decode($body,true):null;
 if($status!==200||!is_array($json)||empty($json['disconnected']))throw new RuntimeException('WordPress nepotvrdil odpojení (HTTP '.$status.').');
}
require_once __DIR__.'/site-disconnect.php';
if($action==='disconnect' && $_SERVER['REQUEST_METHOD']==='POST'){
 if(!$user||$user['role']!=='superadmin'||empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Neplatné oprávnění.');}
 $site=pairingSiteUrl((string)($_POST['site']??''));
 if(!$site||($_POST['confirm']??'')!=='disconnect'){http_response_code(400);exit('Odpojení nebylo potvrzeno.');}
 if(!odlAccountsCan($db,$user,$site,true)){http_response_code(403);exit('K tomuto webu nemáte oprávnění.');}
 $mode=($_POST['registry_only']??'')==='1'?'registry':'bridge';
 $q=$db->prepare('SELECT shared_key FROM connected_sites WHERE site_url=?');$q->execute([$site]);$record=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if(!$record){http_response_code(404);exit('Web není registrován.');}
 try{
  odlDisconnectRegistry($db,$site,(string)$record['shared_key'],(string)$user['email'],$mode,'bridgeDisconnect');
  $_SESSION['disconnect_notice']=$site.($mode==='registry'?' byl odebrán z Odladění. Novou instalaci můžete znovu připojit.':' byl odpojen v Odladění i WordPressu.');
  go('/console/');
 }catch(Throwable $e){error_log('Odladeni disconnect: '.$e->getMessage());$_SESSION['disconnect_notice']='Odpojení webu '.$site.' se nepodařilo. Pokud byl WordPress přeinstalován, zaškrtněte odebrání pouze z Odladění.';go('/console/?open='.rawurlencode($site));}
}

// Authenticated rules API. JSON reads require a Google session; writes additionally require CSRF.
if($action==='rules_api'){
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store');
 if(!$user || !in_array($user['role'],['superadmin','admin'],true)){
  http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Google administrator login required']);exit;
 }
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $rows=[];$current=qualitySettings($db);
  foreach($qualityRules as $key=>$desc){$rows[]=['key'=>$key,'label'=>$desc[0],'description'=>$desc[1],'required'=>(bool)($current[$key]??false)];}
  echo json_encode(['ok'=>true,'rules'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){
  header('Allow: GET, POST');http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed']);exit;
 }
 if($user['role']!=='superadmin' || !hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_ODLADENI_CSRF']??''))){
  http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Superadmin and CSRF required']);exit;
 }
 $raw=file_get_contents('php://input');$data=json_decode($raw?:'',true);
 if(!is_array($data) || !isset($data['key'],$data['required']) || !is_string($data['key']) || !array_key_exists($data['key'],$qualityRules) || !is_bool($data['required'])){
  http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Expected key and boolean required']);exit;
 }
  $flags=qualitySettings($db);$flags[$data['key']]=$data['required'];
  sharedQualitySave($flags);
echo json_encode(['ok'=>true,'key'=>$data['key'],'required'=>$data['required']]);exit;
}
$qualityNotice='';$qualityReport=null;
if($action==='quality' && $_SERVER['REQUEST_METHOD']==='POST'){
 if(!$user || !hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Neplatné oprávnění.');}
 $cmd=(string)($_POST['cmd']??'');
 if($cmd==='quality_save'){
  if($user['role']!=='superadmin'){http_response_code(403);exit('Pouze superadministrátor.');}
  $selected=(array)($_POST['required']??[]);
   $flags=[];foreach($qualityRules as $key=>$_){$flags[$key]=isset($selected[$key]);}
   sharedQualitySave($flags);
  go('/console/?action=quality&saved=1');
 }elseif($cmd==='quality_test'){
  if(!in_array($user['role'],['superadmin','admin'],true)){http_response_code(403);exit('Nedostatečné oprávnění.');}
  $upload=$_FILES['quality_zip']??null;
  if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??''))){$qualityNotice='ZIP se nepodařilo načíst.';}
  elseif((int)$upload['size']>15*1024*1024){$qualityNotice='ZIP překračuje limit 15 MB.';}
  else{$qualityReport=inspectPluginZip((string)$upload['tmp_name'],$qualityRules);}
 }else{http_response_code(400);exit('Neznámá operace.');}
}
if($_SERVER['REQUEST_METHOD']==='POST' && $action!=='quality' && $action!=='chatgpt' && $action!=='templately'){
 if(!$user||!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Neplatné oprávnění.');}
 $cmd=(string)($_POST['cmd']??'');
 if($cmd==='logout'){$_SESSION=[];session_destroy();go('/console/');}
 if($user['role']!=='superadmin'){http_response_code(403);exit('Pouze superadministrátor.');}
 $target=strtolower(trim((string)($_POST['email']??'')));$role=(string)($_POST['role']??'viewer');
 if(!filter_var($target,FILTER_VALIDATE_EMAIL)||strlen($target)>254||!in_array($role,['admin','viewer'],true)||$target===$super){http_response_code(400);exit('Neplatné údaje.');}
 if($cmd==='invite'){$q=$db->prepare('INSERT INTO users(email,role,enabled) VALUES (?,?,1) ON CONFLICT(email) DO UPDATE SET role=excluded.role,enabled=1');$q->execute([$target,$role]);odlAccountsReview($db,$user,$target,'approved');}
 elseif($cmd==='disable'){odlAccountsReview($db,$user,$target,'rejected');}
 else {http_response_code(400);exit('Neznámá operace.');}
 go('/console/');
}
$chatgptTest=null;
if($user && $user['role']==='superadmin' && $action==='chatgpt' && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cmd']??'')==='chatgpt_test'){
 if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Neplatné oprávnění.');}
 $chatgptTest=['time'=>gmdate('Y-m-d H:i:s').' UTC','checks'=>[]];
 $chatgptTest['checks']['Google superadministrátor']=true;
 $chatgptTest['checks']['PHP cURL']=extension_loaded('curl');
 $chatgptTest['checks']['Databáze administrace']=$db instanceof PDO;
 $chatgptTest['checks']['Logo Odladění']=is_file(__DIR__.'/../assets/odladeni-mustek.svg');
 $ch=curl_init('https://plaginy.odladeni.cz/mcp/');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_NOBODY=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
 $chatgptTest['checks']['HTTPS dostupnost MCP']=$http>=200&&$http<500;
 $chatgptTest['http']=$http;$chatgptTest['error']=$error;
}
if($user)$_SESSION['last_activity']=time();
$siteDetail=null;$siteDiagnostics=null;$siteError='';
if($user && $action==='site'){
 $requested=pairingSiteUrl((string)($_GET['site']??''));
 if($requested){
  $q=$db->prepare('SELECT site_url,site_name,approved_by,connected_at,shared_key FROM connected_sites WHERE site_url=?');$q->execute([$requested]);$siteDetail=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
  if($siteDetail){try{$siteDiagnostics=bridgeDiagnostics($requested,(string)$siteDetail['shared_key']);}catch(Throwable $e){$siteError=$e->getMessage();}}
 }
}
odlFleetSchema($db);$fleetCache=[];if($user){foreach($db->query('SELECT site_url,payload FROM fleet_overview')->fetchAll(PDO::FETCH_ASSOC) as $cached){$payload=json_decode($cached['payload'],true);if(is_array($payload)&&odlAccountsCan($db,$user,$cached['site_url']))$fleetCache[$cached['site_url']]=$payload;}}
$sites=[];if($user){$sites=odlAccountsSites($db,$user);}
$users=[];if($user && $user['role']==='superadmin')$users=$db->query('SELECT email,role,enabled,last_login FROM users ORDER BY role DESC,email')->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="fleet-csrf" content="<?=h((string)($_SESSION['csrf']??''))?>"><title>Odladění – administrace</title><link rel="icon" type="image/svg+xml" href="/assets/odladeni-mustek.svg"><style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#172343;background:#f4f7ff}*{box-sizing:border-box}body{margin:0}.top{background:linear-gradient(105deg,#0b1e51,#283ba4 75%,#5030a4);color:white;padding:25px max(24px,calc((100vw - 1120px)/2))}.top h1{margin:0;font-size:27px}.top p{margin:6px 0 0;color:#dce6ff}main{max-width:1120px;margin:34px auto;padding:0 24px}.panel{background:white;border:1px solid #e0e7f5;border-radius:18px;padding:24px;box-shadow:0 8px 28px #1b37620a;margin-bottom:18px}.btn{background:#2163e9;color:white;border:0;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.muted{color:#69758e}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}.stat{padding:20px;background:#f2f5ff;border-radius:14px}.stat b{display:block;font-size:24px;margin-top:7px}input,select{padding:11px;border:1px solid #ccd5e6;border-radius:9px;max-width:100%}form.inline{display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap}table{border-collapse:collapse;width:100%}td,th{text-align:left;border-bottom:1px solid #e8edf6;padding:6px 8px;line-height:1.25}.quality-ok{color:#167346;font-weight:700}.quality-fail{color:#b42335;font-weight:700}.quality-warn{color:#976000;font-weight:700}.quality-note{padding:12px 14px;background:#fff7e5;border-radius:10px;margin:12px 0}.plugin-table{font-size:13px}.plugin-table td,.plugin-table th{padding:5px 8px}.danger{background:#b42335}h2{margin-top:0}a{color:#2163e9}@media(max-width:650px){table{font-size:12px}.panel{padding:16px}}
.brandbar{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:14px}.brand-logo{width:48px;height:48px;border-radius:12px;background:#fff}.top .brand h1{font-size:25px}.top-tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.top-link{display:inline-block;padding:10px 13px;border-radius:10px;background:#ffffff20;color:#fff;text-decoration:none;font-weight:650}.top-link:hover{background:#ffffff35}.account-pill{display:flex;align-items:center;gap:9px;background:#ffffff20;border:1px solid #ffffff30;padding:5px 12px 5px 5px;border-radius:40px}.account-avatar{width:34px;height:34px;display:grid;place-items:center;background:#fff;color:#2840a0;border-radius:50%;font-weight:800}.account-text{display:flex;flex-direction:column;line-height:1.25;font-size:12px}.account-text strong{font-size:13px}.account-compact{display:flex;align-items:center;gap:9px}.account-compact .account-avatar{background:#eaf0ff}@media(max-width:650px){.top .brand h1{font-size:20px}.top-tools{width:100%}.account-text{max-width:175px;overflow-wrap:anywhere}}
.account-menu{position:relative}.account-menu summary{list-style:none;cursor:pointer}.account-menu summary::-webkit-details-marker{display:none}.account-menu summary:focus-visible{outline:3px solid #fff;outline-offset:3px}.account-dropdown{position:absolute;right:0;top:calc(100% + 9px);z-index:30;width:min(290px,90vw);padding:10px;background:#fff;color:#172343;border:1px solid #dce5f5;border-radius:14px;box-shadow:0 14px 40px #0b1e5130}.account-dropdown a{display:block;padding:11px 13px;border-radius:9px;color:#172343;text-decoration:none;font-weight:650}.account-dropdown a:hover,.account-dropdown a:focus{background:#eef3ff}.account-dropdown .menu-caption{padding:8px 12px 11px;color:#69758e;font-size:12px;overflow-wrap:anywhere;border-bottom:1px solid #e8edf6;margin-bottom:5px}.account-dropdown .menu-separator{border-top:1px solid #e8edf6;margin:6px 0}.account-dropdown button{background:transparent;color:#b42335;border:0;text-align:left;width:100%;padding:11px 13px;font-weight:650;cursor:pointer}.account-dropdown button:hover{background:#fff1f1;border-radius:9px}
.site-tab-panel[hidden]{display:none!important}.site-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 22px}.site-tabs a:hover,.site-tabs a:focus-visible{background:#dbe8ff;border-color:#6197ff;color:#0d48bf;box-shadow:0 3px 10px #2163e926}.site-tabs a.active{background:#2163e9;color:#fff;border-color:#2163e9}.site-tabs a{padding:10px 13px;border:1px solid #dbe5f8;border-radius:9px;background:#f2f5ff;text-decoration:none;font-weight:700}.site-summary{padding:16px;background:#f8faff;border-radius:12px;margin-bottom:22px}.site-diagnostics{margin-top:22px;border:1px solid #dce5f5;border-radius:12px;padding:15px}.site-diagnostics summary{cursor:pointer;font-weight:700;color:#2163e9}.site-diagnostics[open] summary{margin-bottom:16px}#web-design{margin-top:24px;padding-top:14px;border-top:1px solid #e8edf6}#web-plugins{scroll-margin-top:18px}
.top{padding-top:24px;padding-bottom:24px;box-shadow:0 8px 24px #0b1e5115}body{background:radial-gradient(ellipse at 70% -10%,#e2eaff 0,transparent 45%),#f5f7fc;color:#1d2b4c}main{max-width:1170px}.panel{border-color:#e7ebf4;border-radius:22px;box-shadow:0 12px 35px #16234b0a;padding:28px}.panel h2{font-size:23px;letter-spacing:-.6px}.btn{border-radius:12px;padding:12px 18px;box-shadow:0 4px 10px #2163e918}.btn:hover{background:#1553d4}.account-dropdown{border-radius:16px}.muted{line-height:1.65}.plugins-header{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:22px}.plugins-header h2{margin:7px 0 4px}.plugins-header p{margin:0}.eyebrow{font-size:11px;letter-spacing:2px;font-weight:800;color:#667bb0}.add-plugin{white-space:nowrap}.library-stats{display:flex;gap:12px;flex-wrap:wrap;margin:24px 0}.library-stats>div{display:flex;flex-direction:column;gap:5px;min-width:145px;flex:1;background:#f4f7ff;padding:18px 20px;border:1px solid #e8eefb;border-radius:15px}.library-stats strong{font-size:25px;letter-spacing:-.7px}.library-stats span{font-size:12px;color:#72809c}.plugin-library{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:15px}.plugin-card{border:1px solid #e4eaf5;border-radius:18px;padding:19px;background:linear-gradient(145deg,#fff,#fafbff);transition:box-shadow .2s,transform .2s}.plugin-card:hover{box-shadow:0 12px 25px #1e347414;transform:translateY(-2px)}.plugin-card-top{display:flex;align-items:center;gap:12px}.plugin-symbol{width:45px;height:45px;flex:none;border-radius:13px;display:grid;place-items:center;background:linear-gradient(145deg,#2c67e8,#6540c5);color:white;font-size:25px}.plugin-info{min-width:0;flex:1}.plugin-info h3{margin:0 0 3px;font-size:16px;overflow-wrap:anywhere}.plugin-info p{margin:0;color:#7986a0;font-size:12px;overflow-wrap:anywhere}.version-pill{border-radius:30px;background:#eaf1ff;color:#2359bf;padding:7px 10px;font-weight:750;font-size:12px;white-space:nowrap}.plugin-meta{display:flex;gap:10px;flex-wrap:wrap;color:#76829c;font-size:12px;margin:17px 0}.plugin-versions{border-top:1px solid #edf0f7;padding-top:13px}.plugin-versions summary{cursor:pointer;list-style:none;color:#245cce;font-size:13px;font-weight:750;display:flex;justify-content:space-between}.plugin-versions summary::-webkit-details-marker{display:none}.version-list{padding-top:9px}.version-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 0;border-top:1px solid #f0f2f8}.version-row span{display:flex;flex-direction:column;gap:4px}.version-row small{color:#8490a6}.version-row a{font-size:12px;font-weight:700;white-space:nowrap}.empty-state{padding:30px;text-align:center;border:1px dashed #bdc8e0;border-radius:16px}
@media(max-width:650px){.panel{padding:19px}.plugin-library{grid-template-columns:1fr}.library-stats>div{min-width:110px}}
.dash-sidebar{position:fixed;top:100px;left:0;bottom:0;width:242px;background:#fff;border-right:0;padding:24px 14px;display:flex;flex-direction:column;justify-content:space-between;z-index:5}.dash-sidebar nav{display:grid;gap:8px}.dash-sidebar a{padding:14px;border-radius:12px;text-decoration:none;color:#263659;font-weight:700;font-size:14px}.dash-sidebar a:hover{background:#e1e9ff}.sidebar-brand{display:flex;gap:12px;align-items:center;border-top:1px solid #dae3f6;padding:20px 8px}.sidebar-brand img{border-radius:10px}.sidebar-brand strong,.sidebar-brand small{display:block}.sidebar-brand small{font-size:12px;color:#7483a3}.dash-main{margin:20px 20px 32px 242px;max-width:none;padding:0}.top{padding:20px 34px;min-height:100px}.dash-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:20px}.dash-stat{display:flex;align-items:flex-start;gap:14px;padding:21px 15px;border:1px solid #e3eaf8;background:white;border-radius:18px;text-decoration:none;color:#1d2d51;box-shadow:0 9px 22px #182b5709;font-size:14px;font-weight:700}.dash-stat i{display:grid;place-items:center;width:48px;height:48px;flex:none;color:#fff;border-radius:14px;font-style:normal;font-size:25px}.ds-green{background:#16c96a}.ds-purple{background:#7634e8}.ds-blue{background:#249af5}.ds-orange{background:#ff9b30}.dash-stat strong,.dash-stat small{display:block}.dash-stat strong{font-size:28px;margin:8px 0}.dash-stat small{color:#7e8ca5;font-size:12px;font-weight:400}.dash-heading{display:flex;justify-content:space-between;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:23px}.dash-heading h2{margin:7px 0}.dash-heading p{margin:0}.dash-heading input{min-width:210px}.dash-count{font-size:12px;background:#dcf8e8;color:#117c45;border-radius:20px;padding:6px 11px;vertical-align:middle}.dash-web{display:grid;grid-template-columns:215px 1fr;gap:18px;padding:16px;border:1px solid #e0e8f6;border-radius:19px;background:linear-gradient(145deg,#fff,#fbfcff);margin-bottom:14px}.web-art{min-height:206px;border-radius:13px;background:radial-gradient(circle at 70% 30%,#3668b4,transparent 40%),linear-gradient(130deg,#090e23,#1b2954);display:flex;flex-direction:column;justify-content:center;align-items:center;color:white;text-align:center;padding:12px;gap:10px}.web-art strong{font-size:19px;overflow-wrap:anywhere}.web-art small{color:#aabfee;overflow-wrap:anywhere}.web-title{display:flex;align-items:center;gap:16px}.web-title h3{margin:0 0 5px;font-size:22px}.dot{width:14px;height:14px;border-radius:50%;background:#18c567;box-shadow:0 0 0 7px #dff9e9;flex:none;margin:0 7px}.web-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:23px 0}.web-facts>span{display:flex;gap:8px;align-items:center;min-width:0}.web-facts i{padding:9px;background:#eaf1ff;color:#3777e7;border-radius:9px;font-style:normal;font-weight:800}.web-facts small{font-size:12px;color:#8190aa}.web-facts strong{display:block;color:#213353;font-size:12px;margin-top:4px;overflow-wrap:anywhere}.web-registered{font-size:12px;font-weight:700;color:#0e8748}.web-registered small{font-weight:400;color:#8692a7;margin-left:12px}.web-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px;border-top:1px solid #eaf0f8;padding-top:13px}.web-actions a:not(.btn){text-decoration:none;background:#eef3ff;padding:11px;border-radius:10px;font-size:12px;font-weight:750}.dash-bottom{display:grid;grid-template-columns:1fr 1fr;gap:18px}.quick-tiles{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}.qt{display:flex;align-items:center;gap:12px;padding:15px;border-radius:15px;text-decoration:none;color:#223153;font-weight:750}.qt b{font-size:24px;width:43px;height:43px;flex:none;display:grid;place-items:center;background:#ffffffa0;border-radius:12px}.qt small{display:block;color:#7886a0;font-weight:400;font-size:12px;margin-top:5px}.qt-purple{background:#eee7ff}.qt-blue{background:#e5efff}.qt-green{background:#def7eb}.qt-orange{background:#fff0e4}.dash-events>div{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid #e8edf7}.dash-events i{font-style:normal;font-size:22px;color:#476be2;background:#eef2ff;width:42px;height:42px;border-radius:12px;display:grid;place-items:center}.dash-events strong,.dash-events small{display:block}.dash-events small{color:#8591a7;margin-top:5px}[hidden]{display:none!important}@media(max-width:1180px){.dash-stats{grid-template-columns:repeat(2,1fr)}.dash-bottom{grid-template-columns:1fr}.web-facts{grid-template-columns:repeat(2,1fr)}}@media(max-width:840px){.dash-sidebar{position:static;width:auto;display:block;padding:8px}.dash-sidebar nav{display:flex;overflow-x:auto}.dash-sidebar a{white-space:nowrap}.sidebar-brand{display:none}.dash-main{margin:18px 15px}.dash-web{grid-template-columns:1fr}.web-art{min-height:100px}.top{min-height:0}}@media(max-width:550px){.dash-stats{grid-template-columns:1fr 1fr;gap:8px}.dash-stat{padding:12px;gap:8px}.dash-stat i{width:35px;height:35px;font-size:18px}.dash-stat strong{font-size:22px}.quick-tiles{grid-template-columns:1fr}.web-facts{grid-template-columns:1fr 1fr}}/* Odladeni modern dashboard v2 */ 
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#111c44;background:#eef3ff;font-size:15px}
body{background:linear-gradient(135deg,#edf4ff 0%,#f7f9ff 70%,#f0f4ff 100%);color:#101d44;min-height:100vh}
.top{background:linear-gradient(105deg,#2429a1 0%,#1650d9 55%,#5425c5 100%);padding:20px 30px;min-height:101px;box-shadow:0 8px 30px #2c3eae15}
.top h1{font-size:26px;letter-spacing:-.6px}.top p{font-size:13px;opacity:.92}
.dash-sidebar{position:relative;top:auto;float:left;height:auto;min-height:calc(100vh - 100px);width:246px;background:linear-gradient(180deg,#fff 0%,#f0f5ff 100%);border-right:1px solid #dce6fa;padding:22px 14px;box-shadow:none;overflow:visible;justify-content:space-between}
.dash-sidebar nav{gap:7px}.dash-sidebar a{font-size:14px;padding:15px 16px;border-radius:12px;color:#182856;font-weight:600;display:flex;align-items:center;gap:8px}
.dash-sidebar a:hover,.dash-sidebar a.is-active{background:linear-gradient(110deg,#1971ff,#2860e9);color:white;box-shadow:0 8px 20px #2060e92c}
.sidebar-brand{background:#fff;border:1px solid #e4ebfb;border-radius:15px;padding:13px 10px;box-shadow:0 5px 18px #142b5c0b;gap:10px}
.sidebar-brand svg{width:62px;height:49px;flex:none}.sidebar-brand strong{font-size:16px}.sidebar-brand small{font-size:11px}
.dash-main{margin:0 20px 35px 266px;padding:24px 0;max-width:none}
.panel{border:1px solid #dce6fa;border-radius:20px;box-shadow:0 10px 30px #1a326212;padding:24px}
.dash-stat{border:1px solid #dfe7fa;border-radius:17px;box-shadow:0 8px 24px #193b7c0d;padding:20px;align-items:center;gap:17px}
.dash-stat i{width:58px;height:58px;border-radius:14px;font-size:29px;box-shadow:inset 0 3px 12px #ffffff3d}
.dash-stat strong{font-size:30px;letter-spacing:-1px}.dash-stat small{font-size:13px;color:#62769c}
.dash-heading h2{font-size:27px;letter-spacing:-.8px}.eyebrow{color:#5979bd;font-size:11px;letter-spacing:2px;font-weight:800}
.dash-count{font-size:13px;padding:6px 14px}.dash-web{grid-template-columns:240px minmax(0,1fr);border-radius:18px;border-color:#dce6f8;padding:16px;background:#fff;box-shadow:0 5px 15px #24418408}
.web-art{min-height:220px;background:radial-gradient(circle at 80% 25%,#185de6,transparent 52%),linear-gradient(125deg,#0c173b,#1b3470);border-radius:14px}
.web-title h3{font-size:24px;letter-spacing:-.6px}.web-facts{margin:23px 0 18px;gap:9px}
.web-facts>span{padding:12px 7px;border-radius:12px;background:#f3f6fd}.web-facts i{font-size:18px;padding:9px}.web-facts strong{font-size:13px}
.web-actions a{border-radius:10px!important;padding:12px 15px!important;font-size:13px!important}.web-actions a:not(.btn){background:#eef3ff}
.qt{padding:17px;border-radius:15px}.qt b{width:47px;height:47px;border-radius:13px}.dash-events>div{padding:15px 0}
.btn{border-radius:11px;background:linear-gradient(115deg,#1379ff,#2454ec);box-shadow:0 4px 12px #2666df22}
a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid #6ea7ff;outline-offset:3px}
@media(max-width:1180px){.dash-web{grid-template-columns:1fr}.web-art{min-height:110px}.dash-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:840px){.dash-sidebar{position:static;float:none;width:auto;height:auto;padding:10px 12px;box-shadow:none}.dash-sidebar nav{display:flex;flex-wrap:nowrap;overflow-x:auto}.dash-sidebar a{white-space:nowrap;padding:10px}.sidebar-brand{display:none}.dash-main{margin:0 12px 24px;padding-top:16px}}
@media(max-width:560px){.dash-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-stat{display:block;padding:13px}.dash-stat i{width:40px;height:40px;font-size:22px}.dash-stat strong{font-size:24px}.web-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* Integrated navigation and tabbed site detail */
.top{padding-left:266px;position:relative;z-index:2}.dash-sidebar{position:fixed!important;top:0!important;bottom:0!important;left:0!important;height:100dvh!important;width:246px!important;float:none!important;z-index:10!important;padding-top:122px!important;background:linear-gradient(180deg,#f8faff,#eff4ff)!important;box-shadow:none!important}.dash-main{margin-left:266px!important}.site-tab-panel[hidden]{display:none!important}.site-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.site-tabs a.active{background:#2163e9;color:#fff;border-color:#2163e9}.site-tab-panel{scroll-margin-top:20px}
@media(max-width:840px){.top{padding-left:20px}.dash-sidebar{position:relative!important;top:auto!important;height:auto!important;width:100%!important;padding:10px!important;z-index:1!important}.dash-main{margin-left:12px!important}}

/* Final unified layout and interaction refinements */
:root{--od-bg:#e8f0ff;--od-border:#d8e4fb}
html,body{background:linear-gradient(135deg,#e7f0ff 0%,#eef3ff 58%,#e8edff 100%)!important;min-height:100%}
.top{position:relative!important;z-index:30!important;width:100%!important;padding:15px 28px!important;min-height:100px!important;box-sizing:border-box!important}
.brandbar{width:100%!important;max-width:none!important}.header-bridge{width:78px;height:65px;display:flex;align-items:center;justify-content:center;flex:none}.header-bridge svg{width:77px;height:58px;filter:drop-shadow(0 2px 6px #050a5a25)}.header-bridge svg path{stroke-width:5}
.dash-sidebar{position:relative!important;left:auto!important;top:auto!important;bottom:auto!important;float:left!important;height:auto!important;min-height:calc(100vh - 100px)!important;padding:20px 12px 14px!important;width:218px!important;box-sizing:border-box!important;z-index:12!important;overflow:visible!important;justify-content:space-between!important;background:transparent!important;border-right:0!important;box-shadow:none!important}
.dash-main{margin-left:238px!important;margin-right:20px!important;padding-top:22px!important}
.dash-sidebar nav a{transition:background .18s,color .18s,transform .18s}.dash-sidebar nav a:hover{transform:translateX(3px)}
.site-tabs a{display:inline-flex!important;align-items:center;justify-content:center;cursor:pointer!important;transition:background .18s,color .18s,box-shadow .18s,transform .18s!important;position:relative;z-index:1}
.site-tabs a:hover,.site-tabs a:focus-visible{background:#d8e6ff!important;color:#1148c9!important;box-shadow:0 3px 12px #1748a624;transform:translateY(-2px)}
.site-tabs a.active,.site-tabs a[aria-selected="true"]{background:#2064f4!important;color:#fff!important;box-shadow:0 5px 14px #1a5dec3b}
.site-tabs a.active:hover{background:#114bd1!important;color:#fff!important}
.panel,.dash-stat,.dash-web{box-shadow:0 10px 28px #173e8311!important}
@media(max-width:840px){.top{padding:12px 16px!important}.dash-sidebar{position:relative!important;top:auto!important;height:auto!important;width:100%!important;padding:10px!important}.dash-main{margin:0 12px!important}.header-bridge{width:55px}.header-bridge svg{width:55px}}
.plugin-symbol-main{background:linear-gradient(145deg,#7934ef,#3a35cb)!important;box-shadow:0 4px 14px #6c3fca33}.plugin-symbol-main svg{display:block}/* Sidebar scrolls with the document, not fixed to the viewport. */
.dash-sidebar{position:absolute;top:100px;left:0;bottom:auto;width:242px;min-height:calc(100% - 100px);background:#fff;border-right:0;padding:24px 14px;display:flex;flex-direction:column;justify-content:flex-start;gap:32px;z-index:5}
.dash-sidebar nav{display:grid;gap:8px}
.dash-sidebar .sidebar-brand{margin-top:auto}
.dash-main{margin:20px 20px 32px 242px;max-width:none;padding:0}
@media(max-width:760px){.dash-sidebar{position:static;width:auto;min-height:0;padding:12px}.dash-main{margin:16px;padding:0}}
@media(min-width:841px){.dash-sidebar .sidebar-brand{position:fixed!important;bottom:12px!important;left:12px!important;top:auto!important;width:170px!important;z-index:20!important;margin:0!important;box-sizing:border-box!important}}<?php readfile(dirname(__DIR__).'/assets/plugin-ui.css');readfile(dirname(__DIR__).'/assets/fleet-workspace.css');readfile(dirname(__DIR__).'/assets/console-responsive.css');?>
</style></head><body><header class="top"><div class="brandbar"><div class="brand"><span class="header-bridge"><svg viewBox="0 0 110 80" role="img" aria-label="Logo Odladění – most a šipky" xmlns="http://www.w3.org/2000/svg"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 41 L26 41 L38 34 L72 34 L84 41 L101 41 M18 30 L18 52 M92 30 L92 52 M10 52 L100 52" stroke="#ffffff" stroke-width="4"/><path d="M24 27 Q55 1 86 27 M36 24 Q55 10 74 24" stroke="#14c7f6" stroke-width="5"/><path d="M24 60 Q55 88 86 60 M36 61 Q55 76 74 61" stroke="#168bff" stroke-width="5"/><path d="M17 36 L10 42 L17 48 M93 36 L100 42 L93 48" stroke="#ffffff" stroke-width="3"/></g></svg></span><div><h1>Odladění <small> / Administrace</small></h1><p>Bezpečná správa uživatelů a připojených webů</p></div></div><?php if($user):?><nav class="top-tools" aria-label="Správa a účet"><button type="button" class="mobile-menu-toggle" aria-expanded="false" aria-controls="dashboard-nav"><span aria-hidden="true">☰</span> Menu</button><details class="account-menu"><summary class="account-pill" title="Otevřít nastavení účtu"><span class="account-avatar"><?=h(strtoupper(substr((string)$user['email'],0,1)))?></span><span class="account-text"><strong>Google účet ▾</strong><?=h($user['email'])?></span></summary><div class="account-dropdown"><div class="menu-caption">Přihlášeno přes Google · <?=h($user['role'])?></div><?php if($user['role']==='superadmin'):?><a href="/console/">Přehled webů</a><a href="/console/?action=users">⚙ Správa uživatelů</a><?php endif;?><?php if($user['role']==='superadmin'):?><a href="/console/?action=plugins">Správa pluginů</a><a href="/console/?action=quality">Pravidla a validace</a><?php endif;?><a href="/console/?action=chatgpt">Propojit s ChatGPT</a><div class="menu-separator"></div><form method="post" action="/console/"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="cmd" value="logout"><button type="submit">Odhlásit se</button></form></div></details></nav><?php endif;?></div></header><?php if($user):?><aside class="dash-sidebar" id="dashboard-nav"><nav><a href="/console/">⌂ &nbsp; Přehled webů</a><a href="/console/?action=chatgpt">↗ &nbsp; Propojit s ChatGPT</a><?php if($user['role']==='superadmin'):?><a href="/console/?action=installations">◎ &nbsp; Instalace a propojení</a><a href="/console/?action=plugins">◈ &nbsp; Správa pluginů</a><a href="/console/?action=plugin_trash">🗑 &nbsp; Koš pluginů</a><a href="/console/?action=templately">▧ &nbsp; Designy a šablony</a><a href="/console/?action=users">♙ &nbsp; Uživatelé · schvalování <strong>(<?=(int)$db->query("SELECT COUNT(*) FROM account_approvals WHERE status='pending'")->fetchColumn()?>)</strong></a><a href="/console/?action=quality">♢ &nbsp; Pravidla a validace</a><?php endif;?></nav><div class="sidebar-brand"><svg viewBox="0 0 110 80" role="img" aria-label="Logo Odladění – most a šipky" xmlns="http://www.w3.org/2000/svg"><g fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 41 L26 41 L38 34 L72 34 L84 41 L101 41 M18 30 L18 52 M92 30 L92 52 M10 52 L100 52" stroke="#3335ad" stroke-width="4"/><path d="M24 27 Q55 1 86 27 M36 24 Q55 10 74 24" stroke="#14c7f6" stroke-width="5"/><path d="M24 60 Q55 88 86 60 M36 61 Q55 76 74 61" stroke="#168bff" stroke-width="5"/><path d="M17 36 L10 42 L17 48 M93 36 L100 42 L93 48" stroke="#3934c8" stroke-width="3"/></g></svg><span><strong>Odladění</strong><small>MCP 2.6.1</small></span></div></aside><?php endif;?><main class="dash-main">
<?php if($action==='installations')odlInstallationRender($db,$user);?>
<?php if($action==='templately'&&$user)odlDesignRender($db,$user);?>
<?php if($user && $user['role']==='superadmin' && $action==='chatgpt'):?><section class="panel"><h2>Spojení s ChatGPT</h2><p class="muted">Přehled propojení a bezpečné kontroly serverové části.</p><div class="grid"><div class="stat">Verze MCP<b>2.6.1</b><small>Poslední ověření: 18. 10. 2026</small></div><div class="stat">Autorizace ChatGPT<b>Ověřena</b><small>Poslední úspěšné volání z ChatGPT</small></div><div class="stat">Test všech 42 funkcí<b>Neproveden</b><small>Destruktivní operace nejsou testovány</small></div></div><h3>Test spojení jedním kliknutím</h3><p>Bez změny pluginů ověří PHP, databázi, logo a HTTPS dostupnost MCP.</p><form method="post" action="/console/?action=chatgpt"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="cmd" value="chatgpt_test"><button class="btn">Otestovat spojení</button></form><?php if($chatgptTest):?><h3>Výsledky · <?=h($chatgptTest['time'])?></h3><table><tbody><?php foreach($chatgptTest['checks'] as $label=>$ok):?><tr><td><?=h($label)?></td><td class="<?=$ok?'quality-ok':'quality-fail'?>"><?=$ok?'V pořádku':'Chyba'?></td><td><span class="auto-state <?=!empty($plugin['auto_update'])?'yes':''?>"><?=!empty($plugin['auto_update'])?'&#10003; Zapnuto':'&#9633; Vypnuto'?></span></td></tr><?php endforeach;?></tbody></table><p class="muted">HTTP MCP: <?=h((string)$chatgptTest['http'])?> <?=h($chatgptTest['error'])?></p><?php endif;?><p class="muted">Skutečné autorizované volání ChatGPT a úplný test nástrojů je nutné provést z ChatGPT. Tento webový test je nenahrazuje.</p></section><?php endif;?>
<?php if($user && $action==='site'):?><section class="panel"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0"><?=h((string)($siteDetail['site_name']??'Připojený web'))?></h2><a class="btn" href="/console/">← Všechny weby</a></div>
<?php if(!$siteDetail):?><p>Web není registrován.</p><?php else:?>
<p><a href="<?=h($siteDetail['site_url'])?>" target="_blank" rel="noopener noreferrer"><?=h($siteDetail['site_url'])?> ↗</a></p><div class="site-tabs"><a href="#web-overview">Přehled</a><a href="#web-plugins">Pluginy</a><a href="#web-design">Design a šablona</a><a href="#web-diagnostics">Diagnostika</a></div><div id="web-overview" class="site-summary site-tab-panel"><h3>Přehled webu</h3><p>Schválil: <?=h($siteDetail['approved_by'])?> · Připojeno: <?=h($siteDetail['connected_at'])?></p><?php if($siteDiagnostics):?><div class="grid"><div class="stat">WordPress<b><?=h((string)($siteDiagnostics['wordpress_version']??'—'))?></b></div><div class="stat">PHP<b><?=h((string)($siteDiagnostics['php_version']??'—'))?></b></div><div class="stat">Pluginy<b><?=count($siteDiagnostics['plugins']??[])?></b></div></div><?php endif;?></div>
<?php if($siteError):?><p><strong>Diagnostika nedostupná:</strong> <?=h($siteError)?></p><?php elseif($siteDiagnostics):?>
<div id="web-plugins" class="site-tab-panel" hidden><?php if(isset($_SESSION['update_notice'])):?><p role="status"><?=h((string)$_SESSION['update_notice'])?></p><?php unset($_SESSION['update_notice']);endif;?><?php if(isset($_SESSION['plugin_operation_result'])):$operation=$_SESSION['plugin_operation_result'];unset($_SESSION['plugin_operation_result']);?>
<div role="status" style="padding:18px 20px;border-radius:12px;margin:18px 0;background:<?=$operation['status']==='success'?'#e9f7ef':'#fff5df'?>;border:1px solid <?=$operation['status']==='success'?'#b9e1c9':'#efd49b'?>">
<strong><?=$operation['status']==='success'?'✓':'!'?> <?=h((string)$operation['title'])?></strong><ul><?php foreach((array)($operation['steps']??[]) as $step):?><li><?=h((string)$step)?></li><?php endforeach;?></ul></div><?php endif;?><h3>Pluginy na webu</h3><p><a class="btn" href="/console/?action=plugin_manager&amp;site=<?=rawurlencode((string)$siteDetail['site_url'])?>">＋ Přidat a spravovat pluginy</a></p><p class="muted">Přehled nainstalovaných pluginů, jejich verzí a stavu.</p>
<style>/* Compact one-line WordPress plugin inventory for Odladeni */
.plugin-table{width:100%;border-collapse:collapse;table-layout:fixed}
.plugin-table th,.plugin-table td{border-bottom:1px solid #e5eaf2;padding:8px 9px;vertical-align:middle}
.plugin-table th:first-child{width:36%}
.plugin-table th:nth-child(2){width:10%}
.plugin-table th:nth-child(3){width:9%}
.plugin-table th:nth-child(4){width:10%}
.plugin-table th:nth-child(5){width:16%}
.plugin-table th:nth-child(6){width:19%}
.plugin-table tbody tr:hover{background:#f7faff}
.plugin-table .plugin-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.plugin-table .icon-ok{color:#15803d;font-weight:700;font-size:19px}
.plugin-table .icon-off{color:#94a3b8;font-size:19px}
.plugin-table .plugin-state.is-inactive{background:#e2e8f0;color:#475569}.plugin-table .plugin-state.is-active{background:#2163e9;color:#fff}.plugin-table button:disabled{opacity:.6;cursor:wait}
.plugin-table .btn{padding:5px 9px;font-size:12px;min-height:0;border-radius:6px}
.plugin-table form{margin:0}
.plugin-table details{position:relative;display:inline-block}
.plugin-table summary{list-style:none;cursor:pointer;color:#2456b8;font-size:20px}
.plugin-table summary::-webkit-details-marker{display:none}
.plugin-table details[open]{z-index:5}
.plugin-table .plugin-info{position:absolute;top:28px;right:0;width:350px;max-width:75vw;background:white;border:1px solid #cbd5e1;border-radius:8px;padding:12px;box-shadow:0 8px 24px #17234324;font-size:13px;line-height:1.5;white-space:normal}
.plugin-table .auto-toggle{width:32px;height:19px;display:inline-flex;align-items:center;padding:2px;background:#dce2ea;border:1px solid #cbd5e1;border-radius:30px;cursor:pointer;transition:background .15s}
.plugin-table .auto-toggle .knob{width:12px;height:12px;border-radius:50%;background:white;box-shadow:0 1px 3px #0002}
.plugin-table .auto-toggle.on{background:#16803c;justify-content:flex-end}
.plugin-table .auto-toggle:disabled{opacity:.45;cursor:not-allowed}
.plugin-table .auto-toggle:focus-visible,.plugin-table summary:focus-visible{outline:2px solid #2456b8;outline-offset:3px}
@media(max-width:760px){.plugin-table{min-width:740px}.plugin-table-wrap{overflow-x:auto}}
</style><div class="plugin-table-wrap">
<table class="plugin-table">
<thead><tr><th>Plugin</th><th>Verze</th><th>Aktivní</th><th>Podrobnosti</th><th>Aktualizace</th><th>Automaticky</th><th>Odstranit</th></tr></thead>
<tbody>
<?php foreach (($siteDiagnostics['plugins'] ?? []) as $plugin):
 $file=(string)($plugin['file']??'');
 $presentation=odlPluginPresentation($file,(string)($plugin['name']??$file),(string)($plugin['description']??''));
 $autoKnown=array_key_exists('auto_update',$plugin);
 $auto=$autoKnown && !empty($plugin['auto_update']);
 $canUpdate=!empty($plugin['update_available'])&&!empty($siteDiagnostics['capabilities']['update'])&&in_array($user['role'],['admin','superadmin'],true);
?>
<tr>
<td><div class="plugin-name-row"><?=$presentation['own']?odlPluginIcon($presentation['icon']):''?><div class="plugin-name" title="<?=h($presentation['name'])?>"><?=h($presentation['name'])?></div></div></td>
<td><?=h((string)($plugin['version']??''))?></td>
<td><form data-plugin-operation method="post" action="/console/?action=site_plugin_state">
<input type="hidden" name="request_id" value="<?=bin2hex(random_bytes(16))?>">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<input type="hidden" name="site" value="<?=h($siteDetail['site_url'])?>">
<input type="hidden" name="plugin" value="<?=h($file)?>">
<input type="hidden" name="enabled" value="<?=!empty($plugin['active'])?'0':'1'?>">
<button type="submit" class="btn plugin-state <?=!empty($plugin['active'])?'is-active':'is-inactive'?>" <?=($file==='prvotni-instalace/prvotni-instalace.php'||!in_array($user['role'],['admin','superadmin'],true))?'disabled':''?> title="<?=!empty($plugin['active'])?'Deaktivovat':'Aktivovat'?>"><?=!empty($plugin['active'])?'&#10003; Vypnout':'&#9675; Zapnout'?></button>
</form></td>
<td><details><summary aria-label="Zobrazit podrobnosti" title="Podrobnosti">&#9432;</summary><div class="plugin-info">
<strong><?=h($presentation['name'])?></strong>
<p><?=h($presentation['description']?:'Popis není dostupný.')?></p>
<p><strong>Poslední změny:</strong> <?=h((string)($plugin['changelog']??'Historie změn není dostupná.'))?></p>
</div></details></td>
<td><?php if($canUpdate):?><form data-plugin-operation method="post" action="/console/?action=site_plugin_update"><input type="hidden" name="request_id" value="<?=bin2hex(random_bytes(16))?>">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<input type="hidden" name="site" value="<?=h($siteDetail['site_url'])?>">
<input type="hidden" name="plugin" value="<?=h($file)?>">
<button type="submit" class="btn">Aktualizovat<?=!empty($plugin['new_version'])?' na '.h((string)$plugin['new_version']):''?></button>
</form><?php else:?>&#8212;<?php endif;?></td>
<td><form method="post" action="/console/?action=site_auto_update">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<input type="hidden" name="site" value="<?=h($siteDetail['site_url'])?>">
<input type="hidden" name="plugin" value="<?=h($file)?>">
<input type="hidden" name="enabled" value="<?=$auto?'0':'1'?>">
<button type="submit" class="auto-toggle <?=$auto?'on':''?>" aria-label="Automatické aktualizace: <?=$auto?'zapnuty':'vypnuty'?>" title="<?=$auto?'Vypnout':'Zapnout'?> automatické aktualizace" <?=!$autoKnown?'disabled':''?>><span class="knob"></span></button>
</form></td>
<td><?php $canDelete=$file!=='prvotni-instalace/prvotni-instalace.php'&&in_array($user['role'],['admin','superadmin'],true)&&!empty($siteDiagnostics['capabilities']['delete']);?>
<?php if($canDelete&&empty($plugin['active'])):?><form data-plugin-operation data-confirm-delete method="post" action="/console/?action=site_plugin_delete">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="site" value="<?=h($siteDetail['site_url'])?>"><input type="hidden" name="plugin" value="<?=h($file)?>"><input type="hidden" name="request_id" value="<?=bin2hex(random_bytes(16))?>">
<label style="font-size:11px;display:block"><input type="checkbox" name="confirm_delete" value="1" required> Potvrzuji</label><button class="btn delete-plugin" type="submit">Smazat</button></form>
<?php else:?><button class="btn delete-plugin" disabled title="<?=$file==='prvotni-instalace/prvotni-instalace.php'?'Hlavní instalace chrání spojení.':'Před smazáním plugin deaktivujte.'?>">Smazat</button><?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?></div>
<section id="web-design" class="site-tab-panel" hidden><h3>Design a šablona</h3><?php $themeInfo=$siteDiagnostics['theme']??$siteDiagnostics['active_theme']??null;?><p><?php if(is_array($themeInfo)):?>Aktivní šablona: <strong><?=h((string)($themeInfo['name']??$themeInfo['stylesheet']??'Neznámá'))?></strong><?php if(isset($themeInfo['version'])):?> · verze <?=h((string)$themeInfo['version'])?><?php endif;?><?php elseif(is_string($themeInfo)&&$themeInfo!==''):?>Aktivní šablona: <strong><?=h($themeInfo)?></strong><?php else:?>Aktivní šablonu zatím připojený WordPress neposílá v diagnostice. Zobrazíme ji po rozšíření můstku.<?php endif;?></p><p><a href="<?=h($siteDetail['site_url'])?>" target="_blank" rel="noopener noreferrer">Otevřít vzhled webu ↗</a></p></section>
<section id="web-diagnostics" class="site-tab-panel" hidden><h3>Diagnostika webu</h3>
<p class="muted">Aktuální spojení a údaje, které poskytuje připojený WordPress.</p>
<div class="diag-controls"><button type="button" class="btn" id="refresh-diagnostics" data-site="<?=h($siteDetail['site_url'])?>">Aktualizovat diagnostiku</button><span id="diagnostics-summary" role="status" aria-live="polite">Poslední kontrola: <?=h((string)($siteDiagnostics['checked_at']??'nezískána'))?></span></div>
<table class="diag-table"><thead><tr><th>Kontrolovaný bod</th><th>Výsledek</th></tr></thead><tbody id="diagnostics-rows">
<?php foreach(odlDiagnosticRows($siteDiagnostics,$siteError) as $row):?><tr data-diagnostic="<?=h($row['key'])?>"><td><?=h($row['label'])?></td><td data-result><span class="odl-status <?=$row['ok']?'ok':'fail'?>"><span class="mark" aria-hidden="true"><?=$row['ok']?'✓':'✕'?></span><?=$row['ok']?'Ověřeno':'Nepotvrzeno'?></span><small><?=h($row['detail'])?></small></td></tr><?php endforeach;?></tbody></table>
<p class="diag-note">Možnosti správy ukazují podporu hlášenou WordPressem. Kontrola neinstaluje ani nemaže pluginy a neprovádí obnovu zálohy. Obnova a zásahy se potvrzují až při skutečné operaci.</p>
<details><summary>Pravidla pro vydání pluginů v Odladění</summary><p class="muted">Tato pravidla se ověřují při zpracování konkrétního ZIPu; nejsou výsledkem kontroly webu.</p><ul><?php foreach($qualityPolicy['rules'] as $rule):?><li><strong><?=h($rule['label'])?></strong> — <?=h($rule['description'])?> (<?=$rule['required']?'povinné':'volitelné'?>)</li><?php endforeach;?></ul></details>
<?php if($user['role']==='superadmin')odlDisconnectForm((string)$siteDetail['site_url'],(string)$_SESSION['csrf'],true);?></section>
<?php endif;?></section><?php endif;?>
<?php if(!$user):?><section class="panel"><h2>Přihlášení a registrace</h2><p class="muted">Přihlaste se svým Google účtem. Nový účet se vytvoří automaticky; přístup ke konkrétním webům vám přidělí superadministrátor.</p><a class="btn" href="?<?=h(http_build_query(array_filter(['action'=>'login','site'=>($action==='pair'?(string)($_GET['site']??''):''),'token'=>($action==='pair'?(string)($_GET['token']??''):'')])) )?>">Přihlásit / registrovat přes Google</a></section>
<?php else:?><?php if(isset($_SESSION['disconnect_notice'])):?><section class="panel" role="status"><?=h((string)$_SESSION['disconnect_notice'])?></section><?php unset($_SESSION['disconnect_notice']);endif;?><?php if($action==='pair'):?>
<section class="panel"><h2>Schválit propojení WordPressu</h2>
<?php $site=pairingSiteUrl((string)($_GET['site']??''));$token=(string)($_GET['token']??''); ?>
<?php if($site && preg_match('/^[a-f0-9]{64}$/D',$token) && in_array($user['role'],['superadmin','admin'],true)):?>
<p>Chcete propojit <strong><?=h($site)?></strong> s účtem <strong><?=h($user['email'])?></strong>?</p>
<form method="post" action="/console/?action=pair"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="site" value="<?=h($site)?>"><input type="hidden" name="token" value="<?=h($token)?>"><button class="btn" type="submit">Schválit připojení</button> <a href="/console/">Zrušit</a></form>
<?php else:?><p>Neplatný nebo nepovolený požadavek na párování.</p><?php endif;?></section>
<?php endif;?>
<?php if($action==='quality'):?><section class="panel" id="quality"><p><a class="btn" href="/console/">← Zpět do konzole</a></p><h2>Pravidla a validace pluginů</h2>
<p class="muted">Standard 1.2 · Aktuální popisy pravidel včetně oznamování aktualizací a kolečka průběhu. Nastavení povinných požadavků a předběžná kontrola ZIPu.</p>
<div class="quality-note"><strong>Stav integrace:</strong> Pravidla jsou sdílená s MCP a ZIP lze předběžně otestovat. Administrace a MCP nyní čtou a ukládají společný soubor pravidel. Publikační cesty kontrolují ZIP i PHP syntaxi a skutečné veřejné stažení. Selhání nebo neprovedená kontrola zablokuje nabídku nové verze a vrátí konkrétní chybu.</div>
<?php if(isset($_GET['saved'])):?><p class="quality-ok">Nastavení pravidel bylo uloženo.</p><?php endif;?>
<?php $qualityValues=qualitySettings($db);?>
<form method="post" action="/console/?action=quality"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="cmd" value="quality_save">
<table><thead><tr><th>Požadavek</th><th>Popis</th><th>Povinný</th></tr></thead><tbody>
<?php foreach($qualityRules as $key=>$rule):?><tr><td><strong><?=h($rule[0])?></strong></td><td><?=h($rule[1])?></td><td><input type="checkbox" name="required[<?=h($key)?>]" value="1" <?=!empty($qualityValues[$key])?'checked':''?> <?=$user['role']==='superadmin'?'':'disabled'?>></td></tr><?php endforeach;?></tbody></table>
<?php if($user['role']==='superadmin'):?><p><button class="btn" type="submit">Uložit pravidla</button></p><?php endif;?></form>
<h3>Otestovat ZIP před nahráním</h3><form method="post" action="/console/?action=quality" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="cmd" value="quality_test"><input type="file" name="quality_zip" accept=".zip,application/zip" required> <button class="btn" type="submit">Zkontrolovat ZIP</button></form>
<p class="muted">Test pouze čte obsah ZIPu; nikam ho neinstaluje ani nepublikuje. Maximální velikost 15 MB.</p>
<?php if($qualityNotice):?><p class="quality-fail"><?=h($qualityNotice)?></p><?php endif;?>
<?php if($qualityReport):?><h3>Výsledek předběžné kontroly</h3><p>Plugin: <strong><?=h($qualityReport['name'])?></strong> · Verze: <strong><?=h($qualityReport['version'])?></strong></p><p>SHA-256: <code><?=h((string)$qualityReport['sha256'])?></code></p>
<?php foreach($qualityReport['errors'] as $error):?><p class="quality-fail"><?=h($error)?></p><?php endforeach;?>
<table><thead><tr><th>Pravidlo</th><th>Výsledek</th><th>Režim</th></tr></thead><tbody><?php foreach($qualityRules as $key=>$rule):?><?php $result=$qualityReport['checks'][$key]??null;?><tr><td><?=h($rule[0])?></td><td class="<?=$result===true?'quality-ok':($result===false?'quality-fail':'quality-warn')?>"><?=$result===true?'Splněno':($result===false?'Chybí':'Neověřeno')?></td><td><?=!empty($qualityValues[$key])?'Povinné':'Doporučené'?></td></tr><?php endforeach;?></tbody></table>
<?php endif;?><p><a href="/console/">← Zpět do administrace</a></p></section><?php endif;?>
<?php if($user['role']==='superadmin' && $action==='users'):?><section class="panel" id="users"><h2>Správa uživatelů</h2><p class="muted">Nový uživatel musí mít vlastní Google účet. Přístup je vázaný na ověřenou identitu.</p><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="cmd" value="invite"><input type="email" name="email" placeholder="E-mail uživatele" required><select name="role"><option value="admin">Administrátor</option><option value="viewer">Pozorovatel</option></select><button class="btn">Povolit přístup</button></form><div style="overflow:auto"><table><thead><tr><th>E-mail</th><th>Role</th><th>Stav</th><th>Poslední přihlášení</th><th>Akce</th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><?=h($u['email'])?></td><td><?=h($u['role'])?></td><td><?=((int)$u['enabled'])?'Povolen':'Zakázán'?></td><td><?=h((string)($u['last_login']??'—'))?></td><td><?php if($u['email']!==$super && $u['enabled']):?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="cmd" value="disable"><input type="hidden" name="email" value="<?=h($u['email'])?>"><input type="hidden" name="role" value="viewer"><button>Zakázat</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<?php if(in_array($user['role'],['superadmin','admin'],true) && $action==='plugins'):?>
<section class="panel" id="plugins">
<div class="plugins-header"><div><div class="eyebrow">KNIHOVNA ODLADĚNÍ</div><h2>Správa pluginů</h2><p class="muted">Všechny uložené ZIP verze ve Vašem úložišti Odladění.</p></div><div><?php if($user['role']==='superadmin'):?><a class="btn" href="/console/?action=plugin_trash">🗑 Koš pluginů</a> <?php endif;?><a class="btn add-plugin" href="/console/?action=plugin_library">＋ Přidat plugin</a></div></div>
<?php
require_once dirname(__DIR__).'/api/release-gate.php';
$releaseDir=realpath(__DIR__.'/../releases');
$releaseGroups=[];$releaseTotal=0;
if($releaseDir && is_dir($releaseDir)){
 foreach(new DirectoryIterator($releaseDir) as $entry){
  if(!$entry->isFile() || strtolower($entry->getExtension())!=='zip')continue;
  if(!odlReleaseVisible($entry->getPathname()))continue;
  $filename=$entry->getFilename();
  if(!preg_match('/^([a-z0-9][a-z0-9-]*)-v([0-9][a-zA-Z0-9._-]*)-([a-f0-9]{12})\\.zip$/D',$filename,$match))continue;
  $slug=$match[1];$version=$match[2];
  $releaseGroups[$slug][]=['version'=>$version,'filename'=>$filename,'bytes'=>$entry->getSize(),'mtime'=>$entry->getMTime()];
  $releaseTotal++;
 }
}
ksort($releaseGroups);
foreach($releaseGroups as &$versions){usort($versions,static function($a,$b){$cmp=version_compare($b['version'],$a['version']);return $cmp!==0?$cmp:($b['mtime']<=>$a['mtime']);});}unset($versions);
?>
<div class="library-stats"><div><strong><?=count($releaseGroups)?></strong><span>Pluginů</span></div><div><strong><?=$releaseTotal?></strong><span>Uložených verzí</span></div><div><strong><?=h(date('d. m. Y'))?></strong><span>Aktuální přehled</span></div></div>
<?php if(!$releaseGroups):?><div class="empty-state"><h3>V úložišti se nepodařilo načíst pluginy</h3><p>Ověřte dostupnost adresáře releases. Zatím nebyly provedeny žádné změny souborů.</p></div><?php else:?>
<div class="plugin-library">
<?php foreach($releaseGroups as $slug=>$versions):$latest=$versions[0];?>
<?php $presentation=odlPluginPresentation($slug);?><article class="plugin-card"><div class="plugin-card-top"><?=odlPluginIcon($presentation['icon'])?><div class="plugin-info"><h3><?=h($presentation['name'])?></h3><p><?=h($slug)?></p></div><span class="version-pill">v<?=h($latest['version'])?></span></div><p class="plugin-description"><?=h($presentation['description'])?></p>
<div class="plugin-meta"><span><?=count($versions)?> <?=count($versions)===1?'verze':'verzí'?></span><span>Poslední úprava <?=h(date('d. m. Y',$latest['mtime']))?></span></div>
<?php if($user['role']==='superadmin' && $slug!=='prvotni-instalace'):?><p><a href="/console/?action=plugin_trash&amp;preview=archive&amp;plugin=<?=rawurlencode($slug)?>">🗑 Přesunout do koše…</a></p><?php endif;?><details class="plugin-versions"><summary>Zobrazit uložené verze <span>⌄</span></summary><div class="version-list"><?php foreach($versions as $item):?><div class="version-row"><span><strong>v<?=h($item['version'])?></strong><small><?=h(date('d. m. Y H:i',$item['mtime']))?> · <?=h(number_format($item['bytes']/1024,1,',',' '))?> kB</small></span><a href="/releases/<?=rawurlencode($item['filename'])?>" download>Stáhnout ZIP ↗</a></div><?php endforeach;?></div></details></article>
<?php endforeach;?></div><?php endif;?>
</section><?php endif;?><?php if(!in_array($action,['site','users','plugins','quality','chatgpt','installations','templately'],true)):?>
<?php $zipCount=0;$zipSlugs=[];$dir=realpath(__DIR__.'/../releases');if($dir&&is_dir($dir)){foreach(new DirectoryIterator($dir) as $e){if(!$e->isFile()||strtolower($e->getExtension())!=='zip')continue;if(preg_match('/^([a-z0-9][a-z0-9-]*)-v[0-9][a-zA-Z0-9._-]*-[a-f0-9]{12}\\.zip$/D',$e->getFilename(),$m)){$zipCount++;$zipSlugs[$m[1]]=true;}}}?>
<?php if($user['role']==='superadmin'):?><div class="dash-stats"><a href="/console/" class="dash-stat"><i class="ds-green">↗</i><span>Napojené weby<strong><?=count($sites)?></strong><small>Registrované instalace</small></span></a><a href="/console/?action=plugins" class="dash-stat"><i class="ds-purple">◈</i><span>Uložené pluginy<strong><?=$zipCount?></strong><small>ZIP verzí · <?=count($zipSlugs)?> pluginů</small></span></a><a href="/console/?action=users" class="dash-stat"><i class="ds-blue">♙</i><span>Uživatelé<strong><?=count($users)?></strong><small>Účty administrace</small></span></a><a href="/console/?action=chatgpt" class="dash-stat"><i class="ds-orange">⌁</i><span>Stav MCP<strong>2.6.1</strong><small>Otevřít diagnostiku</small></span></a></div>
<?php endif;?><section class="panel dash-sites"><div class="dash-heading"><div><div class="eyebrow">WORDPRESS · PŘIPOJENÍ</div><h2>Napojené weby <span class="dash-count"><?=count($sites)?> web<?=count($sites)===1?'':'ů'?></span></h2><p class="muted">Přehled všech připojených WordPress instalací</p></div><input id="web-search" type="search" placeholder="⌕ Hledat web…" aria-label="Hledat web"></div>
<?php if(!$sites):?><p class="muted">Zatím vám není přidělený žádný web. Přístup přiděluje superadministrátor v přehledu uživatelů.</p><?php else:?>
<?php require __DIR__.'/fleet-workspace-rows.php';?>
<p id="no-webs" class="muted" hidden>Žádný web neodpovídá hledání.</p><?php endif;?></section>
<?php if($user['role']==='superadmin'):?><div class="dash-bottom"><section class="panel"><h2>Rychlé akce</h2><p class="muted">Nejčastěji používané funkce</p><div class="quick-tiles"><a href="/console/?action=plugin_library" class="qt qt-purple"><b>＋</b><span>Přidat plugin<small>Nahrát nový ZIP soubor</small></span></a><a href="/console/?action=plugins" class="qt qt-blue"><b>◈</b><span>Správa pluginů<small>Uložené verze</small></span></a><a href="/console/?action=chatgpt" class="qt qt-green"><b>↗</b><span>Spojení s ChatGPT<small>Test MCP rozhraní</small></span></a><a href="/console/?action=users" class="qt qt-orange"><b>♙</b><span>Správa uživatelů<small>Přístupy a role</small></span></a></div></section><section class="panel"><h2>Stav systému</h2><p class="muted">Aktuálně dostupné informace</p><div class="dash-events"><div><i>◈</i><span><strong>Úložiště pluginů</strong><small><?=$zipCount?> ZIP verzí, <?=count($zipSlugs)?> pluginů</small></span></div><div><i>↗</i><span><strong>Registrované weby</strong><small><?=count($sites)?> záznamů</small></span></div><div><i>♙</i><span><strong>Google účet</strong><small><?=h($user['role'])?></small></span></div><div><i>⌁</i><span><strong>Kontrola spojení</strong><small>Spusťte test na stránce Spojení s ChatGPT</small></span></div></div><p class="muted">Historie přístupů a požadavků je dostupná ve správě uživatelů.</p></section></div>
<?php endif;?><?php endif;?>
<?php endif;?><p><a href="/">← O systému Odladění</a></p></main><script nonce="<?=h($scriptNonce)?>"><?php readfile(dirname(__DIR__).'/assets/console-responsive.js');?>document.querySelectorAll('.dash-sidebar nav a').forEach(a=>{if(new URL(a.href,location.href).search===location.search)a.classList.add('is-active')});document.getElementById('web-search')?.addEventListener('input',function(){let n=0;document.querySelectorAll('[data-name]').forEach(el=>{const ok=el.dataset.name.includes(this.value.toLocaleLowerCase('cs'));el.hidden=!ok;if(ok)n++});const empty=document.getElementById('no-webs');if(empty)empty.hidden=n!==0});if(location.search.includes('action=users'))document.getElementById('users')?.scrollIntoView();if(location.search.includes('action=plugins'))document.getElementById('plugins')?.scrollIntoView();(function(){const tabs=document.querySelector('.site-tabs');if(!tabs)return;const ids=['web-overview','web-plugins','web-design','web-diagnostics'];const show=id=>{if(!ids.includes(id))id='web-overview';ids.forEach(x=>{const el=document.getElementById(x);if(el)el.hidden=x!==id});tabs.querySelectorAll('a').forEach(a=>{const active=a.getAttribute('href')==='#'+id;a.classList.toggle('active',active);a.setAttribute('aria-selected',active?'true':'false')})};tabs.querySelectorAll('a').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();const id=a.getAttribute('href').slice(1);show(id);history.replaceState(null,'','#'+id)}));show(ids.includes(location.hash.slice(1))?location.hash.slice(1):'web-overview')})();
window.addEventListener('hashchange',()=>{const ids=['web-overview','web-plugins','web-design','web-diagnostics'];const id=location.hash.slice(1);if(!ids.includes(id))return;ids.forEach(x=>{const el=document.getElementById(x);if(el)el.hidden=x!==id});document.querySelectorAll('.site-tabs a').forEach(a=>{const active=a.hash==='#'+id;a.classList.toggle('active',active);a.setAttribute('aria-selected',String(active))})});
</script><script nonce="<?=h($scriptNonce)?>" src="/assets/plugin-controls.js"></script></body></html>
