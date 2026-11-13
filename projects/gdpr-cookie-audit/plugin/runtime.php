<?php
/** Private, fixed-distribution browser runtime bootstrap. No public installation action. */
function gca_runtime_distribution(){return [
 'node_url'=>'https://nodejs.org/dist/v22.23.5/node-v22.23.5-linux-x64.tar.gz',
 'node_sha256'=>'c54118104acc524633b5abe106bbf44a53126ec8b4e5fa105cb92b5d7de8a1a1',
 'node_folder'=>'node-v22.23.5-linux-x64','playwright_version'=>'1.65.0',
];}
function gca_runtime_private_base($wpRoot,$documentRoot){
 $base=dirname(rtrim($wpRoot,'/')).'/.gdpr-cookie-audit-runtime-v2';$doc=rtrim($documentRoot,'/');
 return str_starts_with($base.'/', $doc.'/')?null:$base;
}
function gca_runtime_base(){
 $root=realpath(ABSPATH);$documentRoot=realpath($_SERVER['DOCUMENT_ROOT']??ABSPATH);
 if(!$root||!$documentRoot)throw new RuntimeException('Hosting paths cannot be verified.');
 $base=gca_runtime_private_base($root,$documentRoot);
 if($base&&file_exists($base)){$resolved=realpath($base);if(!$resolved||str_starts_with($resolved.'/',rtrim($documentRoot,'/').'/'))$base=null;}
 if(!$base)throw new RuntimeException('Private runtime storage unavailable.');return $base;
}
function gca_runtime_public_status($status){
 if(!empty($status['detail'])){
  $detail=$status['detail'];
  if(empty($status['missing_libraries'])&&preg_match_all('~\blib[A-Za-z0-9_.+-]{1,70}\.so(?:\.[0-9]+)*~',$detail,$m))$status['missing_libraries']=array_slice(array_values(array_unique($m[0])),0,12);
  $flags=[];foreach(['download'=>'~download|Download~','launch'=>'~launch|Launch~','certificate'=>'~certificate|CERT_|TLS~','dns'=>'~ENOTFOUND|EAI_AGAIN|getaddrinfo~','permission'=>'~EACCES|Permission denied|Operation not permitted~','disk'=>'~ENOSPC|No space left~','timeout'=>'~timed out|ETIMEDOUT|SIGTERM~','sandbox'=>'~sandbox|namespace~'] as $flag=>$pattern)if(preg_match($pattern,$detail))$flags[]=$flag;
  $status['failure_flags']=$flags;
  if(preg_match('~server returned code\s*(\d{3})~i',$detail,$m))$status['download_http_status']=(int)$m[1];
 }

 if(($status['state']??'')==='installing' && !empty($status['updated_at']) && strtotime($status['updated_at'])<time()-900){$status['state']='blocked';$status['reason']='installation_timeout';}
 return array_intersect_key($status,array_flip(['state','reason','started_at','updated_at','sandbox','version','missing_library','node_present','archive_bytes','library_present','missing_libraries','failure_flags','download_http_status','platform','installer_error_code','installer_tool','engine','sandbox_mode','content_processes']));
}
function gca_runtime_status(){
 try{$file=gca_runtime_base().'/status.json';}catch(Throwable $e){return ['state'=>'blocked','reason'=>'private_storage_unavailable'];}
 $s=is_file($file)?json_decode((string)file_get_contents($file),true):null;
 if(is_array($s)){ $base=dirname($file);$plan=gca_runtime_distribution();$s['node_present']=is_file($base.'/'.$plan['node_folder'].'/bin/node');$s['archive_bytes']=is_file($base.'/node.tar.gz')?filesize($base.'/node.tar.gz'):0;$s['library_present']=is_file($base.'/node_modules/playwright-core/package.json'); }
 return gca_runtime_public_status(is_array($s)?$s:(function_exists('get_option')?get_option('gca_runtime_bootstrap',['state'=>'not_installed']):['state'=>'not_installed']));
}
function gca_runtime_write_status($status){
 $status['updated_at']=gmdate('c');$path=gca_runtime_base().'/status.json';
 if(file_put_contents($path.'.tmp',json_encode($status,JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('Private runtime status is not writable.');
 chmod($path.'.tmp',0600);rename($path.'.tmp',$path);
}
function gca_runtime_fixed_process($argv,$cwd){
 $pipes=[];$proc=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
 if(!is_resource($proc))throw new RuntimeException('Process could not start.');
 fclose($pipes[0]);$out=stream_get_contents($pipes[1],8192);$err=stream_get_contents($pipes[2],8192);fclose($pipes[1]);fclose($pipes[2]);
 return ['code'=>proc_close($proc),'stdout'=>$out,'stderr'=>$err];
}
function gca_runtime_bootstrap(){
 if(parse_url(home_url(),PHP_URL_HOST)!=='gdpr-cookies.cz')return;
 if(PHP_OS_FAMILY!=='Linux'||!in_array(php_uname('m'),['x86_64','amd64'],true)){update_option('gca_runtime_bootstrap',['state'=>'blocked','reason'=>'platform_unsupported'],false);return;}
 if(!function_exists('proc_open')){update_option('gca_runtime_bootstrap',['state'=>'blocked','reason'=>'process_unavailable'],false);return;}
 $base=null;
 try{
  $base=gca_runtime_base();
  if(!is_dir($base) && !mkdir($base,0700,true))throw new RuntimeException('Private runtime directory cannot be created.');
  chmod($base,0700);$status=gca_runtime_status();if(in_array($status['state']??'',['installing','ready'],true))return;
  gca_runtime_write_status(['state'=>'installing','reason'=>'node_download','started_at'=>gmdate('c')]);
  @set_time_limit(120);$plan=gca_runtime_distribution();$archive=$base.'/node.tar.gz';$node=$base.'/'.$plan['node_folder'].'/bin/node';
  if(!is_file($node)){
   $legacy=dirname(rtrim(ABSPATH,'/')).'/.gdpr-cookie-audit-runtime/node.tar.gz';
   $reused=is_file($legacy)&&hash_equals($plan['node_sha256'],hash_file('sha256',$legacy))&&copy($legacy,$archive);
   $response=$reused?['response'=>['code'=>200]]:wp_remote_get($plan['node_url'],['timeout'=>85,'redirection'=>0,'stream'=>true,'filename'=>$archive,'limit_response_size'=>80000000]);
   if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuntimeException('Official Node download failed.');
   if(!hash_equals($plan['node_sha256'],hash_file('sha256',$archive)))throw new RuntimeException('Node integrity verification failed.');
   gca_runtime_write_status(['state'=>'installing','reason'=>'node_extract','started_at'=>gmdate('c')]);
   $tar=gca_runtime_fixed_process(['/bin/tar','--extract','--gzip','--file',$archive,'--directory',$base,'--no-same-owner'],$base);
   if($tar['code']!==0)throw new RuntimeException('Node archive extraction failed: '.$tar['stderr']);
   chmod($node,0700);unlink($archive);
  }
  gca_runtime_write_status(['state'=>'installing','reason'=>'node_probe','started_at'=>gmdate('c')]);
  $test=gca_runtime_fixed_process([$node,'--version'],$base);
  if($test['code']!==0)throw new RuntimeException('Node executable cannot run: '.$test['stderr']);
  $result=gca_runtime_fixed_process([$node,__DIR__.'/runtime/setup.cjs','--detach',$base,$plan['node_folder'],$plan['playwright_version']],$base);
  if($result['code']!==0)throw new RuntimeException('Runtime setup could not start: '.$result['stderr']);
 }catch(Throwable $e){
  $state=['state'=>'blocked','reason'=>str_contains($e->getMessage(),'Node download')?'node_download_failed':(str_contains($e->getMessage(),'integrity')?'integrity_failed':(str_contains($e->getMessage(),'executable')?'node_execution_failed':'bootstrap_failed')),'detail'=>$e->getMessage()];
  if($base && is_dir($base))gca_runtime_write_status($state);update_option('gca_runtime_bootstrap',$state,false);
 }
}
