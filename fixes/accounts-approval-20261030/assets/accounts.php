<?php
declare(strict_types=1);
require_once __DIR__.'/site-locale.php';
// Shared console/MCP policy. A global account role never grants a website membership.
function odlAccountsMigrationBackup(PDO $db):void {
 if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='account_migrations'")->fetchColumn())return;
 $files=$db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);$file='';foreach($files as $f)if($f['name']==='main')$file=(string)$f['file'];if($file==='')return;
 $parent=dirname($file);$backup=$parent.'/accounts-before-membership-v1.sqlite';$lp=$parent.'/accounts-migration.lock';
 if(is_link($backup)||is_link($lp))throw new RuntimeException('Unsafe account migration storage');
 $lock=fopen($lp,'c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Account migration busy');chmod($lp,0600);
 try{if(is_file($backup))return;$tmp=$backup.'.'.bin2hex(random_bytes(8));try{$db->exec('VACUUM INTO '.$db->quote($tmp));chmod($tmp,0600);if(!rename($tmp,$backup))throw new RuntimeException('Account migration backup failed');}finally{if(is_file($tmp))unlink($tmp);}}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function odlAccountsSchema(PDO $db):void {
 odlAccountsMigrationBackup($db);
 $db->exec("CREATE TABLE IF NOT EXISTS account_memberships(site_url TEXT NOT NULL,email TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','viewer')),granted_by TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(site_url,email));CREATE INDEX IF NOT EXISTS account_memberships_email ON account_memberships(email);CREATE TABLE IF NOT EXISTS account_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,actor TEXT NOT NULL,event TEXT NOT NULL,site_url TEXT NOT NULL DEFAULT '',target TEXT NOT NULL DEFAULT '',outcome TEXT NOT NULL DEFAULT 'done');CREATE TABLE IF NOT EXISTS account_migrations(name TEXT PRIMARY KEY)");
 $columns=$db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
 if(!in_array('token_epoch',array_column($columns,'name'),true)){try{$db->exec('ALTER TABLE users ADD COLUMN token_epoch INTEGER NOT NULL DEFAULT 0');}catch(PDOException $e){if(!str_contains($e->getMessage(),'duplicate column'))throw $e;}}
 odlAccountsApprovalBackup($db);
 $db->exec('BEGIN IMMEDIATE');try{
  if(!$db->query("SELECT 1 FROM account_migrations WHERE name='membership-v1'")->fetchColumn()){
   $db->exec("INSERT OR IGNORE INTO account_memberships(site_url,email,role,granted_by) SELECT s.site_url,u.email,CASE WHEN u.role='viewer' THEN 'viewer' ELSE 'admin' END,s.approved_by FROM connected_sites s JOIN users u ON lower(u.email)=lower(s.approved_by) WHERE u.role!='superadmin';INSERT INTO account_migrations VALUES('membership-v1')");
  }
  $db->exec("CREATE TABLE IF NOT EXISTS account_approvals(email TEXT PRIMARY KEY,status TEXT NOT NULL CHECK(status IN ('pending','approved','rejected')),reviewed_by TEXT,reviewed_at TEXT)");
  if(!$db->query("SELECT 1 FROM account_migrations WHERE name='approval-v1'")->fetchColumn()){
   $db->exec("INSERT OR IGNORE INTO account_approvals(email,status) SELECT email,'pending' FROM users WHERE role!='superadmin';UPDATE users SET enabled=0,token_epoch=token_epoch+1 WHERE role!='superadmin';INSERT INTO account_migrations VALUES('approval-v1')");
  }
  $db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function odlAccountsUser(PDO $db,string $email):?array {
 $q=$db->prepare('SELECT email,google_sub,role,enabled,token_epoch FROM users WHERE email=?');$q->execute([strtolower(trim($email))]);$u=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $u?:null;
}
function odlAccountsAudit(PDO $db,string $actor,string $event,string $site='',string $target='',string $outcome='done'):void {
 $q=$db->prepare('INSERT INTO account_audit(actor,event,site_url,target,outcome) VALUES(?,?,?,?,?)');$q->execute([$actor,substr($event,0,100),substr($site,0,1000),substr($target,0,254),substr($outcome,0,40)]);
 if((int)$db->lastInsertId()%100===0){$db->exec("DELETE FROM account_audit WHERE id <= (SELECT id FROM account_audit ORDER BY id DESC LIMIT 1 OFFSET 100000) OR at < datetime('now','-365 days')");}
}
function odlAccountsGoogle(PDO $db,array $profile):array {
 $email=strtolower(trim((string)($profile['email']??'')));$sub=(string)($profile['sub']??'');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||$sub===''||strlen($sub)>255||($profile['email_verified']??false)!==true)throw new RuntimeException('Neověřená Google identita.');
 $db->exec('BEGIN IMMEDIATE');try{
  $u=odlAccountsUser($db,$email);
  if(!$u){$q=$db->prepare("INSERT INTO users(email,google_sub,role,enabled,last_login) VALUES(?,?,'viewer',0,CURRENT_TIMESTAMP)");$q->execute([$email,$sub]);odlAccountsAudit($db,$email,'google_registered_pending');$q=$db->prepare("INSERT INTO account_approvals(email,status) VALUES(?,'pending')");$q->execute([$email]);$u=odlAccountsUser($db,$email);}
  if($u['google_sub']!==null&&!hash_equals((string)$u['google_sub'],$sub))throw new RuntimeException('Účet je zakázaný nebo se změnila ověřená identita.');
  $q=$db->prepare('UPDATE users SET google_sub=?,last_login=CURRENT_TIMESTAMP WHERE email=?');$q->execute([$sub,$email]);odlLocaleFromGoogle($db,$email,$profile,$_COOKIE['odl_lang']??null);$db->exec('COMMIT');return odlAccountsUser($db,$email);
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function odlAccountsCan(PDO $db,?array $user,string $site,bool $write=false):bool {
 if(!$user)return false;$u=odlAccountsUser($db,(string)($user['email']??''));if(!$u||empty($u['enabled']))return false;
 $q=$db->prepare('SELECT 1 FROM connected_sites WHERE site_url=?');$q->execute([$site]);$exists=(bool)$q->fetchColumn();$q->closeCursor();if(!$exists)return false;
 if($u['role']==='superadmin')return true;
 $q=$db->prepare('SELECT role FROM account_memberships WHERE email=? AND site_url=?');$q->execute([$u['email'],$site]);$role=$q->fetchColumn();$q->closeCursor();return $write?$role==='admin':in_array($role,['admin','viewer'],true);
}
function odlAccountsSites(PDO $db,array $user):array {
 $u=odlAccountsUser($db,(string)($user['email']??''));if(!$u||empty($u['enabled']))return [];
 if($u['role']==='superadmin')return $db->query("SELECT site_url,site_name,approved_by,connected_at,last_seen,'admin' AS membership_role FROM connected_sites ORDER BY site_name,site_url")->fetchAll(PDO::FETCH_ASSOC);
 $q=$db->prepare('SELECT s.site_url,s.site_name,s.connected_at,s.last_seen,m.role AS membership_role FROM connected_sites s JOIN account_memberships m ON m.site_url=s.site_url WHERE m.email=? ORDER BY s.site_name,s.site_url');$q->execute([$u['email']]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function odlAccountsAssign(PDO $db,array $actor,string $email,string $site,string $role):void {
 $a=odlAccountsUser($db,(string)($actor['email']??''));if(!$a||empty($a['enabled'])||$a['role']!=='superadmin')throw new RuntimeException('Pouze superadministrátor může přidělovat weby.');
 $email=strtolower(trim($email));$u=odlAccountsUser($db,$email);if(!$u||$u['role']==='superadmin'||!in_array($role,['admin','viewer','remove'],true)||!odlAccountsCan($db,$a,$site))throw new InvalidArgumentException('Vyberte existující účet, web a oprávnění.');
 $db->exec('BEGIN IMMEDIATE');try{
  if($role==='remove'){$q=$db->prepare('DELETE FROM account_memberships WHERE site_url=? AND email=?');$q->execute([$site,$email]);}
  else{$q=$db->prepare('INSERT INTO account_memberships(site_url,email,role,granted_by) VALUES(?,?,?,?) ON CONFLICT(site_url,email) DO UPDATE SET role=excluded.role,granted_by=excluded.granted_by');$q->execute([$site,$email,$role,$a['email']]);if($role==='admin'){$q=$db->prepare("UPDATE users SET role='admin' WHERE email=? AND role='viewer'");$q->execute([$email]);}}
  odlAccountsAudit($db,$a['email'],'membership_'.$role,$site,$email);$db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function odlAccountsTokenUser(PDO $db,array $record,string $super):?array {
 // Only pre-existing private single-owner records lack email. No public flow creates these.
 $u=odlAccountsUser($db,(string)($record['email']??$super));if(!$u||empty($u['enabled']))return null;
 if(!array_key_exists('email',$record))return $u['role']==='superadmin'&&(int)$u['token_epoch']===0?$u:null;
 if(empty($u['google_sub'])||!hash_equals((string)$u['google_sub'],(string)($record['sub']??''))||(int)$u['token_epoch']!==(int)($record['epoch']??-1))return null;
 return $u;
}
function odlAccountsClaims(array $u):array {return ['email'=>$u['email'],'sub'=>$u['google_sub'],'epoch'=>(int)$u['token_epoch']];}
function odlAccountsToolAllowed(PDO $db,array $user,string $tool,array $args):bool {
 $u=odlAccountsUser($db,(string)($user['email']??''));if(!$u||empty($u['enabled']))return false;if($u['role']==='superadmin')return true;
 if(in_array($tool,['fleet_list_sites','list_connected_sites','wordpress_search_plugins'],true))return true;
 if(!in_array($tool,['fleet_site_diagnostics','fleet_plugin_manage','fleet_content_inspect','fleet_content_plan','fleet_content_apply','fleet_content_rollback'],true))return false;
 // A central kit library is shared across customers; never expose it through a tenant write.
 if((isset($args['maintenance'])&&!is_array($args['maintenance']))||isset($args['library'])||isset($args['context_id'])||in_array($args['maintenance']['domain']??'', ['session','vault'],true))return false;
 $site=$args['site_url']??null;if(!is_string($site))return false;
 return odlAccountsCan($db,$u,$site,!in_array($tool,['fleet_site_diagnostics','fleet_content_inspect'],true));
}
function odlAccountsConsoleAllowed(PDO $db,array $user,string $action,string $method,array $get,array $post):bool {
 $u=odlAccountsUser($db,(string)($user['email']??''));if(!$u||empty($u['enabled']))return false;if($u['role']==='superadmin')return true;
 if(!in_array($action,['','site','fleet_overview_api','fleet_snapshots_api','fleet_operation_api','fleet_preview','wp_admin_login','plugin_manager','chatgpt','mcp_authorize'],true))return false;
 if(in_array($action,['site','fleet_overview_api','fleet_operation_api','fleet_preview','wp_admin_login','plugin_manager'],true)){
  $site=(string)($get['site']??$post['site']??'');if(isset($get['site'],$post['site'])&&$get['site']!==$post['site'])return false;
  return odlAccountsCan($db,$u,$site,in_array($action,['fleet_operation_api','wp_admin_login','plugin_manager'],true)||($method==='POST'&&$action==='site'));
 }
 return $method==='GET'||($method==='POST'&&in_array($action,['mcp_authorize','chatgpt'],true))||($method==='POST'&&($post['cmd']??'')==='logout');
}

// Review and token invalidation are one transaction. No Google login can approve itself.
function odlAccountsReview(PDO $db,array $actor,string $email,string $status):void {
 $db->exec('BEGIN IMMEDIATE');try{
  $a=odlAccountsUser($db,(string)($actor['email']??''));
  if(!$a||empty($a['enabled'])||$a['role']!=='superadmin')throw new RuntimeException('Pouze superadministrátor může schvalovat uživatele.');
  $email=strtolower(trim($email));$u=odlAccountsUser($db,$email);
  if(!$u||$u['role']==='superadmin'||!in_array($status,['approved','rejected'],true))throw new RuntimeException('Vyberte platný uživatelský účet a rozhodnutí.');
  $q=$db->prepare('INSERT INTO account_approvals(email,status,reviewed_by,reviewed_at) VALUES(?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(email) DO UPDATE SET status=excluded.status,reviewed_by=excluded.reviewed_by,reviewed_at=excluded.reviewed_at');$q->execute([$email,$status,$a['email']]);
  $q=$db->prepare('UPDATE users SET enabled=?,token_epoch=token_epoch+1 WHERE email=?');$q->execute([$status==='approved'?1:0,$email]);
  odlAccountsAudit($db,$a['email'],'account_'.$status,'',$email);$db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function odlAccountsConnection(PDO $db,array $user,string $root,?int $now=null):array {
 $now??=time();$current=odlAccountsUser($db,(string)$user['email']);$count=0;
 if(!$current||empty($current['enabled']))return ['count'=>0,'expires'=>0];$expires=0;
 require_once __DIR__.'/accounts-oauth.php';
 // Report only live user-bound grants; never return token identifiers or secrets.
 foreach(odlOauthRead($root.'/refresh.json') as $r){
  if(($r['exp']??0)<=$now||($r['email']??'')!==$current['email']||!odlAccountsTokenUser($db,$r,$current['email']))continue;
  $count++;$expires=max($expires,(int)$r['exp']);
 }
 return ['count'=>$count,'expires'=>$expires];
}

function odlAccountsApprovalBackup(PDO $db):void {
 if($db->query("SELECT 1 FROM account_migrations WHERE name='approval-v1'")->fetchColumn())return;
 $file='';foreach($db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row)if($row['name']==='main')$file=(string)$row['file'];if($file==='')return;
 $backup=dirname($file).'/accounts-before-approval-v1.sqlite';$lockPath=$backup.'.lock';
 if(is_link($backup)||is_link($lockPath))throw new RuntimeException('Unsafe approval backup storage');
 $lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Approval backup busy');chmod($lockPath,0600);
 try{if(is_file($backup))return;$tmp=$backup.'.'.bin2hex(random_bytes(8));try{$db->exec('VACUUM INTO '.$db->quote($tmp));chmod($tmp,0600);if(!rename($tmp,$backup))throw new RuntimeException('Approval backup failed');}finally{if(is_file($tmp))unlink($tmp);}}finally{flock($lock,LOCK_UN);fclose($lock);}
}
