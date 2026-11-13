<?php
define('ABSPATH','/');define('HOUR_IN_SECONDS',3600);
$store=[];$options=[];
function add_action(...$args){} function add_shortcode(...$args){} function register_activation_hook(...$args){} function add_filter(...$args){}
function get_transient($k){global $store;return $store[$k]??false;}
function set_transient($k,$v,$ttl){global $store;$store[$k]=$v;return true;}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function add_option($k,$v,...$x){global $options;if(isset($options[$k]))return false;$options[$k]=$v;return true;}
function delete_option($k){global $options;unset($options[$k]);}
function home_url($path=''){return 'https://example.com'.$path;}
class WP_Error{public $data;public function __construct(...$v){$this->data=$v;}}
require __DIR__.'/../plugin/gdpr-cookie-audit.php';
function check($v,$m){if(!$v)throw new Exception($m);echo "PASS $m\n";}
class Req{public function __construct(public array $params=[],public array $headers=[]){}function get_param($k){return $this->params[$k]??null;}function get_header($k){return $this->headers[$k]??null;}}
$id=str_repeat('a',32);$token='secret';$job=['id'=>$id,'token_hash'=>hash('sha256',$token),'expires_at'=>time()-1,'queue'=>[],'policies'=>[],'state'=>'complete','cursor'=>0];
$store['gca_job_'.$id]=$job;
check(gca_session(new Req(['id'=>$id],['x-audit-token'=>$token]))===null,'expired jobs rejected independently of transient TTL');
$store['gca_job_'.$id]['expires_at']=time()+3600;
check(gca_session(new Req(['id'=>$id],['x-audit-token'=>'wrong']))===null,'cross-session token rejected');
check(gca_session(new Req(['id'=>$id],['x-audit-token'=>$token]))['id']===$id,'valid token accepted');
check(gca_same_origin(new Req([],['origin'=>'https://evil.com'])) instanceof WP_Error,'cross origin rejected');
check(gca_lock('job-test')===true && gca_lock('job-test')===false,'exclusive lock');gca_unlock('job-test');
check(gca_lock('job-test')===true,'lock released');
$public=gca_public_report($store['gca_job_'.$id]);check(!isset($public['token_hash'])&&!isset($public['queue']),'session secrets excluded');
echo "ALL WP TESTS PASSED\n";
