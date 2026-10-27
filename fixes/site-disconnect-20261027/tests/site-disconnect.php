<?php
declare(strict_types=1);
require dirname(__DIR__).'/console/site-disconnect.php';
function fixture():PDO {
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("CREATE TABLE connected_sites(site_url TEXT PRIMARY KEY,shared_key TEXT);CREATE TABLE account_memberships(site_url TEXT,email TEXT);CREATE TABLE fleet_overview(site_url TEXT,payload TEXT);CREATE TABLE account_audit(actor TEXT,event TEXT,site_url TEXT);
 INSERT INTO connected_sites VALUES('https://old.example','key'),('https://other.example','other');INSERT INTO account_memberships VALUES('https://old.example','a'),('https://other.example','b');INSERT INTO fleet_overview VALUES('https://old.example','{}'),('https://other.example','{}')");return $db;
}
function odlAccountsAudit(PDO $db,string $actor,string $event,string $site):void {$db->prepare('INSERT INTO account_audit VALUES(?,?,?)')->execute([$actor,$event,$site]);}
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
$db=fixture();$calls=0;
odlDisconnectRegistry($db,'https://old.example','key','owner','registry',function()use(&$calls){$calls++;});
check($calls===0,'Registry removal must work without contacting WordPress');
foreach(['connected_sites','account_memberships','fleet_overview'] as $t){check((int)$db->query("SELECT COUNT(*) FROM $t WHERE site_url='https://old.example'")->fetchColumn()===0,'Old records removed');check((int)$db->query("SELECT COUNT(*) FROM $t WHERE site_url='https://other.example'")->fetchColumn()===1,'Other sites preserved');}
check($db->query('SELECT event FROM account_audit')->fetchColumn()==='site_disconnect_registry','Audit mode recorded');
$db=fixture();try{odlDisconnectRegistry($db,'https://old.example','key','owner','bridge',function(){throw new RuntimeException('offline');});throw new LogicException('Expected failure');}catch(RuntimeException $e){check($e->getMessage()==='offline','Bridge failure propagated');}
check((int)$db->query('SELECT COUNT(*) FROM connected_sites')->fetchColumn()===2,'Bridge failure preserves registry');
$db=fixture();try{odlDisconnectRegistry($db,'https://old.example','wrong','owner','registry',function(){});throw new LogicException('Expected conflict');}catch(RuntimeException $e){}
check((int)$db->query('SELECT COUNT(*) FROM connected_sites')->fetchColumn()===2,'Concurrent pairing preserved');
$db=fixture();odlDisconnectRegistry($db,'https://old.example','key','owner','bridge',function()use(&$calls){$calls++;});check($calls===1,'Normal disconnect contacts WordPress');
echo "PASS: offline, online, failure, conflict, cleanup, isolation and audit\n";
