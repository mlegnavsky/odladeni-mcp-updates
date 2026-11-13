<?php
/** Private durable snapshots. No public index and no requester IP addresses. */
function gca_archive_table(){global $wpdb;return $wpdb->prefix.'gca_measurements';}
function gca_archive_install(){
    global $wpdb;if((int)get_option('gca_archive_schema',0)>=2)return true;
    if(!function_exists('dbDelta'))require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $table=gca_archive_table();$collate=$wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        id char(32) NOT NULL,
        url text NOT NULL,
        started_at varchar(30) NOT NULL,
        updated_at varchar(30) NOT NULL,
        state varchar(16) NOT NULL,
        share_hash char(64) NOT NULL DEFAULT '',
        job_json longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY started_at (started_at),
        KEY share_hash (share_hash)
    ) $collate;");
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)return false;
    if($wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'share_hash'")!=='share_hash')return false;
    $index=$wpdb->get_row("SHOW INDEX FROM $table WHERE Key_name = 'share_hash'",ARRAY_A);if(($index['Column_name']??'')!=='share_hash')return false;
    update_option('gca_archive_schema',2,false);return true;
}
function gca_save_job($job){
    global $wpdb;if(!gca_archive_install())return false;
    unset($job['ip_hash']);$job['storage']='permanent';$job['expires_at']=null;
    $json=wp_json_encode($job,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($json))return false;
    $ok=$wpdb->replace(gca_archive_table(),['id'=>$job['id'],'url'=>$job['url'],'started_at'=>$job['started_at'],'updated_at'=>gmdate('c'),'state'=>$job['state'],'share_hash'=>hash('sha256',gca_share_key($job)),'job_json'=>$json],['%s','%s','%s','%s','%s','%s','%s']);
    if($ok===false)return false;
    set_transient('gca_job_'.$job['id'],$job,HOUR_IN_SECONDS);return true;
}
function gca_load_job($id){
    $job=get_transient('gca_job_'.$id);if($job)return $job;
    return gca_load_archived_job($id);
}
function gca_load_archived_job($id){
    global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT job_json FROM '.gca_archive_table().' WHERE id = %s',$id),ARRAY_A);
    if(!$row)return null;$job=json_decode($row['job_json'],true);return is_array($job)?$job:null;
}
function gca_report($request){
    $job=gca_session($request);if(!$job)return gca_error('Uložené měření nebylo nalezeno nebo nemáte platný soukromý odkaz.',404);
    if(($job['storage']??'')!=='permanent'){if(!gca_save_legacy_job($job))return gca_error('Měření se nepodařilo trvale uložit. Zkuste to znovu.',503);$job=gca_load_archived_job($job['id']);if(!$job)return gca_error('Uložené měření není dostupné.',503);}
    if(($job['phase']??'')==='browser'&&gca_lock('job_'.$job['id'],30)){try{$latest=gca_load_archived_job($job['id']);if($latest){$job=gca_browser_poll($latest);gca_save_job($job);}}finally{gca_unlock('job_'.$job['id']);}}
    $job['storage']='permanent';$job['expires_at']=null;return gca_public_report($job);
}
add_action('plugins_loaded','gca_archive_install');
add_action('admin_menu',function(){add_management_page('Historie měření cookies','Měření cookies','manage_options','gca-measurements','gca_archive_admin');});
function gca_archive_admin(){
    if(!current_user_can('manage_options'))return;
    global $wpdb;$page=max(1,(int)($_GET['paged']??1));$limit=25;$offset=($page-1)*$limit;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT id,url,started_at,updated_at,state,job_json FROM '.gca_archive_table().' ORDER BY started_at DESC,id DESC LIMIT %d OFFSET %d',$limit,$offset),ARRAY_A);
    $total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.gca_archive_table());
    echo '<div class="wrap"><h1>Historie měření cookies</h1><p>Trvale uložená měření: '.(int)$total.'. Zahrnuje rozpracované i neúspěšné pokusy. Přístup k tomuto přehledu má pouze správce.</p><table class="widefat striped"><thead><tr><th>Web / ID</th><th>Zahájeno</th><th>Stav</th><th>Výsledek</th></tr></thead><tbody>';
    $labels=['running'=>'Rozpracované','complete'=>'Zdrojová kontrola dokončena','partial'=>'Částečný výsledek','failed'=>'Neúspěšné'];
    foreach($rows as $row){$saved=json_decode($row['job_json'],true);$link=is_array($saved)?gca_report_url($saved):home_url('/kontrola-cookies/');echo '<tr><td>'.esc_html($row['url']).'<br><code>'.esc_html($row['id']).'</code></td><td>'.esc_html(wp_date('j. n. Y H:i',strtotime($row['started_at']))).'</td><td>'.esc_html($labels[$row['state']]??$row['state']).'</td><td><a class="button" href="'.esc_url($link).'" target="_blank" rel="noopener noreferrer">Otevřít a stáhnout PDF</a></td></tr>';}
    if(!$rows)echo '<tr><td colspan="4">Zatím nejsou uložená měření.</td></tr>';
    echo '</tbody></table><p>';
    if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'gca-measurements','paged'=>$page-1],admin_url('tools.php'))).'">Předchozí</a> ';
    if($offset+$limit<$total)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'gca-measurements','paged'=>$page+1],admin_url('tools.php'))).'">Další</a>';
    echo '</p></div>';
}
function gca_admin_report($request){
    $id=$request->get_param('id');if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))return gca_error('Neplatné ID měření.',400);
    $job=gca_load_job($id);return $job?gca_public_report($job,true):gca_error('Měření nebylo nalezeno.',404);
}

/** Insert-only promotion: a concurrent step must always win over a legacy snapshot. */
function gca_save_legacy_job($job){
    global $wpdb;if(!gca_archive_install())return false;
    unset($job['ip_hash']);$job['storage']='permanent';$job['expires_at']=null;
    $json=wp_json_encode($job,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($json))return false;
    $result=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.gca_archive_table().' (id,url,started_at,updated_at,state,share_hash,job_json) VALUES (%s,%s,%s,%s,%s,%s,%s)',$job['id'],$job['url'],$job['started_at'],gmdate('c'),$job['state'],hash('sha256',gca_share_key($job)),$json));
    // Never update the cache here: a newer step may have completed after INSERT.
    return $result!==false;
}

/** Recover legacy transient snapshots without exposing their token hashes. */
function gca_archive_import(){
    global $wpdb;if(!gca_archive_install())return false;
    $pattern=$wpdb->esc_like('_transient_gca_job_').'%';$table=gca_archive_table();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT o.option_name,o.option_value FROM {$wpdb->options} o LEFT JOIN $table a ON a.id = RIGHT(o.option_name,32) WHERE o.option_name LIKE %s AND a.id IS NULL LIMIT 20",$pattern),ARRAY_A);
    if(!is_array($rows)||$wpdb->last_error)return false;$count=0;
    foreach($rows as $row){
        $id=substr($row['option_name'],-32);if(!preg_match('/^[a-f0-9]{32}$/',$id))continue;
        $job=@unserialize($row['option_value'],['allowed_classes'=>false]);
        if(!is_array($job)||($job['id']??'')!==$id||!isset($job['token_hash'],$job['url'],$job['started_at'],$job['state']))continue;
        if(!gca_save_legacy_job($job))return false;$count++;
    }
    if(count($rows)<20)update_option('gca_archive_import_complete',true,false);
    elseif(function_exists('wp_next_scheduled')&&!wp_next_scheduled('gca_archive_import'))wp_schedule_single_event(time()+5,'gca_archive_import');
    return $count;
}
add_action('gca_archive_import','gca_archive_import');
add_action('plugins_loaded',function(){if(!get_option('gca_archive_import_complete')&&!wp_next_scheduled('gca_archive_import'))wp_schedule_single_event(time(),'gca_archive_import');});


function gca_share_key($job){return hash_hmac('sha256','gca-report:'.$job['id'],$job['token_hash']);}
function gca_report_url($job){return home_url('/kontrola-cookies/').'?mereni='.gca_share_key($job);}
function gca_shared_report($request){
    $key=$request->get_param('key');if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key))return gca_error('Odkaz na měření není platný.',404);
    global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT job_json FROM '.gca_archive_table().' WHERE share_hash = %s LIMIT 1',hash('sha256',$key)),ARRAY_A);
    $job=$row?json_decode($row['job_json'],true):null;
    if(!is_array($job)||!hash_equals(gca_share_key($job),$key))return gca_error('Uložené měření nebylo nalezeno.',404);
    return gca_public_report($job);
}
function gca_archive_share_backfill(){
    global $wpdb;if(!gca_archive_install())return;
    $rows=$wpdb->get_results('SELECT id,job_json FROM '.gca_archive_table()." WHERE share_hash = '' LIMIT 20",ARRAY_A);
    if(!is_array($rows)||$wpdb->last_error)return;
    foreach($rows as $row){$job=json_decode($row['job_json'],true);if(!is_array($job)||empty($job['token_hash'])||($job['id']??'')!==$row['id'])continue;
        if($wpdb->update(gca_archive_table(),['share_hash'=>hash('sha256',gca_share_key($job))],['id'=>$row['id'],'share_hash'=>''],['%s'],['%s','%s'])===false)return;
    }
    if(count($rows)<20)update_option('gca_archive_share_complete',true,false);
    elseif(!wp_next_scheduled('gca_archive_share_backfill'))wp_schedule_single_event(time()+5,'gca_archive_share_backfill');
}
add_action('gca_archive_share_backfill','gca_archive_share_backfill');
add_action('plugins_loaded',function(){if(!get_option('gca_archive_share_complete')&&!wp_next_scheduled('gca_archive_share_backfill'))wp_schedule_single_event(time(),'gca_archive_share_backfill');});
add_action('template_redirect',function(){if(is_page('kontrola-cookies')){header('Referrer-Policy: no-referrer');if(isset($_GET['mereni']))header('X-Robots-Tag: noindex, nofollow');nocache_headers();}});
add_filter('wp_robots',function($robots){if(isset($_GET['mereni'])){$robots['noindex']=true;$robots['nofollow']=true;}return $robots;});
