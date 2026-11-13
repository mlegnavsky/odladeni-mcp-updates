<?php
require __DIR__.'/wp.php';
class ArchiveDB {
 public $schemaColumn=true;public $schemaIndex=true;public $prefix='wp_';public $options='wp_options';public $rows=[];public $onRead=null;public $legacy=[];public $fail=false;public $last_error='';
 function esc_like($v){return addcslashes($v,'_%');}
 function get_charset_collate(){return '';}
 function replace($table,$data,$formats){if($this->fail)return false;$this->rows[$data['id']]=$data;return 1;}
 function prepare($sql,...$args){return [$sql,$args];}
 function get_results($q,$format){if($this->onRead){$fn=$this->onRead;$this->onRead=null;$fn();}return $this->legacy;}
 function query($q){if($this->fail)return false;$v=$q[1];if(isset($this->rows[$v[0]]))return 0;$this->rows[$v[0]]=array_combine(['id','url','started_at','updated_at','state','share_hash','job_json'],$v);return 1;}
 function get_var($sql){if(is_string($sql)&&str_contains($sql,'SHOW COLUMNS'))return $this->schemaColumn?'share_hash':null;return 'wp_gca_measurements';}
 function get_row($q,$format){if(is_string($q)&&str_contains($q,'SHOW INDEX'))return $this->schemaIndex?['Key_name'=>'share_hash','Column_name'=>'share_hash']:null;if(str_contains($q[0],'WHERE share_hash')){foreach($this->rows as $row)if(($row['share_hash']??'')===$q[1][0])return $row;return null;}return $this->rows[$q[1][0]]??null;}
}
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
function wp_json_encode($v,...$a){return json_encode($v,...$a);}
function update_option($k,$v,...$x){global $options;$options[$k]=$v;return true;}
function dbDelta($sql){}
$wpdb=new ArchiveDB();$options['gca_archive_schema']=2;
$job=['id'=>str_repeat('b',32),'token_hash'=>hash('sha256','private'),'url'=>'https://spd.cz/','started_at'=>gmdate('c'),'state'=>'running','expires_at'=>time()+10,'queue'=>[['url'=>'https://spd.cz/']],'cursor'=>0,'policies'=>[],'pages'=>[],'errors'=>[],'ip_hash'=>'do-not-store'];
check(function_exists('gca_save_job'),'durable storage entry point exists');
check(gca_save_job($job),'start snapshot durably saved');
$store=[];
$r=gca_session(new Req(['id'=>$job['id']],['x-audit-token'=>'private']));
check($r&&$r['state']==='running','running measurement recoverable after transient eviction');
check(!str_contains($wpdb->rows[$job['id']]['job_json'],'do-not-store'),'archive never retains requester IP hash');
check(gca_session(new Req(['id'=>$job['id']],['x-audit-token'=>'wrong']))===null,'archive retrieval requires the original capability token');
$job['state']='failed';$job['expires_at']=time()-100;$job['errors']=[['url'=>$job['url'],'message'=>'timeout','kind'=>'page']];
check(gca_save_job($job),'failed measurement retained too');$store=[];
$r=gca_report(new Req(['id'=>$job['id']],['x-audit-token'=>'private']));
check(is_array($r)&&$r['state']==='failed'&&!isset($r['errors'])&&count(gca_load_archived_job($job['id'])['errors'])===1,'saved failed result accessible after former one-hour expiry');
check(!isset($r['token_hash'])&&!isset($r['queue'])&&!isset($r['ip_hash']),'private archive fields absent from downloadable report');
$job['id']=str_repeat('c',32);$job['state']='complete';check(gca_save_job($job)&&count($wpdb->rows)===2,'different measurements preserve distinct snapshots');
$wpdb->fail=true;$job['cursor']=1;check(gca_save_job($job)===false,'durability failure is propagated instead of silently claiming saved');
echo "ALL ARCHIVE TESTS PASSED\n";
$wpdb->fail=false;$options['gca_archive_import_complete']=false;
$legacy=$job;$legacy['id']=str_repeat('d',32);$legacy['state']='complete';$legacy['expires_at']=time()-100;
$wpdb->legacy=[['option_name'=>'_transient_gca_job_'.$legacy['id'],'option_value'=>serialize($legacy)]];
check(function_exists('gca_archive_import'),'older cached measurements have a recovery path');
check(gca_archive_import()===1,'existing legacy job recovered into durable history');
check(($wpdb->rows[$legacy['id']]['state']??null)==='complete','legacy result state preserved');
check(!str_contains($wpdb->rows[$legacy['id']]['job_json'],'do-not-store'),'legacy requester IP excluded during import');

$legacy['id']=str_repeat('e',32);$legacy['cursor']=0;$newer=$legacy;$newer['cursor']=3;$newer['state']='partial';
$wpdb->legacy=[['option_name'=>'_transient_gca_job_'.$legacy['id'],'option_value'=>serialize($legacy)]];
$wpdb->onRead=function()use($newer){gca_save_job($newer);};
gca_archive_import();$store=[];$recovered=gca_session(new Req(['id'=>$legacy['id']],['x-audit-token'=>'private']));
check($recovered['cursor']===3&&$recovered['state']==='partial','legacy import cannot overwrite a concurrent newer step snapshot');
check(function_exists('gca_share_key'),'stable opaque report key exists');
$key=gca_share_key($job);check((bool)preg_match('/^[a-f0-9]{64}$/',$key)&&!str_contains($key,$job['id']),'report URL uses one opaque hash rather than the internal ID');
check($key===gca_share_key($job)&&$key!==gca_share_key(array_merge($job,['id'=>str_repeat('f',32)])),'same measurement key stable and distinct across measurements');
$wpdb->fail=false;gca_save_job($job);$shared=gca_shared_report(new Req(['key'=>$key]));
check(is_array($shared)&&$shared['id']===$job['id'],'opaque URL reopens its saved report without owner token');
check(str_contains($shared['report_url'],'?mereni='.$key)&&!isset($shared['token_hash']),'shared report includes its permanent URL and excludes owner secret');
check(gca_shared_report(new Req(['key'=>str_repeat('0',64)])) instanceof WP_Error,'wrong opaque report key rejected');
$options['gca_archive_schema']=1;$wpdb->schemaColumn=false;
check(gca_archive_install()===false&&$options['gca_archive_schema']===1,'failed share-column upgrade is not marked complete');
$wpdb->schemaColumn=true;$wpdb->schemaIndex=false;
check(gca_archive_install()===false&&$options['gca_archive_schema']===1,'missing share index prevents schema completion');
$wpdb->schemaIndex=true;check(gca_archive_install()&&$options['gca_archive_schema']===2,'verified column and index complete schema upgrade');
