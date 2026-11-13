<?php
/** Cookie-document evidence only. No inference about browser consent behavior. */
function gca_policy_text($html){
    $html=preg_replace('~<(script|style|svg|noscript|nav|header|footer|form)\b[^>]*>.*?</\1>~is',' ',$html);
    if(preg_match('~<(main|article)\b[^>]*>(.*?)</\1>~is',$html,$m))$html=$m[2];
    $html=preg_replace('~</(?:p|h[1-6]|li|tr|section|div)>|<br\s*/?>~i',"\n",$html);
    $html=preg_replace('~</t[dh]>~i',' | ',$html);
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $lines=preg_split('/\R/u',$text);$out=[];
    foreach($lines as $line){$line=trim(preg_replace('/[\p{Z}\t ]+/u',' ',$line));if($line!=='')$out[]=$line;}
    $text=implode("\n",$out);$text=substr($text,0,150000);
    while($text!==''&&!preg_match('//u',$text))$text=substr($text,0,-1);
    return $text;
}
function gca_policy_inventory($text,$cookies){
    $names=[];foreach($cookies as $cookie){$name=$cookie['name'];if($name!==''&&preg_match('~(?<![\w.-])'.preg_quote($name,'~').'(?![\w.-])~iu',$text))$names[]=$name;}
    preg_match_all('~(?<![\w.-])(?:_ga(?:_[A-Z0-9]+)?|_gid|_fbp|cookieyes-consent|wordpress_logged_in_[*a-z0-9]*|PHPSESSID)(?![\w.-])~i',$text,$m);$names=array_merge($names,$m[0]);
    $table=false;
    foreach(explode("\n",$text) as $line){
        if(str_contains($line,'|')&&preg_match('~název|cookie name|^name\s*\||^cookie\s*\|~iu',$line)&&preg_match('~účel|purpose|platnost|duration|expir|provider|poskytovatel~iu',$line)){$table=true;continue;}
        if($table&&str_contains($line,'|')){$name=trim(explode('|',$line)[0]);if(preg_match('~^[a-zA-Z_][a-zA-Z0-9_.*-]{1,79}$~',$name)&&!preg_match('~^(name|cookie|cookies|purpose|provider|category|duration)$~i',$name))$names[]=$name;}
        elseif(!str_contains($line,'|'))$table=false;
    }
    return array_slice(array_values(array_unique($names)),0,100);
}
function gca_policy_excerpt($block,$pattern){
    if(preg_match($pattern,$block,$m)&&preg_match('~.{0,90}'.preg_quote($m[0],'~').'.{0,160}~isu',$block,$excerpt))return trim($excerpt[0]);
    preg_match('~^.{0,320}~su',$block,$m);return $m[0]??'';
}
function gca_policy_check($label,$outcome,$evidence,$url,$fix){
    return ['label'=>$label,'status'=>$outcome==='unavailable'?'unknown':(in_array($outcome,['found','not_detected'],true)?'pass':'warning'),'outcome'=>$outcome,'evidence'=>$evidence,'source_url'=>$url,'recommendation'=>$fix];
}
function gca_analyze_policy($url,$html,$serviceIds=[],$cookies=[]){
    $text=gca_policy_text($html);$blocks=preg_split('/\R|(?<=[.!?])\s+(?=[\p{Lu}])/u',$text);$names=gca_policy_inventory($text,$cookies);
    $cookieContext='~cookies?|'.($names?implode('|',array_map(fn($n)=>preg_quote($n,'~'),$names)):'(?!)').'~iu';
    $checks=[];
    $rules=[
      'purposes'=>['Účely používání cookies','~analytick|marketing|nezbytn|přihláš|měření návštěv|personaliz|purposes?|necessary|advertising|analytics|login~iu',null,'Účel používání cookies','Popište účely jednotlivých kategorií cookies a přiřaďte je ke skutečně používaným cookies.'],
      'retention'=>['Platnost a životnost cookies','~\d+\s*(?:sekund|minut|hodin|dní|dnů|dny|měsíc|rok|let|seconds?|minutes?|hours?|days?|months?|years?)|(?:do|při|po)\s+(?:zavření|uzavření|ukončení)\s+(?:prohlížeče|relace)|po dobu (?:relace|návštěvy)|(?<![\w.-])session(?![\w.-])~iu',null,'Konkrétní doba platnosti cookies','Doplňte u cookies dobu platnosti, případně údaj, že zanikají ukončením relace.'],
      'withdrawal'=>['Změna a odvolání souhlasu s cookies','~odvolat|odvolání|odvoláte|zrušit.{0,35}souhlas|změn.{0,35}(?:souhlas|rozhodnutí)|(?:rozhodnutí|volb[uy]|souhlas).{0,65}(?:upravit|změnit)|withdraw|revok|revoke~iu','~(?:nelze|nemůžete|není možné|cannot|can.t).{0,45}(?:odvol|zruš|změn|withdraw|revoke)|(?:odvolání|změna).{0,35}(?:není možn|není umožněn)~iu','Popis změny nebo odvolání souhlasu s cookies','Doplňte konkrétní postup změny a odvolání souhlasu, například přes trvale dostupné Nastavení cookies.'],
      'choice'=>['Odmítnutí volitelných cookies','~odmítn|nesouhlas|reject|decline|pouze nezbytné|jen nezbytné~iu','~(?:nelze|nemůžete|není možné|cannot|can.t).{0,45}(?:odmítn|reject|decline)|odmítnutí.{0,35}(?:není možn|není umožněn)~iu','Popis odmítnutí volitelných cookies','Popište možnost odmítnutí volitelných cookies a jak ji návštěvník použije.'],
    ];
    foreach($rules as $id=>[$label,$pattern,$negative,$missing,$fix]){
        $found=null;$conflict=null;
        foreach($blocks as $block){
            if(!preg_match($cookieContext,$block)&&preg_match('~newsletter|obchodní sdělení|odběrem|žádost|uchazeč~iu',$block))continue;
            if(!preg_match($cookieContext,$block)&&!(in_array($id,['withdrawal','choice'],true)&&preg_match('~souhlas|consent~iu',$block)&&preg_match($cookieContext,$text)&&!preg_match('~údaj|personal data|newsletter|zpracován~iu',$block)))continue;
            if($negative&&preg_match($negative,$block)){$conflict=gca_policy_excerpt($block,$negative);break;}
            if(preg_match($pattern,$block))$found=gca_policy_excerpt($block,$pattern);
        }
        $outcome=$conflict?'conflict':($found?'found':'missing');
        $evidence=$conflict?('Rozporná formulace v dokumentaci: '.$conflict):($found??('V kontrolovaných dokumentech nerozpoznáno: '.$missing.'.'));
        $checks[$id]=gca_policy_check($label,$outcome,$evidence,$url,$outcome==='found'?'Informace byla nalezena; konkrétní zdroj je uveden u výsledku.':$fix);
    }
    $checks['inventory']=gca_policy_check('Přehled konkrétních cookies',$names?'found':'missing',$names?'Rozpoznané názvy v dokumentaci: '.implode(', ',$names):'V kontrolovaných dokumentech nebyl rozpoznán seznam názvů cookies.',$url,$names?'Nalezený přehled se porovnává s cookies dostupnými při měření.':'Doplňte veřejně dostupnou tabulku s názvy cookies, účely, poskytovateli a dobou platnosti.');
    $bad=null;
    foreach($blocks as $block){if(preg_match('~(?:používáním|prohlížením|návštěvou|pokračováním).{0,120}(?:souhlasíte|souhlas)~iu',$block)&&!preg_match('~neudělujete|nedáváte|nevyjadřujete|nesouhlasíte|neznamená.{0,25}souhlas|nepovažuje~iu',$block)){$bad=gca_policy_excerpt($block,'~(?:používáním|prohlížením|návštěvou|pokračováním).{0,120}(?:souhlasíte|souhlas)~iu');break;}}
    $checks['implied_consent']=gca_policy_check('Souhlas pouhou návštěvou — kontrola formulace',$bad?'conflict':'not_detected',$bad?'Nalezena formulace, která odvozuje souhlas z návštěvy webu: '.$bad:'V kontrolovaných dokumentech nebyla rozpoznána formulace souhlasu pouhou návštěvou. Toto je kontrola textu; chování lišty tím není ověřeno.',$url,$bad?'Odstraňte formulaci souhlasu pouhou návštěvou a popište aktivní volbu návštěvníka.':'Tato kontrola se týká formulace dokumentu. Skutečné udělení souhlasu patří do samostatného prohlížečového testu.');
    if($text===''||preg_match('~just a moment|checking your browser|verify you are human|ověřte.{0,20}(?:člověk|člověkem)|attention required.{0,40}cloudflare~iu',$text))foreach($checks as $id=>$check)$checks[$id]=gca_policy_check($check['label'],'unavailable','Dokument nemá dostupný text nebo místo něj byla načtena bezpečnostní výzva. Kontrolu jeho obsahu nebylo možné provést.',$url,'Zpřístupněte cookie dokument pro automatickou kontrolu a poté spusťte nové měření.');
    $out=['url'=>$url,'checks'=>$checks,'services'=>[],'cookies'=>[],'text_length'=>strlen($text),'text_truncated'=>strlen($text)>=149996];$catalog=gca_provider_catalog();
    $available=$checks['purposes']['outcome']!=='unavailable';
    foreach(array_unique($serviceIds) as $id){if(!isset($catalog[$id]))continue;$p=$catalog[$id];$found=preg_match($p[3],$text,$m);$out['services'][]=['id'=>$id,'name'=>$p[0],'status'=>!$available?'unknown':($found?'pass':'warning'),'source_url'=>$url,'evidence'=>!$available?'Dokument nebyl dostupný pro kontrolu zmínky o službě.':($found?'V dokumentaci nalezena zmínka: '.$m[0]:'Služba nalezena ve zdroji webu, v kontrolované cookie dokumentaci nenalezena.')];}
    foreach($cookies as $c)$out['cookies'][]=['name'=>$c['name'],'status'=>!$available?'unknown':(in_array($c['name'],$names,true)?'pass':'warning')];
    return $out;
}
function gca_analyze_policy_documents($documents,$serviceIds=[],$cookies=[]){
    $results=[];foreach($documents as $document)$results[]=gca_analyze_policy($document['url'],htmlspecialchars($document['_text'],ENT_QUOTES,'UTF-8'),$serviceIds,$cookies);
    if(!$results)return null;
    $out=$results[0];$out['documents']=[];$checked=0;$incomplete=false;
    foreach($documents as $i=>$document){
        $available=$results[$i]['checks']['purposes']['outcome']!=='unavailable';$truncated=$results[$i]['text_truncated'];
        $out['documents'][]=['url'=>$document['url'],'text_length'=>strlen($document['_text']),'available'=>$available,'text_truncated'=>$truncated];
        if($available)$checked++;if(!$available||$truncated)$incomplete=true;
    }
    $priority=['unavailable'=>0,'missing'=>1,'not_detected'=>2,'found'=>3,'conflict'=>4];
    foreach($results as $result){
        foreach($result['checks'] as $id=>$check){$old=$out['checks'][$id];if($priority[$check['outcome']]>$priority[$old['outcome']])$out['checks'][$id]=$check;}
        foreach(['services','cookies'] as $type)foreach($result[$type] as $i=>$entry)if($entry['status']==='pass'||(($out[$type][$i]['status']??'unknown')==='unknown'&&$entry['status']==='warning'))$out[$type][$i]=$entry;
    }
    foreach($out['checks'] as &$check){
        $check['documents_checked']=$checked;
        if(in_array($check['outcome'],['missing','not_detected'],true)&&$incomplete){
            $check['outcome']='unavailable';$check['status']='unknown';$check['source_url']=null;
            $check['evidence']='V dostupné části nebyla odpovídající informace nebo formulace rozpoznána. Některý dokument nebyl dostupný nebo překročil textový limit, proto tuto kontrolu nebylo možné dokončit.';
            $check['recommendation']='Zpřístupněte úplný text cookie dokumentace pro automatické načtení a spusťte nové měření.';
        }elseif($check['outcome']==='missing'){$check['source_url']=null;$check['evidence'].=' Prohledáno dokumentů: '.$checked.'.';}
    }unset($check);
    return $out;
}
function gca_discover_queue($queue,$cursor,$page,$manual){
    $done=array_slice($queue,0,$cursor+1);$pending=array_slice($queue,$cursor+1);$seen=array_fill_keys(array_column($done,'url'),true);if(!empty($page['url']))$seen[$page['url']]=true;$docs=[];
    if($manual)$docs[]=['url'=>$manual,'kind'=>'policy'];
    $links=$page['policies'];usort($links,fn($a,$b)=>($b['priority']??0)<=>($a['priority']??0));
    foreach($links as $link)$docs[]=['url'=>$link['url'],'kind'=>'policy'];
    $pendingDocs=array_filter($pending,fn($x)=>$x['kind']==='policy');$pendingPages=array_filter($pending,fn($x)=>$x['kind']!=='policy');
    $candidates=array_merge(array_values($pendingDocs),$docs,array_values($pendingPages));
    if($cursor===0)foreach($page['links'] as $url)$candidates[]=['url'=>$url,'kind'=>'page'];
    foreach($candidates as $item){if(count($done)>=5)break;if(isset($seen[$item['url']]))continue;$done[]=$item;$seen[$item['url']]=true;}
    return $done;
}
