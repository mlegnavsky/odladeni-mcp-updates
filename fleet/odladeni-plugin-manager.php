<?php
/** Signed plugin management. No WordPress user credentials are transmitted. */
if (!defined('ABSPATH')) exit;
// Apply the same repository integrity checks to native and cron updates.
add_filter('upgrader_pre_download','pi_pm_repository_download',30,4);
function pi_pm_repository_download($reply,$url,$owner,$extra){
 if(parse_url((string)$url,PHP_URL_HOST)!=='plaginy.odladeni.cz')return $reply;
 try{
  $file=(string)($extra['plugin']??'');
  if(!$file&&count((array)($extra['plugins']??[]))===1)$file=(string)$extra['plugins'][0];
  if(!pi_pm_valid_file($file))throw new RuntimeException('Repository update requires an exact plugin basename');
  $package=pi_pm_package(['source'=>'odladeni','slug'=>explode('/',$file)[0]]);
  if($package['url']!==$url)throw new RuntimeException('Update manifest changed; check for updates again');
  return pi_pm_download($package,$file);
 }catch(Throwable $e){return pi_pm_error('repository_integrity',$e->getMessage(),500);}
}
add_action('rest_api_init',static function(){
 register_rest_route('odladeni-bridge/v2','/plugin-manage',[
  'methods'=>'POST','permission_callback'=>'pi_pm_permission',
  'callback'=>static function($r){return pi_pm_execute((array)$r->get_json_params());}
 ]);
});
function pi_pm_error($code,$message,$status=409){return new WP_Error($code,$message,['status'=>$status]);}
function pi_pm_permission($r){
 if(!is_ssl())return false;
 $secret=(string)get_option('odladeni_bridge_shared_key','');
 $ts=(string)$r->get_header('x-odladeni-time');$nonce=(string)$r->get_header('x-odladeni-nonce');$sig=(string)$r->get_header('x-odladeni-signature');
 if(!preg_match('/^[a-f0-9]{64}$/D',$secret)||!ctype_digit($ts)||abs(time()-(int)$ts)>120||!preg_match('/^[a-f0-9]{24}$/D',$nonce)||strlen($r->get_body())>16384)return false;
 $expected=hash_hmac('sha256',"POST\n/wp-json/odladeni-bridge/v2/plugin-manage\n".$ts."\n".$nonce."\n".hash('sha256',$r->get_body()),$secret);
 if(!hash_equals($expected,$sig))return false;
 // Unique option insertion is atomic, unlike a get/set transient replay check.
 $key='pi_pm_nonce_'.hash('sha256',$nonce);
 if(!add_option($key,time()+180,'',false))return false;
 $nonces=(array)get_option('pi_pm_nonces',[]);$nonces[$key]=time()+180;
 foreach($nonces as $k=>$expiry)if($expiry<time()){delete_option($k);unset($nonces[$k]);}
 update_option('pi_pm_nonces',$nonces,false);
 return true;
}
function pi_pm_valid_file($file){
 return is_string($file)&&strlen($file)<240&&preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9._-]*(?:/[a-zA-Z0-9_-][a-zA-Z0-9._-]*)*\.php$~D',$file)
  &&!str_contains($file,'..');
}
function pi_pm_backup($file){
 if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP support required for backup');
 $parent=dirname(rtrim(ABSPATH,'/\\'));
 $dir=$parent.'/.odladeni-backups-'.substr(hash('sha256',ABSPATH),0,12);
 if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Private backup directory unavailable');
 $root=realpath(ABSPATH);$dest=realpath($dir);
 if(!$dest||!$root||str_starts_with($dest.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Backup must be outside WordPress root');
 $base=str_contains($file,'/')?explode('/',$file)[0]:$file;
 $path=WP_PLUGIN_DIR.'/'.$base;
 if(is_link($path))throw new RuntimeException('Symlink plugin cannot be backed up');
 $id=bin2hex(random_bytes(16));$zip=new ZipArchive();
 if($zip->open($dir.'/'.$id.'.zip',ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create backup');
 $total=0;
 try{
  $files=is_dir($path)?new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)):[$path];
  foreach($files as $entry){
   $name=(string)$entry;
   if(is_link($name))throw new RuntimeException('Symlink plugin cannot be backed up');
   if(!is_file($name))continue;
   $total+=filesize($name);if($total>67108864)throw new RuntimeException('Plugin backup exceeds 64 MiB');
   if(!$zip->addFile($name,substr($name,strlen(WP_PLUGIN_DIR)+1)))throw new RuntimeException('Backup read failed');
  }
  if(!$zip->close())throw new RuntimeException('Backup finalization failed');
 }catch(Throwable $e){@$zip->close();@unlink($dir.'/'.$id.'.zip');throw $e;}
 chmod($dir.'/'.$id.'.zip',0600);
 $meta=['plugin'=>$file,'sha256'=>hash_file('sha256',$dir.'/'.$id.'.zip'),'created_at'=>gmdate('c')];
 if(file_put_contents($dir.'/'.$id.'.json',wp_json_encode($meta),LOCK_EX)===false)throw new RuntimeException('Backup metadata failed');
 chmod($dir.'/'.$id.'.json',0600);
 return $id;
}
function pi_pm_package($a){
 $source=$a['source']??(($a['action']??'')==='update'?'installed':'wordpress');$slug=(string)($a['slug']??explode('/',(string)($a['plugin']??''))[0]);
 if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug))throw new RuntimeException('Invalid package slug');
 $sha='';$version='';
 if($source==='installed'){
  require_once ABSPATH.'wp-includes/update.php';
  delete_transient('pi_odladeni_'.md5($slug));delete_transient('pi_odladeni_v245_'.md5($slug));
  delete_site_transient('update_plugins');wp_update_plugins();
  $updates=get_site_transient('update_plugins');$update=is_object($updates)?($updates->response[$a['plugin']]??null):null;
  if(!is_object($update)||empty($update->package))throw new RuntimeException('No WordPress update available');
  $url=(string)$update->package;$version=(string)$update->new_version;
  if(parse_url($url,PHP_URL_HOST)==='plaginy.odladeni.cz')return pi_pm_package(array_merge($a,['source'=>'odladeni','slug'=>$slug]));
 }elseif($source==='wordpress'){
  require_once ABSPATH.'wp-admin/includes/plugin-install.php';
  $info=plugins_api('plugin_information',['slug'=>$slug,'fields'=>['sections'=>false]]);
  if(is_wp_error($info))throw new RuntimeException($info->get_error_message());
  $url=(string)($info->download_link??'');$version=(string)($info->version??'');
  if(parse_url($url,PHP_URL_HOST)!=='downloads.wordpress.org')throw new RuntimeException('Invalid WordPress package origin');
 }elseif($source==='odladeni'){
  $response=wp_safe_remote_get('https://plaginy.odladeni.cz/api/manifest.php?plugin='.rawurlencode($slug),['timeout'=>15,'redirection'=>0,'limit_response_size'=>32000]);
  if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuntimeException('Manifest unavailable');
  $d=json_decode(wp_remote_retrieve_body($response),true);
  if(!is_array($d)||($d['slug']??'')!==$slug)throw new RuntimeException('Manifest identity mismatch');
  $url=(string)($d['download_url']??'');$sha=(string)($d['sha256']??'');$version=(string)($d['version']??'');
  if(parse_url($url,PHP_URL_HOST)!=='plaginy.odladeni.cz'||!str_starts_with((string)parse_url($url,PHP_URL_PATH),'/releases/'))throw new RuntimeException('Invalid Odladeni package origin');
 }elseif($source==='zip'){$url=(string)($a['url']??'');$sha=(string)($a['sha256']??'');$version=(string)($a['version']??'');}
 else throw new RuntimeException('Unknown package source');
 $u=parse_url($url);
 if(!is_array($u)||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||(isset($u['port'])&&$u['port']!==443))throw new RuntimeException('HTTPS package required');
 if(!in_array($source,['wordpress','installed'],true)&&!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new RuntimeException('Package SHA-256 required');
 return ['url'=>$url,'sha256'=>$sha,'version'=>$version,'slug'=>$slug];
}
function pi_pm_download($package,$file){
 if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP extension required');
 $tmp=wp_tempnam('odladeni-plugin.zip');if(!$tmp)throw new RuntimeException('Temporary file unavailable');
 try{
  $response=wp_safe_remote_get($package['url'],['timeout'=>90,'redirection'=>0,'sslverify'=>true,'stream'=>true,'filename'=>$tmp,'limit_response_size'=>26214401]);
  if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuntimeException('Package download failed');
  if(filesize($tmp)>26214400||filesize($tmp)<22)throw new RuntimeException('Invalid package size');
  if($package['sha256']!==''&&!hash_equals($package['sha256'],hash_file('sha256',$tmp)))throw new RuntimeException('Package SHA-256 mismatch');
  $z=new ZipArchive();if($z->open($tmp)!==true)throw new RuntimeException('Invalid ZIP');
  try{
   if($z->numFiles>10000)throw new RuntimeException('Too many ZIP entries');
   $size=0;
   for($i=0;$i<$z->numFiles;$i++){
    $s=$z->statIndex($i);$name=$s['name'];$size+=$s['size'];
    if(!str_starts_with($name,$package['slug'].'/')||str_contains($name,'..')||str_contains($name,'\\')||str_contains($name,"\0")||$size>134217728)throw new RuntimeException('Unsafe ZIP structure');
    $opsys=0;$attr=0;$z->getExternalAttributesIndex($i,$opsys,$attr);
    if((($attr>>16)&0170000)===0120000)throw new RuntimeException('ZIP symlinks forbidden');
   }
   $header=$z->getFromName($file,8192);if(!is_string($header)||!preg_match('/^[ \t\/*#@]*Plugin Name\s*:/mi',$header))throw new RuntimeException('Main plugin identity missing');
   if($package['version']!==''&&(!preg_match('/^[ \t\/*#@]*Version\s*:\s*([^\r\n]+)/mi',$header,$m)||trim($m[1])!==$package['version']))throw new RuntimeException('Package version mismatch');
  }finally{$z->close();}
  return $tmp;
 }catch(Throwable $e){@unlink($tmp);throw $e;}
}
function pi_pm_execute($a){
 $action=$a['action']??'';$file=$a['plugin']??'';$id=$a['request_id']??'';
 if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$id)||!pi_pm_valid_file($file)||!in_array($action,['activate','deactivate','auto_update','install','update','delete'],true))return pi_pm_error('invalid_request','Invalid action, plugin or request id',400);
 if(is_multisite())return pi_pm_error('multisite_unsupported','Remote mutations are disabled on multisite');
 if(in_array($action,['deactivate','delete'],true)&&$file==='prvotni-instalace/prvotni-instalace.php')return pi_pm_error('bridge_protected','Hlavni instalace is protected');
 if($action==='auto_update'&&!is_bool($a['enabled']??null))return pi_pm_error('invalid_enabled','Boolean enabled required',400);
 if(in_array($action,['install','update','delete'],true)&&function_exists('wp_is_file_mod_allowed')&&!wp_is_file_mod_allowed('odladeni_plugin_manager'))return pi_pm_error('file_mods_disabled','WordPress file modifications are disabled');
 if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';
 $receipt='pi_pm_request_'.hash('sha256',$id);$hash=hash('sha256',wp_json_encode($a));
 $previous=get_option($receipt);
 if(is_array($previous))return ($previous['hash']??'')===$hash?($previous['result']??pi_pm_error('in_progress','Operation already started; inspect diagnostics')):pi_pm_error('request_conflict','Request id used with different arguments');
 $lock=@fopen(sys_get_temp_dir().'/odladeni-'.hash('sha256',ABSPATH).'.lock','c');
 if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return pi_pm_error('busy','Another plugin operation is in progress');}
 $result=null;
 try{
  if(!add_option($receipt,['hash'=>$hash,'started_at'=>gmdate('c')],'',false))return pi_pm_error('in_progress','Operation already started');
  $plugins=get_plugins();$exists=isset($plugins[$file]);$active=$exists&&is_plugin_active($file);
  if($action!=='install'&&!$exists)throw new RuntimeException('Plugin is not installed');
  if($action==='activate'){$r=activate_plugin($file,'',false,false);if(is_wp_error($r))throw new RuntimeException($r->get_error_message());}
  elseif($action==='deactivate')deactivate_plugins($file,false,false);
  elseif($action==='auto_update'){
   $values=array_values(array_diff((array)get_site_option('auto_update_plugins',[]),[$file]));
   if($a['enabled'])$values[]=$file;
   update_site_option('auto_update_plugins',$values);
  }elseif($action==='delete'){
   if($active)throw new RuntimeException('Deactivate plugin before deleting');
   $prefix=str_contains($file,'/')?explode('/',$file)[0].'/':$file;
   foreach($plugins as $other=>$meta)if(($other===$file||str_starts_with($other,$prefix))&&is_plugin_active($other))throw new RuntimeException('Another plugin in the directory is active');
   if(($a['confirm_delete']??false)!==true)throw new RuntimeException('Explicit confirm_delete required');
   $backup=pi_pm_backup($file);
   require_once ABSPATH.'wp-admin/includes/file.php';
   if(!WP_Filesystem())throw new RuntimeException('WordPress filesystem unavailable');
   global $wp_filesystem;
   $base=str_contains($file,'/')?explode('/',$file)[0]:$file;
   if(!$wp_filesystem->delete(WP_PLUGIN_DIR.'/'.$base,true))throw new RuntimeException('Plugin removal failed; backup '.$backup);
   // Do not call uninstall hooks: site data/settings are intentionally retained.
  }else{
   require_once ABSPATH.'wp-admin/includes/file.php';
   require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
   if($action==='install'&&($exists||is_dir(WP_PLUGIN_DIR.'/'.explode('/',$file)[0])))throw new RuntimeException('Plugin directory already exists');
   $package=pi_pm_package($a);
   if(explode('/',$file)[0]!==$package['slug'])throw new RuntimeException('Plugin slug mismatch');
   if($action==='update'&&$package['version']!==''&&!version_compare($package['version'],$plugins[$file]['Version'],'>'))throw new RuntimeException('No newer package version');
   $backup=$action==='update'?pi_pm_backup($file):null;
   $tmp=pi_pm_download($package,$file);
   try{
    $upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    if($action==='update'){
     $updates=get_site_transient('update_plugins');if(!is_object($updates))$updates=new stdClass();
     $updates->response[$file]=(object)['slug'=>$package['slug'],'plugin'=>$file,'new_version'=>$package['version'],'package'=>$package['url']];
     set_site_transient('update_plugins',$updates);
     $download=static function($reply,$url,$owner,$extra)use($tmp,$file,$package){return ($extra['plugin']??'')===$file&&$url===$package['url']?$tmp:$reply;};
     add_filter('upgrader_pre_download',$download,999,4);
     try{$r=$upgrader->upgrade($file);}finally{remove_filter('upgrader_pre_download',$download,999);}
    }else $r=$upgrader->install($tmp);
    if(is_wp_error($r)||$r!==true)throw new RuntimeException('WordPress upgrader failed; backup '.($backup??'none'));
   }finally{@unlink($tmp);}
   wp_clean_plugins_cache(true);
   if($active&&!is_plugin_active($file)){$r=activate_plugin($file,'',false,false);if(is_wp_error($r))throw new RuntimeException('Activation lost; backup '.$backup);}
   if(!$active&&is_plugin_active($file))deactivate_plugins($file,false,false);
  }
  wp_clean_plugins_cache(true);$after=get_plugins();
  if($action==='delete'&&isset($after[$file]))throw new RuntimeException('Deletion verification failed');
  if(in_array($action,['install','update'],true)&&!isset($after[$file]))throw new RuntimeException('Installation verification failed');
  if(in_array($action,['install','update'],true)&&($package['version']??'')!==''&&($after[$file]['Version']??'')!==$package['version'])throw new RuntimeException('Installed version mismatch; backup '.($backup??'none'));
  if(in_array($action,['install','update'],true)&&is_plugin_active($file)!==$active)throw new RuntimeException('Activation state was not preserved; backup '.($backup??'none'));
  if(in_array($action,['activate','deactivate'],true)&&is_plugin_active($file)!==($action==='activate'))throw new RuntimeException('Active state mismatch');
  if($action==='auto_update'&&in_array($file,(array)get_site_option('auto_update_plugins',[]),true)!==$a['enabled'])throw new RuntimeException('Auto-update setting mismatch');
  $result=['ok'=>true,'request_id'=>$id,'action'=>$action,'plugin'=>$file,'installed'=>isset($after[$file]),'active'=>isset($after[$file])&&is_plugin_active($file),'version'=>$after[$file]['Version']??null,'auto_update'=>in_array($file,(array)get_site_option('auto_update_plugins',[]),true),'backup_id'=>$backup??null,'checked_at'=>gmdate('c')];
 }catch(Throwable $e){$result=pi_pm_error('operation_failed',$e->getMessage(),500);}
 finally{if($result!==null)update_option($receipt,['hash'=>$hash,'result'=>$result,'completed_at'=>gmdate('c')],false);flock($lock,LOCK_UN);fclose($lock);}
 return $result;
}
