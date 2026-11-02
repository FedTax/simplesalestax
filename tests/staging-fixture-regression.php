<?php
/** Persistent fixture behavior, without network calls or real users. */
define('ABSPATH', __DIR__);
define('OBJECT','OBJECT');
$options=[];$users=[];$meta=[];$pages=[];$created_users=0;$created_pages=0;$current_uid=0;
function add_action(...$a){} function add_filter(...$a){}
function home_url(){global $site;return $site??'https://example.test/shop/';}
function trailingslashit($v){return rtrim($v,'/').'/';} function untrailingslashit($v){return rtrim($v,'/');}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,...$a){global $options;$options[$k]=$v;}
function add_option($k,$v,...$a){global $options;if(isset($options[$k]))return false;$options[$k]=$v;return true;}
function delete_option($k){global $options;unset($options[$k]);}
function get_user_meta($id,$k,...$a){global $meta;return $meta[$id][$k]??'';}
function update_user_meta($id,$k,$v){global $meta;$meta[$id][$k]=$v;}
function get_userdata($id){global $users;return $users[$id]??false;}
function get_users(...$a){global $users;return array_values($users);}
function username_exists($v){foreach(get_users() as $u)if($u->user_login===$v)return $u->ID;return false;}
function email_exists($v){foreach(get_users() as $u)if($u->user_email===$v)return $u->ID;return false;}
function wp_generate_password(...$a){return bin2hex(random_bytes(24));}
function wp_insert_user($v){global $users,$created_users;$id=++$created_users+100;$users[$id]=(object)['ID'=>$id,'user_login'=>$v['user_login'],'user_email'=>$v['user_email'],'roles'=>[$v['role']],'caps'=>[$v['role']=>true]];return $id;}
function is_wp_error($v){return $v instanceof WP_Error;} class WP_Error{public $code;function __construct($c,...$a){$this->code=$c;}}
function get_page_by_path($slug,...$a){global $pages;foreach($pages as $p)if($p->post_name===$slug)return $p;return null;}
function get_post($id){global $pages;return $pages[$id]??null;}
function has_shortcode($content,$tag){return str_contains($content,'['.$tag.']');}
function has_block($block,$page){return str_contains($page->post_content,'wp:'.$block);}
function wp_insert_post($v,...$a){global $pages,$created_pages;$id=++$created_pages;$pages[$id]=(object)array_merge(['ID'=>$id,'post_password'=>''],$v);return $id;}
function get_permalink($id){global $pages;return trailingslashit(home_url()).$pages[$id]->post_name.'/';}
function get_current_user_id(){global $current_uid;return $current_uid;}
function current_user_can(...$a){return true;}
function check($v,$m){if(!$v)throw new Exception($m);}
function invoke($method,...$args){return (new ReflectionMethod('SST_Staging_QA',$method))->invoke(null,...$args);}
function rejects($fn,$message){try{$fn();}catch(RuntimeException $e){return;}throw new Exception($message);}
class Request{function __construct(private $v){}function get_json_params(){return $this->v;}}
require __DIR__.'/../cypress/staging-site/helper/sst-staging-helper.php';
// Migration cannot adopt by name alone. It checks old run marker, role and exact synthetic email.
$old='aaaaaaaa-1111-2222-3333-444444444444';
$users[7]=(object)['ID'=>7,'user_login'=>'sstqa-aaaaaaaa-runner','user_email'=>'sstqa-aaaaaaaa-runner@example.test','roles'=>['administrator'],'caps'=>['administrator'=>true]];$meta[7]['_sst_qa_run']=$old;
check(invoke('actor','runner','administrator')->ID===7,'USR-01 adopts proven retained runner');
$b=invoke('actor','buyer','customer');$e=invoke('actor','exempt','customer');
check($created_users===2,'USR-01 only missing actors created');
$b->display_name='Changed visible name';
check(invoke('actor','buyer','customer')->ID===$b->ID&&$created_users===2,'USR-02/03 resume/new run/display rename keep IDs');
$b->roles=['administrator'];rejects(fn()=>invoke('actor','buyer','customer'),'USR-05 role drift must reject');$b->roles=['customer'];
$b->caps['manage_options']=true;rejects(fn()=>invoke('actor','buyer','customer'),'USR-05 direct capability drift must reject');unset($b->caps['manage_options']);
unset($users[$e->ID]);$e2=invoke('actor','exempt','customer');check($e2->ID!==$e->ID&&$created_users===3,'USR-04 recreate only missing actor');
$registry=$options[SST_Staging_QA::REGISTRY];$site='https://other.test/shop/';rejects(fn()=>invoke('registry'),'USR-06 cross-target registry must reject');unset($site);
$options[SST_Staging_QA::REGISTRY]['actors']=[];$users[9]=(object)['ID'=>9,'user_login'=>'sstqa-buyer','user_email'=>'human@example.test','roles'=>['customer'],'caps'=>['customer'=>true]];
unset($users[$b->ID]);rejects(fn()=>invoke('actor','buyer','customer'),'USR-05 untagged login collision must reject');$options[SST_Staging_QA::REGISTRY]=$registry;
$classic='[woocommerce_checkout]';$blocks='<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-fields-block /--><!-- /wp:woocommerce/checkout -->';
$c=invoke('checkout_page','checkoutClassic','classic-checkout',$classic);$d=invoke('checkout_page','checkoutBlock','block-checkout',$blocks);check($created_pages===2,'PAGE-01 exact two pages');
$pages[$c]->post_title='An unrelated valid title';
check(invoke('checkout_page','checkoutClassic','classic-checkout',$classic)===$c&&invoke('checkout_page','checkoutBlock','block-checkout',$blocks)===$d&&$created_pages===2,'PAGE-02/04 title-independent reuse');
unset($pages[$d]);$d=invoke('checkout_page','checkoutBlock','block-checkout',$blocks);check($created_pages===3,'PAGE-03 create only missing page');
$pages[$c]->post_status='draft';rejects(fn()=>invoke('checkout_page','checkoutClassic','classic-checkout',$classic),'PAGE-05 draft conflict');$pages[$c]->post_status='publish';
$pages[$c]->post_content='ordinary content';rejects(fn()=>invoke('checkout_page','checkoutClassic','classic-checkout',$classic),'PAGE-05 preserve unrelated content');$pages[$c]->post_content=$classic;
check(get_permalink($c)==='https://example.test/shop/classic-checkout/','PAGE-08 retains base path');
$options[SST_Staging_QA::OPTION]=['id'=>'run-a','active'=>true,'names'=>[],'leaseToken'=>'private-lease','userIds'=>[7]];
$r=fn($k)=>SST_Staging_QA::control(new Request(['runId'=>'run-a','action'=>'reserveName','key'=>$k]));
$n=$r('scenario-1');check($n['label']==='Txc User 1'&&$r('scenario-1')['token']===$n['token'],'NAME-01/03 retry preserves reservation');
check($r('abandoned')['number']===2,'NAME-03 abandoned reservation consumes number');
$options[SST_Staging_QA::OPTION]['id']='run-b';$n=SST_Staging_QA::control(new Request(['runId'=>'run-b','action'=>'reserveName','key'=>'new-run']));check($n['number']===3,'NAME-04 sequence continues across runs');
$_COOKIE['sst_qa_run']='run-b';check(!invoke('scoped'),'USR-06 public run ID is not a lease');$_COOKIE['sst_qa_run']='private-lease';check(invoke('scoped'),'guest session uses active secret lease');
$options[SST_Staging_QA::OPTION]['active']=false;check(!invoke('scoped'),'ended leases are inert');
echo "Persistent actor/page/lease/name regression checks passed.\n";
// Explicit leases and reservation ownership prevent tagging historical orders of a shared buyer.
class FixtureOrder {
 public $meta=[];function __construct(public $id=0,public $status='pending',public $customer=7){}
 function get_id(){return $this->id;}function get_status(){return $this->status;}function get_customer_id(){return $this->customer;}
 function get_meta($key){return $this->meta[$key]??'';}function update_meta_data($key,$value){$this->meta[$key]=$value;}
}
$options[SST_Staging_QA::OPTION]['active']=true;$_COOKIE['sst_qa_order']=$n['token'];
$historical=new FixtureOrder(42,'completed');SST_Staging_QA::tag_order($historical);check(!$historical->meta,'Shared customer ID cannot tag an old unowned order');
$protected=new FixtureOrder(851,'checkout-draft');rejects(fn()=>SST_Staging_QA::tag_order($protected),'QA writes to protected order 851 must reject');check(!$protected->meta,'Captured order 851 remains explicitly protected');
$foreign=new FixtureOrder(44,'checkout-draft');$foreign->meta['_sst_qa_run']='run-a';rejects(fn()=>SST_Staging_QA::tag_order($foreign),'QA writes to foreign-run orders must reject');check($foreign->meta===['_sst_qa_run'=>'run-a'],'Foreign-run draft cannot be reassigned');
$fresh=new FixtureOrder();SST_Staging_QA::tag_order($fresh);check($fresh->get_meta('_sst_qa_name')==='Txc User 3','New order is tagged from its reservation without changing its customer fields');
$fresh->id=45;SST_Staging_QA::bind_order_name($fresh);SST_Staging_QA::bind_order_name($fresh);
check($options[SST_Staging_QA::OPTION]['names'][$n['token']]['orderId']===45,'Reservation binding is idempotent for one order');
rejects(fn()=>SST_Staging_QA::tag_order(new FixtureOrder()),'A bound reservation cannot create a second logical order');
$options['sst_qa_name_lock']='concurrent-run';$busy=SST_Staging_QA::control(new Request(['runId'=>'run-b','action'=>'reserveName','key'=>'concurrent']));check($busy->code==='name_locked'&&$options['sst_qa_name_sequence']===3,'Concurrent reservation rejects without consuming or reusing a number');
echo "Historical ownership, protected-order and duplicate-reservation checks passed.\n";

unset($options['sst_qa_name_lock']);$next=SST_Staging_QA::control(new Request(['runId'=>'run-b','action'=>'reserveName','key'=>'later-order']));$_COOKIE['sst_qa_order']=$next['token'];
rejects(fn()=>SST_Staging_QA::tag_order($fresh),'Later reservation must not rewrite an already named current-run order');
check($fresh->get_meta('_sst_qa_name')==='Txc User 3','Named order retains its label after the shopper starts another logical order');
echo "Current-run historical naming isolation checks passed.\n";

// Admin/REST requests often have no initialized WC()->session; baseline reset must still clear only leased users.
class ResetFixtureUser { public $ID,$user_login,$user_email,$roles,$caps,$display_name,$first_name,$last_name; function __construct($data){foreach($data as $key=>$value)$this->$key=$value;} function set_role($role){$this->roles=[$role];} }
function WC(){static $wc;return $wc??= (object)['session'=>null];}
function apply_filters($name,$value){return $value;}
function wp_update_user($data){global $users;foreach($data as $key=>$value)if($key!=='ID')$users[$data['ID']]->$key=$value;}
function delete_user_meta($id,$key){global $meta;unset($meta[$id][$key]);}
function get_current_blog_id(){return 1;}
function delete_transient($key){}
class WC_Session_Handler { function delete_session($id){global $reset_sessions;$reset_sessions[]=$id;} }
$users[7]=new ResetFixtureUser((array)$users[7]);$options[SST_Staging_QA::OPTION]['userIds']=[7];$reset_sessions=[];
invoke('reset_actors');check($reset_sessions===[7],'Null admin/REST WC session still deletes exactly the leased actor session');
check($meta[7]['billing_first_name']==='TaxCloud'&&$meta[7]['billing_last_name']==='QA Runner','Explicit runner billing baseline restored');
echo "Null-session baseline recovery checks passed.\n";
