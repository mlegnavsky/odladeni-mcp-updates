<?php
require __DIR__.'/wp.php';
$base=['id'=>str_repeat('d',32),'token_hash'=>hash('sha256','secret'),'url'=>'https://example.com/','started_at'=>'2026-11-13T16:00:00Z','state'=>'complete','queue'=>[],'pages'=>[['url'=>'https://example.com/','status'=>200]],'policies'=>[],'errors'=>[],'policy_discovery'=>['status'=>'missing']];
$j=$base;$j['browser']=['status'=>'complete','results'=>array_map(fn($id)=>['id'=>$id,'status'=>'pass','title'=>$id],['before','contrast','reject','persistence','accept','withdraw','analytics','marketing','mobile','storage']),'observations'=>[['phase'=>'before','cookies'=>[['name'=>'SECRET_COOKIE','category'=>'necessary'],['name'=>'_ga','category'=>'analytics'],['name'=>'_fbp','category'=>'marketing'],['name'=>'custom','category'=>'unknown']],'storage'=>[]]]];
$p=gca_presentation($j);check(isset($p['coverage']),'dashboard exposes measured area coverage');
$phase=$p['phases'][0];check($phase['breakdown']['necessary']===1&&$phase['breakdown']['analytics']===1&&$phase['breakdown']['advertisement']===1&&$phase['breakdown']['other']===1,'stacked graph preserves actual category counts and unknowns');
check(array_sum($phase['breakdown'])===$phase['cookies'],'stacked segments sum exactly to observed cookies');
$missing=$p['phases'][1];check($missing['cookies']===null&&$missing['breakdown']===null,'unperformed phase is missing rather than zero');
$public=gca_public_report($j);check(!str_contains(json_encode($public),'SECRET_COOKIE')&&!isset($public['presentation']['classification_details']),'public projection omits technical inventory and internal evidence');
$internal=gca_public_report($j,true);check($internal['is_internal']&&isset($internal['presentation']['classification_details'])&&$internal['browser']['observations'][0]['cookies'][0]['name']==='SECRET_COOKIE','authorized internal view retains complete evidence');
$p=gca_presentation($base);check($p['percent']===null&&$p['coverage']['completed']===1&&$p['coverage']['total']===4&&$p['coverage']['percent']===25,'legacy incomplete scan distinguishes25percent coverage from missing health score');
check($p['totals']['critical']===0&&$p['totals']['warnings']>=1,'headline counts retain severities without invented critical errors');
echo "ALL PRESENTATION TESTS PASSED\n";
