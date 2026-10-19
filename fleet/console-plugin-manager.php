<?php
// Included only by console/index.php after its normal Google session lookup.
if(!isset($user)||!$user||!in_array($user['role'],['admin','superadmin'],true)){http_response_code(403);exit('Přihlaste se jako správce Odladění.');}
require_once dirname(__DIR__).'/assets/fleet-management.php';
$site=(string)($_GET['site']??$_POST['site']??'');
$sites=fmSites();
if(!in_array($site,array_column($sites['sites'],'url'),true)){http_response_code(404);exit('Web není spárovaný s Odladěním.');}
$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);exit('Neplatné potvrzení formuláře.');}
 try{
  $action=(string)($_POST['operation']??'');
  if($action==='upgrade_bridge')$receipt=fmBootstrap(['site_url'=>$site,'confirm'=>true]);
  else{
   $payload=['site_url'=>$site,'action'=>$action,'plugin'=>(string)($_POST['plugin']??''),'request_id'=>(string)($_POST['request_id']??'')];
   if($action==='auto_update')$payload['enabled']=($_POST['enabled']??'')==='1';
   if($action==='delete')$payload['confirm_delete']=($_POST['confirm_delete']??'')==='1';
   if($action==='install')foreach(['source','slug','url','sha256','version'] as $key)$payload[$key]=(string)($_POST[$key]??'');
   $receipt=fmManage($payload);
  }
  $notice='WordPress potvrdil operaci. '.json_encode($receipt['result'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 }catch(Throwable $e){$error=$e->getMessage();}
}
$query=$db->prepare('SELECT shared_key FROM connected_sites WHERE site_url=?');$query->execute([$site]);$key=$query->fetchColumn();
try{$diagnostics=bridgeDiagnostics($site,(string)$key);$plugins=(array)($diagnostics['plugins']??[]);}catch(Throwable $e){$error.=' Diagnostika: '.$e->getMessage();$plugins=[];}
unset($key,$query);
function fmFormFields($site,$file,$action){
 echo '<input type="hidden" name="site" value="'.h($site).'"><input type="hidden" name="csrf" value="'.h((string)$_SESSION['csrf']).'"><input type="hidden" name="request_id" value="'.bin2hex(random_bytes(16)).'"><input type="hidden" name="plugin" value="'.h($file).'"><input type="hidden" name="operation" value="'.h($action).'">';
}
?><!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Správa pluginů — Odladění</title>
<style>body{font:16px system-ui;background:#f1f5fd;color:#17244a;margin:0}main{max-width:1200px;margin:auto;padding:24px}section{background:white;padding:24px;border-radius:16px;margin:20px 0}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #ddd}form{display:inline-block;margin:4px}button{padding:9px 12px;border:0;border-radius:7px;background:#205de0;color:white;cursor:pointer}input,select{padding:10px;max-width:100%;box-sizing:border-box}label{display:block;margin:12px 0}small{display:block;color:#566}output{display:block;padding:16px;background:#e4f5eb;overflow-wrap:anywhere}.error{background:#ffe7e7}.scroll{overflow:auto}</style>
<main><a href="/console/">← Administrace Odladění</a><h1>Správa pluginů</h1><p><?=h($site)?></p>
<?php if($notice):?><output><?=h($notice)?></output><?php endif;if($error):?><output class="error"><?=h($error)?></output><?php endif;?>
<section><h2>Hlavní instalace</h2><p>Nejprve aktualizujte Hlavní instalaci na verzi 2.4.54 nebo novější. Tím se na webu zpřístupní úplná správa pluginů.</p><form method="post"><?php fmFormFields($site,'prvotni-instalace/prvotni-instalace.php','upgrade_bridge');?><button>Aktualizovat Hlavní instalaci</button></form></section>
<section class="scroll"><h2>Nainstalované pluginy</h2><table><thead><tr><th>Plugin</th><th>Verze</th><th>Stav</th><th>Automatické aktualizace</th><th>Akce</th></tr></thead><tbody>
<?php foreach($plugins as $plugin):$file=(string)($plugin['file']??'');if(!$file)continue;$protected=$file==='prvotni-instalace/prvotni-instalace.php';?>
<tr><td><?=h((string)($plugin['name']??$file))?><small><?=h($file)?></small></td><td><?=h((string)($plugin['version']??''))?></td><td><?=!empty($plugin['active'])?'Aktivní':'Neaktivní'?></td>
<td><form method="post"><?php fmFormFields($site,$file,'auto_update');?><input type="hidden" name="enabled" value="<?=empty($plugin['auto_update'])?'1':'0'?>"><button><?=empty($plugin['auto_update'])?'Zapnout':'Vypnout'?></button></form></td><td>
<?php if(!$protected):?><form method="post"><?php fmFormFields($site,$file,!empty($plugin['active'])?'deactivate':'activate');?><button><?=!empty($plugin['active'])?'Deaktivovat':'Aktivovat'?></button></form><?php endif;?>
<?php if(!empty($plugin['update_available'])):?><form method="post"><?php fmFormFields($site,$file,'update');?><button>Aktualizovat</button></form><?php endif;?>
<?php if(!$protected&&empty($plugin['active'])):?><form method="post"><?php fmFormFields($site,$file,'delete');?><label><input type="checkbox" name="confirm_delete" value="1" required> Smazat soubory se zálohou</label><button>Smazat plugin</button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></section>
<section><h2>Nainstalovat plugin</h2><p>Nový plugin se nainstaluje neaktivní. Nastavení a databázová data při smazání zachováváme; odinstalační skripty se nespouštějí.</p><form method="post"><?php fmFormFields($site,'','install');?><label>Zdroj <select name="source"><option value="wordpress">WordPress.org</option><option value="odladeni">Katalog Odladění</option><option value="zip">Vlastní HTTPS ZIP</option></select></label><label>Slug <input name="slug" required placeholder="nazev-pluginu"></label><label>Hlavní soubor <input name="plugin" required placeholder="nazev-pluginu/nazev-pluginu.php"></label><label>HTTPS adresa ZIPu (vlastní ZIP) <input name="url" type="url"></label><label>SHA-256 (vlastní ZIP) <input name="sha256" pattern="[a-f0-9]{64}"></label><label>Očekávaná verze (vlastní ZIP) <input name="version"></label><button>Nainstalovat neaktivní plugin</button></form></section></main></html>
