<?php
declare(strict_types=1);
if(!isset($db,$action)||!($db instanceof PDO)||!function_exists('h')||session_status()!==PHP_SESSION_ACTIVE){http_response_code(403);exit('Otevřete tuto stránku přes administraci Odladění.');}
require_once dirname(__DIR__).'/assets/accounts-oauth.php';
$root=dirname(__DIR__,2).'/private';$notice='';$pendingId=(string)($_GET['request']??$_POST['request']??'');
try{
 foreach((array)($_SESSION['mcp_pending']??[]) as $k=>$v)if(($v['expires']??0)<time())unset($_SESSION['mcp_pending'][$k]);
 if(isset($_GET['client_id'])){
  $request=odlOauthValidate($_GET,odlOauthRead($root.'/clients.json'));
  if(count($_SESSION['mcp_pending']??[])>=8)throw new RuntimeException('Dokončete předchozí propojení nebo se přihlaste znovu.');
  $pendingId=bin2hex(random_bytes(32));$_SESSION['mcp_pending'][$pendingId]=['request'=>$request,'expires'=>time()+600];go('/console/?action=mcp_authorize&request='.$pendingId);
 }
 if(!preg_match('/^[a-f0-9]{64}$/D',$pendingId)||!isset($_SESSION['mcp_pending'][$pendingId]))throw new RuntimeException('Požadavek propojení vypršel. Začněte znovu v ChatGPT.');
 $pending=$_SESSION['mcp_pending'][$pendingId];$request=odlOauthValidate($pending['request'],odlOauthRead($root.'/clients.json'));
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$user||empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Přihlášení nebo potvrzení vypršelo.');
  unset($_SESSION['mcp_pending'][$pendingId]);
  $result=['state'=>$request['state']];
  if(($_POST['decision']??'')==='approve'){
   $current=odlAccountsUser($db,$user['email']);if(!$current||empty($current['enabled'])||empty($current['google_sub']))throw new RuntimeException('Účet není dostupný.');
   $result['code']=odlOauthCode($root,$request,$current);odlAccountsAudit($db,$current['email'],'chatgpt_authorized');
  }else{$result['error']='access_denied';}
  $sep=str_contains($request['redirect_uri'],'?')?'&':'?';go($request['redirect_uri'].$sep.http_build_query($result));
 }
}catch(Throwable $e){http_response_code(400);$notice=$e->getMessage();}
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Propojit ChatGPT — Odladění</title><style>body{font:16px/1.6 system-ui;background:#eef3ff;color:#172343;margin:0}.consent{max-width:620px;margin:6vh auto;background:white;border-radius:20px;padding:28px;box-sizing:border-box}button,.btn{display:inline-block;padding:12px 18px;border:0;border-radius:10px;background:#2163e9;color:white;text-decoration:none;font:inherit;cursor:pointer}button.secondary{background:#edf1fa;color:#172343}li,p{overflow-wrap:anywhere}@media(max-width:650px){.consent{margin:16px;padding:20px}}</style></head><body><main class="consent"><h1>Propojit ChatGPT s Odladěním</h1>
<?php if($notice):?><p role="alert"><?=h($notice)?></p><a href="/console/">Zpět do Odladění</a>
<?php elseif(!$user):?><p>Přihlaste se vlastním Google účtem. Nový účet musí nejdříve schválit superadministrátor. Poté vám přidělí weby a můžete potvrdit propojení.</p><a class="btn" href="/console/?action=login&amp;mcp_request=<?=h($pendingId)?>">Pokračovat přes Google</a>
<?php else:$assigned=odlAccountsSites($db,$user);?><p>Přihlášený účet: <strong><?=h($user['email'])?></strong></p><p>Povolíte aplikaci s návratovou adresou <strong><?=h((string)parse_url($request['redirect_uri'],PHP_URL_HOST))?></strong> pracovat s weby přidělenými tomuto účtu.</p><p>Oprávnění platí i pro weby, které vám správce přidělí později. Odebrání webu nebo zablokování účtu zabrání dalším požadavkům.</p>
<?php if(!$assigned):?><p>Zatím nemáte přidělený žádný web. Propojení můžete dokončit už nyní.</p><?php else:?><ul><?php foreach($assigned as $s):?><li><?=h($s['site_name'])?> — <?=$s['membership_role']==='admin'?'správa':'pouze prohlížení'?></li><?php endforeach;?></ul><?php endif;?>
<form method="post"><input type="hidden" name="request" value="<?=h($pendingId)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button name="decision" value="approve">Povolit propojení</button> <button name="decision" value="deny" class="secondary">Zrušit</button></form><?php endif;?></main></body></html><?php exit;
