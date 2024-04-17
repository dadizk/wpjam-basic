<?php
function wpjam_load($hooks, $callback){
	if(!$callback && !is_callable($callback)){
		return;
	}

	$fn 	= fn($hooks) => array_filter($hooks, fn($hook) => !did_action($hook));
	$hooks	= $fn((array)$hooks);

	if(!$hooks){
		call_user_func($callback);
	}elseif(count($hooks) == 1){
		add_action(current($hooks), $callback);
	}else{
		array_walk($hooks, fn($hook) => add_action($hook, fn() => $fn($hooks) ? null : call_user_func($callback)));
	}
}

function wpjam_loaded($action, ...$args){
	if(did_action('wp_loaded')){
		do_action($action, ...$args);
	}else{
		$object = wpjam_get_items_object('loaded');

		if(!$object->get_items()){
			add_action('wp_loaded', fn() => array_map(fn($args) => do_action(...$args), $object->get_items()));
		}

		$object->add_item([$action, ...$args]);
	}
}

function wpjam_hooks($hooks){
	if(is_callable($hooks)){
		$hooks	= call_user_func($hooks);
	}

	wpjam_call_list('add_filter', $hooks);
}

function wpjam_call($callback, ...$args){
	if(is_array($callback) && !is_object($callback[0])){
		return wpjam_call_method($callback[0], $callback[1], ...$args);
	}else{
		return call_user_func_array($callback, $args);
	}
}

function wpjam_call_list($callback, $items){
	if($items && is_array($items)){
		if(wp_is_numeric_array(reset($items))){
			$items	= array_map(fn($item) => wpjam_call($callback, ...$item), $items);
		}else{
			$items	= wpjam_call($callback, ...$items);
		}
	}

	return $items;
}

function wpjam_try($callback, ...$args){
	wpjam_throw_if_error($callback);

	try{
		return wpjam_throw_if_error(wpjam_call($callback, ...$args));
	}catch(Throwable $e){
		throw $e;
	}
}

function wpjam_catch($callback, ...$args){
	try{
		return wpjam_call($callback, ...$args);
	}catch(WPJAM_Exception $e){
		return $e->get_wp_error();
	}catch(Throwable $e){
		trigger_error($e->getMessage().':'.$e->getTraceAsString());
		return new WP_Error($e->getCode() ?: 'error', $e->getMessage());
	}
}

function wpjam_db_transaction($callback, ...$args){
	$GLOBALS['wpdb']->query("START TRANSACTION;");

	try{
		$result	= call_user_func($callback, ...$args);

		if($GLOBALS['wpdb']->last_error){
			throw new Exception($GLOBALS['wpdb']->last_error);
		}

		$GLOBALS['wpdb']->query("COMMIT;");

		return $result;
	}catch(Exception $e){
		$GLOBALS['wpdb']->query("ROLLBACK;");

		return false;
	}
}

function wpjam_call_for_blog($blog_id, $callback, ...$args){
	try{
		$switched	= (is_multisite() && $blog_id && $blog_id != get_current_blog_id()) ? switch_to_blog($blog_id) : false;

		return call_user_func_array($callback, $args);
	}finally{
		if($switched){
			restore_current_blog();
		}
	}
}

function wpjam_ob_get_contents($callback, ...$args){
	ob_start();

	call_user_func_array($callback, $args);

	return ob_get_clean();
}

function wpjam_value_callback($callback, $name, $id){
	if(is_array($callback) && !is_object($callback[0])){
		$args	= [$id, $name];
		$parsed	= wpjam_parse_method($callback[0], $callback[1], $args);

		if(is_wp_error($parsed)){
			return $parsed;
		}elseif(is_object($parsed[0])){
			return call_user_func_array($parsed, $args);
		}
	}

	return call_user_func($callback, $name, $id);
}

function wpjam_parse_method($model, $method, &$args=[], $number=1){
	if(is_object($model)){
		$object	= $model;
		$model	= get_class($model);
	}else{
		$object	= null;

		if(!class_exists($model)){
			return new WP_Error('invalid_model', [$model]);
		}
	}

	if(!method_exists($model, $method)){
		if(method_exists($model, '__callStatic')){
			$is_public = true;
			$is_static = true;
		}elseif(method_exists($model, '__call')){
			$is_public = true;
			$is_static = false;
		}else{
			return new WP_Error('undefined_method', [$model.'->'.$method.'()']);
		}
	}else{
		$reflection	= new ReflectionMethod($model, $method);
		$is_public	= $reflection->isPublic();
		$is_static	= $reflection->isStatic();
	}

	if($is_static){
		return $is_public ? [$model, $method] : $reflection->getClosure();
	}

	if(is_null($object)){
		if(!method_exists($model, 'get_instance')){
			return new WP_Error('undefined_method', [$model.'->get_instance()']);
		}

		for($i=0; $i < $number; $i++){ 
			$params[]	= $param = array_shift($args);

			if(is_null($param)){
				return new WP_Error('instance_required', '实例方法对象才能调用');
			}
		}

		$object	= call_user_func_array([$model, 'get_instance'], $params);

		if(!$object){
			return new WP_Error('invalid_id', [$model]);
		}
	}

	return $is_public ? [$object, $method] : $reflection->getClosure($object);
}

function wpjam_call_method($model, $method, ...$args){
	$parsed	= wpjam_parse_method($model, $method, $args);

	return is_wp_error($parsed) ? $parsed : call_user_func_array($parsed, $args);
}

function wpjam_get_callback_parameters($callback){
	if(is_array($callback)){
		$reflection	= new ReflectionMethod(...$callback);
	}else{
		$reflection	= new ReflectionFunction($callback);
	}

	return $reflection->getParameters();
}

function wpjam_get_current_priority($name=null){
	$name	= $name ?: current_filter();
	$hook	= $GLOBALS['wp_filter'][$name] ?? null;

	return $hook ? $hook->current_priority() : null;
}

function wpjam_activation($callback='', $hook=null){
	$handler	= wpjam_get_handler(['transient_key'=>'wpjam-actives']);

	if($callback){
		$handler->add($hook ? compact('hook', 'callback') : $callback);
	}else{
		$actives	= $handler->get_items();

		if($actives && is_array($actives)){
			foreach($actives as $active){
				if(is_array($active) && isset($active['hook'])){
					add_action($active['hook'], $active['callback']);
				}else{
					add_action('wp_loaded', $active);
				}
			}

			$handler->empty();
		}
	}
}

function wpjam_register_route($module, $args){
	return WPJAM_Route::add($module, $args);
}

function wpjam_get_query_var($key, $wp=null){
	$wp	= $wp ?: $GLOBALS['wp'];

	return $wp->query_vars[$key] ?? null;
}

function wpjam_get_current_user($required=false){
	$user	= wpjam_get_current_var('user', $isset);

	if(!$isset){
		$user	= apply_filters('wpjam_current_user', null);

		if(!is_null($user)){
			wpjam_set_current_var('user', $user);
		}
	}

	if($required){
		if(is_null($user)){
			return new WP_Error('bad_authentication');
		}
	}else{
		if(is_wp_error($user)){
			return null;
		}
	}

	return $user;
}

function wpjam_json_encode($data){
	return WPJAM_JSON::encode($data, JSON_UNESCAPED_UNICODE);
}

function wpjam_json_decode($json, $assoc=true){
	return WPJAM_JSON::decode($json, $assoc);
}

function wpjam_send_json($data=[], $status_code=null){
	WPJAM_JSON::send($data, $status_code);
}

function wpjam_register_json($name, $args=[]){
	return WPJAM_JSON::register($name, $args);
}

function wpjam_get_json_object($name){
	return WPJAM_JSON::get($name);
}

function wpjam_add_json_module_parser($type, $callback){
	return WPJAM_JSON::add_module_parser($type, $callback);
}

function wpjam_parse_json_module($module){
	return WPJAM_JSON::parse_module($module);
}

function wpjam_get_current_json($output='name'){
	return WPJAM_JSON::get_current($output);
}

function wpjam_is_json_request(){
	if(get_option('permalink_structure')){
		return (bool)preg_match("/\/api\/.*\.json/", $_SERVER['REQUEST_URI']);
	}else{
		return isset($_GET['module']) && $_GET['module'] == 'json';
	}
}

function wpjam_send_error_json($errcode, $errmsg=''){
	wpjam_send_json(new WP_Error($errcode, $errmsg));
}

function wpjam_register_source($name, $callback, $query_args=['source_id']){
	if(!wpjam_get_items('source')){
		add_filter('wpjam_pre_json', fn($pre) => wpjam_source() ?? $pre);
	}

	return wpjam_add_item('source', $name, ['callback'=>$callback, 'query_args'=>$query_args]);
}

function wpjam_source($name=''){
	$name	= $name ?: wpjam_get_parameter('source');
	$source	= $name ? wpjam_get_item('source', $name) : null;

	if($source){
		call_user_func($source['callback'], wpjam_get_parameter($source['query_args']));
	}
}

// wpjam_register_config($key, $value)
// wpjam_register_config($name, $args)
// wpjam_register_config($args)
// wpjam_register_config($name, $callback])
// wpjam_register_config($callback])
function wpjam_register_config(...$args){
	$group	= count($args) >= 3 ? array_shift($args) : '';
	$group 	= wpjam_join(':', [$group, 'config']);
	$args	= filter_null($args);

	if($args){
		if(is_array($args[0]) || count($args) == 1){
			$args	= is_callable($args[0]) ? ['callback'=>$args[0]] : (is_array($args[0]) ? $args[0] : [$args[0] => null]);
		}else{
			$args	= is_callable($args[1]) ? ['name'=>$args[0], 'callback'=>$args[1]] : [$args[0]=>$args[1]];
		}

		wpjam_add_item($group, $args);
	}
}

function wpjam_get_config(...$args){
	if(count($args) >=2){
		$config	= $args[0];
		$item	= $args[1];

		if(!empty($item['callback'])){
			$name	= $item['name'] ?? '';
			$args	= $item['args'] ?? [];
			$args	= $args ?: ($name ? [$name] : []);
			$value	= call_user_func($item['callback'], ...$args);
			$item	= $name ? [$name => $value] : (is_array($value) ? $value : []);
		}

		return array_merge($config, $item);
	}else{
		$group	= array_shift($args);
		$group	= is_array($group) ? array_get($group, 'group') : $group;
		$group 	= wpjam_join(':', [$group, 'config']);

		return array_reduce(wpjam_get_items($group), 'wpjam_get_config', []);
	}
}

function wpjam_die_if_error($result){
	if(is_wp_error($result)){
		wp_die($result);
	}

	return $result;
}

function wpjam_throw_if_error($result){
	if(is_wp_error($result)){
		throw new WPJAM_Exception($result);
	}

	return $result;
}

function wpjam_exception($errmsg, $errcode=null){
	throw new WPJAM_Exception($errmsg, $errcode);
}

function wpjam_parse_error($data){
	return WPJAM_Error::parse($data);
}

function wpjam_register_error_setting($code, $message, $modal=[]){
	return WPJAM_Error::add_setting($code, $message, $modal);
}

function wpjam_get_parameter($name='', $args=[], $method=null){
	if($method == 'data'){
		$object	= WPJAM_Data_Parameter::get_instance();
	}else{
		$object	= WPJAM_Parameter::get_instance();
		$args	= $method ? array_merge($args, ['method'=>$method]) : $args;
	}

	if(is_array($name)){
		return $name ? array_combine($name, array_map(fn($n) => $object->get_value($n, $args), $name)) : [];
	}

	return $object->get_value($name, $args);
}

function wpjam_get_post_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'POST');
}

function wpjam_get_request_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'REQUEST');
}

function wpjam_get_data_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'data');
}

function wpjam_method_allow($method){
	if($_SERVER['REQUEST_METHOD'] != strtoupper($method)){
		return wp_die('method_not_allow', '接口不支持 '.$_SERVER['REQUEST_METHOD'].' 方法，请使用 '.$method.' 方法！');
	}

	return true;
}

function wpjam_http_request($url, $args=[], $err_args=[], &$headers=null){
	$object	= WPJAM_Request::get_instance();

	try{
		return $object->request($url, $args, $err_args, $headers);
	}catch(WPJAM_Exception $e){
		return $e->get_wp_error();
	}
}

function wpjam_remote_request($url, $args=[], $err_args=[], &$headers=null){
	return wpjam_http_request($url, $args, $err_args, $headers);
}

function wpjam_load_extends($dir, ...$args){
	WPJAM_Extend::create($dir, ...$args);
}

function wpjam_get_file_summary($file){
	return WPJAM_Extend::get_file_summay($file);
}

function wpjam_get_extend_summary($file){
	return WPJAM_Extend::get_file_summay($file);
}

function wpjam_register_remixincon_style(){
	wp_register_style('remixicon', 'https://cdn.staticfile.org/remixicon/4.0.1/remixicon.min.css');
}

function wpjam_video_shortcode_override($override, $attr, $content){
	if(preg_match('#//www.bilibili.com/video/(BV[a-zA-Z0-9]+)#i',$content, $matches)){
		$src	= 'https://player.bilibili.com/player.html?bvid='.esc_attr($matches[1]);
	}elseif(preg_match('#//v.qq.com/(.*)iframe/(player|preview).html\?vid=(.+)#i',$content, $matches)){
		$src	= 'https://v.qq.com/'.esc_attr($matches[1]).'iframe/player.html?vid='.esc_attr($matches[3]);
	}elseif(preg_match('#//v.youku.com/v_show/id_(.*?).html#i',$content, $matches)){
		$src	= 'https://player.youku.com/embed/'.esc_attr($matches[1]);
	}elseif(preg_match('#//www.tudou.com/programs/view/(.*?)#i',$content, $matches)){
		$src	= 'https://www.tudou.com/programs/view/html5embed.action?code='.esc_attr($matches[1]);
	}elseif(preg_match('#//tv.sohu.com/upload/static/share/share_play.html\#(.+)#i',$content, $matches)){
		$src	= 'https://tv.sohu.com/upload/static/share/share_play.html#'.esc_attr($matches[1]);
	}elseif(preg_match('#//www.youtube.com/watch\?v=([a-zA-Z0-9\_]+)#i',$content, $matches)){
		$src	= 'https://www.youtube.com/embed/'.esc_attr($matches[1]);
	}else{
		$src	= '';
	}

	if($src){
		$attr	= shortcode_atts(['width'=>0, 'height'=>0], $attr);

		if($attr['width'] || $attr['height']){
			$attr_string	= image_hwstring($attr['width'], $attr['height']).' style="aspect-ratio:4/3;"';
		}else{
			$attr_string	= 'style="width:100%; aspect-ratio:4/3;"';
		}

		return '<iframe class="wpjam_video" '.$attr_string.' src="'.$src.'" scrolling="no" border="0" frameborder="no" framespacing="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
	}

	return $override;
}

wpjam_load_extends([
	'dir'		=> dirname(__DIR__).'/components',
	'hook'		=> 'wpjam_loaded',
	'priority'	=> 0,
]);

wpjam_load_extends([
	'option'	=> 'wpjam-extends',
	'dir'		=> dirname(__DIR__).'/extends',
	'sitewide'	=> true,
	'ajax'		=> false,
	'title'		=> '扩展管理',
	'hook'		=> 'plugins_loaded',
	'priority'	=> 1,
	'menu_page'	=> [
		'network'	=> true,
		'parent'	=> 'wpjam-basic',
		'order'		=> 3,
		'function'	=> 'tab',
		'tabs'		=> ['extends'	=> [
			'order'			=> 20,
			'title'			=> '扩展管理',
			'function'		=> 'option',
			'option_name'	=> 'wpjam-extends'
		]]
	]
]);

if(empty($_GET['wp_theme_preview'])){
	wpjam_load_extends([
		'dir'			=> get_template_directory().'/extends',
		'hierarchical'	=> true,
		'hook'			=> 'plugins_loaded',
		'priority'		=> 0,
	]);
}

wpjam_register_route('json',	['model'=>'WPJAM_JSON']);
wpjam_register_route('txt',		['model'=>'WPJAM_Verify_TXT']);

wpjam_add_json_module_parser('post_type',	['WPJAM_Posts', 'parse_json_module']);
wpjam_add_json_module_parser('taxonomy',	['WPJAM_Terms', 'parse_json_module']);
wpjam_add_json_module_parser('setting',		['WPJAM_Setting', 'parse_json_module']);
wpjam_add_json_module_parser('media',		['WPJAM_Posts', 'parse_media_json_module']);
wpjam_add_json_module_parser('data_type',	['WPJAM_Data_Type', 'parse_json_module']);
wpjam_add_json_module_parser('config',		'wpjam_get_config');

wpjam_register_error_setting('invalid_post_type',	'无效的文章类型');
wpjam_register_error_setting('invalid_taxonomy',	'无效的分类模式');
wpjam_register_error_setting('invalid_menu_page',	'页面%s「%s」未定义。');
wpjam_register_error_setting('invalid_item_key',	'「%s」已存在，无法%s。');
wpjam_register_error_setting('invalid_page_key',	'无效的%s页面。');
wpjam_register_error_setting('invalid_name',		'%s不能为纯数字。');
wpjam_register_error_setting('invalid_nonce',		'验证失败，请刷新重试。');
wpjam_register_error_setting('invalid_code',		'验证码错误。');
wpjam_register_error_setting('invalid_password',	'两次输入的密码不一致。');
wpjam_register_error_setting('incorrect_password',	'密码错误。');
wpjam_register_error_setting('bad_authentication',	'无权限');
wpjam_register_error_setting('access_denied',		'操作受限');
wpjam_register_error_setting('value_required',		'%s的值为空或无效。');
wpjam_register_error_setting('undefined_method',	['WPJAM_Error', 'callback']);
wpjam_register_error_setting('quota_exceeded',		['WPJAM_Error', 'callback']);

add_action('plugins_loaded',	'wpjam_activation', 0);
add_action('wp_loaded',			['WPJAM_Route', 'on_loaded']);
add_action('parse_request',		['WPJAM_Route', 'on_parse_request']);
add_filter('query_vars',		['WPJAM_Route', 'filter_query_vars']);

add_filter('register_post_type_args',	['WPJAM_Post_Type', 'filter_register_args'], 999, 2);
add_filter('register_taxonomy_args',	['WPJAM_Taxonomy', 'filter_register_args'], 999, 3);

add_action('parse_request',		['WPJAM_Posts', 'on_parse_request'], 1);
add_filter('posts_clauses',		['WPJAM_Posts', 'filter_clauses'], 1, 2);
add_filter('post_type_link',	['WPJAM_Post', 'filter_link'], 1, 2);
add_filter('content_save_pre',	['WPJAM_Post', 'filter_content_save_pre'], 1);
add_filter('content_save_pre',	['WPJAM_Post', 'filter_content_save_pre'], 11);

add_filter('wp_video_shortcode_override', 'wpjam_video_shortcode_override', 10, 3);

add_action('wp_enqueue_scripts',	['WPJAM_AJAX', 'on_enqueue_scripts'], 1);
add_action('login_enqueue_scripts',	['WPJAM_AJAX', 'on_enqueue_scripts'], 1);

add_action('wp_enqueue_scripts',	'wpjam_register_remixincon_style');

if(wpjam_is_json_request()){
	add_filter('wp_die_handler', ['WPJAM_Error', 'filter_wp_die_handler']);

	ini_set('display_errors', 0);

	remove_filter('the_title', 'convert_chars');

	remove_action('init', 'wp_widgets_init', 1);
	remove_action('init', 'maybe_add_existing_user_to_blog');
	remove_action('init', 'check_theme_switched', 99);

	remove_action('plugins_loaded', 'wp_maybe_load_widgets', 0);
	remove_action('plugins_loaded', 'wp_maybe_load_embeds', 0);
	remove_action('plugins_loaded', '_wp_customize_include');
	remove_action('plugins_loaded', '_wp_theme_json_webfonts_handler');

	remove_action('wp_loaded', '_custom_header_background_just_in_time');
	remove_action('wp_loaded', '_add_template_loader_filters');
}
