<?php
define('ABSPATH',sys_get_temp_dir().'/odl-test-root/');
define('WP_PLUGIN_DIR',ABSPATH.'wp-content/plugins');
$hooks=[];$opts=[];$plugins=['angie/angie.php'=>['Version'=>'1.0']];$active=['angie/angie.php'];
function add_action(...$x){} function add_filter(...$x){} function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function add_option($k,$v,...$x){if(array_key_exists($k,$GLOBALS['opts']))return false;$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);return true;}
function is_ssl(){return true;} function is_multisite(){return false;}
function get_plugins(){return $GLOBALS['plugins'];} function is_plugin_active($f){return in_array($f,$GLOBALS['active'],true);}
function is_plugin_active_for_network($f){return false;} function wp_clean_plugins_cache(...$a){}
function activate_plugin($f,...$x){$GLOBALS['active'][]=$f;return null;}
function deactivate_plugins($f,...$x){$GLOBALS['active']=array_values(array_diff($GLOBALS['active'],[$f]));}
function get_site_option($k,$d=[]){return $GLOBALS['opts'][$k]??$d;}
function update_site_option($k,$v){$GLOBALS['opts'][$k]=$v;return true;}
function update_option($k,$v,...$x){$GLOBALS['opts'][$k]=$v;return true;}
function wp_json_encode($x){return json_encode($x,JSON_UNESCAPED_SLASHES);}
function rest_ensure_response($x){return $x;} function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {function __construct(public $code,public $message,public $data=[]){ } function get_error_message(){return $this->message;}}
class WP_REST_Request {function __construct(public $body,public $headers=[]){ }function get_body(){return $this->body;}function get_header($k){return $this->headers[$k]??'';}function get_json_params(){return json_decode($this->body,true);}}
require $argv[1];
function check($b,$m){if(!$b)throw new Exception($m);}
check(function_exists('pi_pm_execute'),'plugin manager implementation missing');
$GLOBALS['opts']['odladeni_bridge_shared_key']=str_repeat('a',64);
$body='{"action":"deactivate","plugin":"angie/angie.php","request_id":"1234567890abcdef"}';
$ts=(string)time();$nonce=str_repeat('b',24);
$sig=hash_hmac('sha256',"POST\n/wp-json/odladeni-bridge/v2/plugin-manage\n$ts\n$nonce\n".hash('sha256',$body),str_repeat('a',64));
$r=new WP_REST_Request($body,['x-odladeni-time'=>$ts,'x-odladeni-nonce'=>$nonce,'x-odladeni-signature'=>$sig]);
check(pi_pm_permission($r)===true,'valid body signature rejected');
check(pi_pm_permission($r)!==true,'nonce replay accepted');
$r->body=str_replace('deactivate','activate',$body);check(pi_pm_permission($r)!==true,'tampered body accepted');
$d=pi_pm_execute(['action'=>'deactivate','plugin'=>'angie/angie.php','request_id'=>'deactivate-test-1']);
check(!is_wp_error($d)&&$d['active']===false,'deactivation failed');
$a=pi_pm_execute(['action'=>'activate','plugin'=>'angie/angie.php','request_id'=>'activate-test-01']);
check(!is_wp_error($a)&&$a['active']===true,'activation failed');
$replay=pi_pm_execute(['action'=>'activate','plugin'=>'angie/angie.php','request_id'=>'activate-test-01']);
check($a===$replay,'idempotent retry mismatch');
check(is_wp_error(pi_pm_execute(['action'=>'deactivate','plugin'=>'angie/angie.php','request_id'=>'activate-test-01'])),'request id collision accepted');
foreach(['../evil.php','angie/../evil.php','angie//angie.php'] as $bad)check(is_wp_error(pi_pm_execute(['action'=>'activate','plugin'=>$bad,'request_id'=>'bad-path-test-001'])),'unsafe plugin accepted');
check(is_wp_error(pi_pm_execute(['action'=>'delete','plugin'=>'angie/angie.php','request_id'=>'delete-test-0001'])),'active plugin deletion accepted');
check(is_wp_error(pi_pm_execute(['action'=>'deactivate','plugin'=>'prvotni-instalace/prvotni-instalace.php','request_id'=>'protect-test-001'])),'bridge deactivation accepted');
$auto=pi_pm_execute(['action'=>'auto_update','plugin'=>'angie/angie.php','enabled'=>false,'request_id'=>'auto-off-test-01']);
check(!is_wp_error($auto)&&$auto['auto_update']===false,'auto-update off failed');
$auto=pi_pm_execute(['action'=>'auto_update','plugin'=>'angie/angie.php','enabled'=>true,'request_id'=>'auto-on-test-001']);
check(!is_wp_error($auto)&&$auto['auto_update']===true,'auto-update on failed');
check(is_wp_error(pi_pm_execute(['action'=>'auto_update','plugin'=>'angie/angie.php','enabled'=>'true','request_id'=>'auto-bad-test-01'])),'nonboolean enabled accepted');
echo "PASS: signatures, replay, tampering, state changes, idempotency, paths, protected bridge, active deletion, auto-update flags\n";
