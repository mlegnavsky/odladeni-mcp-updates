<?php
/** Public CMP declarations. JavaScript is parsed as data, never executed. */
function gca_cookieyes_discover($html){
    if(!preg_match('~<script\b[^>]*\bsrc\s*=\s*["\x27]https://cdn-cookieyes\.com/client_data/([a-f0-9]{24,64})/(?:script|cookieyes)\.js(?:\?[^"\x27]*)?["\x27]~i',$html,$m))return null;
    preg_match('~<html\b[^>]*\blang\s*=\s*["\x27]([a-z]{2})~i',$html,$lang);
    return ['client'=>strtolower($m[1]),'language'=>strtolower($lang[1]??'en'),'table_present'=>(bool)preg_match('~class\s*=\s*["\x27][^"\x27]*\bcky-audit-table-element\b~i',$html)];
}
function gca_js_literal_array($script,$key){
    if(!preg_match('~\b'.preg_quote($key,'~').'\s*:\s*(\[)~',$script,$m,PREG_OFFSET_CAPTURE))throw new RuntimeException('Nerozpoznaný formát veřejné konfigurace CookieYes.');
    $start=$m[1][1];$depth=0;$quote=null;$escape=false;
    for($i=$start,$n=strlen($script);$i<$n;$i++){
        $c=$script[$i];if($quote!==null){if($escape){$escape=false;continue;}if($c==='\\'){$escape=true;continue;}if($c===$quote)$quote=null;continue;}
        if($c==='"'||$c==="'"){$quote=$c;continue;}if($c==='[')$depth++;elseif($c===']'&&--$depth===0)return substr($script,$start,$i-$start+1);
    }
    throw new RuntimeException('Neúplná veřejná konfigurace CookieYes.');
}
function gca_cookieyes_categories($script){
    $array=gca_js_literal_array($script,'_categories');$out=[];
    preg_match_all('~\bslug\s*:\s*"([a-z_-]{1,40})"(.*?)\bcookies\s*:\s*\[(.*?)\]~s',$array,$categories,PREG_SET_ORDER);
    foreach($categories as $category){
        $entries=[];preg_match_all('~\{([^{}]*)\}~s',$category[3],$objects,PREG_SET_ORDER);
        foreach($objects as $object){
            if(!preg_match('~\bcookieID\s*:\s*("(?:[^"\\\\]|\\\\.)*")~s',$object[1],$id)||!preg_match('~\bdomain\s*:\s*("(?:[^"\\\\]|\\\\.)*")~s',$object[1],$domainMatch))throw new RuntimeException('Neúplný záznam ve veřejné deklaraci.');
            $name=json_decode($id[1],true);$domain=json_decode($domainMatch[1],true);
            if(!is_string($name)||!is_string($domain)||strlen($name)>160||strlen($domain)>253)throw new RuntimeException('Nepodporovaný záznam deklarace.');
            $entries[]=['name'=>$name,'domain'=>$domain];
        }
        if(preg_match_all('~\bcookieID\s*:~',$category[3])!==count($entries))throw new RuntimeException('Deklaraci nelze zpracovat úplně.');
        preg_match('~isNecessary\s*:\s*(!0|!1|true|false)~',$category[2],$necessary);
        preg_match('~defaultConsent\s*:\s*\{\s*gdpr\s*:\s*(!0|!1|true|false)~',$category[2],$default);
        $out[]=['slug'=>$category[1],'necessary'=>in_array($necessary[1]??'',['!0','true'],true),'default_enabled'=>isset($default[1])?in_array($default[1],['!0','true'],true):null,'cookies'=>$entries];
    }
    if(preg_match_all('~\bslug\s*:~',$array)!==count($out))throw new RuntimeException('Nepodporovaný tvar kategorie deklarace.');
    if(!$out)throw new RuntimeException('Kategorie CookieYes se nepodařilo rozpoznat.');
    return $out;
}
function gca_cookieyes_resource($url,$request){
    if(!preg_match('~^https://cdn-cookieyes\.com/client_data/[a-f0-9]{24,64}/(?:(?:script|banner)\.js|audit-table/[A-Za-z0-9_-]{4,80}\.json)$~D',$url))throw new RuntimeException('Nepovolený zdroj veřejné deklarace.');
    $r=$request($url);if(($r['code']??0)!==200)throw new RuntimeException('Veřejná data CookieYes nevrátila HTTP 200.');
    if(strlen($r['body']??'')>1048576)throw new RuntimeException('Veřejná deklarace překračuje limit 1 MB.');
    if(!preg_match('~javascript|json|text/plain~i',$r['headers']['content-type'][0]??''))throw new RuntimeException('Zdroj CookieYes nemá očekávaný datový formát.');
    return $r['body'];
}
function gca_cookieyes_load($discovery,$pageUrl,$request=null){
    $client=$discovery['client']??'';if(!preg_match('~^[a-f0-9]{24,64}$~D',$client))throw new RuntimeException('Neplatný identifikátor veřejné deklarace.');
    $request=$request??fn($url)=>gca_request($url,5);$base='https://cdn-cookieyes.com/client_data/'.$client.'/';
    $categories=gca_cookieyes_categories(gca_cookieyes_resource($base.'script.js',$request));
    $banner=gca_cookieyes_resource($base.'banner.js',$request);
    if(!preg_match('~_supportedMap\s*:\s*\{([^{}]{1,3000})\}~',$banner,$map))throw new RuntimeException('Jazyky veřejné deklarace nebyly rozpoznány.');
    preg_match_all('~["\x27]?([a-z]{2}(?:-[a-z]{2})?)["\x27]?\s*:\s*["\x27]([A-Za-z0-9_-]{4,80})["\x27]~i',$map[1],$languages,PREG_SET_ORDER);$supported=[];
    foreach($languages as $language)$supported[strtolower($language[1])]=$language[2];
    preg_match('~_default\s*:\s*"([a-z]{2}(?:-[a-z]{2})?)"~i',$banner,$default);
    $lang=strtolower($discovery['language']??'en');if(!isset($supported[$lang]))$lang=strtolower($default[1]??'en');
    if(!isset($supported[$lang]))throw new RuntimeException('Jazyk deklarace není dostupný.');
    $source=$base.'audit-table/'.$supported[$lang].'.json';$data=json_decode(gca_cookieyes_resource($source,$request),true);
    if(!is_array($data)||!isset($data['cookies'])||!is_array($data['cookies']))throw new RuntimeException('Tabulka cookies nemá podporovanou strukturu.');
    $records=[];
    foreach($categories as $category)foreach($category['cookies'] as $cookie){
        $row=$data['cookies'][$cookie['name']]??[];
        $records[]=['name'=>$cookie['name'],'domain'=>$cookie['domain'],'category'=>$category['slug'],'category_label'=>gca_text((string)($data['cky_preference_'.$category['slug'].'_title']??$category['slug'])),'lifetime'=>gca_text((string)($row['duration']??'')),'description'=>gca_text((string)($row['description']??'')),'necessary'=>$category['necessary'],'default_enabled'=>$category['default_enabled'],'basis'=>'declaration','provider'=>'CookieYes','client'=>$client,'language'=>$lang,'source_url'=>$source,'source_page_url'=>$pageUrl];
        if(count($records)>=250)throw new RuntimeException('Deklarace překračuje limit 250 položek.');
    }
    return ['client'=>$client,'language'=>$lang,'records'=>$records,'source_url'=>$source,'categories'=>array_column($categories,'slug')];
}
function gca_declaration_document($records){
    $text="Veřejná tabulka cookies vložená službou CookieYes.\nCookie | Doba trvání | Popis\n";
    foreach($records as $record)$text.=$record['name'].' | '.$record['lifetime'].' | '.$record['description']."\n";
    return $text;
}
