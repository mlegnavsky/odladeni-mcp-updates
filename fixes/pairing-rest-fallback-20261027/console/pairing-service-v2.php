<?php
require_once dirname(__DIR__).'/assets/bridge-json.php';
/** Use the same pinned HTTPS origin when pretty REST URLs return a page or redirect. */
function odlPairNeedsPlainRoute(int $http,?array $data,bool $plain): bool {
 return !$plain&&(in_array($http,[301,302,303,307,308,404],true)||($http===200&&$data===null));
}
/** Pairing transport, signed receipt and read-only WordPress connection verification. */
function odlPairHttp(string $site,string $path,?array $payload=null,array $headers=[],bool $plain=false): array {
 $validated=pairingSiteUrl($site);if($validated!==$site)throw new RuntimeException('Adresa webu není platná.');
 $host=(string)parse_url($site,PHP_URL_HOST);$records=dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];
 foreach($records?:[] as $row){$ip=$row['ip']??$row['ipv6']??null;if(!$ip||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Adresa webu není veřejná.');$ips[]=$ip;}
 if(!$ips)throw new RuntimeException('Web se nepodařilo vyhledat.');
 if(!in_array($path,['/wp-json/odladeni-bridge/v1/pair','/wp-json/odladeni-bridge/v1/ping'],true))throw new RuntimeException('Neplatná cesta propojení.');
 $wire=$plain?'/index.php?rest_route='.rawurlencode(substr($path,8)):$path;
 $pin=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];$ch=curl_init($site.$wire);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$pin],CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','Content-Type: application/json'],$headers)]);
 if($payload!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));}
 $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$data=is_string($body)?odlBridgeJsonDecode($body):null;
 if(odlPairNeedsPlainRoute($http,$data,$plain))return odlPairHttp($site,$path,$payload,$headers,true);
 if($http===404)throw new RuntimeException('WordPress nemá dostupné párovací rozhraní. Aktualizujte a aktivujte Hlavní instalaci, potom klikněte ve WordPressu znovu na Propojit.');
 if($http===200&&!is_array($data))throw new RuntimeException('Web nevrátil platné potvrzení propojení. Vraťte se do WordPressu a spusťte Propojit znovu.');
 if($http!==200||!is_array($data)||($data['ok']??false)!==true)throw new RuntimeException($http===403?'Potvrzení z WordPressu vypršelo. Vraťte se do WordPressu a klikněte znovu na Propojit.':'WordPress nepotvrdil propojení (HTTP '.$http.').');
 return $data;
}
function odlPairVerify(string $site,string $secret): void {
 $path='/wp-json/odladeni-bridge/v1/ping';$ts=(string)time();$nonce=bin2hex(random_bytes(12));
 $sig=hash_hmac('sha256',"GET\n".$path."\n".$ts."\n".$nonce,$secret);
 $data=odlPairHttp($site,$path,null,['X-Odladeni-Time: '.$ts,'X-Odladeni-Nonce: '.$nonce,'X-Odladeni-Signature: '.$sig]);
 if(($data['connected']??false)!==true||rtrim((string)($data['site']??''),'/')!==$site)throw new RuntimeException('Ověření spojení s WordPressem se nezdařilo.');
}
function odlPairReceipt(string $site,string $email,string $token,string $secret,int $stamp): string {
 return hash_hmac('sha256',"paired\n".$site."\n".$email."\n".$stamp."\n".$token,$secret);
}
function odlPairStatus(PDO $db): never {
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
 $fail=static function(){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Spojení nebylo ověřeno.']);exit;};
 if($_SERVER['REQUEST_METHOD']!=='POST')$fail();
 $body=(string)file_get_contents('php://input');if(strlen($body)>4096)$fail();$args=json_decode($body,true);
 $site=is_array($args)?($args['site']??''):'';$nonce=is_array($args)?($args['nonce']??''):'';
 $ts=(string)($_SERVER['HTTP_X_ODLADENI_TIME']??'');$sig=(string)($_SERVER['HTTP_X_ODLADENI_SIGNATURE']??'');
 if(!is_string($site)||!is_string($nonce)||!preg_match('/^[a-f0-9]{24}$/D',$nonce)||!ctype_digit($ts)||abs(time()-(int)$ts)>120||!preg_match('/^[a-f0-9]{64}$/D',$sig))$fail();
 $q=$db->prepare('SELECT shared_key,approved_by FROM connected_sites WHERE site_url=?');$q->execute([$site]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if(!$row||!hash_equals(hash_hmac('sha256',"POST\n/console/?action=pair_status\n".$ts."\n".$nonce."\n".hash('sha256',$body),(string)$row['shared_key']),$sig))$fail();
 $db->exec('CREATE TABLE IF NOT EXISTS pair_status_nonces (nonce TEXT PRIMARY KEY, expires INTEGER NOT NULL)');
 $q=$db->prepare('DELETE FROM pair_status_nonces WHERE expires<?');$q->execute([time()]);
 $q=$db->prepare('INSERT OR IGNORE INTO pair_status_nonces(nonce,expires) VALUES(?,?)');$q->execute([hash('sha256',$site.$nonce),time()+180]);if($q->rowCount()!==1)$fail();
 $data=['ok'=>true,'connected'=>true,'site'=>$site,'account'=>(string)$row['approved_by'],'checked_at'=>time(),'nonce'=>$nonce];
 $canonical=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $data['signature']=hash_hmac('sha256',"pair-status\n".hash('sha256',$canonical),(string)$row['shared_key']);
 echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
