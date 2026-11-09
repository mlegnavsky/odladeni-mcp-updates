<?php
if (!defined('ABSPATH')) exit;
add_action('rest_api_init', static function () {
 register_rest_route('odladeni-bridge/v1','/plugin-state',[
  'methods'=>'POST',
  'permission_callback'=>static function(WP_REST_Request $r){
   $secret=(string)get_option('odladeni_bridge_shared_key','');
   $ts=(string)$r->get_header('x-odladeni-time');$nonce=(string)$r->get_header('x-odladeni-nonce');$sig=(string)$r->get_header('x-odladeni-signature');
   if(!preg_match('/^[a-f0-9]{64}$/D',$secret)||!ctype_digit($ts)||abs(time()-(int)$ts)>120||!preg_match('/^[a-f0-9]{24}$/D',$nonce)||!preg_match('/^[a-f0-9]{64}$/D',$sig))return false;
   $expected=hash_hmac('sha256',"POST\n/wp-json/odladeni-bridge/v1/plugin-state\n".$ts."\n".$nonce,$secret);
   if(!hash_equals($expected,$sig))return false;
   $key='odladeni_nonce_'.hash('sha256',$nonce);if(get_transient($key))return false;set_transient($key,1,180);return true;
  },
  'callback'=>static function(WP_REST_Request $r){
   if(!is_ssl())return new WP_Error('https_required','HTTPS required',['status'=>403]);
   $file=(string)$r->get_param('plugin');$enabled=$r->get_param('enabled');
   if(!is_bool($enabled))return new WP_Error('invalid_state','Boolean enabled required',['status'=>400]);
   if(!preg_match('~^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\\.php$~D',$file)||validate_file($file)!==0)return new WP_Error('invalid_plugin','Invalid plugin',['status'=>400]);
   if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';
   $plugins=get_plugins();if(!isset($plugins[$file]))return new WP_Error('missing_plugin','Plugin not installed',['status'=>404]);
   if($file===plugin_basename(dirname(__DIR__).'/prvotni-instalace.php'))return new WP_Error('bridge_protected','Bridge plugin cannot be toggled remotely',['status'=>409]);
   if(is_multisite()&&is_plugin_active_for_network($file))return new WP_Error('network_plugin','Network plugin protected',['status'=>409]);
   if($enabled){$result=activate_plugin($file,'',false,true);if(is_wp_error($result))return $result;}
   else deactivate_plugins($file,false,false);
   $actual=is_plugin_active($file);
   if($actual!==$enabled)return new WP_Error('state_mismatch','State not confirmed',['status'=>500]);
   return rest_ensure_response(['ok'=>true,'plugin'=>$file,'active'=>$actual,'checked_at'=>gmdate('c')]);
  }
 ]);
});
