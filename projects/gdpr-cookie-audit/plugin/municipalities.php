<?php
if(!defined('ABSPATH'))exit;
/** Private, self-contained administrative directory. No public routes or remote dependency. */
function gca_municipalities_data(){static $data;if($data===null)$data=require __DIR__.'/data/municipalities.php';return $data;}
function gca_municipalities_fold($v){return strtolower(strtr($v,['Á'=>'a','Č'=>'c','Ď'=>'d','É'=>'e','Ě'=>'e','Í'=>'i','Ň'=>'n','Ó'=>'o','Ř'=>'r','Š'=>'s','Ť'=>'t','Ú'=>'u','Ů'=>'u','Ý'=>'y','Ž'=>'z','á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z']));}
function gca_municipalities_param($query,$name){$v=$query[$name]??'';return is_string($v)?trim(substr(strip_tags($v),0,100)):'';}
function gca_municipalities_query($data,$query){
 $q=gca_municipalities_param($query,'q');$region=gca_municipalities_param($query,'region');$district=gca_municipalities_param($query,'district');
 if(!isset($data['regions'][$region]))$region='';if(!isset($data['districts'][$district]))$district='';$needle=gca_municipalities_fold($q);
 $rows=array_values(array_filter($data['rows'],fn($r)=>($region===''||$r['region']===$region)&&($district===''||$r['district']===$district)&&($needle===''||str_contains(gca_municipalities_fold($r['name']),$needle)||str_contains($r['code'],$needle))));
 $total=count($rows);$pages=max(1,(int)ceil($total/50));$raw=$query['paged']??1;$page=is_scalar($raw)?max(1,min($pages,(int)$raw)):1;
 return ['q'=>$q,'region'=>$region,'district'=>$district,'total'=>$total,'page'=>$page,'pages'=>$pages,'rows'=>array_slice($rows,($page-1)*50,50),'per_page'=>50];
}
function gca_municipalities_admin(){
 if(!current_user_can('manage_options'))return;
 $data=gca_municipalities_data();$result=gca_municipalities_query($data,$_GET);$base=admin_url('tools.php');
 echo '<div class="wrap"><h1>Obce ČR</h1><p><strong>'.esc_html(number_format(count($data['rows']),0,',',' ')).' obcí</strong> · 14 krajů · seznam převzatý z projektu Poslanecká sněmovna, podklady ČSÚ. Stav územního seznamu: <strong>8. 9. 2026</strong>.</p><p>Vojenské újezdy a městské části nejsou mezi obcemi. Stejnojmenné obce rozlišíte podle okresu a kódu.</p>';
 echo '<form method="get" action="'.esc_url($base).'" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:24px 0"><input type="hidden" name="page" value="gca-municipalities"><label for="gca-obce-q">Název nebo kód obce<br><input id="gca-obce-q" name="q" type="search" maxlength="100" value="'.esc_attr($result['q']).'" placeholder="Například Žitenice nebo 565962" style="min-width:260px"></label><label for="gca-obce-region">Kraj<br><select id="gca-obce-region" name="region"><option value="">Všechny kraje</option>';
 $regions=$data['regions'];asort($regions,SORT_NATURAL|SORT_FLAG_CASE);foreach($regions as $code=>$name)echo '<option value="'.esc_attr($code).'"'.((string)$code===$result['region']?' selected':'').'>'.esc_html($name).'</option>';
 echo '</select></label><label for="gca-obce-district">Okres<br><select id="gca-obce-district" name="district"><option value="">Všechny okresy</option>';
 $districts=$data['districts'];uasort($districts,fn($a,$b)=>strcmp(gca_municipalities_fold($a['name']),gca_municipalities_fold($b['name'])));foreach($districts as $code=>$d)echo '<option value="'.esc_attr($code).'"'.((string)$code===$result['district']?' selected':'').'>'.esc_html($d['name']).'</option>';
 echo '</select></label><button class="button button-primary" type="submit">Filtrovat</button><a class="button" href="'.esc_url(add_query_arg('page','gca-municipalities',$base)).'">Zrušit filtry</a></form>';
 echo '<p role="status">Nalezeno <strong>'.esc_html(number_format($result['total'],0,',',' ')).'</strong> obcí. Strana '.(int)$result['page'].' z '.(int)$result['pages'].'.</p><table class="widefat striped"><thead><tr><th scope="col">Obec</th><th scope="col">Kód obce (ČSÚ)</th><th scope="col">Okres</th><th scope="col">Kraj</th></tr></thead><tbody>';
 foreach($result['rows'] as $r)echo '<tr><td><strong>'.esc_html($r['name']).'</strong></td><td><code>'.esc_html($r['code']).'</code></td><td>'.esc_html($data['districts'][$r['district']]['name']).'</td><td>'.esc_html($data['regions'][$r['region']]).'</td></tr>';
 if(!$result['rows'])echo '<tr><td colspan="4">Zadaným filtrům neodpovídá žádná obec. Zkuste změnit název, kraj nebo okres.</td></tr>';
 echo '</tbody></table><nav aria-label="Stránkování obcí" style="display:flex;gap:10px;align-items:center;margin:18px 0">';$params=['page'=>'gca-municipalities','q'=>$result['q'],'region'=>$result['region'],'district'=>$result['district']];
 foreach([1=>'První',$result['page']-1=>'Předchozí',$result['page']+1=>'Další',$result['pages']=>'Poslední'] as $page=>$label){if($page<1||$page>$result['pages']||$page===$result['page'])continue;echo '<a class="button" href="'.esc_url(add_query_arg($params+['paged'=>$page],$base)).'">'.esc_html($label).'</a>';}
 echo '<span>'.(int)$result['page'].' / '.(int)$result['pages'].'</span></nav><details><summary>Zdroj a rozsah seznamu</summary><p>Zdroj: '.esc_html($data['meta']['attribution']).'. Verze podkladu '.esc_html($data['meta']['version']).', územní snímek '.esc_html($data['meta']['snapshot_date']).', převzato 13. 11. 2026. Seznam obsahuje názvy obcí a územní identifikátory; neobsahuje volební výsledky, obyvatele ani osobní údaje.</p><p>Praha je uvedena jako jedna obec; okresní položka Praha odpovídá stejnému území. Čtyři vojenské újezdy jsou vyřazeny podle kódu. Hledání funguje i bez diakritiky.</p></details></div>';
}
add_action('admin_menu',function(){add_management_page('Obce ČR','Obce ČR','manage_options','gca-municipalities','gca_municipalities_admin');});
