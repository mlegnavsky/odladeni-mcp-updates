<?php
/**
 * Plugin Name: Prádelna Spořilov – jednorázový import designu
 * Version: 1.0.0
 * Author: Martin Legnavsky
 * Update URI: https://plaginy.odladeni.cz/pradelna-design-import
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Description: Import přesné schválené sady 3.0.3 pomocí nativního API Elementoru. Bez veřejného API.
 */
if(!defined('ABSPATH'))exit;
const PSDI_STATE='pradelna_design_import_20261027';
function psdi_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function psdi_package():array {
 $file=__DIR__.'/design.json';
 psdi_check(hash_file('sha256',$file)==='93dd1901a014335ba848a78a911aa0b928664d6b67bd1c1411e4330b6a4bdb61','Otisk designu se změnil.');
 $d=json_decode(file_get_contents($file),true,100,JSON_THROW_ON_ERROR);
 $allowed=['image','eael-simple-menu','button','heading','icon-box','google_maps','eael-fluentform','text-editor','divider','icon'];
 $walk=function($nodes)use(&$walk,$allowed){foreach($nodes as $n){psdi_check(in_array($n['elType']??'', ['section','column','container','widget'],true),'Neplatný prvek.');if(($n['elType']??'')==='widget')psdi_check(in_array($n['widgetType']??'',$allowed,true),'Nepovolený widget.');foreach($n['settings']??[] as $k=>$v){psdi_check(!preg_match('/(?:javascript|custom_js|html_code)/i',(string)$k),'Spustitelný obsah není součástí importu.');if(is_string($v))psdi_check(!preg_match('~<\s*(script|iframe)|javascript\s*:~i',$v),'Spustitelný obsah není součástí importu.');}$walk($n['elements']??[]);}};
 foreach($d['parts'] as $p)$walk($p['template']['content']);
 foreach($d['assets'] as $a){$path=$a['file'];psdi_check(str_starts_with($path,'assets/')&&!str_contains($path,'..')&&is_file(__DIR__.'/'.$path)&&hash_file('sha256',__DIR__.'/'.$path)===$a['sha256'],'Poškozené médium.');}
 return $d;
}
function psdi_nodes(array $nodes,array $media,array $menus,array $urls,string $location,int $form):array {
 $rewrite=function($v)use(&$rewrite,$media,$urls){
  if(!is_array($v))return $v;
  if(isset($v['url'])&&is_string($v['url'])){
   $u=$v['url'];if(isset($media[$u])){$v=array_replace($v,$media[$u]);}
   elseif(!isset($v['id'])&&!preg_match('~^(mailto:|tel:|#)~i',$u)){
    $q=[];parse_str((string)parse_url($u,PHP_URL_QUERY),$q);$path=trim((string)parse_url($u,PHP_URL_PATH),'/');$key=isset($q['file'])?basename($q['file'],'.html'):$path;
    $aliases=[''=>'home','index'=>'home','index.html'=>'home','uvod'=>'home','o-nas'=>'about','sluzby'=>'services','kontakt'=>'contact','ochrana-osobnich-udaju'=>'privacy'];$key=$aliases[$key]??$key;
    $host=parse_url($u,PHP_URL_HOST);
    if((!$host||in_array($host,['plaginy.odladeni.cz','www.pradelnasporilov.cz','pradelnasporilov.cz'],true))&&isset($urls[$key]))$v['url']=$urls[$key].(($fragment=parse_url($u,PHP_URL_FRAGMENT))?'#'.$fragment:'');
   }
  }
  foreach($v as $k=>$val)if(is_array($val))$v[$k]=$rewrite($val);return $v;
 };
 foreach($nodes as &$n){$n['settings']=$rewrite($n['settings']??[]);$w=$n['widgetType']??'';
  if($w==='eael-simple-menu')$n['settings']['eael_simple_menu_menu']=(string)$menus[$location];
  if($w==='eael-fluentform')$n['settings']['form_list']=(string)$form;
  $n['elements']=psdi_nodes($n['elements']??[],$media,$menus,$urls,$location,$form);
 }unset($n);return $nodes;
}
function psdi_form():int {
 psdi_check(class_exists('FluentForm\\App\\Models\\Form'),'Chybí Fluent Forms.');
 $fields=[];
 foreach([['input_text','name','Jméno a příjmení',true],['input_email','email','E-mail',true],['input_text','phone','Telefon',false],['textarea','message','Vaše zpráva',true]] as [$element,$name,$label,$required]){
  $rules=['required'=>['value'=>$required,'message'=>'Toto pole je povinné.']];if($element==='input_email')$rules['email']=['value'=>true,'message'=>'Zadejte platný e-mail.'];
  $fields[]=['element'=>$element,'attributes'=>['name'=>$name,'type'=>$element==='input_email'?'email':'text','placeholder'=>$label,'rows'=>5],'settings'=>['label'=>$label,'label_placement'=>'top','validation_rules'=>$rules,'conditional_logics'=>[]],'editor_options'=>['template'=>$element==='textarea'?'inputTextarea':'inputText']];
 }
 $fields[]=['element'=>'gdpr_agreement','attributes'=>['name'=>'gdpr_agreement','value'=>false],'settings'=>['label'=>'Souhlas se zpracováním údajů','tnc_html'=>'Souhlasím se zpracováním osobních údajů pro vyřízení mého dotazu.','validation_rules'=>['required'=>['value'=>true,'message'=>'Potvrďte souhlas pro vyřízení dotazu.']],'conditional_logics'=>[]],'editor_options'=>['template'=>'gdprAgreement']];
 $blank=\FluentForm\App\Models\Form::resolvePredefinedForm(['predefined'=>'blank_form']);$schema=json_decode($blank['form_fields'],true);$schema['fields']=$fields;$schema['submitButton']['settings']['button_ui']['text']='Odeslat zprávu';
 $data=\FluentForm\App\Models\Form::prepare(['title'=>'Prádelna Spořilov – kontaktní formulář','status'=>'unpublished','form_fields'=>wp_json_encode($schema),'type'=>'form','has_payment'=>0]);$f=\FluentForm\App\Models\Form::create($data);$id=(int)$f->id;psdi_check($id>0,'Formulář nebyl vytvořen.');
 $settings=\FluentForm\App\Models\Form::getFormsDefaultSettings();$settings['confirmation']['redirectTo']='samePage';$settings['confirmation']['messageToShow']='Děkujeme za zprávu. Ozveme se vám co nejdříve.';
 \FluentForm\App\Models\FormMeta::persist($id,'formSettings',wp_json_encode($settings));$notification=$blank['notifications'];$notification['enabled']=true;$notification['sendTo']=['type'=>'email','email'=>'info@pradelnasporilov.cz'];$notification['subject']='Nový dotaz z webu Prádelna Spořilov';$notification['message']='{all_data}';
 \FluentForm\App\Models\FormMeta::persist($id,'notifications',wp_json_encode($notification));return $id;
}
function psdi_import():void {
 $prior=get_option(PSDI_STATE,[]);if(($prior['status']??'')==='done')return;
 psdi_check(empty($prior),'Předchozí import vyžaduje kontrolu uloženého stavu.');
 psdi_check(untrailingslashit(home_url('/'))==='https://www.pradelnasporilov.cz','Balíček patří pouze Prádelně Spořilov.');
 psdi_check(class_exists('Elementor\\Plugin')&&function_exists('pi_odl_shared_layout_supported')&&pi_odl_shared_layout_supported(),'Vyžadován Hello Elementor bez konfliktu hlavičky.');
 $d=psdi_package();require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';
 $optionKeys=['show_on_front','page_on_front','page_for_posts','elementor_active_kit','odladeni_shared_layout_v1','odladeni_design_deployment_v1','blogname','blogdescription','WPLANG','nav_menu_options'];$before=[];foreach($optionKeys as $k){$marker=new stdClass();$v=get_option($k,$marker);$before[$k]=['exists'=>$v!==$marker,'value'=>$v===$marker?null:$v];}
 $dir=pi_odl_backup_location();psdi_check(is_string($dir)&&is_dir($dir),'Chybí soukromé úložiště záloh.');$backup=$dir.'/pradelna-before-native-20261027-'.bin2hex(random_bytes(6)).'.json';$raw=wp_json_encode(['site'=>home_url('/'),'time'=>gmdate('c'),'options'=>$before,'pages'=>get_posts(['post_type'=>'page','post_status'=>'any','numberposts'=>-1])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);psdi_check(file_put_contents($backup,$raw,LOCK_EX)===strlen($raw),'Zálohu nelze uložit.');chmod($backup,0600);psdi_check(hash_file('sha256',$backup)===hash('sha256',$raw),'Záloha nebyla ověřena.');
 $state=['status'=>'running','backup'=>$backup,'backup_sha256'=>hash('sha256',$raw),'created'=>[],'menus'=>[],'media'=>[],'started_at'=>gmdate('c'),'quality_review'=>'Explicitly waived by site owner; visual QA follows actual deployment.'];update_option(PSDI_STATE,$state,false);
 try{
  $media=[];foreach($d['assets'] as $a){$temp=wp_tempnam(basename($a['file']));psdi_check(copy(__DIR__.'/'.$a['file'],$temp),'Médium nelze připravit.');$id=media_handle_sideload(['name'=>basename($a['file']),'tmp_name'=>$temp],0,$a['title']);psdi_check(!is_wp_error($id),'Médium nelze importovat.');wp_update_post(['ID'=>$id,'post_title'=>$a['title'],'post_content'=>$a['description']]);update_post_meta($id,'_wp_attachment_image_alt',$a['alt']);$media[$a['url']]=['id'=>$id,'url'=>wp_get_attachment_url($id)];$state['media'][]=$id;update_option(PSDI_STATE,$state,false);}
  $ids=[];$slugs=['home'=>'uvod','about'=>'o-nas','services'=>'sluzby','contact'=>'kontakt','privacy'=>'ochrana-osobnich-udaju'];
  foreach($d['parts'] as $p){$id=wp_insert_post(['post_type'=>$p['type']==='page'?'page':'elementor_library','post_status'=>'draft','post_title'=>$p['name'],'post_name'=>$slugs[$p['key']]??$p['key'],'meta_input'=>['_elementor_edit_mode'=>'builder','_elementor_template_type'=>$p['type']==='page'?'wp-page':'section','_wp_page_template'=>'default','_psdi_import'=>'20261027']],true);psdi_check(!is_wp_error($id)&&$id>0,'Dokument nelze vytvořit.');$ids[$p['key']]=$id;$state['created'][]=$id;update_option(PSDI_STATE,$state,false);}
  $kit=wp_insert_post(['post_type'=>'elementor_library','post_status'=>'publish','post_title'=>'Prádelna Spořilov – globální styly','meta_input'=>['_elementor_template_type'=>'kit','_elementor_page_settings'=>$d['site_settings']]],true);psdi_check(!is_wp_error($kit),'Globální styly nelze vytvořit.');$state['created'][]=$kit;update_option(PSDI_STATE,$state,false);update_option('elementor_active_kit',$kit);\Elementor\Plugin::$instance->kits_manager->get_active_kit();
  $menus=[];foreach($d['menus'] as $m){$menu=wp_create_nav_menu('Prádelna Spořilov – '.$m['location'].' – 20261027');psdi_check(!is_wp_error($menu),'Menu nelze vytvořit.');$menus[$m['location']]=$menu;$state['menus'][]=$menu;update_option(PSDI_STATE,$state,false);$parents=[];foreach($m['items'] as $pos=>$item){$mi=wp_update_nav_menu_item($menu,0,['menu-item-title'=>$item['label'],'menu-item-object'=>'page','menu-item-object-id'=>$ids[$item['page']],'menu-item-type'=>'post_type','menu-item-status'=>'publish','menu-item-position'=>$pos+1,'menu-item-parent-id'=>$item['parent']!==''?$parents[$item['parent']]:0]);psdi_check(!is_wp_error($mi),'Položku menu nelze vytvořit.');$parents[$item['id']]=$mi;}}
  $form=psdi_form();$state['form_id']=$form;update_option(PSDI_STATE,$state,false);
  $urls=[];foreach($slugs as $key=>$slug)$urls[$key]=$key==='home'?home_url('/'):get_permalink($ids[$key]);
  foreach($d['parts'] as $p){$id=$ids[$p['key']];$nodes=psdi_nodes($p['template']['content'],$media,$menus,$urls,in_array($p['type'],['header','footer'],true)?$p['type']:'',$form);$doc=\Elementor\Plugin::$instance->documents->get($id);psdi_check((bool)$doc,'Elementor dokument není dostupný.');$saved=$doc->save(['elements'=>$nodes,'settings'=>$p['template']['page_settings']??[]]);psdi_check($saved!==false,'Elementor dokument nelze uložit.');$actual=get_post_meta($id,'_elementor_data',true);if(is_string($actual))$actual=json_decode($actual,true);psdi_check(is_array($actual)&&count($actual)===count($nodes),'Uložení dokumentu nebylo ověřeno.');wp_update_post(['ID'=>$id,'post_status'=>'publish']);if($p['type']==='page'){update_post_meta($id,'rank_math_title',$p['seo']['title']??$p['name']);update_post_meta($id,'rank_math_description',$p['seo']['description']??'');}else update_post_meta($id,'rank_math_robots',['noindex']);}
  \FluentForm\App\Models\Form::where('id',$form)->update(['status'=>'published']);
  update_option('show_on_front','page');update_option('page_on_front',$ids['home']);update_option('page_for_posts',0);update_option('blogname','Prádelna Spořilov');update_option('blogdescription','Praní, žehlení a svoz prádla po Praze a okolí');
  update_option('odladeni_shared_layout_v1',['schema'=>1,'enabled'=>true,'header_id'=>$ids['header'],'footer_id'=>$ids['footer'],'generation'=>(int)(pi_odl_shared_layout_state()['generation']??0)+1]);
  update_option('odladeni_design_deployment_v1',['job_id'=>'native-pradelna-20261027','design_hash'=>hash_file('sha256',__DIR__.'/design.json'),'documents'=>array_values($ids),'menus'=>$menus,'at'=>gmdate('c')]);
  \Elementor\Plugin::$instance->files_manager->clear_cache();flush_rewrite_rules(false);
  psdi_check((int)get_option('page_on_front')===$ids['home']&&pi_odl_shared_layout_state()['enabled']===true,'Aktivace se neověřila.');$state['status']='done';$state['ids']=$ids;$state['kit_id']=$kit;$state['finished_at']=gmdate('c');update_option(PSDI_STATE,$state,false);
 }catch(Throwable $e){foreach($before as $k=>$v){if($v['exists'])update_option($k,$v['value']);else delete_option($k);}foreach($state['created'] as $id)wp_update_post(['ID'=>$id,'post_status'=>'draft']);if(!empty($state['form_id']))\FluentForm\App\Models\Form::where('id',$state['form_id'])->update(['status'=>'unpublished']);$state['status']='failed_recovered';$state['error']=$e->getMessage();update_option(PSDI_STATE,$state,false);wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Import Prádelny – chyba: '.substr($e->getMessage(),0,120),'post_content'=>'Původní nastavení bylo obnoveno; nové dokumenty zůstaly v konceptech.']);throw $e;}
}
register_activation_hook(__FILE__,function(){try{psdi_import();}catch(Throwable $e){wp_die(esc_html('Import Prádelny se zastavil a původní nastavení bylo obnoveno: '.$e->getMessage()));}});
add_action('admin_notices',function(){if(!current_user_can('manage_options'))return;$s=get_option(PSDI_STATE,[]);if(!$s)return;echo '<div class="notice notice-'.(($s['status']??'')==='done'?'success':'warning').'"><p>'.esc_html('Import Prádelny Spořilov: '.($s['status']??'').(!empty($s['error'])?' — '.$s['error']:'')).'</p></div>';});
