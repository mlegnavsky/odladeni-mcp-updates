<?php
require __DIR__.'/../plugin/runtime.php';
function check($v,$m){if(!$v)throw new Exception($m);echo "PASS $m\n";}
$plan=gca_runtime_distribution();
check($plan['node_url']==='https://nodejs.org/dist/v22.23.5/node-v22.23.5-linux-x64.tar.gz','fixed official Node distribution');
check(strlen($plan['node_sha256'])===64,'pinned Node SHA256');
check($plan['playwright_version']==='1.65.0','pinned browser library');
$public=gca_runtime_public_status(['state'=>'blocked','reason'=>'sandbox','detail'=>'/private/secret/host/path','node'=>'/private/node','started_at'=>123]);
check(!isset($public['detail'])&&!isset($public['node']),'private paths are not public');
check($public['state']==='blocked'&&$public['reason']==='sandbox','runtime blocker retained');
$stale=gca_runtime_public_status(['state'=>'installing','updated_at'=>gmdate('c',time()-1800)]);
check($stale['state']==='blocked'&&$stale['reason']==='installation_timeout','stale installation can recover');
check(gca_runtime_private_base('/srv/site/httpdocs/','/srv/site/httpdocs')==='/srv/site/.gdpr-cookie-audit-runtime-v2','runtime outside document root');
check(gca_runtime_private_base('/srv/site/httpdocs/wp/','/srv/site/httpdocs')===null,'subdirectory WordPress cannot create public runtime');
$libs=gca_runtime_public_status(['state'=>'blocked','reason'=>'installation_failed','detail'=>'/private/path/chrome: error while loading shared libraries: libnss3.so: cannot open shared object file']);
check($libs['missing_libraries']===['libnss3.so']&&!isset($libs['detail']),'dependency diagnosis exposes library name only');
echo "ALL RUNTIME TESTS PASSED\n";
