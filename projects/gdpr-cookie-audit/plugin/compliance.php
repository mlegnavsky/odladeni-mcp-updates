<?php
if(!defined('ABSPATH'))exit;
/** Public progress contains allowlisted activities and counts, never worker paths/evidence. */
function gca_scan_progress($j){
 $stages=['initializing','before','contrast','choice-design','reject','persistence','accept','withdraw','analytics','marketing','mobile','consent-mode','storage','default-choice'];
 $state=$j['state']??'running';$terminal=in_array($state,['complete','partial','failed'],true);
 if($terminal)return ['stage'=>$state==='failed'?'failed':'finished','percent'=>$state==='failed'?min(95,(int)($j['cursor']??0)*6):100,'completed'=>0,'total'=>0];
 if(($j['phase']??'')!=='browser')return ['stage'=>'documents','percent'=>min(30,max(0,(int)($j['cursor']??0))*6),'completed'=>max(0,(int)($j['cursor']??0)),'total'=>5];
 if(($j['browser']['status']??'')==='queued')return ['stage'=>'queued','percent'=>30,'completed'=>0,'total'=>13];
 $ids=[];foreach($j['browser']['results']??[] as $r)if(in_array($r['id']??'',$stages,true))$ids[$r['id']]=true;
 $stage=$j['browser']['progress']['stage']??'initializing';if(!in_array($stage,$stages,true))$stage='initializing';
 return ['stage'=>$stage,'percent'=>min(95,30+(int)floor(65*count($ids)/13)),'completed'=>count($ids),'total'=>13];
}
/** Scoped evidence assessment. This is separate from usability scores and legal certification. */
function gca_eu_assessment($doc,$results,$unknown){
 $checks=[];$add=function($id,$label,$status,$basis)use(&$checks){$checks[]=['id'=>$id,'label'=>$label,'status'=>$status,'basis'=>$basis];};
 foreach(['before'=>'Volitelné cookies až po souhlasu','reject'=>'Respektování odmítnutí','persistence'=>'Odmítnutí platí i po obnovení stránky','withdraw'=>'Odvolání souhlasu a zastavení měření','accept'=>'Aktivní udělení souhlasu','storage'=>'Další úložiště respektují souhlas','analytics'=>'Dodržení volby analytických cookies','marketing'=>'Dodržení volby reklamních cookies','choice-design'=>'Odmítnutí ve stejné vrstvě jako přijetí','default-choice'=>'Volitelné kategorie nejsou předem zapnuté'] as $id=>$label){
  $s=$results[$id]['status']??'unknown';$status=($s==='pass'?'pass':($s==='error'?'fail':($s==='warning'?'warning':'unknown')));
  $add($id,$label,$status,$status==='pass'?'Doloženo provedeným testem.':($status==='fail'?'Provedený test zachytil problém.':($status==='warning'?'Zachycen signál vyžadující pozornost; sám neprokazuje porušení.':'Měření tuto podmínku nedoložilo.')));
 }
 foreach(['purposes'=>'Informace o účelech cookies','retention'=>'Informace o době uchování','withdrawal'=>'Popis změny a odvolání souhlasu','inventory'=>'Dostupný přehled konkrétních cookies','implied_consent'=>'Souhlas není odvozován z pouhé návštěvy'] as $id=>$label){$s=$doc[$id]??'unknown';$add('doc-'.$id,$label,$s==='pass'?'pass':($s==='error'?'not_documented':'unknown'),$s==='pass'?'Informace rozpoznána v cookie dokumentaci; její věcná úplnost není tímto potvrzena.':($s==='error'?'V prohledané cookie dokumentaci nebyla podmínka doložena nebo byl zjištěn rozpor.':'Cookie dokumentaci nebylo možné pro tuto podmínku vyhodnotit.'));}
 $add('purposes-known','Rozpoznané účely zachycených cookies a úložišť',$unknown?'unknown':'pass',$unknown?'U části zachycených položek nebyl účel rozpoznán.':'U zachycených položek nebyl zaznamenán nerozpoznaný účel.');
 $counts=array_count_values(array_column($checks,'status'));$failed=$counts['fail']??0;$missing=$counts['unknown']??0;$attention=($counts['not_documented']??0)+($counts['warning']??0);
 $status=$failed?'fail':($attention?'attention':($missing?'incomplete':'pass'));
 return ['status'=>$status,'label'=>['fail'=>'Zjištěný nesoulad v testovaných podmínkách','attention'=>'Kontrolované podmínky nejsou plně doloženy','incomplete'=>'Soulad zatím nelze potvrdit','pass'=>'Kontrolované podmínky splněny'][$status],'passed'=>$counts['pass']??0,'total'=>count($checks),'failed'=>$failed,'unknown'=>$missing,'attention'=>$attention,'checks'=>$checks,'scope'=>'Vyhodnocení kontrolovatelných podmínek pro cookies podle ePrivacy a GDPR. Platí pro zachycené položky, testované scénáře a čas měření; není certifikací celkového právního souladu webu.','sources'=>[['label'=>'GDPR: čl. 4 odst. 11 a čl. 7','url'=>'https://eur-lex.europa.eu/eli/reg/2016/679/oj'],['label'=>'ePrivacy: čl. 5 odst. 3','url'=>'https://eur-lex.europa.eu/eli/dir/2002/58/oj'],['label'=>'EDPB: Cookie Banner Taskforce','url'=>'https://www.edpb.europa.eu/system/files/documents/2023-01/edpb_20230118_report_cookie_banner_taskforce_en.pdf'],['label'=>'ÚOOÚ: cookies','url'=>'https://uoou.gov.cz/verejnost/qa-otazky-a-odpovedi/cookies']]];
}
