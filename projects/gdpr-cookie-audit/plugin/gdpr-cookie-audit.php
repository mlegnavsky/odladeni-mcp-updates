<?php
/**
 * Plugin Name: GDPR Cookies – bezplatný audit webu
 * Description: Přehledná kontrola cookies, zdrojových integrací a zásad cookies s PDF zprávou.
 * Version: 2.2.2
 * Requires PHP: 8.0
 * Author: Martin Legnavsky
 * Update URI: https://plaginy.odladeni.cz/gdpr-cookie-audit
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/core.php';
require_once __DIR__.'/runtime.php';
define('GCA_VERSION','2.2.2');
require_once __DIR__.'/archive.php';
require_once __DIR__.'/browser.php';
require_once __DIR__.'/compliance.php';
require_once __DIR__.'/presentation.php';

function gca_asset_url($file) { return plugins_url('assets/'.$file,__FILE__); }
function gca_enqueue() {
    wp_enqueue_style('gca-audit',gca_asset_url('audit.css'),[],GCA_VERSION);
    wp_enqueue_style('gca-dashboard',gca_asset_url('dashboard.css'),['gca-audit'],GCA_VERSION);
    wp_enqueue_script('gca-scan',gca_asset_url('scan.js'),[],GCA_VERSION,true);
    wp_enqueue_script('gca-view',gca_asset_url('view.js'),[],GCA_VERSION,true);
    wp_enqueue_script('gca-pdf',gca_asset_url('jspdf.umd.min.js'),[],GCA_VERSION,true);
    wp_enqueue_script('gca-font',gca_asset_url('font.js'),['gca-pdf'],GCA_VERSION,true);
    wp_enqueue_script('gca-model',gca_asset_url('model.js'),[],GCA_VERSION,true);
    wp_enqueue_script('gca-pdf-export',gca_asset_url('pdf.js'),['gca-font','gca-model'],GCA_VERSION,true);
    wp_enqueue_script('gca-pdf-detail',gca_asset_url('pdf-detail.js'),['gca-pdf-export'],GCA_VERSION,true);
    wp_enqueue_script('gca-history',gca_asset_url('history.js'),[],GCA_VERSION,true);
    wp_enqueue_script('gca-audit',gca_asset_url('audit.js'),['gca-pdf-export','gca-history','gca-view','gca-scan'],GCA_VERSION,true);
    if(current_user_can('manage_options'))wp_localize_script('gca-audit','GCAAdmin',['nonce'=>wp_create_nonce('wp_rest')]);
}
add_action('wp_enqueue_scripts',function(){if(is_page('kontrola-cookies'))gca_enqueue();});
function gca_dashboard(){ gca_enqueue(); ob_start();require __DIR__.'/dashboard.php';return ob_get_clean(); }
add_shortcode('gdpr_cookie_audit','gca_dashboard');
add_action('gca_runtime_install','gca_runtime_bootstrap');
register_activation_hook(__FILE__,function(){if(!wp_next_scheduled('gca_runtime_install'))wp_schedule_single_event(time()-1,'gca_runtime_install');});
register_activation_hook(__FILE__,function() {
    if (!get_page_by_path('kontrola-cookies')) {
        $id=wp_insert_post(['post_type'=>'page','post_title'=>'Bezplatná kontrola cookies','post_name'=>'kontrola-cookies','post_status'=>'draft','post_content'=>'[gdpr_cookie_audit]']);
        if (!is_wp_error($id)) update_option('gca_created_page',$id,false);
    }
});

function gca_error($text,$status=400) { return new WP_Error('gca_error',$text,['status'=>$status]); }
function gca_same_origin($request) {
    $origin=$request->get_header('origin');
    if ($origin && rtrim($origin,'/')!==rtrim(home_url(),'/')) return gca_error('Měření spusťte přímo na tomto webu.',403);
    return true;
}
function gca_lock($key,$ttl=25) {
    $key='gca_lock_'.$key;
    $old=get_option($key,0);if($old && $old<time()) delete_option($key);
    return add_option($key,time()+$ttl,'',false);
}
function gca_unlock($key) { delete_option('gca_lock_'.$key); }
function gca_limit($key,$max) {
    $key='gca_rate_'.hash('sha256',$key);$count=(int)get_transient($key);
    if ($count >= $max) return false;
    set_transient($key,$count+1,HOUR_IN_SECONDS); return true;
}
function gca_session($request) {
    $id=$request->get_param('id');$token=$request->get_header('x-audit-token');
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/',$id) || !is_string($token)) return null;
    $job=gca_load_job($id);
    if (!$job || (($job['storage']??'')!=='permanent'&&($job['expires_at']??0)<time()) || !hash_equals($job['token_hash'],hash('sha256',$token))) return null;
    return $job;
}
function gca_public_report($job, $internal=false) {
     $job['report_url']=gca_report_url($job);$job['total']=count($job['queue']);$job['policy_discovery']=gca_policy_discovery($job);
    unset($job['token_hash'],$job['queue'],$job['ip_hash'],$job['manual_policy'],$job['_declaration_clients'],$job['_browser_started'],$job['_browser_wait_started'],$job['_source_state'],$job['_policy_documents']);
    foreach($job['policies'] as &$policy) unset($policy['_text'],$policy['_dynamic_table'],$policy['_declaration_client']);unset($policy);
    if($internal){$job['presentation']=gca_presentation($job);$job['is_internal']=true;return $job;}
    return gca_public_view($job);
}
function gca_start($request) {
    $url=gca_normalize_url($request->get_param('url'));
    if (!$url) return gca_error('Zadejte veřejnou adresu webu, například https://firma.cz. Bez hesla, nestandardního portu a parametrů za otazníkem.');
    $manual=trim((string)$request->get_param('policy_url'));$policyUrl=$manual?gca_normalize_url($manual):null;
    if($manual && !$policyUrl)return gca_error('Odkaz na zásady musí být veřejná HTTP/HTTPS adresa bez parametrů.');
    if (!function_exists('curl_init')) return gca_error('Měření je dočasně nedostupné.',503);
    if (!gca_lock('start',5)) return gca_error('Zkuste měření za několik sekund.',429);
    $ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',wp_salt('nonce'));
    $ok=gca_limit('ip:'.$ip,8) && gca_limit('domain:'.parse_url($url,PHP_URL_HOST),5) && gca_limit('global',120);
    gca_unlock('start');
    if (!$ok) return gca_error('Byl dosažen limit měření. Další kontrolu zkuste přibližně za hodinu.',429);
    $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(24));
    $runtime=gca_runtime_status();
    $runtimeReasons=['sandbox_unavailable'=>'Nepodařilo se ověřit izolaci webových procesů měřicího prohlížeče. Testy přijetí, odmítnutí, skutečného úložiště a kontrastu neproběhly.','system_library_missing'=>'Na hostingu chybí knihovny potřebné ke spuštění měřicího prohlížeče.','installation_failed'=>'Měřicí prohlížeč se na hostingu nepodařilo připravit.'];
    $browserReason=($runtime['state']??'')==='installing'?'Probíhá příprava měřicího prohlížeče. Tato kontrola zatím analyzuje veřejné dokumenty a deklarace.':(($runtime['state']??'')==='ready'?'Prohlížečové měření se spustí po načtení zdrojů a dokumentace.':($runtimeReasons[$runtime['reason']??'']??'Měřicí prohlížeč není dostupný. Skutečné interakce, úložiště a kontrast nebyly měřeny.'));
    $job=['id'=>$id,'token_hash'=>hash('sha256',$token),'url'=>$url,'started_at'=>gmdate('c'),'storage'=>'permanent','expires_at'=>null,'finished_at'=>null,'state'=>'running','cursor'=>0,'manual_policy'=>$policyUrl,'queue'=>[['url'=>$url,'kind'=>'page']],'pages'=>[],'policies'=>[],'errors'=>[],'declared_cookies'=>[],'declaration_sources'=>[],'declaration_errors'=>[],'_declaration_clients'=>[],'engine'=>'source','browser'=>['available'=>false,'status'=>'unverified','reason'=>$browserReason],'scope'=>5];
    if(!gca_save_job($job))return gca_error('Měření se nepodařilo uložit do historie. Zkuste to znovu.',503);
    return ['id'=>$id,'token'=>$token,'report'=>gca_public_report($job)];
}
function gca_step($request) {
    $job=gca_session($request);if(!$job)return gca_error('Měření vypršelo nebo odkaz není platný. Spusťte novou kontrolu.',404);
    $id=$job['id'];if(!gca_lock('job_'.$id,60)) return gca_error('Kontrola právě probíhá. Vyčkejte několik sekund.',409);
    try {
        $job=gca_session($request);if(!$job)return gca_error('Měření vypršelo.',404);
        if ($job['state']!=='running' || (int)$request->get_param('cursor')!==$job['cursor']) return gca_public_report($job);
        if(($job['phase']??'')==='browser'){ $job=gca_browser_poll($job);if(!gca_save_job($job))return gca_error('Výsledek se nepodařilo uložit.',503);return gca_public_report($job); }
        $slot=null;
        for($i=0;$i<3;$i++) if(gca_lock('worker_'.$i,50)){$slot=$i;break;}
        if($slot===null)return gca_error('Všechny kontroly právě pracují. Zkuste to za několik sekund.',429);
        try {
            $item=$job['queue'][$job['cursor']];
            try {
                $f=gca_fetch($item['url']);$page=gca_analyze_page($f['url'],$f['html'],$f['cookies'],$f['status']);
                $discovery=gca_cookieyes_discover($f['html']);
                if($discovery&&($job['cursor']===0||parse_url($f['url'],PHP_URL_HOST)===parse_url($job['url'],PHP_URL_HOST))&&!isset($job['_declaration_clients'][$discovery['client']])&&count($job['_declaration_clients']??[])<2){
                    $job['_declaration_clients'][$discovery['client']]=true;
                    try{$declaration=gca_cookieyes_load($discovery,$f['url']);$job['declared_cookies']=array_merge($job['declared_cookies']??[],$declaration['records']);$job['declaration_sources'][]=['provider'=>'CookieYes','source_url'=>$declaration['source_url'],'source_page_url'=>$f['url'],'language'=>$declaration['language'],'records'=>count($declaration['records'])];}
                    catch(Throwable $e){$job['declaration_errors'][]=['source_page_url'=>$f['url'],'message'=>$e->getMessage()];}
                }
                $page['kind']=$item['kind'];$page['is_target']=$job['cursor']===0 || parse_url($f['url'],PHP_URL_HOST)===parse_url($job['url'],PHP_URL_HOST);$job['pages'][]=$page;
                if ($item['kind']==='policy') {
                    $ids=[];$cookies=[];foreach($job['pages'] as $p){if(empty($p['is_target']))continue;foreach($p['services'] as $s)$ids[]=$s['id'];$cookies=array_merge($cookies,$p['cookies']);}
                    $policy=gca_analyze_policy($f['url'],$f['html'],$ids,$cookies);
                    // Retain only plain text, temporarily, to compare integrations found on later pages.
                    $policy['_text']=gca_policy_text($f['html']);$policy['_dynamic_table']=$discovery['table_present']??false;$policy['_declaration_client']=$discovery['client']??null;$job['policies'][]=$policy;
                }
                if($job['cursor']===0)$job['url']=$f['url'];
                $job['queue']=gca_discover_queue($job['queue'],$job['cursor'],$page,$job['manual_policy']);
            } catch (Throwable $e) { $job['errors'][]=['url'=>$item['url'],'kind'=>$item['kind'],'message'=>$e->getMessage()]; }
            $job['cursor']++;
            if ($job['cursor']>=count($job['queue'])) {
                $job['state']=empty($job['pages'])?'failed':(empty($job['errors'])?'complete':'partial');$job['finished_at']=gmdate('c');
                $ids=[];$cookies=[];foreach($job['pages'] as $p){if(empty($p['is_target']))continue;foreach($p['services'] as $s)$ids[]=$s['id'];$cookies=array_merge($cookies,$p['cookies']);}
                $job['_policy_documents']=$job['policies'];$assessment=gca_analyze_policy_documents($job['policies'],$ids,$cookies,$job['declared_cookies']??[]);$job['policies']=$assessment?[$assessment]:[];$job=gca_browser_begin($job);
            }
            if(!gca_save_job($job))return gca_error('Měření se nepodařilo uložit do historie. Zkuste to znovu.',503);
            return gca_public_report($job);
        } finally { gca_unlock('worker_'.$slot); }
    } finally { gca_unlock('job_'.$id); }
}
add_action('rest_api_init',function() {
    register_rest_route('gdpr-audit/v1','/status',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>function(){return ['version'=>GCA_VERSION,'source_audit'=>function_exists('curl_init'),'browser_audit'=>(gca_runtime_status()['state']??'')==='ready','runtime'=>gca_runtime_status(),'archive_ready'=>(int)get_option('gca_archive_schema',0)>=2,'archive_import_complete'=>(bool)get_option('gca_archive_import_complete',false),'report_urls_ready'=>(bool)get_option('gca_archive_share_complete',false)];}]);
    register_rest_route('gdpr-audit/v1','/shared-report',['methods'=>'POST','permission_callback'=>'gca_same_origin','callback'=>'gca_shared_report']);
    register_rest_route('gdpr-audit/v1','/report',['methods'=>'POST','permission_callback'=>'gca_same_origin','callback'=>'gca_report']);
    register_rest_route('gdpr-audit/v1','/admin-report',['methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>'gca_admin_report']);
    register_rest_route('gdpr-audit/v1','/start',['methods'=>'POST','permission_callback'=>'gca_same_origin','callback'=>'gca_start']);
    register_rest_route('gdpr-audit/v1','/step',['methods'=>'POST','permission_callback'=>'gca_same_origin','callback'=>'gca_step']);
});

add_filter('hello_elementor_page_title',function($show){return is_page('kontrola-cookies')?false:$show;});
