<?php
if(!function_exists('is_closure')){
	function is_closure($object){
		return $object instanceof Closure;
	}
}

if(!function_exists('base64_urlencode')){
	function base64_urlencode($str){
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}
}

if(!function_exists('base64_urldecode')){
	function base64_urldecode($str){
		return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '='));
	}
}

// JWT
function wpjam_generate_jwt($payload, $key='', $header=[]){
	if(is_array($payload)){
		$header	= wp_parse_args($header, ['alg'=>'HS256', 'typ'=>'JWT']);

		if($header['alg'] == 'HS256'){
			$jwt	= base64_urlencode(wpjam_json_encode($header)).'.'.base64_urlencode(wpjam_json_encode($payload));

			return $jwt.'.'.base64_urlencode(hash_hmac('sha256', $jwt, ($key ?: wp_salt()), true));
		}
	}

	return false;
}

function wpjam_verify_jwt($token, $key=''){
	$tokens	= $token ? explode('.', $token) : [];

	if(count($tokens) != 3){
		return false;
	}

	[$header, $payload, $sign] = $tokens;

	if(!hash_equals(base64_urlencode(hash_hmac('sha256', $header.'.'.$payload, ($key ?: wp_salt()), true)), $sign)){
		return false;
	}

	$header		= wpjam_json_decode(base64_urldecode($header));
	$payload	= wpjam_json_decode(base64_urldecode($payload));

	if(empty($header['alg']) || $header['alg'] != 'HS256'){
		return false;
	}

	//iat 签发时间大于当前服务器时间验证失败
	//nbf 时间之前不接收处理该Token
	//exp 过期时间小于当前服务器时间验证失败
	foreach(['iat'=>'>', 'nbf'=>'>', 'exp'=>'<'] as $key => $compare){
		if(isset($payload[$key]) && wpjam_compare($payload[$key], $compare, time())){
			return false;
		}
	}

	return $payload;
}

function wpjam_get_jwt($key='access_token', $required=false){
	$headers	= getallheaders();

	if(isset($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer')){
		return trim(wpjam_remove_prefix($headers['Authorization'], 'Bearer'));
	}

	return wpjam_get_parameter($key, ['required'=>$required]);
}

// user agent
function wpjam_get_user_agent(){
	return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function wpjam_get_ip(){
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function wpjam_parse_user_agent($user_agent=null, $referer=null){
	return WPJAM_Var::parse_user_agent($user_agent, $referer);
}

function wpjam_parse_ip($ip=''){
	return WPJAM_Var::parse_ip($ip);
}

function wpjam_get_current_var($name, &$isset=false){
	$object	= WPJAM_Var::get_instance();
	$isset	= isset($object->$name);

	return $object->$name;
}

function wpjam_current_support($feature){
	$object	= WPJAM_Var::get_instance();

	return $object->support($feature);
}

function wpjam_set_current_var($name, $value){
	$object	= WPJAM_Var::get_instance();

	return $object->$name = $value;
}

function wpjam_get_device(){
	return wpjam_get_current_var('device');
}

function wpjam_get_os(){
	return wpjam_get_current_var('os');
}

function wpjam_get_browser(){
	return wpjam_get_current_var('browser');
}

function wpjam_get_version($key){
	return wpjam_get_current_var($key.'_version');
}

function is_ipad(){
	return wpjam_get_device() == 'iPad';
}

function is_iphone(){
	return wpjam_get_device() == 'iPone';
}

function is_ios(){
	return wpjam_get_os() == 'iOS';
}

function is_macintosh(){
	return wpjam_get_os() == 'Macintosh';
}

function is_android(){
	return wpjam_get_os() == 'Android';
}

function is_weixin(){ 
	if(isset($_GET['weixin_appid'])){
		return true;
	}

	return wpjam_get_current_var('app') == 'weixin';
}

function is_weapp(){ 
	if(isset($_GET['appid'])){
		return true;
	}

	return wpjam_get_current_var('app') == 'weapp';
}

function is_bytedance(){ 
	if(isset($_GET['bytedance_appid'])){
		return true;
	}

	return wpjam_get_current_var('app') == 'bytedance';
}

function wpjam_url($dir, $scheme=null){
	$path	= str_replace([rtrim(ABSPATH, '/'), '\\'], ['', '/'], $dir);

	return $scheme == 'relative' ? $path : site_url($path, $scheme);
}

function wpjam_dir($url){
	return ABSPATH.str_replace(site_url('/'), '', $url);
}

// File
function wpjam_upload($name){
	if(is_array($name)){
		if(isset($name['bits'])){
			$upload	= wp_upload_bits(($name['name'] ?? ''), null, $name['bits']);
		}else{
			$upload	= wp_handle_sideload($name, ['test_form'=>false]);
		}
	}else{
		$upload	= wp_handle_upload($_FILES[$name], ['test_form'=>false]);
	}

	$error	= WPJAM_File::is_error($upload);

	return $error ?: $upload+['path'=>WPJAM_File::convert($upload['file'], 'file', 'path')];
}

function wpjam_upload_bits($bits, $name, $media=true, $post_id=0){
	$args	= WPJAM_File::parse_args($name, $media, $post_id);
	$name	= $args['name'];
	$field	= $args['field'];
	$pos	= strrpos($name, '.');
	$part	= $pos ? substr($name, 0, $pos) : $name;

	if(preg_match('/data:image\/([^;]+);base64,(.*)/i', $bits, $matches)){
		$bits	= base64_decode(trim($matches[2]));
		$name	= $part.'.'.$matches[1];
	}

	$upload	= wp_upload_bits($name, null, $bits);
	$error	= WPJAM_File::is_error($upload);

	if($error){
		return $error;
	}

	if($args['media']){
		$id	= WPJAM_File::add_to_media($upload, $part, $args['post_id']);

		if(is_wp_error($id) || $field == 'id'){
			return $id;
		}
	}

	return $upload[$field];
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	$id		= WPJAM_File::get_id_by_meta($url, 'source_url');
	$args	= WPJAM_File::parse_args($name, $media, $post_id);
	$name	= $args['name'];
	$field	= $args['field'];

	if($id){
		return WPJAM_File::convert($id, 'id', $field);
	}

	$tmp_file	= download_url($url);

	if(is_wp_error($tmp_file)){
		return $tmp_file;
	}

	if(!$name){
		$type	= wp_get_image_mime($tmp_file);
		$name	= md5($url).'.'.(explode('/', $type)[1]);
	}

	$file	= ['name'=>$name, 'tmp_name'=>$tmp_file];

	if($args['media']){
		$id	= media_handle_sideload($file, $args['post_id']);

		if(is_wp_error($id)){
			@unlink($tmp_file);

			return $id;
		}

		update_post_meta($id, 'source_url', $url);

		return WPJAM_File::convert($id, 'id', $field);
	}else{
		$upload	= wpjam_upload($file);

		return is_wp_error($upload) ? $upload : $upload[$field];
	}
}

function wpjam_scandir($dir, $callback=null){
	$files	= [];

	foreach(scandir($dir) as $file){
		if($file == '.' || $file == '..'){
			continue;
		}

		$file 	= $dir.'/'.$file;
		$files	= array_merge($files, (is_dir($file) ? wpjam_scandir($file) : [$file]));
	}

	if($callback && is_callable($callback)){
		$output	= [];

		foreach($files as $file){
			call_user_func_array($callback, [$file, &$output]);
		}

		return $output;
	}

	return $files;
}

function wpjam_bits($str){
	return 'data:'.finfo_buffer(finfo_open(), $str, FILEINFO_MIME_TYPE).';base64, '.base64_encode($str);
}

function wpjam_toggle_url_scheme($url){
	$http	= str_starts_with($url, 'http://');

	return ($http ? 'https://' : 'http://').substr($url, ($http ? 7 : 8));
}

function wpjam_is_external_url($url, $scene=''){
	$site_url 	= site_url();
	$status		= !str_starts_with($url, $site_url) || !str_starts_with($url, wpjam_toggle_url_scheme($site_url));

	return apply_filters('wpjam_is_external_url', $status, $url, $scene);
}

function wpjam_fetch_external_images(&$urls, $post_id=0){
	$search	= $replace	= [];
	$args	= [
		'post_id'	=> $post_id, 
		'media'		=> $post_id ? true : false,
		'field'		=> 'url'
	];
		
	foreach($urls as $url){
		if($url && wpjam_is_external_url($url, 'fetch')){
			$download	= wpjam_download_url($url, $args);

			if(!is_wp_error($download)){
				$search[]	= $url;
				$replace[]	= $download;
			}	
		}
	}

	$urls	= $search;

	return $replace;
}

// 1. $img
// 2. $img, ['width'=>100, 'height'=>100]	// 这个为最标准版本
// 3. $img, 100x100
// 4. $img, 100
// 5. $img, [100,100]
// 6. $img, [100,100], $crop=1, $ratio=1
// 7. $img, 100, 100, $crop=1, $ratio=1
function wpjam_get_thumbnail($img, ...$args){
	$url	= is_numeric($img) ? WPJAM_File::convert($img, 'id', 'url') : $img;

	if($url){
		$url	= wpjam_zh_urlencode($url);	// 中文名
		$url	= remove_query_arg(['orientation', 'width', 'height'], $url);

		return apply_filters('wpjam_thumbnail', $url, WPJAM_File::parse_thumbnail_args(...$args));
	}

	return '';
}

function wpjam_get_thumbnail_args(...$args){
	return apply_filters('wpjam_thumbnail', '', WPJAM_File::parse_thumbnail_args(...$args));
}

function wpjam_parse_size($size, $ratio=1){
	return WPJAM_File::parse_size($size, $ratio);
}

function wpjam_get_image_size($value, $type='id'){
	$size	= WPJAM_File::convert($value, $type, 'size');
	$size	= apply_filters('wpjam_image_size', $size, $value, $type);

	if($size){
		$size = array_map('intval', $size);

		$size['orientation']	= $size['height'] > $size['width'] ? 'portrait' : 'landscape';
	}

	return $size;
}

function wpjam_is_image($value, $type=''){
	$type	= $type ?: (is_numeric($value) ? 'id' : 'url');

	if($type == 'url'){
		$types	= wp_get_ext_types();
		$parts	= explode('?', $value);
		$url	= wpjam_remove_postfix($parts[0], '#');

		return preg_match('/\.('.implode('|', $types['image']).')$/i', $url);
	}elseif($type == 'file'){
		return !empty(WPJAM_File::convert($value, $type, 'size'));
	}elseif($type == 'id'){
		return wp_attachment_is_image($value);
	}
}

function wpjam_parse_image_query($url){
	$query	= parse_url($url, PHP_URL_QUERY);
	$query	= wp_parse_args($query);

	return wpjam_map($query, fn($v, $k) => in_array($k, ['width', 'height']) ? (int)$v : $v);
}

// Attr
function wpjam_attr($attr, $type=''){
	return WPJAM_Attr::create($attr, $type);
}

function wpjam_is_bool_attr($attr){
	return WPJAM_Attr::is_bool($attr);
}

// Tag
function wpjam_tag($tag='', $attr=[], $text=''){
	return new WPJAM_Tag($tag, $attr, $text);
}

function wpjam_wrap($text, $wrap='', ...$args){
	if((is_array($wrap) || is_closure($wrap))){
		$text	= is_callable($wrap) ? call_user_func_array($wrap, $text, $args) : $text;
		$wrap	= '';
	}

	if(is_a($text, 'WPJAM_Tag') && !$wrap){
		return $text;
	}

	return wpjam_tag('', [], $text)->wrap($wrap, ...$args);
}

function wpjam_is_single_tag($tag){
	return WPJAM_Tag::is_single($tag);;
}

function wpjam_html_tag_processor($html, $query=null){
	$proc	= new WP_HTML_Tag_Processor($html);

	return $proc->next_tag($query) ? $proc : null;
}

// Field
function wpjam_fields($fields, $args=[]){
	$object	= WPJAM_Fields::create($fields);

	if($args){
		$echo		= wpjam_pull($args, 'echo', true);
		$rendered	= $object->render($args);

		if($echo){
			echo $rendered;
		}else{
			return $rendered;
		}
	}

	return $object;
}

function wpjam_field($field, $args=[]){
	$object	= WPJAM_Field::create($field);

	if($args){
		if(isset($args['wrap_tag'])){
			return $object->wrap(wpjam_pull($args, 'wrap_tag'), $args);
		}else{
			return $object->render($args);
		}
	}

	return $object;
}

// $value, $args
// $value, $value2
// $value, $compare, $value2, $strict=false 
function wpjam_compare($value, $compare, ...$args){
	if(wpjam_is_assoc_array($compare)){
		$args		= $compare;
		$compare	= array_get($args, 'compare');
		$value2		= array_get($args, 'value');
		$key		= array_get($args, 'key');
		$swap		= array_get($args, 'swap');
		$strict		= (bool)array_get($args, 'strict');
		$item		= $value;

		if(is_object($item)){
			$value	= $item->{$key} ?? null;
		}elseif(is_array($item)){
			$value	= $item[$key] ?? null;
		}else{
			$value	= null;
		}

		if(array_get($args, 'callable') && is_callable($value)){
			return call_user_func($value, $value2, $item);
		}

		if(is_null($compare) && isset($args['if_null']) && is_null($value)){
			return $args['if_null'];
		}

		if(is_array($value)){
			$swap	= true;
		}

		if($swap){
			[$value, $value2]	= [$value2, $value];
		}

		return wpjam_compare($value, $compare, $value2, $strict);
	}

	if(is_array($compare) || !$args){
		[$value2, $compare, $strict]	= [$compare, '', false];
	}else{
		$value2	= $args[0];
		$strict	= $args[1] ?? false;
	}

	if($compare){
		$compare	= strtoupper($compare);
		$antonym	= ['!='=>'=', '<='=>'>', '>='=>'<', 'NOT IN'=>'IN', 'NOT BETWEEN'=>'BETWEEN'][$compare] ?? '';

		if($antonym){
			return !wpjam_compare($value, $antonym, $value2, $strict);
		}
	}else{
		$compare	= is_array($value2) ? 'IN' : '=';
	}

	if(in_array($compare, ['IN', 'BETWEEN'])){
		$value2	= wp_parse_list($value2);

		if(!is_array($value) && count($value2) == 1){
			$value2		= $value2[0];
			$compare	= '=';
		}
	}else{
		if(is_string($value2)){
			$value2	= trim($value2);
		}
	}

	if($compare == '='){
		if($strict){
			return $value === $value2;
		}else{
			return $value == $value2;
		}
	}elseif($compare == '>'){
		return $value > $value2;
	}elseif($compare == '<'){
		return $value < $value2;
	}elseif($compare == 'IN'){
		if(is_array($value)){
			return wpjam_every($value, fn($v) => in_array($v, $value2, $strict));
		}else{
			return in_array($value, $value2, $strict);
		}
	}elseif($compare == 'BETWEEN'){
		return $value >= $value2[0] && $value <= $value2[1];
	}

	return false;
}

function wpjam_show_if($item, $args){
	if(wp_is_numeric_array($args)){
		return wpjam_every($args, fn($arg) => wpjam_show_if($item, $arg));
	}

	if(is_array($args) && !empty($args['key']) && empty($args['external'])){
		return wpjam_compare($item, $args);
	}

	return true;
}

function wpjam_match($item, $args=[], $operator='AND'){
	$operator	= strtoupper($operator);

	$fn	= fn($v, $k) => wpjam_compare($item, wpjam_is_assoc_array($v) ? $v+['key'=>$k] : ['key'=>$k, 'value'=>$v]);

	if('OR' === $operator){
		return wpjam_some($args, $fn);
	}elseif('AND' === $operator){
		return wpjam_every($args, $fn);
	}elseif('NOT' === $operator){
		return !wpjam_every($args, $fn);
	}

	return false;
}

// Array
function wpjam_array($value=null, $output=''){
	$data	= [];

	if(is_object($value)){
		if(method_exists($value, 'to_array')){
			$data	= $value->to_array();
		}elseif($value instanceof ArrayAccess){
			foreach($value as $k => $v){
				$data[$k]	= $v;
			}
		}
	}elseif(!is_null($value)){
		$data	= (array)$value;
	}

	if($output == ''){
		return $data;
	}elseif($output == 'object'){
		trigger_error('WPJAM_Array');	// delete 2024-0-31
		return new WPJAM_Array($data);
	}elseif(is_callable($output)){
		$new	= [];

		foreach($data as $k => $v){
			$result		= call_user_func($output, $k, $v);
			[$k, $v]	= is_array($result) ? $result : [$result, $v];

			if(!is_null($k)){
				$new[$k]	= $v;
			}
		}

		return $new;
	}

	return $data;
}

function wpjam_map($array, $callback){
	foreach($array as $key => &$value){
		$value	= call_user_func($callback, $value, $key);
	}

	return $array;
}

function wpjam_fill($keys, $callback){
	return array_combine($keys, array_map($callback, $keys));
}

function wpjam_every($array, $callback) {
	foreach($array as $key => $value){
		if(!call_user_func($callback, $value, $key)){
			return false;
		}
	}

	return true;
}

function wpjam_some($array, $callback) {
	foreach($array as $key => $value){
		if(call_user_func($callback, $value, $key)){
			return true;
		}
	}

	return false;
}

function wpjam_find($array, $callback, $return='value'){
	if($return == 'index'){
		$i	= 0;
	}

	foreach($array as $key => $value){
		if(call_user_func($callback, $value, $key)){
			if($return == 'index'){
				return $i;
			}elseif($return == 'key'){
				return $key;
			}else{
				return $value;
			}
		}

		if($return == 'index'){
			$i++;
		}
	}

	return false;
}

function wpjam_group($array, $field){
	foreach($array as $i => $item){
		$k = is_object($item) ? $item->$field : $item[$field];

		$grouped[$k][$i] = $item;
	}

	return $grouped ?? [];
}

function wpjam_pull(&$array, $key, $default=null){
	if(is_array($key)){
		if(wp_is_numeric_array($key)){
			$value	= wp_array_slice_assoc($array, $key);
		}else{
			$value	= wpjam_map($key, fn($v, $k) => $array[$k] ?? $v);
			$key	= array_keys($key);
		}
	}else{
		$value	= array_get($array, $key, $default);
	}

	$array	= wpjam_except($array, $key);

	return $value;
}

function wpjam_except($array, $key){
	if(is_array($key)){
		return array_reduce($key, 'wpjam_except', $array);
	}

	if(isset($array[$key]) || !str_contains($key, '.')){
		unset($array[$key]);
	}else{
		$key	= explode('.', $key);
		$sub	= &$array;

		while($key){
			$k	= array_shift($key);

			if(empty($key)){
				unset($sub[$k]);
			}elseif(isset($sub[$k])){
				$sub = &$sub[$k];
			}else{
				break;
			}
		}
	}

	return $array;
}

function wpjam_merge($array, $data){
	foreach($data as $key => $value){
		if(wpjam_is_assoc_array($value) && isset($array[$key]) && wpjam_is_assoc_array($array[$key])){
			$array[$key]	= wpjam_merge($array[$key], $value);
		}else{
			$array[$key]	= $value;
		}
	}

	return $array;
}

function wpjam_diff($array, $data){
	foreach($data as $key => $value){
		if(isset($array[$key])){
			if(wpjam_is_assoc_array($value) && wpjam_is_assoc_array($array[$key])){
				$array[$key]	= wpjam_diff($array[$key], $value);
			}else{
				unset($array[$key]);
			}
		}
	}

	return $array;
}

function wpjam_filter($array, $callback, $deep=true){
	if($callback == 'isset'){
		$callback	= fn($v) => !is_null($v);
	}elseif($callback == 'filled'){
		$callback	= fn($v) => $v || is_numeric($v);
	}

	if($deep){
		foreach($array as $key => &$value){
			if(is_array($value)){
				$value	= wpjam_filter($value, $callback, $deep);
			}
		}
	}

	return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
}

function wpjam_is_assoc_array($array){
	return is_array($array) && !wp_is_numeric_array($array);
}

function wpjam_is_array_accessible($array){
	return is_array($array) || $array instanceof ArrayAccess;
}

function wpjam_move($array, $id, $pos){
	if(!in_array($id, $array)){
		return new WP_Error('invalid_id', '无效的 ID');
	}

	$value	= null;
	$offset	= 0;

	if(is_numeric($pos)){
		$value	= $pos;
	}elseif(is_array($pos)){
		if(isset($pos['next'])){
			$value	= (int)$pos['next'];
		}elseif(isset($pos['prev'])){
			$value	= (int)$pos['prev'];
			$offset	= 1;
		}
	}

	if(is_null($value) || !in_array($value, $array)){
		return new WP_Error('invalid_position', '无效的移动位置');
	}

	$array	= array_values(array_diff($array, [$id]));
	$offset	+= array_search($value, $array);

	return array_merge(array_slice($array, 0, $offset), [$id], array_slice($array, $offset));
}

if(!function_exists('array_get')){
	function array_get($array, $key, $default=null){
		if(!wpjam_is_array_accessible($array)){
			return $default;
		}

		if(is_null($key)){
			return $array;
		}

		if(!is_array($key)){
			if(isset($array[$key]) || !str_contains($key, '.')){
				return $array[$key] ?? $default;
			}

			$key	= explode('.', $key);
		}

		foreach($key as $k){
			if(!wpjam_is_array_accessible($array) || !isset($array[$k])){
				return $default;
			}

			$array	= $array[$k];
		}

		return $array;
	}
}

if(!function_exists('array_set')){
	function array_set($array, $key, $value){
		if(is_null($key)){
			return $array = $value;
		}

		if(isset($array[$key]) || !str_contains($key, '.')){
			$array[$key] = $value;

			return $array;
		}

		$keys	= explode('.', $key);
		$sub	= &$array;

		while($keys){
			$key	= array_shift($keys);

			if(!$keys){
				$sub[$key]	= $value;
			}else{
				$sub[$key]	??= [];
				$sub[$key]	= wpjam_is_array_accessible($sub[$key]) ? $sub[$key] : [];
				$sub		= &$sub[$key];
			}
		}

		return $array;
	}
}

function wpjam_has_bit($value, $bit){
	return ((int)$value & (int)$bit) == $bit;
}

function wpjam_add_bit($value, $bit){
	return $value = (int)$value | (int)$bit;
}

function wpjam_remove_bit($value, $bit){
	return $value = (int)$value & (~(int)$bit);
}

function wpjam_create_uuid(){
	$chars	= md5(uniqid(mt_rand(), true));

	return implode('-', array_map(fn($args) => substr($chars, ...$args), [[0, 8], [8, 4], [12, 4], [16, 4], [20, 12]]));
}

// String
function wpjam_join($sep, ...$args){
	$array	= ($args && is_array($args[0])) ? $args[0] : $args;

	return join($sep, array_filter($array));
}

function wpjam_add_prefix($str, $prefix){
	if($prefix && !str_starts_with($str, $prefix)){
		$added	= true;

		return $prefix.$str;
	}

	return $str;
}

function wpjam_remove_prefix($str, $prefix, $removed=false){
	return wpjam_replace_prefix($str, $prefix, '', $removed);
}

function wpjam_replace_prefix($str, $prefix, $replace, &$replaced=false){
	if($prefix && str_starts_with($str, $prefix)){
		$replaced	= true;

		return $replace.substr($str, strlen($prefix));
	}

	return $str;
}

function wpjam_add_postfix($str, $postfix, &$added=false){
	if($postfix && !str_ends_with($str, $postfix)){
		$added	= true;

		return $str.$postfix;
	}

	return $str;
}

function wpjam_remove_postfix($str, $postfix, &$removed=false){
	return wpjam_replace_postfix($str, $postfix, '', $removed);
}

function wpjam_replace_postfix($str, $postfix, $replace, &$replaced=false){
	if($postfix && str_ends_with($str, $postfix)){
		$replaced	= true;

		return substr($str, 0, strlen($str) - strlen($postfix)).$replace;
	}

	return $str;
}

function wpjam_unserialize(&$serialized){
	if($serialized){
		$fixed	= preg_replace_callback('!s:(\d+):"(.*?)";!', fn($m) => 's:'.strlen($m[2]).':"'.$m[2].'";', $serialized);
		$result	= unserialize($fixed);

		if($result && is_array($result)){
			$serialized	= $fixed;

			return $result;
		}
	}

	return false;
}

// 去掉非 utf8mb4 字符
function wpjam_strip_invalid_text($text, $charset='utf8mb4'){
	if(!$text){
		return '';
	}

	$regex	= '/
		(
			(?:	[\x00-\x7F]					# single-byte sequences   0xxxxxxx
			|	[\xC2-\xDF][\x80-\xBF]		# double-byte sequences   110xxxxx 10xxxxxx';

	if($charset === 'utf8mb3' || $charset === 'utf8mb4'){
		$regex	.= '
			|	\xE0[\xA0-\xBF][\x80-\xBF]	# triple-byte sequences   1110xxxx 10xxxxxx * 2
			|	[\xE1-\xEC][\x80-\xBF]{2}
			|	\xED[\x80-\x9F][\x80-\xBF]
			|	[\xEE-\xEF][\x80-\xBF]{2}';
	}

	if($charset === 'utf8mb4'){
		$regex	.= '
			|	\xF0[\x90-\xBF][\x80-\xBF]{2}	# four-byte sequences   11110xxx 10xxxxxx * 3
			|	[\xF1-\xF3][\x80-\xBF]{3}
			|	\xF4[\x80-\x8F][\x80-\xBF]{2}';
	}

	$regex		.= '
		){1,40}					# ...one or more times
		)
		| .						# anything else
		/x';

	return preg_replace($regex, '$1', $text);
}

// 去掉 4字节 字符
function wpjam_strip_4_byte_chars($text){
	return $text ? preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text) : '';
	// return preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $text);
}

// 去掉控制字符
function wpjam_strip_control_chars($text){
	// 移除 除了 line feeds 和 carriage returns 所有控制字符
	return $text ? preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/u', '', $text) : '';
	// return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $text);
}

function wpjam_strip_control_characters($text){
	return wpjam_strip_control_chars($text);
}

function wpjam_strip_tags($text){
	return $text ? trim(wp_strip_all_tags($text)) : $text;
}

//获取纯文本
function wpjam_get_plain_text($text){
	$text	= wpjam_strip_tags($text);

	if($text){
		$text	= str_replace(['"', '\''], '', $text);
		$text	= str_replace(["\r\n", "\n", "  "], ' ', $text);
		$text	= trim($text);
	}

	return $text;
}

//获取第一段
function wpjam_get_first_p($text){
	$text	= wpjam_strip_tags($text);

	return $text ? trim((explode("\n", $text))[0]) : '';
}

//中文截取方式
function wpjam_mb_strimwidth($text, $start=0, $width=40, $trimmarker='...', $encoding='utf-8'){
	$text	= wpjam_get_plain_text($text);

	return $text ? mb_strimwidth($text, $start, $width, $trimmarker, $encoding) : '';
}

function wpjam_unicode_decode($text){
	// [U+D800 - U+DBFF][U+DC00 - U+DFFF]|[U+0000 - U+FFFF]
	// return mb_convert_encoding(pack("H*", $matches[1]), 'UTF-8', 'UCS-2BE');

	return preg_replace_callback('/(\\\\u[0-9a-fA-F]{4})+/i', fn($m) => json_decode('"'.$m[0].'"') ?: $m[0], $text);
}

function wpjam_zh_urlencode($url){
	return $url ? preg_replace_callback('/[\x{4e00}-\x{9fa5}]+/u', fn($m) => urlencode($m[0]), $url) : '';
}

// 检查非法字符
function wpjam_blacklist_check($text, $name='内容'){
	if(!$text){
		return false;
	}

	$pre	= apply_filters('wpjam_pre_blacklist_check', null, $text, $name);

	if(!is_null($pre)){
		return $pre;
	}

	$words	= (array)explode("\n", get_option('disallowed_keys'));

	return wpjam_some($words, fn($w) => (trim($w) && preg_match("#".preg_quote(trim($w), '#')."#i", $text)));
}

function wpjam_hex2rgba($color, $opacity=null){
	$color	= $color[0] == '#' ? substr($color, 1) : $color;
	$len	= strlen($color);

	if($len != 3 && $len != 6){
		return $color;
	}

	$hex	= array_map(fn($i) => $len == 6 ? $color[$i*2].$color[$i*2+1] : $color[$i].$color[$i], [0, 1, 2]);
	$rgb	= implode(',', array_map('hexdec', $hex));
	$rgb	.= isset($opacity) ? ','.($opacity > 1 ? 1.0 : $opacity) : '';

	return 'rgb('.$rgb.')';
}

function wpjam_doing_debug(){
	if(isset($_GET['debug'])){
		return $_GET['debug'] ? sanitize_key($_GET['debug']) : true;
	}else{
		return false;
	}
}

function wpjam_do_shortcode($content, $tagnames, $ignore_html=false){
	if(str_contains($content, '[')){
		preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches);

		$tagnames	= array_intersect((array)$tagnames, $matches[1]);
		$content	= do_shortcodes_in_html_tags($content, $ignore_html, $tagnames);
		$pattern	= get_shortcode_regex($tagnames);
		$content	= preg_replace_callback("/$pattern/", 'do_shortcode_tag', $content);
		$content	= unescape_invalid_shortcodes($content);
	}

	return $content;
}

function wpjam_parse_shortcode_attr($str, $tagnames=null){
	$pattern = get_shortcode_regex([$tagnames]);

	if(preg_match("/$pattern/", $str, $m)){
		return shortcode_parse_atts($m[3]);
	}

	return [];
}

function wpjam_get_current_page_url(){
	return set_url_scheme('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
}

function wpjam_date($format, $timestamp=null){
	$timestamp	??= time();

	return is_numeric($timestamp) ? date_create('@'.$timestamp)->setTimezone(wp_timezone())->format($format) : false;
}

function wpjam_strtotime($string){
	return $string ? date_create($string, wp_timezone())->getTimestamp() : 0;
}

function wpjam_human_time_diff($from, $to=0){
	return sprintf(__('%s '.(($to ?: time()) > $from ? 'ago' : 'from now')), human_time_diff($from, $to));
}

function wpjam_human_date_diff($from, $to=0){
	$zone	= wp_timezone();
	$to		= $to ? date_create($to, $zone) : current_datetime();
	$from	= date_create($from, $zone);
	$diff	= $to->diff($from);
	$days	= (int)$diff->format('%R%a');
	$day	= [0=>'今天', -1=>'昨天', -2=>'前天', 1=>'明天', 2=>'后天'][$days] ?? '';

	if($day){
		return $day;
	}

	if($from->format('W') - $to->format('W') == 0){
		return __($from->format('l'));
	}else{
		return $from->format('m月d日');
	}
}

// 打印
function wpjam_print_r($value){
	$capability	= is_multisite() ? 'manage_site' : 'manage_options';

	if(current_user_can($capability)){
		echo '<pre>';
		print_r($value);
		echo '</pre>'."\n";
	}
}

function wpjam_var_dump($value){
	$capability	= is_multisite() ? 'manage_site' : 'manage_options';
	if(current_user_can($capability)){
		echo '<pre>';
		var_dump($value);
		echo '</pre>'."\n";
	}
}

function wpjam_pagenavi($total=0, $echo=true){
	$result	= '<div class="pagenavi">'.paginate_links(array_filter([
		'prev_text'	=> '&laquo;',
		'next_text'	=> '&raquo;',
		'total'		=> $total
	])).'</div>';

	if($echo){
		echo $result;
	}

	return $result; 
}

function wpjam_localize_script($handle, $name, $l10n ){
	wp_localize_script($handle, $name, ['l10n_print_after' => $name.' = '.wpjam_json_encode($l10n)]);
}

function wpjam_is_mobile_number($number){
	return preg_match('/^0{0,1}(1[3,5,8][0-9]|14[5,7]|166|17[0,1,3,6,7,8]|19[8,9])[0-9]{8}$/', $number);
}

function wpjam_set_cookie($key, $value, $expire=DAY_IN_SECONDS){
	if(is_null($value)){
		unset($_COOKIE[$key]);

		$value	= ' ';
		$expire	= time() - YEAR_IN_SECONDS;
	}else{
		$_COOKIE[$key]	= $value;

		$expire	= $expire < time() ? $expire+time() : $expire;
	}

	setcookie($key, $value, $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

	if(COOKIEPATH != SITECOOKIEPATH){
		setcookie($key, $value, $expire, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
	}
}

function wpjam_clear_cookie($key){
	wpjam_set_cookie($key, null);
}

function wpjam_get_filter_name($name='', $type=''){
	return wpjam_add_prefix(str_replace('-', '_', $name).'_'.$type, 'wpjam_');
}

function wpjam_get_filesystem(){
	if(empty($GLOBALS['wp_filesystem'])){
		if(!function_exists('WP_Filesystem')){
			require_once(ABSPATH.'wp-admin/includes/file.php');
		}

		WP_Filesystem();
	}

	return $GLOBALS['wp_filesystem'];
}
