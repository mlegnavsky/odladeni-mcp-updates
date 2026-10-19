<?php
declare(strict_types=1);
// Single-user MCP Streamable HTTP + OAuth 2.1 PKCE.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$secret = require dirname(__DIR__) . '/api/config.php';
if (!is_string($secret) || strlen($secret) < 40 || str_contains($secret, 'CHANGE_ME')) {
    http_response_code(503); exit('Configure api/config.php first');
}
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403); exit('HTTPS required');
}
$origin = 'https://plaginy.odladeni.cz';
$dir = dirname(__DIR__, 2) . '/private';
if (!is_dir($dir)) { http_response_code(503); exit('Private directory missing'); }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/mcp/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function j($value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function err(string $message, int $status = 400): never { j(['error' => $message], $status); }
function fileget(string $path): array {
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function fileset(string $path, array $data): void {
    $tmp = $path . '.' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, json_encode($data), LOCK_EX) === false) throw new RuntimeException('Cannot write state');
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) throw new RuntimeException('Cannot save state');
}
function token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function releaseList(?string $plugin = null): array {
    global $origin;
    $root = dirname(__DIR__) . '/releases';
    $paths = glob($root . '/*.zip') ?: [];
    usort($paths, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $out = [];
    foreach ($paths as $path) {
        $name = basename($path);
        if (!preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)-v([0-9]+(?:\.[0-9]+)*(?:[-.][a-zA-Z0-9]+)*)-([a-f0-9]{12})\.zip$/', $name, $m)) continue;
        if ($plugin !== null && $m[1] !== $plugin) continue;
        $out[] = [
            'plugin' => $m[1], 'version' => $m[2], 'filename' => $name,
            'download_url' => $origin . '/releases/' . rawurlencode($name),
            'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'modified_utc' => gmdate('c', filemtime($path))
        ];
        if (count($out) >= 200) break;
    }
    return $out;
}
function releasePlugins(): array {
    $groups = [];
    foreach (releaseList() as $r) {
        $slug = $r['plugin'];
        if (!isset($groups[$slug])) $groups[$slug] = ['plugin' => $slug, 'count' => 0, 'latest' => $r];
        $groups[$slug]['count']++;
    }
    return array_values($groups);
}
function mcpFailure(array $response, string $message): never {
    $response['result'] = ['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]];
    j($response);
}

function bridgeLog(string $name, array $info=[]): string {
 global $dir;
 $id=bin2hex(random_bytes(8));
 $line=json_encode(['id'=>$id,'time'=>gmdate('c'),'operation'=>$name,'info'=>$info],JSON_UNESCAPED_SLASHES)."\n";
 $file=$dir.'/mcp-bridge-diagnostics.jsonl';
 if(is_dir($dir)&&!is_link($file)){@file_put_contents($file,$line,FILE_APPEND|LOCK_EX);@chmod($file,0600);}
 return $id;
}
function transferLog(string $operationId, string $direction, string $event, array $details=[]): string {
    global $dir;
    $trace=bin2hex(random_bytes(8));
    $path=$dir.'/mcp-transfer-diagnostics.jsonl';
    $record=['trace_id'=>$trace,'operation_id'=>$operationId,'utc'=>gmdate('c'),
        'direction'=>$direction,'event'=>$event,'details'=>$details];
    if (!is_link($path)) {
        @file_put_contents($path,json_encode($record,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n",FILE_APPEND|LOCK_EX);
        @chmod($path,0600);
    }
    return $trace;
}
function transferHistory(array $args): array {
    global $dir;
    $id=$args['operation_id']??null;
    if ($id!==null && (!is_string($id)||!preg_match('/^[a-zA-Z0-9_.-]{1,160}$/D',$id)))
        throw new RuntimeException('Invalid operation_id');
    $limit=$args['limit']??50;
    if (!is_int($limit)||$limit<1||$limit>200) throw new RuntimeException('Invalid limit');
    $path=$dir.'/mcp-transfer-diagnostics.jsonl';
    if (!is_file($path)) return ['events'=>[],'count'=>0,'operation_id'=>$id];
    $f=new SplFileObject($path,'r');$events=[];
    while (!$f->eof()) {
        $line=$f->fgets();$entry=json_decode($line,true);
        if (!is_array($entry)||($id!==null&&($entry['operation_id']??null)!==$id)) continue;
        $events[]=$entry;
        if(count($events)>$limit) array_shift($events);
    }
    $summary=['download_chunks_sent'=>0,'upload_chunks_received'=>0,'bytes_sent'=>0,'bytes_received'=>0,'last_event'=>null];
    foreach($events as $entry) {
        $event=$entry['event']??'';
        $details=$entry['details']??[];
        if($event==='chunk_sent') {$summary['download_chunks_sent']++;$summary['bytes_sent']+=(int)($details['chunk_bytes']??0);}
        if($event==='chunk_received') {$summary['upload_chunks_received']++;$summary['bytes_received']+=(int)($details['chunk_bytes']??0);}
        $summary['last_event']=$event;
    }
    return ['summary'=>$summary,'events'=>$events,'count'=>count($events),'operation_id'=>$id,
        'note'=>'Server-side events only; last chunk does not prove client assembled or verified ZIP' ];
}
function deployPhpDiagnostics(): array {
    $available=defined('TOKEN_PARSE') && function_exists('token_get_all');
    return ['lint_available'=>$available,'lint_method'=>'token_get_all_TOKEN_PARSE',
        'php_cli_path'=>null,'php_cli_candidates'=>[],
        'proc_open_available'=>false,'lint_reason'=>$available?'ready':'tokenizer_unavailable',
        'php_web_version'=>PHP_VERSION,
        'backup_directory_writable'=>is_writable(dirname(__DIR__,2).'/private')];
}
function deployState(): array {
    global $dir;
    $root=__DIR__;$main=$root.'/index.php';
    $backup=$dir.'/mcp-index-backup.php';$stage=$dir.'/mcp-index-staged.php';
    $lint=deployPhpDiagnostics();
    return array_merge(['supported'=>is_readable($main) && is_writable($root) && is_writable($dir),
      'current_sha256'=>is_file($main)?hash_file('sha256',$main):null,
      'backup_available'=>is_file($backup),'backup_sha256'=>is_file($backup)?hash_file('sha256',$backup):null,
      'staged'=>is_file($stage),'staged_sha256'=>is_file($stage)?hash_file('sha256',$stage):null,
      'recovery_endpoint'=>is_file($root.'/recovery.php')],$lint);
}
function deployLint(string $file): array {
    $info=deployPhpDiagnostics();
    if(!$info['lint_available'])return ['ok'=>false,'message'=>'PHP tokenizer parser unavailable'];
    if(!is_file($file)||is_link($file)||!is_readable($file))return ['ok'=>false,'message'=>'File unavailable'];
    $size=filesize($file);
    if($size===false||$size<5||$size>2097152)return ['ok'=>false,'message'=>'Invalid file size'];
    $code=file_get_contents($file);
    if($code===false||!str_starts_with($code,'<?php'))return ['ok'=>false,'message'=>'Invalid PHP header'];
    try {
        // TOKEN_PARSE invokes PHP's parser, without executing the candidate file.
        token_get_all($code, TOKEN_PARSE);
        return ['ok'=>true,'message'=>'PHP syntax parsed successfully (not executed)',
            'lint_method'=>'token_get_all_TOKEN_PARSE','php_web_version'=>PHP_VERSION];
    } catch (ParseError $e) {
        return ['ok'=>false,'message'=>substr($e->getMessage(),0,500),
            'lint_method'=>'token_get_all_TOKEN_PARSE','line'=>$e->getLine()];
    } catch (Throwable $e) {
        return ['ok'=>false,'message'=>'Parser failed: '.substr($e->getMessage(),0,300)];
    }
}
function deployAction(string $action, array $args): array {
    global $dir;
    $main=__DIR__.'/index.php';$stage=$dir.'/mcp-index-staged.php';$backup=$dir.'/mcp-index-backup.php';
    $lock=fopen($dir.'/mcp-deploy.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Deployment locked');
    try {
      if ($action==='deploy_stage_patch') {
        $expectedCurrent=$args['expected_current_sha256']??null;
        $expectedNew=$args['sha256']??null;
        $edits=$args['replacements']??null;
        if(!is_string($expectedCurrent)||!preg_match('/^[a-f0-9]{64}$/D',$expectedCurrent)
          ||!is_string($expectedNew)||!preg_match('/^[a-f0-9]{64}$/D',$expectedNew)
          ||!is_array($edits)||count($edits)<1||count($edits)>20)
          throw new RuntimeException('Invalid patch request');
        if(!hash_equals($expectedCurrent,hash_file('sha256',$main)))throw new RuntimeException('Current MCP SHA256 changed');
        $code=file_get_contents($main);
        if(!is_string($code))throw new RuntimeException('Cannot read current MCP');
        foreach($edits as $edit){
          $find=$edit['find']??null;$replace=$edit['replace']??null;
          if(!is_string($find)||$find===''||strlen($find)>12000||!is_string($replace)||strlen($replace)>12000)
            throw new RuntimeException('Invalid replacement');
          if(substr_count($code,$find)!==1)throw new RuntimeException('Replacement text not unique');
          $code=str_replace($find,$replace,$code);
        }
        if(strlen($code)<1000||strlen($code)>250000||!hash_equals($expectedNew,hash('sha256',$code))
          ||!str_starts_with($code,'<?php')||!str_contains($code,'function deployAction(')
          ||!str_contains($code,'function auth(): bool'))throw new RuntimeException('Patched MCP integrity failed');
        $temp=$dir.'/mcp-index-staging-'.bin2hex(random_bytes(6)).'.php';
        if(file_put_contents($temp,$code,LOCK_EX)!==strlen($code))throw new RuntimeException('Patch staging write failed');
        chmod($temp,0600);$lint=deployLint($temp);
        if(!$lint['ok']){@unlink($temp);throw new RuntimeException('Lint failed: '.$lint['message']);}
        if(!rename($temp,$stage))throw new RuntimeException('Patch staging rename failed');
        return ['status'=>'staged','sha256'=>$expectedNew,'bytes'=>strlen($code),'lint'=>$lint,
          'current_sha256'=>$expectedCurrent,'source'=>'verified_exact_patch'];
      }
      if ($action==='deploy_stage_url') {
        $url=$args['url']??null;$expected=$args['sha256']??null;
        if(!is_string($url)||strlen($url)>2048||!is_string($expected)
          ||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Invalid URL or SHA256');
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'
          ||($parts['host']??null)!=='raw.githubusercontent.com'
          ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])
          ||isset($parts['fragment'])||isset($parts['query'])
          ||!preg_match('~^/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_./-]+\.txt$~D',$parts['path']??'')
          ||str_contains($parts['path'],'..'))throw new RuntimeException('Only raw.githubusercontent.com HTTPS .txt files allowed');
        if(!function_exists('curl_init'))throw new RuntimeException('HTTPS download requires cURL');
        $code='';$overflow=false;
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,
          CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
          CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Odladeni-MCP-Deploy/1.0',
          CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$code,&$overflow){
            if(strlen($code)+strlen($chunk)>250000){$overflow=true;return 0;}
            $code.=$chunk;return strlen($chunk);
          }]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($ok!==true||$overflow||$status!==200||strlen($code)<1000
          ||!hash_equals($expected,hash('sha256',$code))||!str_starts_with($code,'<?php')
          ||!str_contains($code,'function deployAction(')||!str_contains($code,'function auth(): bool'))
          throw new RuntimeException('HTTPS source or MCP integrity verification failed');
        $temp=$dir.'/mcp-index-staging-'.bin2hex(random_bytes(6)).'.php';
        if(file_put_contents($temp,$code,LOCK_EX)!==strlen($code))throw new RuntimeException('URL staging write failed');
        chmod($temp,0600);$lint=deployLint($temp);
        if(!$lint['ok']){@unlink($temp);throw new RuntimeException('Lint failed: '.$lint['message']);}
        if(!rename($temp,$stage))throw new RuntimeException('URL staging rename failed');
        return ['status'=>'staged','sha256'=>$expected,'bytes'=>strlen($code),'lint'=>$lint,
          'current_sha256'=>hash_file('sha256',$main),'source'=>'verified_https'];
      }
      if ($action==='deploy_stage_file') {
        // Only accept a previously uploaded file in a dedicated, non-executable assets directory.
        $relative=$args['file']??null;$expected=$args['sha256']??null;
        if(!is_string($relative)||!preg_match('~^assets/odladeni-deploy/[a-zA-Z0-9_-]{1,80}\.txt$~D',$relative)
           ||!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected))
            throw new RuntimeException('Invalid staging source or SHA256');
        $root=realpath(dirname(__DIR__));$folder=realpath(dirname(__DIR__).'/assets/odladeni-deploy');
        $source=dirname(__DIR__).'/'.$relative;
        $real=realpath($source);
        if(!$root||!$folder||$folder!==$root.'/assets/odladeni-deploy'
           ||!$real||dirname($real)!==$folder||is_link($source)||!is_file($real)||!is_readable($real))
            throw new RuntimeException('Staging source unavailable or unsafe');
        $size=filesize($real);
        if($size===false||$size<1000||$size>250000||!hash_equals($expected,hash_file('sha256',$real)))
            throw new RuntimeException('Staging source integrity failed');
        $code=file_get_contents($real);
        if(!is_string($code)||!str_starts_with($code,'<?php')
           ||!str_contains($code,'function deployAction(')||!str_contains($code,'function auth(): bool'))
            throw new RuntimeException('Missing required MCP functions');
        $temp=$dir.'/mcp-index-staging-'.bin2hex(random_bytes(6)).'.php';
        if(file_put_contents($temp,$code,LOCK_EX)!==strlen($code))throw new RuntimeException('Staging write failed');
        chmod($temp,0600);$lint=deployLint($temp);
        if(!$lint['ok']){@unlink($temp);throw new RuntimeException('Lint failed: '.$lint['message']);}
        if(!rename($temp,$stage))throw new RuntimeException('Staging rename failed');
        return ['status'=>'staged','sha256'=>$expected,'bytes'=>$size,'lint'=>$lint,
          'current_sha256'=>hash_file('sha256',$main),'source'=>$relative];
      }
      if ($action==='deploy_stage') {
        $encoded=$args['content_base64']??'';$expected=$args['sha256']??'';
        if(!is_string($encoded)||strlen($encoded)>400000||!preg_match('/^[a-f0-9]{64}$/D',(string)$expected))throw new RuntimeException('Invalid content/hash');
        $code=base64_decode($encoded,true);
        if($code===false||strlen($code)<1000||strlen($code)>250000||hash('sha256',$code)!==$expected||!str_starts_with($code,'<?php'))throw new RuntimeException('Payload integrity failed');
        if(!str_contains($code,'function deployAction(')||!str_contains($code,'function auth(): bool'))throw new RuntimeException('Missing required bridge functions');
        $temp=$dir.'/mcp-index-staging-'.bin2hex(random_bytes(6)).'.php';
        if(file_put_contents($temp,$code,LOCK_EX)!==strlen($code))throw new RuntimeException('Staging write failed');
        chmod($temp,0600);$lint=deployLint($temp);
        if(!$lint['ok']){unlink($temp);throw new RuntimeException('Lint failed: '.$lint['message']);}
        if(!rename($temp,$stage))throw new RuntimeException('Staging rename failed');
        return ['status'=>'staged','sha256'=>$expected,'bytes'=>strlen($code),'lint'=>$lint,'current_sha256'=>hash_file('sha256',$main)];
      }
      if($action==='deploy_apply') {
        $expected=$args['sha256']??'';$current=$args['expected_current_sha256']??'';
        if(!is_file($stage)||!is_string($expected)||!hash_equals(hash_file('sha256',$stage),$expected)||!is_string($current)||!hash_equals(hash_file('sha256',$main),$current))throw new RuntimeException('Stage or current SHA256 mismatch');
        if(!is_file(__DIR__.'/recovery.php'))throw new RuntimeException('Recovery endpoint missing');
        $lint=deployLint($stage);if(!$lint['ok'])throw new RuntimeException('Lint failed: '.$lint['message']);
        $tmp=$dir.'/mcp-index-backup-'.bin2hex(random_bytes(6)).'.php';
        if(!copy($main,$tmp))throw new RuntimeException('Backup copy failed');chmod($tmp,0600);
        if(!rename($tmp,$backup))throw new RuntimeException('Backup save failed');
        $new=__DIR__.'/.mcp-index-next-'.bin2hex(random_bytes(6)).'.php';
        if(!copy($stage,$new))throw new RuntimeException('Deployment copy failed');chmod($new,0644);
        if(!rename($new,$main))throw new RuntimeException('Atomic deployment failed');
        return ['status'=>'applied','sha256'=>hash_file('sha256',$main),'previous_sha256'=>hash_file('sha256',$backup),'recovery_url'=>'https://plaginy.odladeni.cz/mcp/recovery.php'];
      }
      if($action==='deploy_rollback') {
        if(!is_file($backup))throw new RuntimeException('No backup available');
        $lint=deployLint($backup);if(!$lint['ok'])throw new RuntimeException('Backup lint failed');
        $new=__DIR__.'/.mcp-index-rollback-'.bin2hex(random_bytes(6)).'.php';
        if(!copy($backup,$new))throw new RuntimeException('Rollback copy failed');chmod($new,0644);
        if(!rename($new,$main))throw new RuntimeException('Rollback rename failed');
        return ['status'=>'rolled_back','sha256'=>hash_file('sha256',$main)];
      }
      throw new RuntimeException('Unknown deployment action');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}


// File bridge: authenticated MCP calls only. Restrict bootstrap credentials and private storage.
function odladeniBridge(): OdladeniFileBridge {
    $module=dirname(__DIR__,2).'/private/odladeni-file-bridge.php';
    if(!is_file($module)||is_link($module))throw new RuntimeException('File bridge module unavailable');
    require_once $module;
    return new OdladeniFileBridge(dirname(__DIR__),dirname(__DIR__,2).'/private/file-bridge-backups');
}
function odladeniBridgePath(array $args): string {
    $file=$args['file']??null;
    if(!is_string($file)||strlen($file)>1024||!preg_match('~^[a-zA-Z0-9_./-]+$~D',$file))throw new RuntimeException('Invalid file path');
    // Bootstrap, secrets and sensitive state must not be exposed through the general file API.
    $parts=explode('/',$file);
    if(in_array('.htaccess',$parts,true)||in_array('.env',$parts,true)||in_array('config.php',$parts,true)
       ||in_array('recovery.php',$parts,true)||in_array('index.php1',$parts,true)
       ||in_array('private',$parts,true)||in_array('releases',$parts,true)
       ||in_array('vendor',$parts,true)||in_array('.git',$parts,true)
       ||$file==='mcp/index.php'||str_starts_with($file,'mcp/.'))throw new RuntimeException('Protected path');
    return $file;
}
function odladeniBridgeCall(string $tool,array $args): array {
    $file=odladeniBridgePath($args);
    $bridge=odladeniBridge();
    if($tool==='file_read')return $bridge->read($file,$args['offset']??0,$args['length']??49152);
    if($tool==='file_list')return $bridge->listDirectory($file);
    if($tool==='file_mkdir')return $bridge->mkdirSafe($file);
    if($tool==='file_create')return $bridge->createFile($file,$args['content_base64']??'');
    if($tool==='file_write_chunk')return $bridge->stagedWrite($file,$args['upload_id']??'', $args['chunk_index']??-1,$args['total_chunks']??0,$args['content_base64']??'', $args['expected_current_sha256']??'', $args['expected_new_sha256']??'');
    if($tool==='file_backup')return $bridge->backup($file);
    if($tool==='file_write'){
        if(!is_string($args['content_base64']??null)||!is_string($args['expected_sha256']??null))throw new RuntimeException('Missing write payload/hash');
        return $bridge->write($file,$args['content_base64'],$args['expected_sha256']);
    }
    if($tool==='file_restore'){
        if(!is_string($args['backup_id']??null)||!is_string($args['expected_current_sha256']??null))throw new RuntimeException('Missing restore ID/hash');
        $id=$args['backup_id'];
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid backup ID');
        $metaPath=dirname(__DIR__,2).'/private/file-bridge-backups/'.$id.'.json';
        if(is_link($metaPath)||!is_file($metaPath))throw new RuntimeException('Backup metadata unavailable');
        $meta=json_decode((string)file_get_contents($metaPath),true);
        if(!is_array($meta)||($meta['file']??null)!==$file)throw new RuntimeException('Backup does not belong to requested file');
        return $bridge->restore($id,$args['expected_current_sha256']);
    }
    throw new RuntimeException('Unknown file bridge tool');
}

// Read-only server file access. Explicitly allowlisted API source only; excludes other api/, mcp/, private/ and releases/.
function serverReadRoot(): string {
    return dirname(__DIR__);
}
function serverAllowedFiles(): array {
    $root=serverReadRoot();
    // Explicitly permit the console controller source, never its configuration or data.
    $names=['index.php', 'console/index.php', 'api/upload.php', 'mcp/index.php'];
    foreach (['assets','css','js'] as $folder) {
        $base=$root.'/'.$folder;
        if (!is_dir($base) || is_link($base)) continue;
        $it=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $entry) {
            if (count($names)>=200) break 2;
            if (!$entry->isFile() || $entry->isLink()) continue;
            $relative=substr($entry->getPathname(),strlen($root)+1);
            if (substr_count($relative,'/')>3 || !preg_match('~^(?:assets|css|js)/[a-zA-Z0-9_./-]+\.(?:css|js|svg|txt)$~D',$relative)
                || str_contains($relative,'..') || $entry->getSize()>1024*1024) continue;
            $names[]=$relative;
        }
    }
    sort($names);
    return $names;
}
function serverReadFile(array $args): array {
    $file=$args['file']??null;
    if (!is_string($file) || !in_array($file,serverAllowedFiles(),true))
        throw new RuntimeException('File is not on the explicit read-only allowlist');
    $root=realpath(serverReadRoot());
    $path=serverReadRoot().'/'.$file;
    $real=realpath($path);
    if (!$root || !$real || !str_starts_with($real,$root.'/') || !is_file($real) || !is_readable($real)
        || is_link($path) || filesize($real)>1024*1024)
        throw new RuntimeException('File unavailable or unsafe');
    $offset=$args['offset']??0; $length=$args['length']??16384;
    if (!is_int($offset)||$offset<0||!is_int($length)||$length<1||$length>16384)
        throw new RuntimeException('Invalid read bounds');
    $size=filesize($real);
    $fh=fopen($real,'rb'); if (!$fh) throw new RuntimeException('Cannot read file');
    fseek($fh,min($offset,$size));
    $bytes=fread($fh,$length); fclose($fh);
    if ($bytes===false) throw new RuntimeException('Cannot read chunk');
    return ['file'=>$file,'bytes'=>$size,'offset'=>min($offset,$size),
        'next_offset'=>min($offset,$size)+strlen($bytes),
        'has_more'=>min($offset,$size)+strlen($bytes)<$size,
        'chunk_sha256'=>hash('sha256',$bytes),'file_sha256'=>hash_file('sha256',$real),
        'content_base64'=>base64_encode($bytes)];
}


// Read-only cleanup preview; never deletes files.
function releaseCleanupPreview(array $args): array {
    $keep=$args['keep_latest']??10;
    if (!is_int($keep)||$keep<3||$keep>100) throw new RuntimeException('keep_latest must be 3..100');
    $protected=$args['protected_versions']??[];
    if (!is_array($protected)||count($protected)>100) throw new RuntimeException('Invalid protected_versions');
    $protect=[];
    foreach($protected as $item) {
        if (!is_string($item)||!preg_match('/^[a-z0-9-]+@[0-9A-Za-z.-]+$/D',$item))
            throw new RuntimeException('Invalid protected version');
        $protect[$item]=true;
    }
    $grouped=[];
    foreach(releaseList() as $r) $grouped[$r['plugin']][]=$r;
    $candidates=[];$saved=0;$retained=[];
    foreach($grouped as $slug=>$versions) {
        $retained[$slug]=0;
        foreach($versions as $i=>$r) {
            $key=$slug.'@'.$r['version'];
            if($i<$keep||isset($protect[$key])) {$retained[$slug]++;continue;}
            $candidates[]=['plugin'=>$slug,'version'=>$r['version'],
                'filename'=>$r['filename'],'bytes'=>$r['bytes'],'sha256'=>$r['sha256']];
            $saved+=$r['bytes'];
        }
    }
    return ['mode'=>'preview_only','keep_latest'=>$keep,'protected_versions'=>array_keys($protect),
        'candidate_count'=>count($candidates),'reclaimable_bytes'=>$saved,
        'retained_by_plugin'=>$retained,'candidates'=>$candidates,
        'warning'=>'Nothing was deleted. Review candidates and backups before enabling deletion.'];
}

// Release archive management. No irreversible deletion and no access to MCP/config files.
function cleanupPlan(array $args): array {
    $slug=$args['plugin']??null;
    $keep=$args['keep_latest']??10;
    if (!is_string($slug)||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)) throw new RuntimeException('Valid plugin required');
    if (!is_int($keep)||$keep<0||$keep>100) throw new RuntimeException('keep_latest must be 0..100');
    $versions=releaseList($slug);
    $selected=array_slice($versions,$keep);
    $items=[];$total=0;
    foreach($selected as $r){
        $items[]=['filename'=>$r['filename'],'version'=>$r['version'],'bytes'=>$r['bytes'],'sha256'=>$r['sha256']];
        $total+=$r['bytes'];
    }
    $basis=['plugin'=>$slug,'keep_latest'=>$keep,'files'=>$items];
    return ['mode'=>'archive_preview','plugin'=>$slug,'keep_latest'=>$keep,
        'selected_count'=>count($items),'selected_bytes'=>$total,
        'files'=>$items,'plan_sha256'=>hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES)),
        'note'=>'Archiving moves ZIPs to private storage. No permanent deletion.'];
}
function archivePluginReleases(array $args): array {
    global $dir;
    $expected=$args['plan_sha256']??null;
    $confirm=$args['confirm']??false;
    if (!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||$confirm!==true)
        throw new RuntimeException('Explicit confirmation and matching preview hash required');
    $lock=@fopen($dir.'/mcp-release-archive.lock','c');
    if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Archive operation locked');
    try {
        $plan=cleanupPlan($args);
        if (!hash_equals($plan['plan_sha256'],$expected)) throw new RuntimeException('Preview changed; review a new plan');
        if ($plan['selected_count']===0) return ['status'=>'nothing_to_archive','count'=>0];
        $source=dirname(__DIR__).'/releases';
        $archive=$dir.'/release-archive';
        if (is_link($source)||is_link($archive)) throw new RuntimeException('Symlinked directory rejected');
        if (!is_dir($archive)&&!mkdir($archive,0700,true)) throw new RuntimeException('Cannot create private archive');
        @chmod($archive,0700);
        if (!is_writable($archive)) throw new RuntimeException('Archive is not writable');
        $operation=bin2hex(random_bytes(12));
        $destination=$archive.'/'.$operation;
        if (!mkdir($destination,0700)) throw new RuntimeException('Cannot create operation archive');
        $moved=[];
        foreach ($plan['files'] as $entry) {
            $filename=$entry['filename'];
            $src=$source.'/'.$filename;$dst=$destination.'/'.$filename;
            if (is_link($src)||!is_file($src)||!is_readable($src)||!hash_equals($entry['sha256'],hash_file('sha256',$src))||is_file($dst)) {
                foreach(array_reverse($moved) as $name) @rename($destination.'/'.$name,$source.'/'.$name);
                throw new RuntimeException('Source changed or unsafe: '.$filename);
            }
            if (!rename($src,$dst)) {
                foreach(array_reverse($moved) as $name) @rename($destination.'/'.$name,$source.'/'.$name);
                throw new RuntimeException('Archive move failed: '.$filename);
            }
            $moved[]=$filename;
        }
        $manifest=['operation_id'=>$operation,'utc'=>gmdate('c'),'plugin'=>$plan['plugin'],
            'keep_latest'=>$plan['keep_latest'],'plan_sha256'=>$expected,'files'=>$plan['files']];
        if (file_put_contents($destination.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)
            throw new RuntimeException('Files archived but manifest write failed; preserve archive '.$operation);
        @chmod($destination.'/manifest.json',0600);
        bridgeLog('archive_plugin_releases',['operation_id'=>$operation,'plugin'=>$plan['plugin'],'count'=>count($moved)]);
        return ['status'=>'archived','operation_id'=>$operation,'plugin'=>$plan['plugin'],
            'archived_count'=>count($moved),'archived_bytes'=>$plan['selected_bytes'],
            'private_archive'=>true,'permanently_deleted'=>false];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

// Archived releases only: never touch active releases, MCP code or manual backups.
function archiveOperationPlan(array $args): array {
    global $dir;
    $id=$args['operation_id']??null;
    if (!is_string($id)||!preg_match('/^[a-f0-9]{24}$/D',$id)) throw new RuntimeException('Invalid archive operation ID');
    $base=$dir.'/release-archive';$path=$base.'/'.$id;
    if (is_link($base)||is_link($path)||!is_dir($path)) throw new RuntimeException('Archive operation not found or unsafe');
    $manifestPath=$path.'/manifest.json';
    if(is_link($manifestPath)||!is_file($manifestPath)||filesize($manifestPath)>1048576) throw new RuntimeException('Archive manifest unavailable');
    $manifest=json_decode(file_get_contents($manifestPath),true);
    if(!is_array($manifest)||($manifest['operation_id']??null)!==$id||!isset($manifest['files'])||!is_array($manifest['files'])||count($manifest['files'])>1000)
        throw new RuntimeException('Invalid archive manifest');
    $files=[];$bytes=0;$seen=[];
    foreach($manifest['files'] as $f){
        $name=$f['filename']??null;$sha=$f['sha256']??null;
        if(!is_string($name)||!preg_match('/^[a-z0-9][a-z0-9.-]*\.zip$/D',$name)||isset($seen[$name])||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))
            throw new RuntimeException('Unsafe archive filename or digest');
        $seen[$name]=true;$full=$path.'/'.$name;
        if(is_link($full)||!is_file($full)||!hash_equals($sha,hash_file('sha256',$full))) throw new RuntimeException('Archive ZIP missing or modified: '.$name);
        $size=filesize($full);$bytes+=$size;
        $files[]=['filename'=>$name,'bytes'=>$size,'sha256'=>$sha];
    }
    $actual=scandir($path);$expected=array_merge(['.','..','manifest.json'],array_keys($seen));sort($actual);sort($expected);
    if($actual!==$expected) throw new RuntimeException('Unexpected files in archive operation');
    $basis=['operation_id'=>$id,'plugin'=>$manifest['plugin']??null,'files'=>$files];
    return ['operation_id'=>$id,'plugin'=>$manifest['plugin']??null,'files'=>$files,'count'=>count($files),'bytes'=>$bytes,
      'plan_sha256'=>hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES))];
}
function listArchiveOperations(): array {
    global $dir;
    $base=$dir.'/release-archive';$out=[];
    if(!is_dir($base)) return ['operations'=>[],'count'=>0];
    if(is_link($base)) throw new RuntimeException('Unsafe archive directory');
    foreach(scandir($base) as $id){
        if(!preg_match('/^[a-f0-9]{24}$/D',$id)) continue;
        try{$plan=archiveOperationPlan(['operation_id'=>$id]);$out[]=['operation_id'=>$id,'plugin'=>$plan['plugin'],'count'=>$plan['count'],'bytes'=>$plan['bytes']];}
        catch(Throwable $e){$out[]=['operation_id'=>$id,'status'=>'requires_review'];}
    }
    return ['operations'=>$out,'count'=>count($out),'read_only'=>true];
}
function deleteArchivedOperation(array $args): array {
    global $dir;
    if(($args['confirm']??false)!==true||!is_string($args['plan_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$args['plan_sha256']))
        throw new RuntimeException('Explicit confirmation and preview SHA256 required');
    $lock=fopen($dir.'/mcp-release-archive.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Archive locked');
    try{
        $plan=archiveOperationPlan($args);
        if(!hash_equals($plan['plan_sha256'],$args['plan_sha256'])) throw new RuntimeException('Preview changed; request a new preview');
        $path=$dir.'/release-archive/'.$plan['operation_id'];
        // Manifest is retained until all ZIP files have been removed; partial deletion remains detectable.
        $deleted=0;$bytes=0;
        foreach($plan['files'] as $f){
            $full=$path.'/'.$f['filename'];
            if(is_link($full)||!is_file($full)||!hash_equals($f['sha256'],hash_file('sha256',$full))) throw new RuntimeException('File changed; deletion stopped');
            if(!unlink($full)) throw new RuntimeException('Deletion failed; remaining archive requires review');
            $deleted++;$bytes+=$f['bytes'];
        }
        if(!unlink($path.'/manifest.json')||!rmdir($path)) throw new RuntimeException('ZIPs removed, archive directory cleanup incomplete');
        bridgeLog('delete_archived_operation',['operation_id'=>$plan['operation_id'],'plugin'=>$plan['plugin'],'count'=>$deleted,'bytes'=>$bytes]);
        return ['status'=>'permanently_deleted','operation_id'=>$plan['operation_id'],'plugin'=>$plan['plugin'],'deleted_count'=>$deleted,'deleted_bytes'=>$bytes];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function restoreArchivedOperation(array $args): array {
    global $dir;
    if(($args['confirm']??false)!==true||!is_string($args['plan_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$args['plan_sha256']))
        throw new RuntimeException('Explicit confirmation and preview SHA256 required');
    $lock=fopen($dir.'/mcp-release-archive.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Archive locked');
    try{
        $plan=archiveOperationPlan($args);
        if(!hash_equals($plan['plan_sha256'],$args['plan_sha256'])) throw new RuntimeException('Preview changed; request a new preview');
        $source=$dir.'/release-archive/'.$plan['operation_id'];$target=dirname(__DIR__).'/releases';
        if(is_link($target)||!is_dir($target)||!is_writable($target)) throw new RuntimeException('Active releases unavailable');
        foreach($plan['files'] as $f) if(file_exists($target.'/'.$f['filename'])||is_link($target.'/'.$f['filename'])) throw new RuntimeException('Restore filename conflict: '.$f['filename']);
        $moved=[];
        foreach($plan['files'] as $f){$name=$f['filename'];
            if(!rename($source.'/'.$name,$target.'/'.$name)){
                foreach(array_reverse($moved) as $back) @rename($target.'/'.$back,$source.'/'.$back);
                throw new RuntimeException('Restore failed; review archive state');
            }
            $moved[]=$name;
        }
        if(!unlink($source.'/manifest.json')||!rmdir($source)) throw new RuntimeException('Files restored, archive metadata cleanup incomplete');
        bridgeLog('restore_archived_operation',['operation_id'=>$plan['operation_id'],'plugin'=>$plan['plugin'],'count'=>count($moved)]);
        return ['status'=>'restored','operation_id'=>$plan['operation_id'],'plugin'=>$plan['plugin'],'restored_count'=>count($moved)];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

// Fleet phase 1: read-only, OAuth-gated by the existing MCP dispatcher.
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

function fleetSiteDiagnostics(string $site): array {
    $registered=connectedSitesReadOnly();
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
    $path='/wp-json/odladeni-bridge/v1/diagnostics';
    $time=(string)time();$nonce=bin2hex(random_bytes(12));
    $signature=hash_hmac('sha256',"GET\n".$path."\n".$time."\n".$nonce,$key);
    unset($key,$config,$query,$db);
    $body='';$overflow=false;
    $ch=curl_init(rtrim($site,'/').$path);
    if ($ch===false) throw new RuntimeException('HTTPS transport unavailable');
    $pin=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];
    curl_setopt_array($ch,[CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_RESOLVE=>[$host.':443:'.$pin],
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-Odladeni-Time: '.$time,
            'X-Odladeni-Nonce: '.$nonce,'X-Odladeni-Signature: '.$signature],
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$body,&$overflow):int {
            if(strlen($body)+strlen($chunk)>2097152){$overflow=true;return 0;}
            $body.=$chunk;return strlen($chunk);
        }]);
    $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($overflow) throw new RuntimeException('Diagnostic response exceeds limit');
    if ($ok===false||$http!==200) throw new RuntimeException('Remote diagnostics unavailable (HTTP '.$http.')');
    $data=json_decode($body,true,64);
    if (!is_array($data)||($data['ok']??false)!==true||!is_array($data['plugins']??null)) {
        throw new RuntimeException('Invalid remote diagnostics response');
    }
    $plugins=[];
    foreach($data['plugins'] as $plugin) {
        if (!is_array($plugin)) continue;
        $clean=[];
        foreach(['slug','file','plugin','basename','name','version','active','update_available','new_version','latest_version','auto_update','auto_updates','auto_update_enabled'] as $field) {
            if(array_key_exists($field,$plugin)&&(is_scalar($plugin[$field])||$plugin[$field]===null))$clean[$field]=$plugin[$field];
        }
        $plugins[]=$clean;
    }
    return ['site'=>$site,'ok'=>true,'wordpress_version'=>is_string($data['wordpress_version']??null)?$data['wordpress_version']:null,
        'php_version'=>is_string($data['php_version']??null)?$data['php_version']:null,
        'plugins'=>$plugins,'checked_at'=>gmdate('c')];
}
function fleetDiagnostics(): array {
    $sites=connectedSitesReadOnly();$results=[];$started=microtime(true);
    foreach($sites['sites'] as $site) {
        if(microtime(true)-$started>48){$results[]=['site'=>$site['url'],'ok'=>false,'error'=>'Fleet time budget reached'];continue;}
        try{$results[]=fleetSiteDiagnostics($site['url']);}
        catch(Throwable $e){$results[]=['site'=>$site['url'],'ok'=>false,'error'=>$e->getMessage()];}
    }
    return ['count'=>count($results),'results'=>$results,'read_only'=>true];
}

function connectedSitesReadOnly(): array {
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

function serverInventory(): array {
    $root=dirname(__DIR__);
    $folders=['admin','api','assets','mcp','releases'];
    $entries=[];
    foreach($folders as $folder){
        $path=$root.'/'.$folder;
        $entries[]=['name'=>$folder,'exists'=>is_dir($path),'is_symlink'=>is_link($path),
            'readable'=>is_readable($path)];
    }
    $rootFiles=[];
    foreach(glob($root.'/*')?:[] as $path){
        if (!is_file($path)||is_link($path))continue;
        $rootFiles[]=['name'=>basename($path),'bytes'=>filesize($path),'modified_utc'=>gmdate('c',filemtime($path))];
        if(count($rootFiles)>=100)break;
    }
    return ['mode'=>'read_only','folders'=>$entries,'root_files'=>$rootFiles,
        'note'=>'Inventory is metadata-only. Does not enumerate secrets or private storage.'];
}


// Editable validation policy. Stored outside the public web root.
function validationDefaults(): array {
    return [
        ['key'=>'main_php','label'=>'Hlavní PHP soubor','description'=>'ZIP obsahuje hlavní PHP soubor s hlavičkou Plugin Name.','required'=>true],
        ['key'=>'version','label'=>'Verze pluginu','description'=>'Hlavička Version musí obsahovat platné číslo verze.','required'=>true],
        ['key'=>'author','label'=>'Autor Martin Legnavsky','description'=>'Hlavička Author musí uvádět Martin Legnavsky.','required'=>true],
        ['key'=>'update_uri','label'=>'Identifikace aktualizací','description'=>'Hlavička Update URI musí být vyplněna.','required'=>true],
        ['key'=>'manifest','label'=>'Manifest pluginu','description'=>'V ZIPu je manifest.json s identifikací a verzí.','required'=>true],
        ['key'=>'readme','label'=>'Dokumentace','description'=>'V ZIPu je readme.txt nebo README.md.','required'=>true],
        ['key'=>'php_syntax','label'=>'Kontrola syntaxe PHP','description'=>'PHP syntaxe musí projít odděleným validátorem.','required'=>true],
        ['key'=>'signature','label'=>'Integrita a kontrolní součet','description'=>'Systém eviduje SHA-256 přijatého ZIPu.','required'=>true]
    ];
}
function validationRules(): array {
    global $dir;
    $path=$dir.'/mcp-validation-rules.json';
    if(is_link($path)) throw new RuntimeException('Unsafe rules storage');
    $stored=fileget($path);
    $rules=$stored['rules']??validationDefaults();
    if(!is_array($rules)) throw new RuntimeException('Invalid stored rules');
    return $rules;
}
function validationPolicy(): array {
    $rules=validationRules();
    return ['standard'=>'1.2','rules'=>$rules,'sha256'=>hash('sha256',json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
      'note'=>'Policy metadata only; upload rejection requires the same validator to be integrated into all upload paths.'];
}
function validationUpdate(array $args): array {
    global $dir;
    $expected=$args['expected_sha256']??null;
    $rules=$args['rules']??null;
    if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_array($rules)||count($rules)!==8)
        throw new RuntimeException('Expected SHA256 and exactly eight rules required');
    $allowed=array_column(validationDefaults(),null,'key');$seen=[];$clean=[];
    foreach($rules as $rule){
        if(!is_array($rule)||!is_string($rule['key']??null)||!isset($allowed[$rule['key']])||isset($seen[$rule['key']]))
            throw new RuntimeException('Invalid or duplicate rule key');
        $key=$rule['key'];$seen[$key]=true;
        $label=$rule['label']??null;$desc=$rule['description']??null;$required=$rule['required']??null;
        if(!is_string($label)||strlen($label)>120||trim($label)===''||!is_string($desc)||strlen($desc)>1000||trim($desc)===''||!is_bool($required))
            throw new RuntimeException('Invalid rule fields');
        $clean[]=['key'=>$key,'label'=>$label,'description'=>$desc,'required'=>$required];
    }
    $lock=fopen($dir.'/mcp-validation-rules.lock','c');
    if(!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock rules');
    try {
        $current=validationPolicy();
        if(!hash_equals($current['sha256'],$expected)) throw new RuntimeException('Rules changed; fetch current rules and retry');
        $old=$dir.'/mcp-validation-rules.json';
        if(is_file($old)) {
            $backup=$dir.'/mcp-validation-rules-backup-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)).'.json';
            if(!copy($old,$backup)) throw new RuntimeException('Cannot back up rules');
            chmod($backup,0600);
        }
        fileset($old,['standard'=>'1.2','updated_utc'=>gmdate('c'),'rules'=>$clean]);
        bridgeLog('validation_rules_update',['keys'=>array_keys($seen)]);
        return validationPolicy();
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function validationInspectRelease(array $args): array {
    $slug=$args['plugin']??null;$version=$args['version']??null;
    if(!is_string($slug)||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)
        ||!is_string($version)||!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D',$version))
        throw new RuntimeException('Invalid plugin/version');
    $release=null;foreach(releaseList($slug) as $item){if($item['version']===$version){$release=$item;break;}}
    if(!$release)throw new RuntimeException('Release not found');
    $zip=new ZipArchive();
    if($zip->open(dirname(__DIR__).'/releases/'.$release['filename'],ZipArchive::RDONLY)!==true)throw new RuntimeException('Cannot open ZIP');
    try {
        $root=$slug.'/';$main=null;$readme=false;$manifest=false;$php=[];
        if($zip->numFiles>2000)throw new RuntimeException('Too many ZIP entries');
        for($i=0;$i<$zip->numFiles;$i++){
            $name=$zip->getNameIndex($i);
            if(!is_string($name)||!str_starts_with($name,$root)||str_contains($name,'..')||str_contains($name,'\\'))throw new RuntimeException('Unsafe ZIP entry');
            $rel=substr($name,strlen($root));
            if(in_array(strtolower($rel),['readme.txt','readme.md'],true))$readme=true;
            if($rel==='manifest.json')$manifest=true;
            if(str_ends_with(strtolower($rel),'.php')){
                $php[]=$name;
                if(substr_count($rel,'/')===0){
                    $content=$zip->getFromName($name);
                    if(is_string($content)&&strlen($content)<2097152&&preg_match('/^[ \t*#\/]*Plugin Name:\s*(.+?)\s*$/mi',$content))$main=$content;
                }
            }
        }
        $checks=['main_php'=>$main!==null,'version'=>$main!==null&&preg_match('/^[ \t*#\/]*Version:\s*[0-9]+(?:\.[0-9]+)+\s*$/mi',$main)===1,
            'author'=>$main!==null&&preg_match('/^[ \t*#\/]*Author:\s*Martin Legnavsky\s*$/mi',$main)===1,
            'update_uri'=>$main!==null&&preg_match('/^[ \t*#\/]*Update URI:\s*\S+\s*$/mi',$main)===1,
            'manifest'=>$manifest,'readme'=>$readme,'php_syntax'=>null,'signature'=>preg_match('/^[a-f0-9]{64}$/D',$release['sha256'])===1];
        $lint=deployPhpDiagnostics();
        if($lint['lint_available']){
            $all=true;
            foreach($php as $name){
                $code=$zip->getFromName($name);
                if(!is_string($code)||strlen($code)>2097152){$all=false;break;}
                $tmp=tempnam(sys_get_temp_dir(),'odl-lint-');
                if($tmp===false){$all=false;break;}
                file_put_contents($tmp,$code);
                $check=deployLint($tmp);unlink($tmp);
                if(!$check['ok']){$all=false;break;}
            }
            $checks['php_syntax']=$all;
        }
        $results=[];$pass=true;
        foreach(validationRules() as $rule){
            $state=$checks[$rule['key']]??null;
            $results[]=['key'=>$rule['key'],'label'=>$rule['label'],'required'=>$rule['required'],'passed'=>$state,
                'status'=>$state===null?'not_verified':($state?'pass':'fail')];
            if($rule['required']&&$state!==true)$pass=false;
        }
        return ['plugin'=>$slug,'version'=>$version,'accepted'=>$pass,'checks'=>$results,'sha256'=>$release['sha256'],
            'read_only'=>true,'note'=>'Inspection does not change release. PHP lint unavailable means not verified, not passed.'];
    } finally {$zip->close();}
}

function authorizationHeader(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) { $header = (string)$value; break; }
        }
    }
    return is_string($header) ? $header : '';
}
function auth(): bool {
    global $dir;
    $header = authorizationHeader();
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) return false;
    $tokens = fileget($dir . '/access.json');
    $key = hash('sha256', $matches[1]);
    return isset($tokens[$key]) && is_array($tokens[$key]) && ($tokens[$key]['exp'] ?? 0) > time();
}
function authDebug(): void {
    global $dir;
    $header = authorizationHeader();
    $present = $header !== '';
    $bearer = preg_match('/^Bearer\s+\S+$/i', $header) === 1;
    $tokens = fileget($dir . '/access.json');
    $matched = false;
    $expired = false;
    if ($bearer && preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
        $key = hash('sha256', $matches[1]);
        $matched = isset($tokens[$key]);
        $expired = $matched && ($tokens[$key]['exp'] ?? 0) <= time();
    }
    // Never log token, password, hash, client_id or authorization code.
    error_log('MCP AUTH DEBUG: header=' . ($present ? 'present' : 'missing')
        . ', bearer=' . ($bearer ? 'yes' : 'no')
        . ', token_found=' . ($matched ? 'yes' : 'no')
        . ', expired=' . ($expired ? 'yes' : 'no'));
}

if ($path === '/.well-known/oauth-protected-resource' || $path === '/.well-known/oauth-protected-resource/mcp') {
    j(['resource' => $origin . '/mcp/', 'authorization_servers' => [$origin]]);
}
if ($path === '/.well-known/oauth-authorization-server') {
    j([
        'issuer' => $origin,
        'authorization_endpoint' => $origin . '/mcp/authorize',
        'token_endpoint' => $origin . '/mcp/token',
        'registration_endpoint' => $origin . '/mcp/register',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none']
    ]);
}
if ($path === '/mcp/register' && $method === 'POST') {
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) err('invalid_client_metadata');
    $uris = $data['redirect_uris'] ?? [];
    if (!is_array($uris) || count($uris) < 1 || count($uris) > 5) err('invalid_redirect_uris');
    foreach ($uris as $uri) {
        if (!is_string($uri) || strlen($uri) > 1000) err('invalid_redirect_uri');
        $parsed = parse_url($uri);
        if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) err('invalid_redirect_uri');
    }
    $id = token(16);
    $clients = fileget($dir . '/clients.json');
    $clients[$id] = ['redirect_uris' => $uris, 'created' => time()];
    fileset($dir . '/clients.json', $clients);
    j(['client_id' => $id, 'client_id_issued_at' => time(), 'redirect_uris' => $uris,
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none'], 201);
}
if ($path === '/mcp/authorize') {
    $params = $method === 'POST' ? $_POST : $_GET;
    $client = (string)($params['client_id'] ?? '');
    $redirect = (string)($params['redirect_uri'] ?? '');
    $challenge = (string)($params['code_challenge'] ?? '');
    $state = (string)($params['state'] ?? '');
    $clients = fileget($dir . '/clients.json');
    if (!isset($clients[$client]) || !in_array($redirect, $clients[$client]['redirect_uris'], true)
        || ($params['response_type'] ?? '') !== 'code'
        || ($params['code_challenge_method'] ?? '') !== 'S256'
        || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) err('invalid_authorization_request');
    if ($method === 'POST') {
        if (!hash_equals($secret, (string)($params['password'] ?? ''))) {
            http_response_code(403); exit('Nesprávný přístupový klíč.');
        }
        $code = token();
        $codes = fileget($dir . '/codes.json');
        $codes[hash('sha256', $code)] = ['client' => $client, 'redirect' => $redirect,
            'challenge' => $challenge, 'exp' => time() + 300];
        fileset($dir . '/codes.json', $codes);
        $sep = str_contains($redirect, '?') ? '&' : '?';
        header('Location: ' . $redirect . $sep . http_build_query(['code' => $code, 'state' => $state]), true, 302);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Odladění – přihlášení</title><style>body{font:16px system-ui;max-width:480px;margin:10vh auto;padding:24px}input{display:block;width:100%;padding:12px;box-sizing:border-box}button{margin-top:16px;padding:12px 22px}</style><h2>Odladění – správa pluginů</h2><p>Propojení ChatGPT autorizujte přístupovým klíčem uloženým v api/config.php. Klíč nikomu neposílejte.</p><form method="post">';
    foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state'] as $key) {
        echo '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES) . '" value="' . htmlspecialchars((string)($params[$key] ?? ''), ENT_QUOTES) . '">';
    }
    echo '<label>Přístupový klíč<input type="password" name="password" autocomplete="off" required></label><button>Autorizovat ChatGPT</button></form></html>';
    exit;
}
// Access tokens last one hour; refresh tokens are rotated and expire after 90 days.
// Keep refresh tokens hashed at rest, just like access tokens.
function issueTokens(string $client): never {
    global $dir;
    $access = token();
    $refresh = token();
    $now = time();
    $tokens = fileget($dir . '/access.json');
    foreach ($tokens as $key => $record) {
        if (!is_array($record) || ($record['exp'] ?? 0) <= $now) unset($tokens[$key]);
    }
    $tokens[hash('sha256', $access)] = ['exp' => $now + 3600];
    fileset($dir . '/access.json', $tokens);
    $refreshes = fileget($dir . '/refresh.json');
    foreach ($refreshes as $key => $record) {
        if (!is_array($record) || ($record['exp'] ?? 0) <= $now) unset($refreshes[$key]);
    }
    $refreshes[hash('sha256', $refresh)] = ['client' => $client, 'exp' => $now + 90 * 86400];
    fileset($dir . '/refresh.json', $refreshes);
    header('Cache-Control: no-store');
    j(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600,
       'refresh_token' => $refresh]);
}
if ($path === '/mcp/token' && $method === 'POST') {
    $data = $_POST;
    if (!$data) parse_str((string)file_get_contents('php://input'), $data);
    $grant = $data['grant_type'] ?? '';
    if ($grant === 'refresh_token') {
        $client = (string)($data['client_id'] ?? '');
        $presented = (string)($data['refresh_token'] ?? '');
        if ($client === '' || $presented === '') err('invalid_grant');
        // Serialize refresh exchanges to prevent simultaneous reuse of a rotated token.
        $lock = fopen($dir . '/refresh.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) err('temporarily_unavailable', 503);
        $refreshes = fileget($dir . '/refresh.json');
        $key = hash('sha256', $presented);
        $record = $refreshes[$key] ?? null;
        if (!is_array($record) || ($record['exp'] ?? 0) <= time()
            || !hash_equals((string)($record['client'] ?? ''), $client)) {
            flock($lock, LOCK_UN); fclose($lock);
            err('invalid_grant');
        }
        unset($refreshes[$key]);
        fileset($dir . '/refresh.json', $refreshes);
        // Keep the lock until replacement refresh token is persisted.
        $access = token(); $refresh = token(); $now = time();
        $tokens = fileget($dir . '/access.json');
        foreach ($tokens as $k => $r) if (!is_array($r) || ($r['exp'] ?? 0) <= $now) unset($tokens[$k]);
        $tokens[hash('sha256', $access)] = ['exp' => $now + 3600];
        fileset($dir . '/access.json', $tokens);
        $refreshes[hash('sha256', $refresh)] = ['client' => $client, 'exp' => $now + 90 * 86400];
        fileset($dir . '/refresh.json', $refreshes);
        flock($lock, LOCK_UN); fclose($lock);
        j(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600,
           'refresh_token' => $refresh]);
    }
    if ($grant !== 'authorization_code') err('unsupported_grant_type');
    $code = (string)($data['code'] ?? '');
    $codes = fileget($dir . '/codes.json');
    $key = hash('sha256', $code);
    $record = $codes[$key] ?? null;
    unset($codes[$key]);
    fileset($dir . '/codes.json', $codes);
    if (!$record || $record['exp'] < time()
        || !hash_equals($record['client'], (string)($data['client_id'] ?? ''))
        || !hash_equals($record['redirect'], (string)($data['redirect_uri'] ?? ''))) err('invalid_grant');
    $verifier = (string)($data['code_verifier'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) err('invalid_grant');
    $calculated = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    if (!hash_equals($record['challenge'], $calculated)) err('invalid_grant');
    issueTokens($record['client']);
}
if ($path === '/mcp/' || $path === '/mcp') {
    if (!auth()) {
        authDebug();
        header('WWW-Authenticate: Bearer resource_metadata="' . $origin . '/.well-known/oauth-protected-resource"');
        err('unauthorized', 401);
    }
    if ($method === 'GET') { header('Allow: POST, OPTIONS'); http_response_code(405); exit; }
    if ($method !== 'POST') err('method_not_allowed', 405);
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) err('invalid_json');
    $id = $data['id'] ?? null;
    $name = $data['method'] ?? '';
    if ($id === null) { http_response_code(202); exit; }
    $response = ['jsonrpc' => '2.0', 'id' => $id];
    if ($name === 'initialize') {
        $response['result'] = ['protocolVersion' => '2025-03-26',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'odladeni-plugin-releases', 'version' => '2.6.1']];
        j($response);
    }
    if ($name === 'ping') { $response['result'] = (object)[]; j($response); }
    if ($name === 'tools/list') {
        $empty = ['type' => 'object', 'properties' => (object)[]];
        $filter = ['type' => 'object', 'properties' => [
            'plugin' => ['type' => 'string', 'description' => 'Optional plugin slug, e.g. grafika-volby']]];
        $response['result'] = ['tools' => [
            ['name'=>'preview_release_cleanup','description'=>'Read-only preview of older ZIP releases beyond per-plugin retention. Never deletes files.','inputSchema'=>['type'=>'object','properties'=>['keep_latest'=>['type'=>'integer','minimum'=>3,'maximum'=>100],'protected_versions'=>['type'=>'array','items'=>['type'=>'string']]]]],
            ['name'=>'fleet_plugin_manage','description'=>'Change one explicitly selected plugin on one paired site. Supports activate, deactivate, install, update, delete and auto_update. Stable request_id required; installation inactive by default; delete requires confirm_delete. Hlavni instalace is protected from removal.','inputSchema'=>['type'=>'object','properties'=>['site_url'=>['type'=>'string'],'action'=>['type'=>'string','enum'=>['activate','deactivate','install','update','delete','auto_update']],'plugin'=>['type'=>'string'],'request_id'=>['type'=>'string'],'enabled'=>['type'=>'boolean'],'confirm_delete'=>['type'=>'boolean'],'source'=>['type'=>'string','enum'=>['installed','wordpress','odladeni','zip']],'slug'=>['type'=>'string'],'url'=>['type'=>'string'],'sha256'=>['type'=>'string'],'version'=>['type'=>'string']],'required'=>['site_url','action','plugin','request_id']]],
            ['name'=>'fleet_upgrade_bridge','description'=>'Upgrade only Hlavni instalace via the existing paired legacy updater, then verify with live diagnostics. Explicitly selected site and confirmation required.','inputSchema'=>['type'=>'object','properties'=>['site_url'=>['type'=>'string'],'confirm'=>['type'=>'boolean']],'required'=>['site_url','confirm']]],
            ['name'=>'fleet_list_sites','description'=>'Read-only paired WordPress registry; no credentials.','inputSchema'=>$empty],
            ['name'=>'fleet_site_diagnostics','description'=>'Signed read-only diagnostics for one paired HTTPS WordPress site.','inputSchema'=>['type'=>'object','properties'=>['site_url'=>['type'=>'string']],'required'=>['site_url']]],
            ['name'=>'fleet_diagnostics','description'=>'Signed read-only diagnostics for paired WordPress sites; independent failures and bounded duration.','inputSchema'=>$empty],
            ['name'=>'list_connected_sites','description'=>'Paired WordPress registry, optionally with signed live diagnostics.','inputSchema'=>['type'=>'object','properties'=>['include_diagnostics'=>['type'=>'boolean']]]],
            ['name'=>'server_inventory','description'=>'Read-only metadata inventory of public web root and selected folders; never returns file contents.','inputSchema'=>$empty],
            ['name'=>'preview_plugin_archive','description'=>'Preview ZIP releases to archive for one plugin, including a confirmation SHA256. keep_latest=0 archives all versions.','inputSchema'=>['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'keep_latest'=>['type'=>'integer','minimum'=>0,'maximum'=>100]],'required'=>['plugin']]],
            ['name'=>'archive_plugin_releases','description'=>'After explicit user approval, move precisely previewed releases to a private reversible archive. Never permanently deletes.','inputSchema'=>['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'keep_latest'=>['type'=>'integer','minimum'=>0,'maximum'=>100],'plan_sha256'=>['type'=>'string'],'confirm'=>['type'=>'boolean']],'required'=>['plugin','keep_latest','plan_sha256','confirm']]],
            ['name'=>'list_archived_operations','description'=>'Read-only inventory of private archive operations.','inputSchema'=>$empty],
            ['name'=>'preview_archived_operation','description'=>'Read-only exact ZIP manifest and confirmation SHA256 for one private archive operation.','inputSchema'=>['type'=>'object','properties'=>['operation_id'=>['type'=>'string']],'required'=>['operation_id']]],
            ['name'=>'restore_archived_operation','description'=>'Restore a previously archived operation to active ZIP releases after explicit confirmation and matching preview SHA256.','inputSchema'=>['type'=>'object','properties'=>['operation_id'=>['type'=>'string'],'plan_sha256'=>['type'=>'string'],'confirm'=>['type'=>'boolean']],'required'=>['operation_id','plan_sha256','confirm']]],
            ['name'=>'delete_archived_operation','description'=>'IRREVERSIBLY delete only the ZIPs in a private archive operation after explicit approval and matching preview SHA256; cannot delete active releases or server files.','inputSchema'=>['type'=>'object','properties'=>['operation_id'=>['type'=>'string'],'plan_sha256'=>['type'=>'string'],'confirm'=>['type'=>'boolean']],'required'=>['operation_id','plan_sha256','confirm']]],
            ['name'=>'file_list','description'=>'List managed directory metadata, no file contents.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string']],'required'=>['file']]],
            ['name'=>'file_mkdir','description'=>'Create one directory under an existing managed directory.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string']],'required'=>['file']]],
            ['name'=>'file_create','description'=>'Create a new file, never overwrite an existing file (up to 1 MiB).','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string'],'content_base64'=>['type'=>'string']],'required'=>['file','content_base64']]],
            ['name'=>'file_write_chunk','description'=>'Write or create a large file using ordered base64 chunks; atomic finalization and backup.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string'],'upload_id'=>['type'=>'string'],'chunk_index'=>['type'=>'integer','minimum'=>0],'total_chunks'=>['type'=>'integer','minimum'=>1,'maximum'=>4096],'content_base64'=>['type'=>'string'],'expected_current_sha256'=>['type'=>'string'],'expected_new_sha256'=>['type'=>'string']],'required'=>['file','upload_id','chunk_index','total_chunks','content_base64','expected_current_sha256','expected_new_sha256']]],
            ['name'=>'file_read','description'=>'Read a managed server file in verified base64 chunks. Protected credentials and MCP bootstrap excluded.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string'],'offset'=>['type'=>'integer','minimum'=>0],'length'=>['type'=>'integer','minimum'=>1,'maximum'=>49152]],'required'=>['file']]],
            ['name'=>'file_backup','description'=>'Create SHA256-verified private backup of a managed file.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string']],'required'=>['file']]],
            ['name'=>'file_write','description'=>'Atomic replace with SHA256 compare-and-swap and automatic backup (up to 1 MiB per call).','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string'],'content_base64'=>['type'=>'string'],'expected_sha256'=>['type'=>'string']],'required'=>['file','content_base64','expected_sha256']]],
            ['name'=>'file_restore','description'=>'Restore verified backup with current SHA256 compare-and-swap.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string'],'backup_id'=>['type'=>'string'],'expected_current_sha256'=>['type'=>'string']],'required'=>['file','backup_id','expected_current_sha256']]],
            ['name'=>'list_server_files','description'=>'List explicitly permitted public web files; read-only.','inputSchema'=>$empty],
            ['name'=>'read_server_file','description'=>'Read an allowlisted public server file in base64 chunks with SHA256. Read-only; no secrets/config access.','inputSchema'=>['type'=>'object','properties'=>[
                'file'=>['type'=>'string'],'offset'=>['type'=>'integer','minimum'=>0],
                'length'=>['type'=>'integer','minimum'=>1,'maximum'=>16384]],'required'=>['file']]],
            ['name'=>'get_validation_rules','description'=>'Read current Odladeni plugin validation policy and revision SHA256.','inputSchema'=>$empty],
            ['name'=>'update_validation_rules','description'=>'Update existing validation rule labels, descriptions and required flags with revision check and private backup. Does not change upload enforcement.','inputSchema'=>['type'=>'object','properties'=>['expected_sha256'=>['type'=>'string'],'rules'=>['type'=>'array','items'=>['type'=>'object','properties'=>['key'=>['type'=>'string'],'label'=>['type'=>'string'],'description'=>['type'=>'string'],'required'=>['type'=>'boolean']],'required'=>['key','label','description','required']]]],'required'=>['expected_sha256','rules']]],
            ['name'=>'inspect_release_validation','description'=>'Read-only check of a stored plugin ZIP against current rules. Unknown PHP lint never counts as passed.','inputSchema'=>['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'version'=>['type'=>'string']],'required'=>['plugin','version']]],
            ['name' => 'server_status', 'description' => 'Check plugin storage health.', 'inputSchema' => $empty],
            ['name'=>'connection_diagnostics','description'=>'Authenticated bridge status and capabilities.','inputSchema'=>$empty],
            ['name'=>'connection_self_test','description'=>'Non-destructive ZIP and storage diagnostics.','inputSchema'=>$empty],
            ['name'=>'deploy_diagnostics','description'=>'Read-only MCP self-update readiness, backup, staged package, lint and recovery status.','inputSchema'=>$empty],
            ['name'=>'deploy_stage','description'=>'Stage and PHP-lint a complete new MCP index.php; does not activate.','inputSchema'=>['type'=>'object','properties'=>['content_base64'=>['type'=>'string'],'sha256'=>['type'=>'string']],'required'=>['content_base64','sha256']]],
            ['name'=>'deploy_stage_patch','description'=>'Stage exact text patches of current MCP, verify old/new SHA256 and PHP syntax; no activation.','inputSchema'=>['type'=>'object','properties'=>['expected_current_sha256'=>['type'=>'string'],'sha256'=>['type'=>'string'],'replacements'=>['type'=>'array','items'=>['type'=>'object','properties'=>['find'=>['type'=>'string'],'replace'=>['type'=>'string']],'required'=>['find','replace']]]],'required'=>['expected_current_sha256','sha256','replacements']]],
            ['name'=>'deploy_stage_url','description'=>'Stage HTTPS .txt MCP source from raw.githubusercontent.com only, SHA256 and PHP-parse verified; no activation.','inputSchema'=>['type'=>'object','properties'=>['url'=>['type'=>'string'],'sha256'=>['type'=>'string']],'required'=>['url','sha256']]],
            ['name'=>'deploy_stage_file','description'=>'Stage and PHP-parse a verified MCP candidate previously uploaded in chunks to assets/odladeni-deploy/*.txt. Does not activate.','inputSchema'=>['type'=>'object','properties'=>['file'=>['type'=>'string'],'sha256'=>['type'=>'string']],'required'=>['file','sha256']]],
            ['name'=>'deploy_apply','description'=>'Explicitly activate previously staged MCP code with compare-and-swap SHA256 and backup.','inputSchema'=>['type'=>'object','properties'=>['sha256'=>['type'=>'string'],'expected_current_sha256'=>['type'=>'string']],'required'=>['sha256','expected_current_sha256']]],
            ['name'=>'deploy_rollback','description'=>'Restore backed-up MCP index.php after an update.','inputSchema'=>$empty],
            ['name'=>'transfer_diagnostics','description'=>'Read recent ZIP transfer events by operation ID; no payloads or secrets.',
             'inputSchema'=>['type'=>'object','properties'=>['operation_id'=>['type'=>'string'],
               'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200]]]],
            ['name'=>'download_release_chunk','description'=>'Download a ZIP in base64 byte chunks with SHA256 verification.',
             'inputSchema'=>['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'version'=>['type'=>'string'],
               'offset'=>['type'=>'integer','minimum'=>0],'length'=>['type'=>'integer','minimum'=>1,'maximum'=>49152], 'transfer_id'=>['type'=>'string']],
               'required'=>['plugin','version']]],

            ['name' => 'list_releases', 'description' => 'List ZIP releases across all plugins, optionally filtered by plugin slug.', 'inputSchema' => $filter],
            ['name' => 'list_release_files', 'description' => 'List safe ZIP entry paths in a stored WordPress plugin release.',
             'inputSchema' => ['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'version'=>['type'=>'string']], 'required'=>['plugin','version']]],
            ['name'=>'read_release_file_base64','description'=>'Read source as base64 with integrity checks and trace ID; diagnostic alternative to plain-text reading.',
             'inputSchema'=>['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'version'=>['type'=>'string'],'file'=>['type'=>'string'],
             'offset'=>['type'=>'integer','minimum'=>0],'length'=>['type'=>'integer','minimum'=>1,'maximum'=>16384]],'required'=>['plugin','version','file']]],
            ['name' => 'read_release_file', 'description' => 'Read UTF-8 source code from a stored release in chunks (offset and length in bytes). PHP, JS, CSS, JSON, TXT only.',
             'inputSchema' => ['type'=>'object','properties'=>['plugin'=>['type'=>'string'],'version'=>['type'=>'string'],'file'=>['type'=>'string'],
                 'offset'=>['type'=>'integer','minimum'=>0], 'length'=>['type'=>'integer','minimum'=>1,'maximum'=>32768]], 'required'=>['plugin','version','file']]],
            ['name' => 'patch_release', 'description' => 'Create a new release from an existing ZIP: update WordPress Version header and replace one exact text fragment in one PHP, JS or CSS file. Source release remains intact. Use for small verified changes; not for arbitrary binary edits.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                'plugin' => ['type' => 'string'], 'from_version' => ['type' => 'string'], 'to_version' => ['type' => 'string'],
                'file' => ['type' => 'string', 'description' => 'Relative ZIP entry path, e.g. grafika-volby/grafika-volby.php'],
                'find' => ['type' => 'string', 'description' => 'Exact existing text, must occur exactly once'],
                'replace' => ['type' => 'string', 'description' => 'New text replacing find']],
                'required' => ['plugin', 'from_version', 'to_version', 'file', 'find', 'replace']]],
            ['name' => 'edit_release', 'description' => 'Create a new ZIP release from an existing release with multiple exact text replacements and new text files. Never overwrites an existing release.',
             'inputSchema' => ['type'=>'object','properties'=>[
                 'plugin'=>['type'=>'string'], 'from_version'=>['type'=>'string'], 'to_version'=>['type'=>'string'],
                 'changes'=>['type'=>'array','minItems'=>1,'maxItems'=>20,'items'=>['type'=>'object','properties'=>[
                     'file'=>['type'=>'string'], 'action'=>['type'=>'string','enum'=>['replace','create']],
                     'find'=>['type'=>'string'], 'replace'=>['type'=>'string'], 'content'=>['type'=>'string']],
                     'required'=>['file','action']]]],
                 'required'=>['plugin','from_version','to_version','changes']]],
            ['name' => 'upload_plugin_zip_url', 'description' => 'Import a WordPress plugin ZIP from a publicly accessible HTTPS URL. Private addresses and redirects are blocked. Max 15 MiB.',
             'inputSchema' => ['type'=>'object','properties'=>['url'=>['type'=>'string']], 'required'=>['url']]],
            ['name' => 'upload_plugin_zip_chunk', 'description' => 'Upload a WordPress plugin ZIP in Base64 chunks (max 48 KiB each); the final chunk validates and publishes it.',
             'inputSchema' => ['type'=>'object','properties'=>[
               'upload_id'=>['type'=>'string'], 'filename'=>['type'=>'string'],
               'chunk_index'=>['type'=>'integer'], 'total_chunks'=>['type'=>'integer'],
               'chunk_base64'=>['type'=>'string']],
               'required'=>['upload_id','filename','chunk_index','total_chunks','chunk_base64']]],
            ['name' => 'upload_plugin_zip_base64', 'description' => 'Upload any valid WordPress plugin ZIP (maximum 15 MB); returns HTTPS download URL and SHA256.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                'filename' => ['type' => 'string'], 'content_base64' => ['type' => 'string']],
                'required' => ['filename', 'content_base64']]]
        ]];
        j($response);
    }
    if ($name === 'tools/call') {
        $tool = $data['params']['name'] ?? '';
        $args = $data['params']['arguments'] ?? [];
        $result = null;
        if ($tool === 'preview_release_cleanup') {
            try {$result=releaseCleanupPreview($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'get_validation_rules') {
            try {$result=validationPolicy();}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'update_validation_rules') {
            try {$result=validationUpdate($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'inspect_release_validation') {
            try {$result=validationInspectRelease($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool==='fleet_plugin_manage'||$tool==='fleet_upgrade_bridge') {
            try{$result=$tool==='fleet_plugin_manage'?fmManage($args):fmBootstrap($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif (in_array($tool,['fleet_list_sites','fleet_site_diagnostics','fleet_diagnostics','list_connected_sites'],true)) {
            try {
                if ($tool==='fleet_site_diagnostics') $result=fleetSiteDiagnostics((string)($args['site_url']??''));
                elseif ($tool==='fleet_diagnostics'||($tool==='list_connected_sites'&&($args['include_diagnostics']??false)===true)) $result=fleetDiagnostics();
                else $result=connectedSitesReadOnly();
            } catch(Throwable $e){mcpFailure($response,'Fleet request failed: '.$e->getMessage());}
        } elseif ($tool === 'server_inventory') {
            $result=serverInventory();
        } elseif ($tool === 'preview_plugin_archive') {
            try {$result=cleanupPlan($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'archive_plugin_releases') {
            try {$result=archivePluginReleases($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'list_archived_operations') {
            try {$result=listArchiveOperations();}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'preview_archived_operation') {
            try {$result=archiveOperationPlan($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'restore_archived_operation') {
            try {$result=restoreArchivedOperation($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'delete_archived_operation') {
            try {$result=deleteArchivedOperation($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif (in_array($tool,['file_read','file_backup','file_write','file_restore','file_list','file_mkdir','file_create','file_write_chunk'],true)) {
            try {$result=odladeniBridgeCall($tool,$args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'list_server_files') {
            $result=['files'=>serverAllowedFiles(),'read_only'=>true];
        } elseif ($tool === 'read_server_file') {
            try { $result=serverReadFile($args); }
            catch(Throwable $e) { mcpFailure($response,$e->getMessage()); }
        } elseif ($tool === 'transfer_diagnostics') {
            try {$result=transferHistory($args);}catch(Throwable $e){mcpFailure($response,$e->getMessage());}
        } elseif ($tool === 'deploy_diagnostics') {
            $result=deployState();$result['trace_id']=bridgeLog($tool,['supported'=>$result['supported']]);
        } elseif (in_array($tool,['deploy_stage','deploy_stage_file','deploy_stage_patch','deploy_stage_url','deploy_apply','deploy_rollback'],true)) {
            try {$result=deployAction($tool,$args);$result['trace_id']=bridgeLog($tool,['status'=>$result['status']]);}
            catch(Throwable $e){$trace=bridgeLog($tool,['error'=>$e->getMessage()]);mcpFailure($response,'Deployment failed ['.$trace.']: '.$e->getMessage());}
        } elseif ($tool === 'connection_diagnostics') {
            $root=dirname(__DIR__).'/releases';
            $result=['status'=>'ready','version'=>'2.6.1','authenticated'=>true,'time_utc'=>gmdate('c'),
                'storage_readable'=>is_readable($root),'storage_writable'=>is_writable($root),
                'zip_extension'=>class_exists('ZipArchive'),'deployment'=>deployState()];
            $result['trace_id']=bridgeLog($tool,['status'=>'ready']);
        } elseif ($tool === 'connection_self_test') {
            $root=dirname(__DIR__).'/releases';$list=releaseList();
            $checks=['zip_extension'=>class_exists('ZipArchive'),'storage_readable'=>is_readable($root),
                'storage_writable'=>is_writable($root),'release_count'=>count($list),'deployment'=>deployState()];
            if($list && class_exists('ZipArchive')) {
                $z=new ZipArchive();$ok=$z->open($root.'/'.$list[0]['filename'],ZipArchive::RDONLY)===true;
                $checks['sample_zip_open']=$ok;
                if($ok){$checks['sample_zip_entries']=$z->numFiles;$z->close();}
            }
            $result=['status'=>in_array(false,$checks,true)?'degraded':'ready','checks'=>$checks];
            $result['trace_id']=bridgeLog($tool,$checks);
        } elseif ($tool === 'download_release_chunk') {
            $slug=$args['plugin']??null;$version=$args['version']??null;
            if(!is_string($slug)||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)
              ||!is_string($version)||!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D',$version))mcpFailure($response,'Invalid plugin/version');
            $found=null;
            foreach(releaseList($slug) as $r){if($r['version']===$version){$found=$r;break;}}
            if(!$found)mcpFailure($response,'Release not found');
            $transferId=$args['transfer_id']??null;
            if ($transferId!==null && (!is_string($transferId)||!preg_match('/^[a-zA-Z0-9_.-]{1,160}$/D',$transferId)))
                mcpFailure($response,'Invalid transfer_id');
            $operationId=$transferId??('download-'.$slug.'-'.$version);
            $offset=$args['offset']??0;$length=$args['length']??32768;
            if(!is_int($offset)||$offset<0||!is_int($length)||$length<1||$length>49152)mcpFailure($response,'Invalid chunk bounds');
            $path=dirname(__DIR__).'/releases/'.$found['filename'];$size=filesize($path);
            if($size===false||$offset>$size)mcpFailure($response,'Invalid offset');
            $f=@fopen($path,'rb');if(!$f)mcpFailure($response,'ZIP cannot be opened');
            if(fseek($f,$offset)!==0){fclose($f);mcpFailure($response,'ZIP seek failed');}
            $chunk=fread($f,$length);fclose($f);
            if($chunk===false)mcpFailure($response,'ZIP read failed');
            $next=$offset+strlen($chunk);
            $result=['plugin'=>$slug,'version'=>$version,'bytes'=>$size,'offset'=>$offset,
              'chunk_bytes'=>strlen($chunk),'next_offset'=>$next,'has_more'=>$next<$size,
              'sha256'=>$found['sha256'],'chunk_sha256'=>hash('sha256',$chunk),
              'content_base64'=>base64_encode($chunk)];
            $result['operation_id']=$operationId;
            $result['trace_id']=transferLog($operationId,'download','chunk_sent',[
                'plugin'=>$slug,'version'=>$version,'offset'=>$offset,'chunk_bytes'=>strlen($chunk),
                'next_offset'=>$next,'total_bytes'=>$size,'has_more'=>$next<$size,
                'chunk_sha256'=>$result['chunk_sha256'],'file_sha256'=>$result['sha256'],
                'server_status'=>($next<$size?'in_progress':'all_bytes_sent_unconfirmed')]);
        } elseif ($tool === 'server_status') {
            $result = ['status'=>'ready','service'=>'plugin-repository','version'=>'2.6.1'];
        } elseif ($tool === 'list_plugins') {
            $result = ['plugins'=>releasePlugins()];
        } elseif ($tool === 'list_releases' || $tool === 'get_latest_release') {
            $slug = $args['plugin'] ?? ($tool === 'get_latest_release' ? 'grafika-volby' : null);
            if ($slug !== null && (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug))) mcpFailure($response,'Invalid plugin slug');
            $releases = releaseList($slug);
            $result = $tool === 'list_releases' ? ['count'=>count($releases),'releases'=>$releases] : ['release'=>$releases[0]??null];
        } elseif ($tool === 'list_release_files' || $tool === 'read_release_file' || $tool === 'read_release_file_base64') {
            $slug = $args['plugin'] ?? null;
            $version = $args['version'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
                || !is_string($version) || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $version))
                mcpFailure($response, 'Invalid plugin or version');
            $source = null;
            foreach (releaseList($slug) as $release) {
                if ($release['version'] === $version) { $source = $release; break; }
            }
            if ($source === null) mcpFailure($response, 'Release not found');
            $zip = new ZipArchive();
            if ($zip->open(dirname(__DIR__) . '/releases/' . $source['filename'], ZipArchive::RDONLY) !== true)
                mcpFailure($response, 'Cannot open release ZIP');
            if ($zip->numFiles > 2000) { $zip->close(); mcpFailure($response, 'Too many ZIP entries'); }
            $entries = [];
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (!is_string($entry) || !str_starts_with($entry, $slug . '/') || str_contains($entry, '..')
                    || str_contains($entry, '\\') || str_contains($entry, "\0")) {
                    $zip->close(); mcpFailure($response, 'Unsafe ZIP entry');
                }
                if (!str_ends_with($entry, '/')) $entries[] = $entry;
            }
            if ($tool === 'list_release_files') {
                $result = ['plugin'=>$slug,'version'=>$version,'count'=>count($entries),'files'=>$entries];
            } else {
                $file = $args['file'] ?? null;
                if (!is_string($file) || !preg_match('~^'.preg_quote($slug,'~').'/[a-zA-Z0-9_./-]+\.(?:php|js|css|json|txt)$~iD', $file)
                    || str_contains($file, '..') || !in_array($file, $entries, true)) {
                    $zip->close(); mcpFailure($response, 'Invalid or missing source file');
                }
                $stat = $zip->statName($file);
                if (!is_array($stat) || ($stat['size'] ?? 0) > 2*1024*1024) {
                    $zip->close(); mcpFailure($response, 'Source file exceeds 2 MiB');
                }
                $offset = $args['offset'] ?? 0;
                $length = $args['length'] ?? 16384;
                if (!is_int($offset) || $offset < 0 || !is_int($length) || $length < 1 || $length > ($tool === 'read_release_file_base64' ? 16384 : 32768)) {
                    $zip->close(); mcpFailure($response, 'Invalid offset or length');
                }
                $contents = $zip->getFromName($file);
                if (!is_string($contents) || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
                    $zip->close(); mcpFailure($response, 'Source file is not valid UTF-8 text');
                }
                // Keep chunks valid UTF-8; byte offsets are reported for reliable pagination.
                $start = min($offset, strlen($contents));
                while ($start < strlen($contents) && (ord($contents[$start]) & 0xC0) === 0x80) $start++;
                $end = min(strlen($contents), $start + $length);
                while ($end < strlen($contents) && (ord($contents[$end]) & 0xC0) === 0x80) $end--;
                $result = ['plugin'=>$slug,'version'=>$version,'file'=>$file,'bytes'=>strlen($contents),
                    'offset'=>$start,'next_offset'=>$end,'has_more'=>$end<strlen($contents),
                    'content'=>substr($contents,$start,$end-$start)];
                if ($tool === 'read_release_file_base64') {
                    $chunk = substr($contents,$start,$end-$start);
                    $result = ['plugin'=>$slug,'version'=>$version,'file'=>$file,'bytes'=>strlen($contents),
                      'offset'=>$start,'next_offset'=>$end,'has_more'=>$end<strlen($contents),
                      'content_base64'=>base64_encode($chunk),'chunk_sha256'=>hash('sha256',$chunk),
                      'file_sha256'=>hash('sha256',$contents)];
                }
                $result['trace_id']=bridgeLog($tool,['plugin'=>$slug,'version'=>$version,'file'=>$file,
                    'offset'=>$start,'chunk_bytes'=>$end-$start,'status'=>'ok']);
            }
            $zip->close();
        } elseif ($tool === 'edit_release') {
            $slug = $args['plugin'] ?? null;
            $from = $args['from_version'] ?? null;
            $to = $args['to_version'] ?? null;
            $changes = $args['changes'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
                || !is_string($from) || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $from)
                || !is_string($to) || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $to)
                || version_compare($to, $from, '<=') || !is_array($changes)
                || count($changes) < 1 || count($changes) > 20 || !array_is_list($changes))
                mcpFailure($response, 'Invalid release parameters');
            $source = null;
            foreach (releaseList($slug) as $release) {
                if ($release['version'] === $from) $source = $release;
                if ($release['version'] === $to) mcpFailure($response, 'Target version already exists');
            }
            if ($source === null) mcpFailure($response, 'Source release not found');
            $edits = []; $payload = 0;
            foreach ($changes as $change) {
                if (!is_array($change)) mcpFailure($response, 'Invalid change');
                $file = $change['file'] ?? null;
                $action = $change['action'] ?? null;
                if (!is_string($file) || !preg_match('~^'.preg_quote($slug,'~').'/[a-zA-Z0-9_./-]+\.(?:php|js|css|json|txt)$~D', $file)
                    || str_contains($file, '..') || isset($edits[$file]) || !in_array($action, ['replace','create'], true))
                    mcpFailure($response, 'Invalid or duplicate change file');
                if ($action === 'replace') {
                    $find = $change['find'] ?? null;
                    $replacement = $change['replace'] ?? null;
                    if (!is_string($find) || strlen($find) < 4 || strlen($find) > 32768
                        || !is_string($replacement) || strlen($replacement) > 262144 || $find === $replacement)
                        mcpFailure($response, 'Invalid replacement');
                    $payload += strlen($find) + strlen($replacement);
                } else {
                    $content = $change['content'] ?? null;
                    if (!is_string($content) || strlen($content) > 262144 || $content === '')
                        mcpFailure($response, 'Invalid new file content');
                    $payload += strlen($content);
                }
                if ($payload > 1024*1024) mcpFailure($response, 'Changes exceed 1 MiB');
                $edits[$file] = $change;
            }
            $releaseDir = dirname(__DIR__) . '/releases';
            $src = new ZipArchive();
            if ($src->open($releaseDir . '/' . $source['filename'], ZipArchive::RDONLY) !== true)
                mcpFailure($response, 'Cannot open source ZIP');
            if ($src->numFiles > 2000) { $src->close(); mcpFailure($response, 'Too many ZIP entries'); }
            $entries = []; $mainName = null;
            for ($i=0; $i<$src->numFiles; $i++) {
                $entry = $src->getNameIndex($i);
                if (!is_string($entry) || !str_starts_with($entry, $slug.'/') || str_contains($entry,'..')
                    || str_contains($entry,'\\') || str_contains($entry,"\0") || strlen($entry)>512
                    || isset($entries[$entry])) { $src->close(); mcpFailure($response, 'Unsafe ZIP entry'); }
                $entries[$entry] = true;
                if (substr_count($entry,'/') === 1 && str_ends_with(strtolower($entry), '.php')) {
                    $data = $src->getFromIndex($i);
                    if (is_string($data) && preg_match('/^[ \t*#\/]*Plugin Name:\s*(.+?)\s*$/mi', $data)
                        && preg_match('/^([ \t*#\/]*Version:\s*)'.preg_quote($from,'/').'([ \t]*)$/mi', $data))
                        $mainName = $entry;
                }
            }
            if ($mainName === null) { $src->close(); mcpFailure($response, 'Main plugin PHP not found'); }
            foreach ($edits as $file=>$change) {
                if ($change['action']==='create' && isset($entries[$file])) {
                    $src->close(); mcpFailure($response, 'New file already exists: '.$file);
                }
                if ($change['action']==='replace' && !isset($entries[$file])) {
                    $src->close(); mcpFailure($response, 'Replacement file missing: '.$file);
                }
            }
            // Validate every replacement before creating an output archive.
            foreach ($edits as $file=>$change) {
                if ($change['action']!=='replace') continue;
                $data = $src->getFromName($file);
                if (!is_string($data) || strlen($data)>2*1024*1024 || substr_count($data,$change['find'])!==1) {
                    $src->close(); mcpFailure($response, 'Text must match exactly once in '.$file);
                }
            }
            $tmp = tempnam($releaseDir, '.edit-');
            if ($tmp===false) { $src->close(); mcpFailure($response, 'Cannot create temporary ZIP'); }
            $dst = new ZipArchive();
            if ($dst->open($tmp, ZipArchive::OVERWRITE)!==true) {
                $src->close(); @unlink($tmp); mcpFailure($response, 'Cannot write ZIP');
            }
            $ok=true;
            for ($i=0; $i<$src->numFiles; $i++) {
                $entry = $src->getNameIndex($i);
                if (str_ends_with($entry,'/')) { if (!$dst->addEmptyDir(rtrim($entry,'/'))) $ok=false; continue; }
                $bytes = $src->getFromIndex($i);
                if (!is_string($bytes)) { $ok=false; break; }
                if ($entry===$mainName) {
                    $count=0;
                    $bytes=preg_replace('/^([ \t*#\/]*Version:\s*)'.preg_quote($from,'/').'([ \t]*)$/mi', '${1}'.$to.'${2}', $bytes, 1, $count);
                    if ($bytes===null || $count!==1) { $ok=false; break; }
                }
                if (isset($edits[$entry])) {
                    $change=$edits[$entry];
                    $bytes=str_replace($change['find'],$change['replace'],$bytes);
                }
                if (!$dst->addFromString($entry,$bytes)) { $ok=false; break; }
            }
            if ($ok) foreach ($edits as $file=>$change) {
                if ($change['action']==='create' && !$dst->addFromString($file,$change['content'])) { $ok=false; break; }
            }
            $src->close();
            if (!$dst->close() || !$ok) { @unlink($tmp); mcpFailure($response, 'Failed to build ZIP'); }
            if (filesize($tmp)>15*1024*1024) { @unlink($tmp); mcpFailure($response, 'ZIP exceeds 15 MiB'); }
            $hash=hash_file('sha256',$tmp);
            $filename=$slug.'-v'.$to.'-'.substr($hash,0,12).'.zip';
            $target=$releaseDir.'/'.$filename;
            if (is_file($target) || !rename($tmp,$target)) {
                @unlink($tmp); mcpFailure($response, 'Target already exists or cannot be saved');
            }
            chmod($target,0644);
            $result=['plugin'=>$slug,'from_version'=>$from,'version'=>$to,'files_changed'=>array_keys($edits),
                'filename'=>$filename,'download_url'=>$origin.'/releases/'.$filename,
                'sha256'=>$hash,'bytes'=>filesize($target)];
        } elseif ($tool === 'clone_release' || $tool === 'patch_release') {
            $slug = $args['plugin'] ?? null;
            $from = $args['from_version'] ?? null;
            $to = $args['to_version'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
                || !is_string($from) || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $from)
                || !is_string($to) || !preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $to)
                || $from === $to) mcpFailure($response, 'Invalid plugin or version');
            $patch = $tool === 'patch_release';
            $patchFile = $args['file'] ?? null;
            $find = $args['find'] ?? null;
            $replace = $args['replace'] ?? null;
            if ($patch && (!is_string($patchFile) || !preg_match('~^'.preg_quote($slug, '~').'/[a-zA-Z0-9_./-]+\.(?:php|js|css)$~D', $patchFile)
                || str_contains($patchFile, '..') || !is_string($find) || !is_string($replace)
                || strlen($find) < 4 || strlen($find) > 8192 || strlen($replace) > 16384 || $find === $replace))
                mcpFailure($response, 'Invalid patch parameters');
            $source = null;
            foreach (releaseList($slug) as $release) {
                if ($release['version'] === $from) { $source = $release; break; }
            }
            if ($source === null) mcpFailure($response, 'Source release not found');
            foreach (releaseList($slug) as $release) {
                if ($release['version'] === $to) mcpFailure($response, 'Target version already exists');
            }
            $releaseDir = dirname(__DIR__) . '/releases';
            $src = new ZipArchive();
            if ($src->open($releaseDir . '/' . $source['filename'], ZipArchive::RDONLY) !== true)
                mcpFailure($response, 'Cannot open source ZIP');
            $mainName = null;
            for ($i=0; $i<$src->numFiles; $i++) {
                $entry = $src->getNameIndex($i);
                if (!is_string($entry) || !str_starts_with($entry, $slug . '/') || str_contains($entry, '..') || str_contains($entry, '\\')) {
                    $src->close(); mcpFailure($response, 'Invalid ZIP structure');
                }
                if (substr_count($entry, '/') === 1 && str_ends_with(strtolower($entry), '.php')) {
                    $contents = $src->getFromIndex($i);
                    if (is_string($contents) && preg_match('/^[ \t*#\/]*Plugin Name:\s*(.+?)\s*$/mi', $contents)
                        && preg_match('/^([ \t*#\/]*Version:\s*)'.preg_quote($from, '/').'([ \t]*)$/mi', $contents)) {
                        $mainName = $entry;
                        break;
                    }
                }
            }
            if ($mainName === null) { $src->close(); mcpFailure($response, 'Main plugin PHP with matching version not found'); }
            $tmp = tempnam($releaseDir, '.clone-');
            if ($tmp === false) { $src->close(); mcpFailure($response, 'Cannot create temporary file'); }
            $dst = new ZipArchive();
            if ($dst->open($tmp, ZipArchive::OVERWRITE) !== true) {
                $src->close(); @unlink($tmp); mcpFailure($response, 'Cannot create ZIP');
            }
            $patchApplied = 0;
            $ok = true;
            for ($i=0; $i<$src->numFiles; $i++) {
                $entry = $src->getNameIndex($i);
                if (str_ends_with($entry, '/')) {
                    if (!$dst->addEmptyDir(rtrim($entry, '/'))) $ok=false;
                    continue;
                }
                $bytes = $src->getFromIndex($i);
                if (!is_string($bytes)) { $ok=false; break; }
                if ($entry === $mainName) {
                    $count = 0;
                    $bytes = preg_replace('/^([ \t*#\/]*Version:\s*)'.preg_quote($from, '/').'([ \t]*)$/mi', '${1}'.$to.'${2}', $bytes, 1, $count);
                    if ($count !== 1 || $bytes === null) { $ok=false; break; }
                }
                if ($patch && $entry === $patchFile) {
                    if (strlen($bytes) > 2*1024*1024 || substr_count($bytes, $find) !== 1) { $ok=false; break; }
                    $bytes = str_replace($find, $replace, $bytes);
                    $patchApplied++;
                }
                if (!$dst->addFromString($entry, $bytes)) { $ok=false; break; }
            }
            $src->close();
            if (!$dst->close() || !$ok || ($patch && $patchApplied !== 1)) { @unlink($tmp); mcpFailure($response, 'Failed to build new ZIP or patch text not found exactly once'); }
            $hash = hash_file('sha256', $tmp);
            $filename = $slug.'-v'.$to.'-'.substr($hash,0,12).'.zip';
            $target = $releaseDir.'/'.$filename;
            if (is_file($target) || !rename($tmp, $target)) { @unlink($tmp); mcpFailure($response, 'Target already exists or cannot be saved'); }
            chmod($target, 0644);
            $result = ['plugin'=>$slug,'from_version'=>$from,'version'=>$to,'filename'=>$filename,
                'download_url'=>$origin.'/releases/'.$filename,'sha256'=>$hash,'bytes'=>filesize($target),'patched_file'=>$patch ? $patchFile : null];
        } elseif ($tool === 'upload_plugin_zip_base64' || $tool === 'upload_plugin_zip_chunk' || $tool === 'upload_plugin_zip_url') {
            if ($tool === 'upload_plugin_zip_url') {
                $url=$args['url']??null;
                if (!is_string($url) || strlen($url)>2048) mcpFailure($response,'Invalid URL');
                $parsed=parse_url($url);
                if (!is_array($parsed) || strtolower($parsed['scheme']??'')!=='https'
                    || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])
                    || isset($parsed['port']) || isset($parsed['fragment']))
                    mcpFailure($response,'HTTPS URL required');
                $host=strtolower($parsed['host']);
                if (!preg_match('/^[a-z0-9.-]+$/D',$host) || filter_var($host,FILTER_VALIDATE_IP))
                    mcpFailure($response,'Public hostname required');
                $ips=gethostbynamel($host);
                if (!$ips) mcpFailure($response,'Hostname cannot be resolved');
                foreach ($ips as $ip) {
                    if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                        mcpFailure($response,'Private or reserved address rejected');
                }
                if (!function_exists('curl_init')) mcpFailure($response,'cURL unavailable');
                $curl=curl_init($url); $download='';
                curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,
                    CURLOPT_TIMEOUT=>35,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],
                    CURLOPT_WRITEFUNCTION=>static function($ch,$data) use (&$download) {
                        if (strlen($download)+strlen($data)>15*1024*1024) return 0;
                        $download.=$data; return strlen($data);
                    }]);
                $success=curl_exec($curl);
                $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
                curl_close($curl);
                if (!$success || $status!==200 || strlen($download)<100)
                    mcpFailure($response,'Cannot download ZIP (HTTP '.$status.')');
                $args['content_base64']=base64_encode($download);
                unset($download);
            }
            if ($tool === 'upload_plugin_zip_chunk') {
                $uid=$args['upload_id']??null; $fn=$args['filename']??null;
                $ix=$args['chunk_index']??null; $count=$args['total_chunks']??null;
                $encoded=$args['chunk_base64']??null;
                // Accept a bounded, filesystem-safe client identifier; use the same ID for history lookup.
                // Never use unvalidated user input as a directory component or diagnostic operation ID.
                $validUid=is_string($uid) && (bool)preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$uid);
                $uploadOperationId=$validUid?'upload-'.$uid:null;
                $rejectUpload=static function(string $reason, string $code) use (&$response,$uploadOperationId,$fn,$ix,$count): void {
                    if ($uploadOperationId!==null) transferLog($uploadOperationId,'upload','rejected',[
                        'reason_code'=>$code,'filename'=>is_string($fn)?substr($fn,0,150):null,
                        'chunk_index'=>is_int($ix)?$ix:null,'total_chunks'=>is_int($count)?$count:null]);
                    mcpFailure($response,$reason);
                };
                if (!$validUid || !is_string($fn) || !preg_match('/^[a-zA-Z0-9_.-]{1,150}\.zip$/D',$fn)
                    || !is_int($ix) || !is_int($count) || $count<1 || $count>4096 || $ix<0 || $ix>=$count
                    || !is_string($encoded) || strlen($encoded)>65536)
                    $rejectUpload('Invalid chunk parameters','invalid_parameters');
                $part=base64_decode($encoded,true);
                if (!is_string($part) || strlen($part)<1 || strlen($part)>49152)
                    $rejectUpload('Invalid chunk data','invalid_base64_or_size');
                $stage=$dir.'/zip-upload-'.$uid;
                if (!is_dir($stage) && !mkdir($stage,0700) && !is_dir($stage))
                    $rejectUpload('Cannot create upload staging','staging_create_failed');
                $metaPath=$stage.'/meta.json';
                $meta=fileget($metaPath);
                if ($meta===[]) { $meta=['filename'=>$fn,'total'=>$count,'created'=>time()]; fileset($metaPath,$meta); }
                if (($meta['filename']??'')!==$fn || ($meta['total']??0)!==$count
                    || ($meta['created']??0)<time()-3600) $rejectUpload('Upload session mismatch or expired','session_mismatch_or_expired');
                $partPath=$stage.'/'.sprintf('%04d',$ix).'.part';
                if (is_file($partPath)) {
                    if (!hash_equals(hash_file('sha256',$partPath),hash('sha256',$part)))
                        $rejectUpload('Conflicting chunk','conflicting_chunk');
                } elseif (file_put_contents($partPath,$part,LOCK_EX)===false)
                    $rejectUpload('Cannot store chunk','chunk_write_failed');
                $uploadTrace=transferLog($uploadOperationId,'upload','chunk_received',[
                    'filename'=>$fn,'chunk_index'=>$ix,'total_chunks'=>$count,
                    'chunk_bytes'=>strlen($part),'chunk_sha256'=>hash('sha256',$part)]);
                $parts=glob($stage.'/*.part')?:[];
                if (count($parts)<$count) {
                    $result=['upload_id'=>$uid,'operation_id'=>$uploadOperationId,'trace_id'=>$uploadTrace,'received'=>count($parts),'total'=>$count,'complete'=>false];
                    $response['result']=['content'=>[['type'=>'text','text'=>json_encode($result)]]];
                    j($response);
                }
                $assembled='';
                for ($i=0;$i<$count;$i++) {
                    $pathPart=$stage.'/'.sprintf('%04d',$i).'.part';
                    if (!is_file($pathPart)) $rejectUpload('Missing chunk','missing_chunk');
                    $assembled.=(string)file_get_contents($pathPart);
                    if (strlen($assembled)>15*1024*1024) $rejectUpload('ZIP too large','zip_too_large');
                }
                transferLog($uploadOperationId,'upload','chunks_assembled',[
                    'total_chunks'=>$count,'total_bytes'=>strlen($assembled),
                    'assembled_sha256'=>hash('sha256',$assembled),
                    'status'=>'assembled_pending_validation']);
                $args['content_base64']=base64_encode($assembled);
                unset($assembled);
                foreach ($parts as $pathPart) @unlink($pathPart);
                @unlink($metaPath); @rmdir($stage);
            }
            $b64 = $args['content_base64'] ?? '';
            if (!is_string($b64) || strlen($b64)>21*1024*1024) mcpFailure($response,'ZIP payload too large');
            $bytes = base64_decode($b64,true);
            if ($bytes===false || strlen($bytes)>15*1024*1024 || strlen($bytes)<100) mcpFailure($response,'Invalid ZIP bytes');
            $tmp=tempnam(sys_get_temp_dir(),'wp-');
            if ($tmp===false || file_put_contents($tmp,$bytes)===false) mcpFailure($response,'Temporary storage failed');
            $zip=new ZipArchive();
            $valid=$zip->open($tmp,ZipArchive::RDONLY)===true;
            $slug=null; $version=null; $pluginName=null;
            if ($valid) {
                $roots=[]; $phpFiles=[];
                $valid=$zip->numFiles>0 && $zip->numFiles<=2000;
                for ($i=0;$valid && $i<$zip->numFiles;$i++) {
                    $entry=$zip->getNameIndex($i);
                    $stat=$zip->statIndex($i);
                    if (!is_string($entry) || strlen($entry)>512 || str_contains($entry,'..')
                        || str_contains($entry,'\\') || str_starts_with($entry,'/')
                        || preg_match('/[\x00-\x1f]/',$entry)
                        || preg_match('~(^|/)\.~',$entry)
                        || !preg_match('~^([a-z0-9]+(?:-[a-z0-9]+)*)/~',$entry,$rootMatch)) {$valid=false;break;}
                    $roots[$rootMatch[1]]=true;
                    if (isset($stat['external_attributes']) && ((($stat['external_attributes'] >> 16)&0170000)===0120000)) {$valid=false;break;}
                    if (str_ends_with(strtolower($entry),'.php') && substr_count($entry,'/')===1) $phpFiles[]=$entry;
                }
                if ($valid && count($roots)===1) {
                    $slug=array_key_first($roots);
                    foreach ($phpFiles as $entry) {
                        $main=$zip->getFromName($entry);
                        if (!is_string($main) || strlen($main)>2*1024*1024) continue;
                        if (preg_match('/^[ \t*#\/]*Plugin Name:\s*(.+?)\s*$/mi',$main,$nm)
                            && preg_match('/^[ \t*#\/]*Version:\s*([0-9]+(?:\.[0-9]+)*(?:[-.][a-zA-Z0-9]+)*)\s*$/mi',$main,$vm)) {
                            $pluginName=trim($nm[1]); $version=$vm[1]; break;
                        }
                    }
                }
                $zip->close();
                $valid=$valid && count($roots)===1 && $pluginName!==null && $version!==null;
            }
            if (!$valid) {@unlink($tmp);mcpFailure($response,'Invalid WordPress plugin ZIP: one directory and root PHP Plugin Name/Version headers required');}
            $hash=hash_file('sha256',$tmp);
            $filename=$slug.'-v'.$version.'-'.substr($hash,0,12).'.zip';
            $target=dirname(__DIR__).'/releases/'.$filename;
            if (!is_file($target) && !rename($tmp,$target)) {@unlink($tmp);mcpFailure($response,'Storage failed');}
            if (is_file($tmp)) unlink($tmp);
            $result=['plugin'=>$slug,'name'=>$pluginName,'version'=>$version,'sha256'=>$hash,'download_url'=>$origin.'/releases/'.$filename];
        } else {
            $response['result'] = ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Unknown tool']]];
            j($response);
        }
        $response['result'] = ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES)]]];
        j($response);
    }
    $response['error'] = ['code' => -32601, 'message' => 'Method not found'];
    j($response);
}
err('not_found', 404);
