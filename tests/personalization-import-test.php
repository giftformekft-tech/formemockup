<?php
define('ABSPATH', __DIR__);
$options = $metadata = array();
function __($s) { return $s; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_textarea_field($s) { return sanitize_text_field($s); }
function sanitize_title($s) { return $s; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_unslash($s) { return is_array($s) ? array_map('wp_unslash', $s) : stripslashes($s); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return esc_attr($s); }
function selected($a, $b, $echo=false) { return $a === $b ? ' selected' : ''; }
function wp_nonce_field($a, $b) {}
function current_time($s) { return '2026-09-23'; }
function get_option($k, $d=false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a=false) { $GLOBALS['options'][$k]=$v; return true; }
function get_post_meta($id, $key, $single=true) { return $GLOBALS['metadata'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['metadata'][$id][$key]=$value; }
function delete_post_meta($id, $key) { unset($GLOBALS['metadata'][$id][$key]); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_message() { return $this->message; }
}
require_once dirname(__DIR__) . '/includes/class-custom-fields-manager.php';
require_once dirname(__DIR__) . '/includes/class-personalization-import.php';
require_once dirname(__DIR__) . '/admin/class-custom-fields-page.php';
require_once dirname(__DIR__) . '/includes/class-bulk-queue.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function data_for($kinds) {
    $values = array('month'=>'január','name'=>'Bence','year'=>'1990','age'=>'36');
    $fields = array();
    foreach ($kinds as $kind) {
        $visible = $kind === 'month' ? 'JANUÁRBAN' : $values[$kind];
        $fields[] = array('kind'=>$kind,'value'=>$values[$kind],'visible_text'=>$visible,'evidence'=>'Minta: '.$visible,'confidence'=>'high');
    }
    return array('schema_version'=>1,'basis'=>'final_image','status'=>'detected','fields'=>$fields,'reason'=>'Cserélhető felirat.');
}
$mappings = array();
foreach (MG_Personalization_Import::profiles() as $key=>$profile) {
    $fields = $ids = array();
    foreach ($profile['kinds'] as $kind) {
        $ids[$kind] = 'field_' . $kind;
        $fields[] = array('id'=>'field_'.$kind,'label'=>$kind,'type'=>$kind==='month'?'select':($kind==='name'?'text':'number'), 'required'=>true, 'options'=>$kind==='month'?array('január','február'):array());
    }
    $preset = MG_Custom_Fields_Manager::save_preset($profile['label'], $fields);
    $mappings[$key] = array('preset_id'=>$preset, 'fields'=>$ids);
}
check(MG_Personalization_Import::save_mappings($mappings) === true, 'All six mappings save');
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/personalization-contract.json'), true);
check(count($cases) === 6, 'Shared DesignFlow contract has all six profiles');
foreach ($cases as $case) {
    $result = MG_Personalization_Import::resolve($case['sidecar']['personalization']);
    check($result['state'] === 'matched' && $result['preset_id'] === $mappings[$case['profile']]['preset_id'], 'Actual DesignFlow export selects exact preset: ' . $case['profile']);
}
foreach (MG_Personalization_Import::profiles() as $key=>$profile) {
    $result = MG_Personalization_Import::resolve(data_for($profile['kinds']));
    check($result['state']==='matched' && $result['preset_id']===$mappings[$key]['preset_id'], 'Exact profile: '.$key);
}
check(MG_Personalization_Import::resolve(data_for(array('age')))['state']==='review', 'Age alone does not select calendar year');
$bad = data_for(array('month')); $bad['fields'][]=$bad['fields'][0];
check(MG_Personalization_Import::resolve($bad)['state']==='review', 'Repeated ambiguous fields');
foreach (array('basis'=>'reference_image','status'=>'uncertain','schema_version'=>2) as $key=>$value) {
    $bad=data_for(array('month')); $bad[$key]=$value;
    check(MG_Personalization_Import::resolve($bad)['state']==='review', 'Uncertain provenance/version is not automatic');
}
$bad=data_for(array('month')); $bad['fields'][0]['evidence']='Nincs hónap';
check(MG_Personalization_Import::resolve($bad)['state']==='review', 'Evidence must contain visible text');
$bad=data_for(array('month')); $bad['fields'][0]['confidence']='medium';
check(MG_Personalization_Import::resolve($bad)['state']==='review', 'Confidence must be high');
$bad=data_for(array('month')); $bad['fields'][0]['value']='március'; $bad['fields'][0]['visible_text']='MÁRCIUSBAN'; $bad['fields'][0]['evidence']='MÁRCIUSBAN születtek';
check(MG_Personalization_Import::resolve($bad)['state']==='review', 'Missing select value is rejected');
check(MG_Personalization_Import::resolve(null)['state']==='missing', 'Legacy JSON is unanalysed');
check(MG_Personalization_Import::resolve(array('schema_version'=>1,'basis'=>'final_image','status'=>'none','fields'=>array()))['state']==='none', 'Explicit negative result');
$bad=$mappings; $bad['name_year']['fields']['year']=$bad['name_year']['fields']['name'];
check(is_wp_error(MG_Personalization_Import::save_mappings($bad)), 'Two meanings cannot map to same field');
check(MG_Personalization_Import::mappings()===$mappings, 'Invalid save is atomic');
$bad=$mappings; $bad['month']['preset_id']='deleted';
check(is_wp_error(MG_Personalization_Import::save_mappings($bad)), 'Unknown preset rejected');
$selection=MG_Personalization_Import::selection_from_request(array('personalization_action'=>'auto','preset_id'=>$mappings['month']['preset_id'],'personalization'=>addslashes(json_encode(data_for(array('month'))))));
check(!is_wp_error($selection), 'Valid automatic import accepted');
check(MG_Personalization_Import::apply_selection(42,$selection)===true, 'Automatic preset applied');
check(MG_Custom_Fields_Manager::is_custom_product(42), 'Product marked custom');
$saved=MG_Custom_Fields_Manager::get_fields_for_product(42);
check($saved[0]['default']==='', 'Example month never becomes customer answer');
MG_Custom_Fields_Manager::save_fields_for_product(42,array(array('id'=>'manual','label'=>'Kézi','type'=>'text')));
check(MG_Personalization_Import::apply_selection(42,$selection)===true, 'Repeated import accepted');
check(MG_Custom_Fields_Manager::get_fields_for_product(42)[0]['id']==='manual', 'Existing manual fields survive automatic import');
MG_Personalization_Import::apply_selection(42,array('mode'=>'preserve'));
check(MG_Custom_Fields_Manager::is_custom_product(42), 'Missing JSON does not clear existing custom flag');
$manual=MG_Personalization_Import::selection_from_request(array('personalization_action'=>'manual','custom_product'=>'0'));
MG_Personalization_Import::apply_selection(42,$manual);
check(!MG_Custom_Fields_Manager::is_custom_product(42), 'Explicit manual disable works');
$methods=(new ReflectionClass('MG_Bulk_Queue'))->getMethods();
$method=null;
foreach($methods as $candidate) if (str_contains($candidate->getName(),'payload')) $method=$candidate;
check($method!==null,'Queue payload sanitizer found');
$clean=$method->invoke(null,array('design_path'=>'/test.png','product_keys'=>array('shirt'),'personalization_selection'=>$selection));
check($clean['personalization_selection']===$selection,'Queue retains evidence and explicit action');
ob_start(); MG_Personalization_Import::render_admin(); $html=ob_get_clean();
check(substr_count($html,'class="mg-personalization-mapping"')===6,'Admin renders six editable mappings');
check(str_contains($html,'personalization_mappings[year_month][fields][month]'),'Combined mapping exposes individual fields');
MG_Custom_Fields_Manager::delete_preset($mappings['month']['preset_id']);
check(MG_Personalization_Import::resolve(data_for(array('month')))['state']==='review','Deleted preset invalidates preview');
check(is_wp_error(MG_Personalization_Import::validate_selection($selection)),'Deleted preset invalidates queued selection');
echo "PASS: six profiles, evidence, admin mapping, manual overrides, queue persistence and legacy JSON.\n";
