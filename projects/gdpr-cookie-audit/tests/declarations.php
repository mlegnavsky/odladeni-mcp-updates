<?php
require __DIR__.'/../plugin/core.php';
function check($v,$m){if(!$v)throw new Exception($m);echo "PASS $m\n";}
$html='<html lang="cs-CZ"><script src="https://cdn-cookieyes.com/client_data/abcdefabcdefabcdefabcdef/script.js"></script><div class="cky-audit-table-element"></div></html>';
$d=gca_cookieyes_discover($html);
check($d['client']==='abcdefabcdefabcdefabcdef'&&$d['language']==='cs'&&$d['table_present'],'discovers the page account and dynamic audit table');
check(gca_cookieyes_discover('<script src="https://evil.test/client_data/abcdefabcdefabcdefabcdef/script.js"></script>')===null,'untrusted provider is not accepted');
$script='window.owned=true;_categories:[{slug:"necessary",isNecessary:!0,defaultConsent:{gdpr:!0},cookies:[{cookieID:"custom_session",domain:"example.com"}]},{slug:"analytics",isNecessary:!1,defaultConsent:{gdpr:!1},cookies:[{cookieID:"_ga_*",domain:".example.com"}]}],_providersToBlock:[]';
$banner='_supportedMap:{cs:"Abcd1234",en:"EfgH5678"},_default:"cs"';
$audit=['cookies'=>['custom_session'=>['duration'=>'relace','description'=>'Přihlášení uživatele.'],'_ga_*'=>['duration'=>'1 rok 1 měsíc','description'=>'Google Analytics counts page views.']],'cky_preference_necessary_title'=>'Nezbytné','cky_preference_analytics_title'=>'Analytické'];
$calls=[];$load=gca_cookieyes_load($d,'https://example.com/cookies/',function($url)use(&$calls,$script,$banner,$audit){$calls[]=$url;return ['code'=>200,'headers'=>['content-type'=>[str_ends_with($url,'.json')?'application/json':'application/javascript']],'body'=>str_ends_with($url,'/script.js')?$script:(str_ends_with($url,'/banner.js')?$banner:json_encode($audit))];});
check(count($calls)===3&&count($load['records'])===2,'three bounded data resources recover declared entries');
check($load['records'][0]['name']==='custom_session'&&$load['records'][0]['lifetime']==='relace','custom names and Czech durations retained');
check($load['records'][1]['category']==='analytics'&&$load['records'][1]['basis']==='declaration','category is declaration evidence, not storage proof');
check(str_ends_with($load['records'][0]['source_url'],'/audit-table/Abcd1234.json'),'exact audit data provenance retained');
check(!str_contains(json_encode($load),'owned'),'script source is not evaluated or returned');
$docs=[['url'=>'https://example.com/cookies/','_text'=>'Cookies používáme pro analytiku.','_declaration_client'=>$d['client'],'_dynamic_table'=>true]];
$p=gca_analyze_policy_documents($docs,['google-tags'],[],$load['records']);
check($p['checks']['inventory']['status']==='pass'&&$p['checks']['retention']['status']==='pass','dynamic table is included in document analysis');
check($p['services'][0]['status']==='pass','service description in dynamic declaration is found');
$p=gca_analyze_policy_documents([['url'=>'https://example.com/cookies/','_text'=>'Cookies','_declaration_client'=>'111111111111111111111111','_dynamic_table'=>true]],[],[], $load['records']);
check($p['checks']['inventory']['status']==='unknown','a different account never fills a document table');
try{gca_cookieyes_load($d,'https://example.com/',fn($url)=>['code'=>302,'headers'=>['location'=>['http://127.0.0.1/']],'body'=>'']);check(false,'redirect rejected');}catch(RuntimeException $e){check(true,'resource redirects are rejected');}
$cats=gca_cookieyes_categories('_categories:[{slug:"analytics",cookies:[{domain:".example.com",duration:"90 days",cookieID:"_fbp"}]}]');
check(count($cats[0]['cookies'])===1,'field order and unrelated fields do not drop declaration entries');
try{gca_cookieyes_categories('_categories:[{slug:"analytics",cookies:[{cookieID:"_ga",domain:".example.com"},{cookieID:"_fbp"}]}]');check(false,'incomplete entry rejected');}catch(RuntimeException $e){check(true,'unsupported or incomplete entry never yields a complete partial list');}
$d=gca_cookieyes_discover("<html lang = \"cs-CZ\"><script src = \"https://cdn-cookieyes.com/client_data/abcdefabcdefabcdefabcdef/script.js\"></script><div class=\n\"cky-audit-table-element\"></div></html>");
check($d!==null&&$d['table_present'],'normal HTML attribute whitespace does not hide the dynamic table');
echo "ALL DECLARATION TESTS PASSED\n";
