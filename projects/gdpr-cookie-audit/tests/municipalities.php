<?php
require __DIR__.'/wp.php';
check(function_exists('gca_municipalities_data'),'municipality directory exists');
$d=gca_municipalities_data();check(count($d['rows'])===6254&&count($d['regions'])===14,'all6254 municipalities and14regions imported');
check(count(array_unique(array_column($d['rows'],'code')))===6254,'all municipal codes unique');
check(!array_intersect(['503941','545422','555177','592935'],array_column($d['rows'],'code')),'four military territories excluded by code');
foreach($d['rows'] as $r)if(!preg_match('/^\d{6}$/',$r['code'])||!isset($d['regions'][$r['region']],$d['districts'][$r['district']]))throw new Exception('Invalid municipality hierarchy');check(true,'all names and parent codes valid');
$q=gca_municipalities_query($d,['q'=>'zitenice']);check($q['total']===1&&$q['rows'][0]['code']==='565962','accentless search finds Žitenice');
$q=gca_municipalities_query($d,['q'=>'565962']);check($q['total']===1&&$q['rows'][0]['name']==='Žitenice','code search retains canonical code and name');
$q=gca_municipalities_query($d,['q'=>'Březina']);check($q['total']>1&&count(array_unique(array_column($q['rows'],'district')))>1,'same names retain independent geographic identities');
$q=gca_municipalities_query($d,['q'=>'Žitenice','region'=>'4200','district'=>'4203']);check($q['total']===1,'region and district filters combine with search');
$q=gca_municipalities_query($d,['q'=>'Žitenice','region'=>'7200']);check($q['total']===0,'wrong region never returns a name-only match');
$q=gca_municipalities_query($d,['paged'=>99999]);check($q['page']===$q['pages']&&count($q['rows'])>0,'page beyond result clamps to final available page');
$q=gca_municipalities_query($d,['q'=>['bad'],'region'=>['bad'],'paged'=>['bad']]);check($q['page']===1&&count($q['rows'])===50,'malformed GET arrays cannot crash or expand pagination');
function current_user_can($cap){return $GLOBALS['directory_authorized']??false;}
ob_start();gca_municipalities_admin();$html=ob_get_clean();check($html===''&&!str_contains($html,'Žitenice'),'unauthorized administrator callback emits no directory');
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_attr($v);}
function admin_url($p){return 'https://example.com/wp-admin/'.$p;}
function add_query_arg($a,$b,$c=null){if(!is_array($a)){$a=[$a=>$b];$b=$c;}return $b.'?'.http_build_query($a);}
$GLOBALS['directory_authorized']=true;$_GET=['q'=>'zitenice'];ob_start();gca_municipalities_admin();$html=ob_get_clean();check(str_contains($html,'Žitenice')&&str_contains($html,'565962')&&str_contains($html,'Litoměřice')&&str_contains($html,'Ústecký kraj'),'authorized admin table displays correct source geography');
$_GET=['q'=>'<script>alert(1)</script>"'];ob_start();gca_municipalities_admin();$html=ob_get_clean();check(!str_contains($html,'<script>')&&str_contains($html,'&quot;'),'search input cannot inject administrator markup');
echo "ALL MUNICIPALITY TESTS PASSED\n";
