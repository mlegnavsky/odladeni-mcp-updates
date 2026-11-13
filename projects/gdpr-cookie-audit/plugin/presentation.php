<?php
if(!defined('ABSPATH'))exit;
function gca_known_category($name){
 if(preg_match('/^(cookieyes-consent|CookieConsent|Optanon|cmplz_|wordpress_|PHPSESSID|wp-settings)/i',$name))return 'necessary';
 if(preg_match('/^(_ga(_|$)|_gid$|_gat|_hj|_clck$|_clsk$)/i',$name))return 'analytics';
 if(preg_match('/^(_fbp$|_fbc$|_gcl_|IDE$|test_cookie$|_ttp$|_tt_enable_cookie$)/i',$name))return 'advertisement';
 return 'unknown';
}
function gca_record_matches($a,$b){
 $pattern='/^'.str_replace('\\*','.*',preg_quote($a['name']??'','/')).'$/';
 $ad=strtolower(ltrim($a['domain']??'','.'));$bd=strtolower(ltrim($b['domain']??'','.'));
 return preg_match($pattern,$b['name']??'')&&$ad!==''&&($ad===$bd||str_ends_with($bd,'.'.$ad));
}
function gca_lifetime_evidence($item,$decl,$observed){
 if(($item['type']??'cookie')!=='cookie')return 'unknown';
 $actual=$item['lifetime']??'';$stated=$decl['lifetime']??'';
 if(str_starts_with($actual,'relace'))return preg_match('/relace|session/i',$stated)?'match':'unknown';
 if(!preg_match('/^(\d{4})-\d\d-\d\dT/',$actual)||!strtotime($observed))return 'unknown';
 if(!preg_match_all('/(\d+)\s*(rok|let|year|měsíc|month|dní|dnů|dny|den|day|hodin|hour|minut|minute|sekund|second)/iu',$stated,$matches,PREG_SET_ORDER))return 'unknown';
 $expected=0;
 foreach($matches as $m){$unit=strtolower($m[2]);$mult=preg_match('/rok|let|year/',$unit)?365*86400:(preg_match('/měsíc|month/u',$unit)?30.44*86400:(preg_match('/hodin|hour/',$unit)?3600:(preg_match('/minut|minute/',$unit)?60:(preg_match('/sekund|second/',$unit)?1:86400))));$expected+=(int)$m[1]*$mult;}
 $seconds=strtotime($actual)-strtotime($observed);
 return abs($seconds-$expected)<=max(60,$expected*.12)?'match':'conflict';
}
function gca_score_area($id,$label,$checks,$available=true){
 $passed=count(array_filter($checks,fn($c)=>$c==='pass'));
 return ['id'=>$id,'label'=>$label,'passed'=>$passed,'total'=>count($checks),'unknown'=>count(array_filter($checks,fn($c)=>!in_array($c,['pass','error','warning','not_applicable'],true))), 'percent'=>$available&&count($checks)?(int)floor(100*$passed/count($checks)):null];
}
function gca_presentation($j){
 $labels=['necessary'=>'Nezbytné','functional'=>'Funkční','analytics'=>'Analytické','performance'=>'Výkonnostní','advertisement'=>'Reklamní','other'=>'Nezařazené'];
 $has=fn($v)=>is_string($v)&&trim($v)!==''&&!preg_match('/^(unknown|neuvedeno|not specified|description is currently not available)[.!]?$/i',trim($v));
 $decl=$j['declared_cookies']??[];$obs=$j['browser']['observations']??[];$inventory=[];
 foreach($obs as $o){
  foreach($o['cookies']??[] as $c){$key='cookie|'.($c['domain']??'').'|'.($c['path']??'/').'|'.$c['name'];$inventory[$key]=$c+['type'=>'cookie','observed_at'=>$o['observed_at']??$j['started_at']??''];}
  foreach($o['storage']??[] as $c){$key=($c['type']??'storage').'|'.($c['origin']??'').'|'.$c['name'];$inventory[$key]=$c+['domain'=>parse_url($c['origin']??'',PHP_URL_HOST)?:'','type'=>$c['type']??'storage'];}
 }
 $categories=[];$details=[];
 foreach($labels as $cat=>$label){$checks=[];$rows=[];
  foreach($decl as $d){if(($d['category']??'other')!==$cat)continue;$known=gca_known_category($d['name']??'');
   $cs=['name'=>$has($d['name']??null),'domain'=>$has($d['domain']??null),'lifetime'=>$has($d['lifetime']??null),'purpose'=>$has($d['description']??null),'category'=>$known!=='unknown'&&$known===$cat];
   $checks=array_merge($checks,array_map(fn($v)=>$v?'pass':'error',array_values($cs)));
   $rows[]=['name'=>$d['name']??'','declared'=>$cat,'recognized'=>$known,'checks'=>$cs,'basis'=>$known==='unknown'?'Účel nebyl nezávisle rozpoznán; uvedená kategorie není potvrzením správnosti.':($known===$cat?'Rozpoznaný vzor názvu; shoda s deklarací.':'Rozpoznaný vzor názvu je v rozporu s deklarovanou kategorií.')];
  }
  $a=gca_score_area($cat,$label,$checks,!count($j['declaration_errors']??[]));$a['count']=count($rows);$categories[]=$a;$details=array_merge($details,$rows);
 }
 $matched=0;$unknown=0;$classification=[];
 foreach($inventory as $item){$known=gca_known_category($item['name']);if($known==='unknown')$unknown++;
  $matches=array_values(array_filter($decl,fn($d)=>gca_record_matches($d,$item)));$match=$matches[0]??null;
  if($match)$matched++;
  $lifetime=$match?gca_lifetime_evidence($item,$match,$item['observed_at']??''):'unknown';
  $classification[]=$lifetime==='match'?'pass':'error';
  $classification[]=$known!=='unknown'&&$match&&($match['category']??'')===$known?'pass':'error';
  $details[]=['name'=>$item['name'],'type'=>$item['type'],'domain'=>$item['domain']??'','recognized'=>$known,'declared'=>$match['category']??null,'lifetime'=>$item['lifetime']??null,'declared_lifetime'=>$match['lifetime']??null,'lifetime_comparison'=>$lifetime,'basis'=>$match?'Položka zachycena a porovnána s deklarací.':'Zachyceno, odpovídající deklarace nenalezena.'];
 }
 // Metadata completeness and independent category evidence each contribute one check.
 foreach($categories as $c){for($i=0;$i<$c['passed'];$i++)$classification[]='pass';for($i=$c['passed'];$i<$c['total'];$i++)$classification[]='error';}
 $doc=[];foreach($j['policies']??[] as $p)foreach($p['checks']??[] as $id=>$c)if(in_array($id,['purposes','retention','withdrawal','choice','inventory','implied_consent']))$doc[$id]=$c['status']??'unknown';
 foreach(['purposes','retention','withdrawal','choice','inventory','implied_consent'] as $id)if(!isset($doc[$id]))$doc[$id]=($j['policy_discovery']['status']??'')==='missing'?'error':'unknown';
 $behavior=[];$appearance=[];$issues=[];$critical=0;$unfinished=0;$testPassed=0;$performed=0;
 $results=[];foreach($j['browser']['results']??[] as $r)$results[$r['id']??'']=$r;
 foreach(['before','contrast','reject','persistence','accept','withdraw','analytics','marketing','mobile','storage'] as $id)if(!isset($results[$id]))$results[$id]=['id'=>$id,'status'=>'unknown'];
 foreach(['before','reject','accept','withdraw','analytics','marketing'] as $id){$r=$results[$id];if(in_array($r['status']??'unknown',['unknown','not_applicable']))continue;$found=false;foreach($obs as $o)if(($o['phase']??'')===$id)$found=true;if(!$found)$results[$id]['status']='unknown';}
 foreach($results as $r){$status=$r['status']??'unknown';if($status==='not_applicable')continue;
  if(in_array($status,['pass','warning','error'])){$performed++;if($status==='pass')$testPassed++;}else $unfinished++;
  if(in_array($r['id']??'',['contrast','mobile']))$appearance[]=$status;else $behavior[]=$status;
  if(in_array($status,['error','warning'])){$severe=$status==='error'&&in_array($r['id']??'',['before','reject','analytics','marketing','storage']);if($severe)$critical++;$issues[]=['area'=>in_array($r['id']??'',['contrast','mobile'])?'Vzhled a dostupnost lišty':'Chování souhlasu','title'=>$r['title']??'Problém scénáře','severity'=>$severe?'critical':$status];}
 }
 if(count(array_filter($doc,fn($s)=>$s!=='pass')))$issues[]=['area'=>'Dokumentace','title'=>'Dokumentace má chybějící nebo nepotvrzené informace','severity'=>'warning'];
 if(count(array_filter($classification,fn($s)=>$s!=='pass')))$issues[]=['area'=>'Cookies a zařazení','title'=>'Úplnost údajů nebo zařazení položek není doloženo','severity'=>'warning'];
 $browserComplete=($j['browser']['status']??'')==='complete'&&!$unfinished;
 $areas=[gca_score_area('documentation','Dokumentace',array_values($doc),!in_array('unknown',$doc,true)),gca_score_area('behavior','Respektování souhlasu',$behavior,$browserComplete),gca_score_area('classification','Cookies a zařazení',$classification,count($obs)>0&&!count($j['declaration_errors']??[])),gca_score_area('appearance','Vzhled a dostupnost',$appearance,$browserComplete)];
 $complete=in_array($j['state']??'',['complete','partial'])&&count(array_filter($areas,fn($a)=>$a['percent']===null))===0;
 $percent=$complete?(int)floor(array_sum(array_column($areas,'percent'))/4):null;
 $phases=[];foreach(['before'=>'Před souhlasem','accept'=>'Po přijetí','reject'=>'Po odmítnutí','withdraw'=>'Po odvolání'] as $id=>$label){$found=null;foreach($obs as $o)if(($o['phase']??'')===$id)$found=$o;$phases[]=['id'=>$id,'label'=>$label,'cookies'=>$found?count($found['cookies']??[]):null,'storage'=>$found?count($found['storage']??[]):null];}
 return ['method'=>'2.0','percent'=>$percent,'critical'=>$critical,'issues'=>$issues,'areas'=>$areas,'categories'=>$categories,'phases'=>$phases,'performed'=>$performed,'passed'=>$testPassed,'unfinished'=>$unfinished,'inventory'=>['total'=>count($inventory),'cookies'=>count(array_filter($inventory,fn($i)=>$i['type']==='cookie')),'storage'=>count(array_filter($inventory,fn($i)=>$i['type']!=='cookie')),'matched'=>$matched,'unknown'=>$unknown],'conclusion'=>$critical?'Zjištěn zásadní problém v respektování souhlasu.':($percent===null?'Část hodnocení není dokončena.':($percent>=90?'Většina provedených kontrol je bez nálezu.':'Web má oblasti vyžadující úpravu.')),'classification_details'=>$details];
}
function gca_public_view($j){
 $p=gca_presentation($j);unset($p['classification_details']);$images=[];
 foreach($j['browser']['screenshots']??[] as $im)if(preg_match('~^data:image/jpeg;base64,[A-Za-z0-9+/=]+$~',$im['data_url']??''))$images[]=['title'=>$im['title']??'Snímek měření','data_url'=>$im['data_url']];
 return ['id'=>$j['id']??'','url'=>$j['url']??'','started_at'=>$j['started_at']??'','finished_at'=>$j['finished_at']??null,'state'=>$j['state']??'partial','phase'=>$j['phase']??'','cursor'=>$j['cursor']??0,'total'=>$j['total']??0,'scope'=>$j['scope']??5,'storage'=>$j['storage']??'permanent','report_url'=>$j['report_url']??'','presentation'=>$p,'screenshots'=>$images,'pages'=>array_map(fn($page)=>['url'=>$page['url']??'','status'=>$page['status']??0],$j['pages']??[])];
}
