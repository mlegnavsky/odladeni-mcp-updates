<?php
/** Detached, bounded browser worker. Files never reside in the public document root. */
function gca_browser_release($id){
    $key='gca_browser_runtime';$value=get_option($key,false);$lock=is_string($value)?json_decode($value,true):null;if(($lock['id']??null)!==$id)return;
    global $wpdb;$deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",$key,$value));
    if($deleted){wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');}
}
function gca_browser_claim($id){
    $old=get_option('gca_browser_runtime',false);$lock=is_string($old)?json_decode($old,true):null;
    if($lock&&($lock['until']??0)<time())gca_browser_release($lock['id']);
    return add_option('gca_browser_runtime',json_encode(['id'=>$id,'until'=>time()+175]),'',false);
}
function gca_browser_apply($job,$result){
    $job['browser']=$result;
    if(in_array($result['status']??'',['complete','failed'],true)){
        if(!empty($job['_policy_documents'])&&!empty($result['observations'])){$ids=[];$cookies=[];foreach($job['pages'] as $page){if(($page['is_target']??true)===false)continue;foreach($page['services']??[] as $service)$ids[]=$service['id'];foreach($page['cookies']??[] as $cookie)$cookies[$cookie['name'].'|'.$cookie['domain']]=$cookie;}foreach($result['observations'] as $observation)foreach($observation['cookies'] as $cookie)$cookies[$cookie['name'].'|'.$cookie['domain']]=$cookie;$assessment=gca_analyze_policy_documents($job['_policy_documents'],$ids,array_values($cookies),$job['declared_cookies']??[]);if($assessment)$job['policies']=[$assessment];}
        $job['state']=($result['status']==='failed')?'partial':($job['_source_state']??'complete');$job['phase']='complete';$job['finished_at']=gmdate('c');
    }
    return $job;
}
function gca_browser_collect_later($id){if(!wp_next_scheduled('gca_browser_collect',[$id]))wp_schedule_single_event(time()+5,'gca_browser_collect',[$id]);}
function gca_browser_begin($job){
    if(($job['state']??'')==='failed'||empty($job['pages']))return $job;
    if((gca_runtime_status()['state']??'')!=='ready')return $job;
    $job['_source_state']=$job['_source_state']??$job['state'];$job['state']='running';$job['phase']='browser';$job['finished_at']=null;
    $job['_browser_wait_started']=$job['_browser_wait_started']??time();
    if(!gca_browser_claim($job['id'])){$job['browser']=['available'=>true,'status'=>'queued','reason'=>'Čekáme na uvolnění měřicího prohlížeče.'];gca_browser_collect_later($job['id']);return $job;}
    try{
        $job['_browser_started']=time();$base=gca_runtime_base();$dir=$base.'/jobs/'.$job['id'];if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Private job directory unavailable.');chmod(dirname($dir),0700);chmod($dir,0700);
        $google=false;foreach($job['pages'] as $p)foreach($p['services']??[] as $s)if($s['id']==='google-tags')$google=true;
        $input=['url'=>$job['url'],'google_tags'=>$google,'declarations'=>array_map(fn($c)=>array_intersect_key($c,array_flip(['name','domain','category'])),$job['declared_cookies']??[])];
        if(file_put_contents($dir.'/input.json',wp_json_encode($input))===false)throw new RuntimeException('Input cannot be saved.');chmod($dir.'/input.json',0600);
        $plan=gca_runtime_distribution();$process=gca_runtime_fixed_process([$base.'/'.$plan['node_folder'].'/bin/node',__DIR__.'/runtime/worker.cjs','--detach',$base,$job['id']],$base);
        if($process['code']!==0)throw new RuntimeException('Worker did not start.');
        $job['browser']=['available'=>true,'status'=>'running','reason'=>'Měříme cookies, úložiště, síťové požadavky a cookie lištu v izolovaném prohlížeči.'];gca_browser_collect_later($job['id']);
    }catch(Throwable $e){gca_browser_release($job['id']);$job=gca_browser_apply($job,['available'=>false,'status'=>'failed','results'=>[],'reason'=>'Měřicí proces se nepodařilo spustit.']);}
    return $job;
}
function gca_browser_poll($job){
    if(($job['phase']??'')!=='browser')return $job;
    if(time()-((($job['browser']['status']??'')==='queued'?$job['_browser_wait_started']??time():$job['_browser_started']??time()))>((( $job['browser']['status']??'')==='queued')?240:170)){gca_browser_release($job['id']);return gca_browser_apply($job,['available'=>false,'status'=>'failed','results'=>[],'reason'=>'Vypršel časový limit měření v prohlížeči.']);}
    if(($job['browser']['status']??'')==='queued')return gca_browser_begin($job);
    try{$file=gca_runtime_base().'/jobs/'.$job['id'].'/result.json';
        if(is_file($file)&&filesize($file)<=6*1024*1024){$result=json_decode((string)file_get_contents($file),true);if(is_array($result)&&in_array($result['status']??'',['running','complete','failed'],true))$job=gca_browser_apply($job,$result);}
    }catch(Throwable $e){}
    if(($job['phase']??'')==='complete')gca_browser_release($job['id']);else gca_browser_collect_later($job['id']);
    return $job;
}
add_action('gca_browser_collect',function($id){
    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))return;
    if(!gca_lock('job_'.$id,60)){gca_browser_collect_later($id);return;}
    try{$job=gca_load_archived_job($id);if($job&&($job['phase']??'')==='browser'){ $job=gca_browser_poll($job);gca_save_job($job); }}finally{gca_unlock('job_'.$id);}
});
