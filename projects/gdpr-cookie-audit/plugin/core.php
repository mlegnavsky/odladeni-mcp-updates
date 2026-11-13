<?php
/** Pure audit functions. No WordPress state and no retained cookie values. */
function gca_normalize_url($input) {
    if (!is_string($input) || strlen($input)>2048 || preg_match('/[\x00-\x20\\\\]/', trim($input))) return null;
    $input=trim($input);
    if (!preg_match('~^[a-z][a-z0-9+.-]*:~i',$input)) $input='https://'.$input;
    $p=parse_url($input);
    if (!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true) || isset($p['user']) || isset($p['pass']) || isset($p['query'])) return null;
    $host=strtolower(rtrim($p['host']??'','.'));
    if (!$host || strlen($host)>253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',$host)) return null;
    if (preg_match('/\.(local|localhost|internal|invalid|test|onion)$/',$host)) return null;
    if (isset($p['port']) && $p['port'] !== ($p['scheme']==='https'?443:80)) return null;
    return strtolower($p['scheme']).'://'.$host.($p['path']??'/');
}
function gca_public_ip($ip) {
    if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
    // Explicit special-purpose ranges also cover mapped IPv4 and documentation networks.
    $packed=inet_pton($ip);
    if (strlen($packed)===16) return substr($packed,0,1)==="\x20" && substr($packed,0,4)!=="\x20\x01\x0d\xb8";
    $n=unpack('N',$packed)[1];
    foreach ([[0xC0000000,24],[0xC0000200,24],[0xC6336400,24],[0xCB007100,24],[0x64400000,10],[0xC6120000,15],[0xE0000000,4]] as [$net,$bits]) {
        $mask=(0xFFFFFFFF << (32-$bits)) & 0xFFFFFFFF;
        if (($n & $mask)===($net & $mask)) return false;
    }
    return true;
}
function gca_resolve_url($base,$link) {
    $link=html_entity_decode(trim($link),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if ($link==='' || $link[0]==='#') return null;
    if (strpos($link,'//')===0) $link=parse_url($base,PHP_URL_SCHEME).':'.$link;
    elseif (!preg_match('~^[a-z][a-z0-9+.-]*:~i',$link)) {
        $origin=parse_url($base,PHP_URL_SCHEME).'://'.parse_url($base,PHP_URL_HOST);
        $path=$link[0]==='/'?$link:dirname(parse_url($base,PHP_URL_PATH)?:'/').'/'.$link;
        $parts=[];
        foreach (explode('/',$path) as $part) { if ($part==='..') array_pop($parts); elseif ($part!=='' && $part!=='.') $parts[]=$part; }
        $link=$origin.'/'.implode('/',$parts).($parts && substr($path,-1)==='/'?'/':'');
    }
    return gca_normalize_url($link);
}
function gca_text($html) {
    $html=preg_replace('~<(script|style|svg|noscript)\b[^>]*>.*?</\1>~is',' ',$html);
    return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8')));
}
function gca_provider_catalog() {
    return [
      'google-tags'=>['Google Analytics / Google Tag Manager','~googletagmanager\.com|google-analytics\.com~i','analytika / správa značek','~Google|Analytics|Tag Manager~iu'],
      'google-ads'=>['Google Ads / DoubleClick','~doubleclick\.net|googlesyndication\.com|googleadservices\.com~i','reklama','~Google|DoubleClick~iu'],
      'meta'=>['Meta / Facebook Pixel','~connect\.facebook\.net|facebook\.com/tr[/?]~i','reklama','~Meta|Facebook~iu'],
      'clarity'=>['Microsoft Clarity','~clarity\.ms~i','analytika','~Clarity|Microsoft~iu'],
      'hotjar'=>['Hotjar','~hotjar\.(com|io)~i','analytika','~Hotjar~iu'],
      'youtube'=>['YouTube','~youtube(?:-nocookie)?\.com/embed~i','vložený obsah','~YouTube|Google~iu'],
      'maps'=>['Google Maps','~google\.[a-z.]+/maps|maps\.google~i','vložený obsah','~Google|Maps~iu'],
      'tiktok'=>['TikTok Pixel','~analytics\.tiktok\.com~i','reklama','~TikTok~iu'],
      'seznam'=>['Seznam / Sklik','~c\.seznam\.cz|sklik\.cz~i','reklama','~Seznam|Sklik~iu'],
    ];
}
function gca_analyze_page($url,$html,$setCookies=[],$status=200) {
    preg_match('~<title\b[^>]*>(.*?)</title>~is',$html,$m);
    $result=['url'=>$url,'status'=>$status,'title'=>gca_text($m[1]??''),'bytes'=>strlen($html),'services'=>[],'cookies'=>[],'policies'=>[],'links'=>[],'dynamic_verified'=>false];
    preg_match_all('~<(?:script|iframe|img)\b[^>]*\bsrc\s*=\s*["\x27]([^"\x27]+)["\x27]~is',$html,$sources);
    // Inline code is source evidence only. No assertion that it executed.
    preg_match_all('~<script\b[^>]*>(.*?)</script>~is',$html,$scripts);
    $evidence=implode("\n",$sources[1])."\n".implode("\n",$scripts[1]);
    foreach (gca_provider_catalog() as $id=>$p) if (preg_match($p[1],$evidence,$match)) $result['services'][]=['id'=>$id,'name'=>$p[0],'category'=>$p[2],'evidence'=>$match[0],'url'=>$url,'basis'=>'source'];
    foreach ((array)$setCookies as $raw) {
        $header=is_array($raw)?$raw['header']:$raw; $cookieUrl=is_array($raw)?$raw['url']:$url;
        $parts=explode(';',$header); $pair=explode('=',array_shift($parts),2); $name=trim($pair[0]);
        if (!$name || strlen($name)>128) continue;
        $c=['name'=>$name,'domain'=>parse_url($cookieUrl,PHP_URL_HOST),'path'=>'/','secure'=>false,'httpOnly'=>false,'sameSite'=>'neuvedeno','lifetime'=>'relace / neuvedeno','url'=>$cookieUrl,'basis'=>'http-header'];
        foreach ($parts as $part) { $a=explode('=',trim($part),2);$k=strtolower($a[0]);$v=$a[1]??'';
            if ($k==='secure') $c['secure']=true; elseif ($k==='httponly') $c['httpOnly']=true;
            elseif ($k==='samesite') $c['sameSite']=substr($v,0,12); elseif ($k==='domain') $c['domain']=substr($v,0,253);
            elseif ($k==='path') $c['path']=substr($v,0,200); elseif ($k==='max-age') $c['lifetime']=substr($v,0,16).' sekund';
            elseif ($k==='expires' && $c['lifetime']==='relace / neuvedeno') $c['lifetime']=substr($v,0,60);
        }
        $result['cookies'][]=$c;
    }
    preg_match_all('~<a\b[^>]*href\s*=\s*["\x27]([^"\x27]*)["\x27][^>]*>(.*?)</a>~is',$html,$links,PREG_SET_ORDER);
    foreach ($links as $a) {
        $target=gca_resolve_url($url,$a[1]); if (!$target) continue; $label=gca_text($a[2]);
        if (preg_match('~cookie|ochran[ay]\s+osobn|privacy|zásad|zasad|soubor[ůu]\s+cookie~iu',$label.' '.parse_url($target,PHP_URL_PATH))) $result['policies'][$target]=['url'=>$target,'label'=>$label];
        if (parse_url($target,PHP_URL_HOST)===parse_url($url,PHP_URL_HOST) && !preg_match('~/(wp-admin|wp-json|wp-login|logout|cart|checkout|kosik|odhlas)|\.(jpg|png|svg|pdf|zip|xml|js|css)(/|$)~i',$target)) $result['links'][$target]=$target;
    }
    $result['links']=array_values($result['links']);$result['policies']=array_values($result['policies']);
    $result['banner_hint']=(bool)preg_match('~cookieyes|cky-consent|cookiebot|cmplz-|onetrust|cc-window|cookie-consent|cookie-law-info~i',$html);
    $result['consent_hint']=(bool)preg_match('~(?:gtag\s*\(\s*["\x27]consent|ad_storage|analytics_storage|ad_user_data|ad_personalization)~i',$html);
    return $result;
}
function gca_analyze_policy($url,$html,$serviceIds=[],$cookies=[]) {
    $text=gca_text($html);
    $patterns=[
      'contact'=>['Provozovatel a kontakt','~.{0,65}(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|IČO\s*:?\s*\d{6,8}|správc[ei]|controller).{0,90}~iu'],
      'purposes'=>['Účely používání','~.{0,65}(?:analytick|marketing|nezbytn|účel|purposes?|necessary|advertising).{0,90}~iu'],
      'retention'=>['Doby uchování','~.{0,65}(?:\d+\s*(?:dní|dnů|dny|měsíc|rok|let|days?|months?|years?)|po dobu relace|session|doba (?:uchování|platnosti)|dobu (?:uchování|platnosti)).{0,90}~iu'],
      'withdrawal'=>['Změna a odvolání souhlasu','~.{0,65}(?:odvola[tln]|odvolání|změn\w* souhlas|withdraw|revoke).{0,90}~iu'],
      'choice'=>['Odmítnutí volitelných cookies','~.{0,65}(?:odmítn|nesouhlas|reject|decline).{0,90}~iu'],
    ];
    $out=['url'=>$url,'checks'=>[],'services'=>[],'cookies'=>[],'text_length'=>strlen($text)];
    foreach ($patterns as $id=>[$label,$pattern]) { $found=preg_match($pattern,$text,$m);$out['checks'][$id]=['label'=>$label,'status'=>$found?'pass':'warning','evidence'=>$found?$m[0]:'V načteném textu nebyla nalezena odpovídající informace. Vyžaduje ruční ověření.']; }
    $catalog=gca_provider_catalog();
    foreach (array_unique($serviceIds) as $id) { if (!isset($catalog[$id])) continue;$p=$catalog[$id];$found=preg_match($p[3],$text,$m);$out['services'][]=['id'=>$id,'name'=>$p[0],'status'=>$found?'pass':'warning','evidence'=>$found?'V dokumentu nalezena zmínka: '.$m[0]:'Služba nalezena ve zdroji webu, v tomto dokumentu nenalezena. Ověřte skutečné použití a dokumentaci.']; }
    foreach ($cookies as $c) $out['cookies'][]=['name'=>$c['name'],'status'=>stripos($text,$c['name'])!==false?'pass':'warning'];
    return $out;
}
function gca_request($url) {
    if (!function_exists('curl_init')) throw new RuntimeException('Hosting nepodporuje bezpečné načítání webu.');
        $host=parse_url($url,PHP_URL_HOST); $records=dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];
        foreach ((array)$records as $record) { $ip=$record['ip']??$record['ipv6']??null;if (!$ip) continue;if (!gca_public_ip($ip)) throw new RuntimeException('Adresa směřuje do neveřejné sítě.');$ips[]=$ip; }
        if (!$ips) throw new RuntimeException('Doménu se nepodařilo přeložit.');
        $ip=$ips[0];$port=parse_url($url,PHP_URL_SCHEME)==='https'?443:80;
        $body='';$headers=[];$overflow=false;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.(strpos($ip,':')!==false?'['.$ip.']':$ip)],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'GDPR-Cookies.cz Audit/1.0 (+https://gdpr-cookies.cz/kontrola-cookies/)',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],CURLOPT_ENCODING=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
          CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$body,&$overflow) { if (strlen($body)+strlen($chunk)>2097152) { $overflow=true;return 0; }$body.=$chunk;return strlen($chunk); },
          CURLOPT_HEADERFUNCTION=>function($ch,$line) use (&$headers) { $p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))][]=trim($p[1]);return strlen($line); },
        ]);
        $ok=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
        if ($overflow) throw new RuntimeException('Stránka překračuje limit 2 MB; měření nebylo dokončeno.');
        if ($ok===false) throw new RuntimeException('Web se nepodařilo načíst v časovém limitu nebo selhalo zabezpečené spojení.');
        return ['code'=>$code,'headers'=>$headers,'body'=>$body];
}
function gca_fetch($url,$request=null) {
    $request=$request??'gca_request';$allCookies=[];
    for ($hop=0;$hop<4;$hop++) {
        $url=gca_normalize_url($url); if (!$url) throw new RuntimeException('Adresa není veřejná HTTP/HTTPS stránka bez přihlašovacích údajů a parametrů.');
        $response=$request($url);$code=$response['code'];$headers=$response['headers'];$body=$response['body'];
        foreach($headers['set-cookie']??[] as $cookie) $allCookies[]=['header'=>$cookie,'url'=>$url];
        if ($code>=300 && $code<400 && !empty($headers['location'][0])) { $url=gca_resolve_url($url,$headers['location'][0]);continue; }
        if ($code<200 || $code>=300) throw new RuntimeException('Web vrátil HTTP '.$code.'.');
        if (!preg_match('~text/html|application/xhtml\+xml~i',$headers['content-type'][0]??'')) throw new RuntimeException('Adresa nevrací HTML stránku.');
        return ['url'=>$url,'html'=>$body,'cookies'=>$allCookies,'status'=>$code];
    }
    throw new RuntimeException('Příliš mnoho přesměrování.');
}
