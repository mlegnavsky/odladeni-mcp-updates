<?php
require __DIR__.'/wp.php';
check(function_exists('gca_scan_progress'),'safe measurement progress exists');
$j=['state'=>'running','cursor'=>2,'scope'=>5,'phase'=>'source','browser'=>[]];$p=gca_scan_progress($j);check($p['percent']===12&&$p['stage']==='documents','source progress follows completed source pages');
$j['phase']='browser';$j['browser']=['status'=>'queued'];$p=gca_scan_progress($j);check($p['percent']===30&&$p['stage']==='queued','queued browser never invents progress');
$j['browser']=['status'=>'running','progress'=>['stage'=>'reject'],'results'=>[['id'=>'before','status'=>'pass']]];$p=gca_scan_progress($j);check($p['stage']==='reject'&&$p['percent']>30&&$p['percent']<100,'real browser steps drive progress');
$j['state']='failed';check(gca_scan_progress($j)['percent']<100,'failed scan never reports100percent completion');
$j['state']='complete';check(gca_scan_progress($j)['percent']===100,'only finished scan reaches100percent');
check(function_exists('gca_eu_assessment'),'EU conditions assessment exists');
$doc=array_fill_keys(['purposes','retention','withdrawal','choice','inventory','implied_consent'],'pass');
$results=array_fill_keys(['before','reject','persistence','withdraw','accept','storage','analytics','marketing','choice-design','default-choice'],'pass');$r=[];foreach($results as $id=>$s)$r[$id]=['id'=>$id,'status'=>$s];
$e=gca_eu_assessment($doc,$r,0);check($e['status']==='pass'&&$e['failed']===0,'all evidenced tested cookie conditions may pass scoped assessment');
$r['before']['status']='error';$e=gca_eu_assessment($doc,$r,0);check($e['status']==='fail'&&$e['failed']>0,'observed consent violation blocks positive verdict regardless score');
$r['before']['status']='pass';unset($r['default-choice']);check(gca_eu_assessment($doc,$r,0)['status']==='incomplete','legacy missing default-choice evidence never passes');
$r['default-choice']=['status'=>'pass'];$r['contrast']=['status'=>'error'];check(gca_eu_assessment($doc,$r,0)['status']==='pass','contrast threshold alone is not an EU law violation');
check(gca_eu_assessment($doc,$r,2)['status']==='incomplete','unrecognized storage purposes prevent complete consent conclusion');
$doc['retention']='error';check(gca_eu_assessment($doc,$r,0)['status']==='attention','missing document information is not disguised as proven legal violation');
$doc['retention']='pass';$r['storage']=['status'=>'error'];check(gca_eu_assessment($doc,$r,0)['status']==='fail','recognized analytics storage before consent blocks EU pass');
echo "ALL PROGRESS EU TESTS PASSED\n";
