<?php
declare(strict_types=1);
require dirname(__DIR__).'/assets/accounts.php';
function check(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users(email TEXT PRIMARY KEY,google_sub TEXT UNIQUE,role TEXT,enabled INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP,last_login TEXT);CREATE TABLE connected_sites(site_url TEXT PRIMARY KEY,site_name TEXT,approved_by TEXT,connected_at TEXT,last_seen TEXT);INSERT INTO users(email,google_sub,role,enabled) VALUES('owner@example.com','owner','superadmin',1),('legacy@example.com','legacy','admin',1);INSERT INTO connected_sites VALUES('https://example.com','Example','owner@example.com',CURRENT_TIMESTAMP,NULL)");
odlAccountsSchema($db);odlAccountsSchema($db);
$owner=odlAccountsUser($db,'owner@example.com');
check((int)$owner['enabled']===1,'superadmin remains active');
$p=['email'=>'client@example.com','sub'=>'client','email_verified'=>true];$u=odlAccountsGoogle($db,$p);
check((int)$u['enabled']===0,'new Google account requires approval');
check(odlAccountsUser($db,$p['email'])!==null,'pending registration is persisted');
check(!odlAccountsCan($db,$u,'https://example.com',true),'pending cannot modify site');
check(odlAccountsTokenUser($db,odlAccountsClaims($u),'owner@example.com')===null,'pending cannot use tokens');
check(!odlAccountsConsoleAllowed($db,$u,'chatgpt','GET',[],[]),'pending cannot authorize chat');
try{odlAccountsReview($db,$u,'legacy@example.com','approved');throw new LogicException('nonadmin review accepted');}catch(RuntimeException $e){check($e->getMessage()!=='nonadmin review accepted','only superadmin reviews');}
odlAccountsReview($db,$owner,$u['email'],'approved');$u=odlAccountsUser($db,$u['email']);
check((int)$u['enabled']===1,'superadmin approval enables account');
check(!odlAccountsCan($db,$u,'https://example.com'),'approval alone grants no sites');
odlAccountsAssign($db,$owner,$u['email'],'https://example.com','viewer');
check(odlAccountsCan($db,$u,'https://example.com')&&!odlAccountsCan($db,$u,'https://example.com',true),'viewer has read only access');
$claims=odlAccountsClaims($u);odlAccountsReview($db,$owner,$u['email'],'rejected');
check(odlAccountsTokenUser($db,$claims,'owner@example.com')===null,'rejection revokes old token');
check(odlAccountsSites($db,$u)===[],'rejected account cannot list sites');
$u=odlAccountsGoogle($db,$p);check((int)$u['enabled']===0,'repeat Google login cannot bypass rejection');
odlAccountsReview($db,$owner,$u['email'],'approved');check(odlAccountsTokenUser($db,$claims,'owner@example.com')===null,'reapproval does not restore old token');
try{odlAccountsReview($db,$owner,$owner['email'],'rejected');throw new LogicException('owner disabled');}catch(RuntimeException $e){check($e->getMessage()!=='owner disabled','superadmin cannot be disabled');}
check((int)odlAccountsUser($db,'legacy@example.com')['enabled']===0,'legacy nonadmin requires explicit review');
check((int)$db->query("SELECT count(*) FROM account_audit WHERE event='account_approved'")->fetchColumn()===2,'approvals are audited');
echo "ALL APPROVAL CHECKS PASSED\n";
