<?php
define('ABSPATH',sys_get_temp_dir().'/odl-pkg-test-'.bin2hex(random_bytes(8)).'/wordpress/');
define('WP_PLUGIN_DIR',ABSPATH.'wp-content/plugins');
function add_action(...$a){} function add_filter(...$a){} function wp_tempnam($x){return tempnam(sys_get_temp_dir(),'odl-pkg-');}
function wp_safe_remote_get($url,$args){if(!isset($args['filename']))return ['code'=>200,'body'=>json_encode($GLOBALS['manifest'])];copy($GLOBALS['fixture'],$args['filename']);return ['code'=>200];}
function wp_remote_retrieve_body($x){return $x['body']??'';}
class WP_Error{function __construct(...$x){}}
function is_wp_error($x){return $x instanceof WP_Error;} function wp_remote_retrieve_response_code($x){return $x['code'];}
function wp_json_encode($x){return json_encode($x);}
require $argv[1];
function verify($b,$m){if(!$b)throw new Exception($m);}
function makeZip($entries){$p=tempnam(sys_get_temp_dir(),'odl-fixture-');unlink($p);$z=new ZipArchive();$z->open($p,ZipArchive::CREATE);foreach($entries as $name=>$body)$z->addFromString($name,$body);$z->close();return $p;}
$main="<?php\n/*\nPlugin Name: Fixture\nVersion: 1.0.0\n*/";
$fixture=makeZip(['fixture/main.php'=>$main]);
$p=['url'=>'https://example.org/fixture.zip','sha256'=>hash_file('sha256',$fixture),'slug'=>'fixture','version'=>'1.0.0'];
$tmp=pi_pm_download($p,'fixture/main.php');verify(is_file($tmp),'valid ZIP rejected');unlink($tmp);unlink($fixture);
foreach([
 ['fixture/main.php'=>$main,'fixture/../evil.php'=>'bad'],
 ['other/main.php'=>$main],
 ['fixture/main.php'=>'no plugin header'],
 ['fixture/main.php'=>str_replace('1.0.0','0.9.0',$main)],
] as $entries){
 $fixture=makeZip($entries);$p['sha256']=hash_file('sha256',$fixture);$failed=false;
 try{$tmp=pi_pm_download($p,'fixture/main.php');unlink($tmp);}catch(RuntimeException $e){$failed=true;}
 verify($failed,'unsafe ZIP accepted');unlink($fixture);
}
$fixture=makeZip(['fixture/main.php'=>$main]);$p['sha256']=str_repeat('0',64);$failed=false;
try{$tmp=pi_pm_download($p,'fixture/main.php');unlink($tmp);}catch(RuntimeException $e){$failed=true;}
verify($failed,'SHA mismatch accepted');unlink($fixture);
$fixture=makeZip(['fixture/main.php'=>$main]);$url='https://plaginy.odladeni.cz/releases/fixture-v1.0.0-123456789012.zip';
$manifest=['slug'=>'fixture','version'=>'1.0.0','download_url'=>$url,'sha256'=>hash_file('sha256',$fixture)];
$native=pi_pm_repository_download(false,$url,null,['plugin'=>'fixture/main.php']);verify(is_string($native)&&is_file($native),'native repository integrity hook rejected valid ZIP');unlink($native);
$manifest['sha256']=str_repeat('0',64);verify(is_wp_error(pi_pm_repository_download(false,$url,null,['plugin'=>'fixture/main.php'])),'native repository hook accepted wrong SHA');
verify(pi_pm_repository_download(false,'https://downloads.wordpress.org/plugin/fixture.zip',null,[])===false,'unrelated updater changed');unlink($fixture);
mkdir(WP_PLUGIN_DIR.'/fixture',0700,true);file_put_contents(WP_PLUGIN_DIR.'/fixture/main.php',$main);
$id=pi_pm_backup('fixture/main.php');
$folder=dirname(rtrim(ABSPATH,'/')).'/.odladeni-backups-'.substr(hash('sha256',ABSPATH),0,12);
$z=new ZipArchive();verify($z->open($folder.'/'.$id.'.zip')===true,'backup missing');verify($z->getFromName('fixture/main.php')===$main,'backup bytes differ');$z->close();
symlink(WP_PLUGIN_DIR.'/fixture/main.php',WP_PLUGIN_DIR.'/fixture/linked.php');$failed=false;
try{pi_pm_backup('fixture/main.php');}catch(RuntimeException $e){$failed=true;}verify($failed,'symlink backup allowed');
echo "PASS: valid package, traversal, root identity, headers, version, SHA-256 mismatch, backup contents, symlink protection\n";
