<?php
// Fleet phase 1: read-only, OAuth-gated by the existing MCP dispatcher.
function fmRequest(string $site,string $path,array $payload=[],bool $legacy=false): array {
    $registered=fmSites();
    if (!in_array($site,array_column($registered['sites'],'url'),true)) {
        throw new InvalidArgumentException('Target is not in the paired-site registry');
    }
    $u=parse_url($site);
    if (!is_array($u)||($u['scheme']??'')!=='https'||empty($u['host'])
        ||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])
        ||(isset($u['port'])&&$u['port']!==443)) throw new RuntimeException('Unsupported paired origin');
    $host=strtolower($u['host']);
    if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D',$host)||filter_var($host,FILTER_VALIDATE_IP)) {
        throw new RuntimeException('Invalid paired hostname');
    }
    $records=dns_get_record($host,DNS_A|DNS_AAAA);
    $ips=[];
    foreach(is_array($records)?$records:[] as $record) {
        $ip=$record['ip']??$record['ipv6']??null;
        if ($ip===null) continue;
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Non-public paired destination rejected');
        }
        $ips[]=$ip;
    }
    if (!$ips) throw new RuntimeException('Public destination DNS unavailable');
    $config=require dirname(__DIR__).'/console/config.php';
    $db=new PDO('sqlite:'.$config['database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA query_only = ON');
    $query=$db->prepare('SELECT shared_key FROM connected_sites WHERE site_url=?');
    $query->execute([$site]);$key=$query->fetchColumn();
    if (!is_string($key)||$key==='') throw new RuntimeException('Paired authentication unavailable');
    if(!in_array($path,['/wp-json/odladeni-bridge/v2/plugin-manage','/wp-json/odladeni-bridge/v1/update-plugin'],true))throw new RuntimeException('Unsupported management route');
    $bodyJson=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $time=(string)time();$nonce=bin2hex(random_bytes(12));
    $signature=hash_hmac('sha256',"POST\n".$path."\n".$time."\n".$nonce.($legacy?'':"\n".hash('sha256',$bodyJson)),$key);
    unset($key,$config,$query,$db);
    $body='';$overflow=false;
    $ch=curl_init(rtrim($site,'/').$path);
    if ($ch===false) throw new RuntimeException('HTTPS transport unavailable');
    $pin=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];
    curl_setopt_array($ch,[CURLOPT_TIMEOUT=>150,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$bodyJson,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_RESOLVE=>[$host.':443:'.$pin],
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-Odladeni-Time: '.$time,
            'X-Odladeni-Nonce: '.$nonce,'X-Odladeni-Signature: '.$signature],
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$body,&$overflow):int {
            if(strlen($body)+strlen($chunk)>2097152){$overflow=true;return 0;}
            $body.=$chunk;return strlen($chunk);
        }]);
    $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($overflow) throw new RuntimeException('Diagnostic response exceeds limit');
    if ($ok===false) throw new RuntimeException('Management outcome unknown; inspect diagnostics before retrying with the same request id');
    $data=json_decode($body,true,64);
    if ($http!==200||!is_array($data)||($data['ok']??false)!==true) {
        $message=is_array($data)&&is_string($data['message']??null)?substr($data['message'],0,500):'Remote management rejected';
        throw new RuntimeException($message.' (HTTP '.$http.')');
    }
    return $data;
}

function fmSites(): array {
    $configFile=dirname(__DIR__).'/console/config.php';
    if (!is_file($configFile) || !is_readable($configFile)) {
        throw new RuntimeException('Pairing registry configuration unavailable');
    }
    $config=require $configFile;
    $dbPath=is_array($config)?($config['database_path']??null):null;
    if (!is_string($dbPath) || !is_file($dbPath) || !is_readable($dbPath)) {
        throw new RuntimeException('Pairing registry unavailable');
    }
    $db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA query_only = ON');
    $rows=$db->query('SELECT site_name,site_url,connected_at,last_seen FROM connected_sites ORDER BY site_name,site_url')->fetchAll(PDO::FETCH_ASSOC);
    return ['count'=>count($rows),'sites'=>array_map(static function(array $row):array {
        return ['name'=>$row['site_name'],'url'=>$row['site_url'],
            'connected_at'=>$row['connected_at'],'last_seen'=>$row['last_seen'],
            'status'=>$row['last_seen']===null?'not_yet_seen':'registered'];
    },$rows),'status_note'=>'registered means paired, not a live connectivity check'];
}


function fmManage(array $args): array {
    $site=(string)($args['site_url']??'');
    $action=$args['action']??'';
    $payload=$args;unset($payload['site_url']);
    if(!in_array($action,['activate','deactivate','auto_update','install','update','delete'],true))throw new RuntimeException('Invalid plugin action');
    if(!is_string($payload['plugin']??null)||!preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9._/-]*\\.php$~D',$payload['plugin'])||str_contains($payload['plugin'],'..'))throw new RuntimeException('Invalid plugin basename');
    if(!is_string($payload['request_id']??null)||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$payload['request_id']))throw new RuntimeException('Stable request_id required');
    if($action==='delete'&&($payload['confirm_delete']??false)!==true)throw new RuntimeException('confirm_delete required for an explicitly selected plugin');
    $result=fmRequest($site,'/wp-json/odladeni-bridge/v2/plugin-manage',$payload);
    return ['site'=>$site,'result'=>$result];
}
function fmBootstrap(array $args): array {
    $site=(string)($args['site_url']??'');
    if(($args['confirm']??false)!==true)throw new RuntimeException('Explicit bridge update confirmation required');
    return ['site'=>$site,'result'=>fmRequest($site,'/wp-json/odladeni-bridge/v1/update-plugin',['plugin'=>'prvotni-instalace/prvotni-instalace.php'],true)];
}
