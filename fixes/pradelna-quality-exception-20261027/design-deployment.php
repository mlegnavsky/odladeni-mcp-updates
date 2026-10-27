<?php
/** Central durable coordinator; the signed WordPress module performs all native writes. */
function odlDeploymentSchema(PDO $db):void {$db->exec('CREATE TABLE IF NOT EXISTS design_deployments(id TEXT PRIMARY KEY,owner TEXT NOT NULL,site TEXT NOT NULL,payload TEXT NOT NULL,updated_at TEXT NOT NULL)');$db->exec('CREATE TABLE IF NOT EXISTS design_deployment_leases(site TEXT PRIMARY KEY,token TEXT NOT NULL,expires INTEGER NOT NULL)');}
function odlDeploymentRequireAdmin(array $u):void {if(($u['role']??'')!=='superadmin')throw new RuntimeException('Nasazování designů je dostupné hlavnímu správci flotily.');}
function odlDeploymentRequest($s):bool {return is_string($s)&&preg_match('/^[A-Za-z0-9_-]{8,80}$/D',$s)===1;}
function odlDeploymentCommandHash(array $v):string {return hash('sha256',json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function odlDeploymentSave(PDO $db,array $j):void {$db->prepare('INSERT INTO design_deployments VALUES(?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload,updated_at=excluded.updated_at')->execute([$j['id'],$j['owner'],$j['site'],json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),gmdate('c')]);}
function odlDeploymentLoad(PDO $db,array $u,string $id):?array {$q=$db->prepare('SELECT payload FROM design_deployments WHERE id=? AND owner=?');$q->execute([$id,$u['email']]);$v=$q->fetchColumn();return $v?json_decode($v,true,100,JSON_THROW_ON_ERROR):null;}
function odlDeploymentPublic(array $j):array {$labels=['begin'=>'Kontrola propojení','upload'=>'Přenos designu','prepare'=>'Kontrola nativního obsahu','backup'=>'Záloha původního designu','stage'=>'Import do konceptů','activate'=>'Aktivace nového designu','cleanup'=>'Úklid nahrazených šablon','done'=>'Design nasazen','restored'=>'Původní design obnoven','blocked'=>'Zastaveno — je potřeba kontrola'];return ['job_id'=>$j['id'],'site_url'=>$j['site'],'design_id'=>$j['design_id'],'phase'=>$j['phase'],'label'=>$labels[$j['phase']]??$j['phase'],'revision'=>$j['revision'],'remote'=>$j['remote']??null,'error'=>$j['error']??null,'quality_exception'=>$j['quality_exception']??null,'updated_at'=>$j['updated_at']??null];}
function odlDeploymentSites(PDO $db):array {$q=$db->query('SELECT site_url FROM connected_sites ORDER BY site_url');return array_column($q->fetchAll(PDO::FETCH_ASSOC),'site_url');}
function odlDeploymentRemote(string $site,array $c):array {
 if(!function_exists('fmRequest'))require_once __DIR__.'/fleet-management.php';
 $r=fmRequest($site,'/wp-admin/admin-ajax.php?action=odladeni_content',['operation'=>'voice','action'=>'maintenance','maintenance'=>['domain'=>'design_deploy','command'=>$c]]);
 if(($r['ok']??false)!==true||!is_array($r['result']??null))throw new RuntimeException('Web nevrátil ověřitelný výsledek importu.');return $r['result'];
}
function odlDeploymentCapability(array $j,array $base):array {
 try{$cap=odlDeploymentRemote($j['site'],['action'=>'capability']+$base);if(($cap['protocol']??'')==='odl-design-deploy-v1'&&($cap['state_schema']??0)>=2)return $cap;}catch(Throwable $failure){$message=$failure->getMessage();}
 if(!function_exists('fmDiagnostics'))throw new RuntimeException('Web potřebuje modul automatického nasazení v Hlavní instalaci 3.1.2+. '.($message??''));
 $diag=fmDiagnostics($j['site']);$main=null;foreach($diag['plugins']??[]as $plugin)if(($plugin['file']??'')==='prvotni-instalace/prvotni-instalace.php')$main=$plugin;
 if(!$main||empty($main['active'])||!is_string($main['version']??null))throw new RuntimeException('Na webu chybí ověřená aktivní Hlavní instalace. Import nic nezměnil.');
 if(version_compare($main['version'],'3.1.2','>='))throw new RuntimeException('Hlavní instalace je aktuální, ale importní modul neposkytuje odpověď. Zkontrolujte aktivního poskytovatele obsahu; konfliktní plugin se automaticky nevypíná.');
 fmManage(['site_url'=>$j['site'],'action'=>'update','plugin'=>'prvotni-instalace/prvotni-instalace.php','request_id'=>'deploy-module-'.substr(hash('sha256',$j['id']),0,48)]);
 $cap=odlDeploymentRemote($j['site'],['action'=>'capability']+$base);if(($cap['protocol']??'')!=='odl-design-deploy-v1'||($cap['state_schema']??0)<2)throw new RuntimeException('Aktualizace neposkytla požadovaný importní modul. Design webu nebyl změněn.');return $cap;
}
function odlDeploymentStart(PDO $db,array $u,array $a):array {
 $id=(string)($a['design_id']??'');$site=(string)($a['site_url']??'');$req=$a['request_id']??'';
 if(!odlDeploymentRequest($req))throw new InvalidArgumentException('Vyžadováno stabilní ID importu.');if(!in_array($site,odlDeploymentSites($db),true))throw new InvalidArgumentException('Vyberte přesnou doménu z propojených webů.');
 $jobId=substr(hash('sha256',$u['email'].'|'.$req),0,32);$existing=odlDeploymentLoad($db,$u,$jobId);if($existing){if($existing['site']!==$site||$existing['design_id']!==$id)throw new RuntimeException('ID importu patří jiné doméně nebo designu.');return odlDeploymentPublic($existing);}
 $r=odlDesignLoad($db,$u,$id);if(!$r)throw new InvalidArgumentException('Design není ve vaší knihovně.');if((int)($a['design_revision']??0)!==(int)$r['revision'])throw new RuntimeException('Design se změnil. Obnovte detail před importem.');$d=odlDesignValidate(json_decode($r['payload'],true,100,JSON_THROW_ON_ERROR));if(odlCatalogMeta($d)['archived'])throw new RuntimeException('Archivovaný design nejprve vraťte do knihovny.');
 $missing=array_keys(array_filter(odlDesignChecklist($d),fn($v)=>$v!==true));
 // One explicitly user-authorized quality exception. Authentication, native validation,
 // backup verification, conflict detection and rollback remain mandatory.
 $qualityException=$id==='b15813cf4c0750873b1cf38dd524242a'&&$site==='https://www.pradelnasporilov.cz'&&$req==='pradelna-design-20261027-1608'&&(int)$r['revision']===13&&$u['email']==='mlegnavsky@gmail.com'&&time()<strtotime('2026-10-27T18:00:00Z');
 if($missing&&!$qualityException)throw new RuntimeException('Import zatím blokuje Kodex: '.implode('; ',array_slice($missing,0,8)).(count($missing)>8?' … Úplný seznam je v technickém ověření detailu.':''));
 $p=array_intersect_key($d,array_flip(['title','parts','menus','styles','site_settings','assets','shared_layout']));$p['source_url']=odlCatalogMeta($d)['demo_url'];$raw=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($raw)>1048576)throw new RuntimeException('Sada překračuje 1 MB.');
 $j=['id'=>$jobId,'owner'=>$u['email'],'site'=>$site,'design_id'=>$id,'design_hash'=>odlCatalogDesignHash($d),'source_revision'=>(int)$r['revision'],'source_payload'=>$r['payload'],'package'=>$raw,'package_hash'=>hash('sha256',$raw),'phase'=>'begin','revision'=>1,'request_id'=>$req,'remote'=>null,'updated_at'=>gmdate('c')];
 if($missing&&$qualityException)$j['quality_exception']=['authorized_by'=>$u['email'],'authorized_at'=>gmdate('c'),'reason'=>'Explicit user instruction to deploy this selected design despite incomplete quality reviews.','unverified_checks'=>$missing];
 $db->beginTransaction();try{$q=$db->prepare('SELECT payload FROM design_deployments WHERE site=?');$q->execute([$site]);foreach($q->fetchAll(PDO::FETCH_COLUMN)as $v){$old=json_decode($v,true);if(!in_array($old['phase'],['done','restored'],true))throw new RuntimeException('Na tomto webu už je nedokončený import. Pokračujte v jeho uložené úloze.');}odlDeploymentSave($db,$j);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}return odlDeploymentPublic($j);
}
function odlDeploymentUpdateRow(PDO $db,array $u,array $r,array $d):void {
 $payload=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if($payload===$r['payload'])return;
 $q=$db->prepare('UPDATE design_library SET payload=?,revision=revision+1,updated_at=? WHERE id=? AND owner=? AND revision=?');$q->execute([$payload,gmdate('c'),$r['id'],$u['email'],$r['revision']]);if($q->rowCount()!==1)throw new RuntimeException('Konflikt evidence nasazení.');$db->prepare('INSERT INTO design_revisions VALUES(?,?,?,?)')->execute([$r['id'],$r['revision'],$r['payload'],gmdate('c')]);
}
function odlDeploymentRecord(PDO $db,array $u,array &$j):void {
 if(!empty($j['recorded']))return;if(($j['remote']['import_verified']??false)!==true)throw new RuntimeException('Chybí ověřený importní výsledek.');
 $db->beginTransaction();try{$r=odlDesignLoad($db,$u,$j['design_id']);if(!$r)throw new RuntimeException('Design byl odstraněn; výsledek zůstává u importní úlohy.');$d=json_decode($r['payload'],true);if(!hash_equals(odlCatalogDesignHash($d),$j['design_hash']))throw new RuntimeException('Import proběhl, ale knihovní design byl mezitím změněn; evidenci je nutné porovnat.');
 $q=$db->prepare('SELECT * FROM design_library WHERE owner=?');$q->execute([$u['email']]);$j['previous_deployments']=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC)as $row){$v=json_decode($row['payload'],true);$changed=false;foreach($v['report']['catalog']['deployments']??[]as $i=>$entry)if(($entry['url']??'')===$j['site']&&in_array($entry['status']??'',['verified','test'],true)){$j['previous_deployments'][]=['design_id'=>$row['id'],'entry'=>$entry];if($row['id']!==$j['design_id']){$v['report']['catalog']['deployments'][$i]['status']='retired';$v['report']['catalog']['deployments'][$i]['note']='Nahrazeno nasazením '.$j['id'].'. Původní evidence je v historii.';$changed=true;}}if($changed)odlDeploymentUpdateRow($db,$u,$row,$v);}
 $rows=array_values(array_filter($d['report']['catalog']['deployments']??[],fn($x)=>($x['url']??'')!==$j['site']));$rows[]=['url'=>$j['site'],'status'=>'verified','verified_at'=>gmdate('c'),'design_hash'=>$j['design_hash'],'note'=>'Nativní import, aktivace a úklid ověřeny z uložené operace '.$j['id'].'. Vzhled v prohlížeči zatím neověřen.','quality_exception'=>$j['quality_exception']??null,'job_id'=>$j['id']];$d['report']['catalog']['deployments']=$rows;odlDeploymentUpdateRow($db,$u,$r,$d);$j['recorded']=true;odlDeploymentSave($db,$j);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function odlDeploymentUndoRecord(PDO $db,array $u,array &$j):void {
 if(empty($j['recorded'])||!empty($j['record_restored']))return;$db->beginTransaction();try{
 $r=odlDesignLoad($db,$u,$j['design_id']);if($r){$d=json_decode($r['payload'],true);foreach($d['report']['catalog']['deployments']??[]as $i=>$entry)if(($entry['job_id']??'')===$j['id']){$d['report']['catalog']['deployments'][$i]['status']='retired';$d['report']['catalog']['deployments'][$i]['note']='Nasazení bylo vráceno; původní design obnoven ze zálohy.';}odlDeploymentUpdateRow($db,$u,$r,$d);}
 foreach($j['previous_deployments']??[]as $previous){$r=odlDesignLoad($db,$u,$previous['design_id']);if(!$r)continue;$d=json_decode($r['payload'],true);$entry=$previous['entry'];if(($entry['design_hash']??'')!==odlCatalogDesignHash($d)){$entry['status']='reference';$entry['note']='Web byl obnoven, ale uložený design se mezitím změnil; shoda vyžaduje kontrolu.';}$rows=array_values(array_filter($d['report']['catalog']['deployments']??[],fn($x)=>($x['url']??'')!==$j['site']));$rows[]=$entry;$d['report']['catalog']['deployments']=$rows;odlDeploymentUpdateRow($db,$u,$r,$d);}
 $j['record_restored']=true;odlDeploymentSave($db,$j);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function odlDeploymentCall(PDO $db,array $u,array $a):array {
 odlDeploymentRequireAdmin($u);odlDeploymentSchema($db);$action=$a['action']??'list';
 if($action==='list'){$q=$db->prepare('SELECT payload FROM design_deployments WHERE owner=? ORDER BY updated_at DESC LIMIT 100');$q->execute([$u['email']]);return ['jobs'=>array_map(fn($v)=>odlDeploymentPublic(json_decode($v,true)),$q->fetchAll(PDO::FETCH_COLUMN)),'protocol'=>'odl-design-deploy-v1'];}
 if($action==='start')return odlDeploymentStart($db,$u,$a);
 $id=(string)($a['job_id']??'');$j=odlDeploymentLoad($db,$u,$id);if(!$j)throw new InvalidArgumentException('Importní úloha nebyla nalezena.');if(isset($a['site_url'])&&$a['site_url']!==$j['site'])throw new RuntimeException('Úloha patří jiné doméně.');if($action==='status'){if($j['phase']==='done'&&empty($j['recorded']))odlDeploymentRecord($db,$u,$j);if($j['phase']==='restored')odlDeploymentUndoRecord($db,$u,$j);return odlDeploymentPublic($j);}if(!in_array($action,['step','restore'],true))throw new InvalidArgumentException('Neplatná akce.');
 $token=bin2hex(random_bytes(16));$q=$db->prepare('INSERT INTO design_deployment_leases VALUES(?,?,?) ON CONFLICT(site) DO UPDATE SET token=excluded.token,expires=excluded.expires WHERE expires<?');$q->execute([$j['site'],$token,time()+300,time()]);if($q->rowCount()!==1)throw new RuntimeException('Na webu právě probíhá krok importu. Načtěte jeho stav.');
 try{
  $j=odlDeploymentLoad($db,$u,$id);if((int)($a['revision']??0)!==$j['revision'])throw new RuntimeException('Stav importu se změnil; načtěte jeho stav.');if($action==='step'&&in_array($j['phase'],['done','restored'],true)){if($j['phase']==='restored')odlDeploymentUndoRecord($db,$u,$j);if($j['phase']==='done'&&empty($j['recorded'])){odlDeploymentRecord($db,$u,$j);$j['recorded']=true;odlDeploymentSave($db,$j);}return odlDeploymentPublic($j);}
  $base=['request_id'=>'deploy-'.substr(hash('sha256',$j['id']),0,48)];
  if($j['phase']==='begin'){
   $cap=odlDeploymentCapability($j,$base);if(empty($cap['supported']))throw new RuntimeException('Web má jiného nebo konfliktního poskytovatele hlavičky a patičky; import vyžaduje jeho adaptér.');
   $j['remote']=odlDeploymentRemote($j['site'],['action'=>'begin','hash'=>$j['package_hash'],'bytes'=>strlen($j['package'])]+$base);$j['phase']='upload';
  }else{
   $remoteId=$j['remote']['job_id']??null;if(!$remoteId)throw new RuntimeException('Chybí ID vzdálené úlohy.');$base['job_id']=$remoteId;
   if($j['phase']==='upload'){$offset=(int)($j['remote']['received_bytes']??0);$raw=substr($j['package'],$offset,48000);if($raw!=='')$j['remote']=odlDeploymentRemote($j['site'],['action'=>'chunk','offset'=>$offset,'content_base64'=>base64_encode($raw)]+$base);if(($j['remote']['received_bytes']??0)>=strlen($j['package']))$j['phase']='prepare';}
   elseif($j['phase']==='prepare'){$j['remote']=odlDeploymentRemote($j['site'],['action'=>'prepare']+$base);$j['phase']=$j['remote']['phase'];}
   else{
    $remote=odlDeploymentRemote($j['site'],['action'=>'status']+$base);if($action!=='restore'&&($remote['status']??'')==='needs_review')throw new RuntimeException('Vzdálený zápis má neznámý výsledek. Záloha a úloha jsou zachovány; je nutná cílená kontrola.');
    if($action==='restore'&&($remote['phase']??'')!=='restored'){if(empty($remote['backup']['verified']))throw new RuntimeException('Chybí ověřená záloha pro obnovu.');$remote=odlDeploymentRemote($j['site'],['action'=>'restore','expected_revision'=>$remote['revision']]+$base);}
    elseif(!in_array($remote['phase'],['done','restored'],true))$remote=odlDeploymentRemote($j['site'],['action'=>'step','expected_revision'=>$remote['revision']]+$base);
    $j['remote']=$remote;$j['phase']=$remote['phase'];
   }
  }
  unset($j['error']);$j['revision']++;$j['updated_at']=gmdate('c');odlDeploymentSave($db,$j);
  if($j['phase']==='restored')odlDeploymentUndoRecord($db,$u,$j);if($j['phase']==='done'&&empty($j['recorded'])){odlDeploymentRecord($db,$u,$j);$j['recorded']=true;odlDeploymentSave($db,$j);}
  if($j['phase']==='restored')odlDeploymentUndoRecord($db,$u,$j);return odlDeploymentPublic($j);
 }catch(Throwable $e){$j['error']=substr($e->getMessage(),0,1500);$j['updated_at']=gmdate('c');odlDeploymentSave($db,$j);throw $e;}finally{$db->prepare('DELETE FROM design_deployment_leases WHERE site=? AND token=?')->execute([$j['site'],$token]);}
}
/** Cached voice/chat clients use the same job API through authenticated virtual file tools. */
function odlDeploymentVoiceFile(PDO $db,array $u,string $tool,array $a):array {
 odlDeploymentRequireAdmin($u);$state=odlDeploymentCall($db,$u,['action'=>'list']);$state['instructions']='Pro import použijte file_write templates/_deploy.json s expected_sha256 z tohoto čtení a base64 JSON povelu. start: action,start + design_id,design_revision,site_url přesně z fleet_list_sites,request_id stabilní. Potom step: job_id,revision z posledního výsledku, dokud phase done. status: job_id. restore: job_id,revision jen na výslovný povel. Doména ani design se nesmí odhadovat. Záloha je povinná; nesplněný Kodex ani konflikty se neobcházejí. Neznámý výsledek načíst přes status, nepoužít nový request_id. Import neověřuje vzhled v prohlížeči.';$raw=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$sha=hash('sha256',$raw);
 if($tool==='file_read'){$off=$a['offset']??0;$len=$a['length']??12000;if(!is_int($off)||$off<0||!is_int($len)||$len<1||$len>49152)throw new InvalidArgumentException('Neplatné meze čtení.');$chunk=substr($raw,$off,$len);return ['file'=>'templates/_deploy.json','sha256'=>$sha,'size'=>strlen($raw),'offset'=>$off,'bytes'=>strlen($chunk),'base64'=>base64_encode($chunk),'eof'=>$off+strlen($chunk)>=strlen($raw),'voice_chat_supported'=>true];}
 if($tool!=='file_write'||!is_string($a['expected_sha256']??null)||!hash_equals($sha,$a['expected_sha256']))throw new RuntimeException('Stav importů se změnil; načtěte templates/_deploy.json před povelem.');$b=$a['content_base64']??'';$decoded=is_string($b)?base64_decode($b,true):false;if(!is_string($decoded)||strlen($decoded)>8192||base64_encode($decoded)!==$b)throw new InvalidArgumentException('Neplatný povel.');$cmd=json_decode($decoded,true,20,JSON_THROW_ON_ERROR);if(!is_array($cmd))throw new InvalidArgumentException('Neplatný povel.');return ['file'=>'templates/_deploy.json','result'=>odlDeploymentCall($db,$u,$cmd)];
}
