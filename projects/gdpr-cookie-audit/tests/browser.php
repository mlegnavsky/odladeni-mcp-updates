<?php
require __DIR__.'/wp.php';
$job=['id'=>str_repeat('a',32),'state'=>'running','phase'=>'browser','_source_state'=>'complete','browser'=>['status'=>'running'],'pages'=>[],'policies'=>[],'errors'=>[]];
check(function_exists('gca_browser_apply'),'browser result integration exists');
$r=gca_browser_apply($job,['status'=>'complete','available'=>true,'results'=>[['id'=>'before','status'=>'error']]]);
check($r['state']==='complete'&&$r['browser']['results'][0]['status']==='error','real technical errors preserved in saved completed report');
check($r['phase']==='complete'&&!empty($r['finished_at']),'browser completion ends measurement');
$r=gca_browser_apply($job,['status'=>'failed','reason'=>'timeout','results'=>[]]);
check($r['state']==='partial'&&$r['browser']['status']==='failed','failed browser keeps valid source work as partial not a fake success');
$r=gca_browser_apply($job,['status'=>'running','results'=>[['id'=>'before','status'=>'pass']]]);
check($r['state']==='running'&&$r['phase']==='browser','in-progress browser work is not marked complete');
$job['_policy_documents']=[['url'=>'https://example.com/cookies/','_text'=>gca_policy_text('<table><tr><th>Soubor cookie</th><th>Doba trvání</th><th>Popis</th></tr><tr><td>_ga_*</td><td>1 rok</td><td>Google Analytics</td></tr></table>')]];
$job['pages']=[['is_target'=>false,'services'=>[['id'=>'clarity']],'cookies'=>[]]];
$r=gca_browser_apply($job,['status'=>'complete','observations'=>[['cookies'=>[['name'=>'_ga_ABC','domain'=>'example.com','basis'=>'browser']]]],'results'=>[]]);
check($r['policies'][0]['cookies'][0]['status']==='pass','actual browser cookies are compared against documented wildcard inventory');
check(!$r['policies'][0]['services'],'external document integrations do not become audited-site services during browser reassessment');
