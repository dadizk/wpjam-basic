<?php
class WPJAM_Route{
	public static function filter_determine_current_user($user_id){
		if(!$user_id){
			$current	= wpjam_get_current_user();

			if($current && !empty($current['user_id'])){
				return $current['user_id'];
			}
		}

		return $user_id;
	}

	public static function filter_current_commenter($commenter){
		$commenter	+= ['user_emails'=>[]];
		$current	= wpjam_get_current_user(false);

		if($current){
			if(!empty($current['user_email'])){
				$commenter['comment_author_email']	= $commenter['user_emails'][] = $current['user_email'];
			}

			if(empty($commenter['comment_author'])){
				$commenter['comment_author']	= $current['nickname'];
			}
		}

		return $commenter;
	}

	public static function filter_pre_avatar_data($args, $id_or_email){
		if(is_object($id_or_email) && isset($id_or_email->comment_ID)){
			$id_or_email	= get_comment($id_or_email);
		}

		$user_id 	= 0;
		$avatarurl	= '';
		$email		= '';

		if(is_numeric($id_or_email)){
			$user_id	= $id_or_email;
		}elseif(is_string($id_or_email)){
			$email		= $id_or_email;
		}elseif($id_or_email instanceof WP_User){
			$user_id	= $id_or_email->ID;
		}elseif($id_or_email instanceof WP_Post){
			$user_id	= $id_or_email->post_author;
		}elseif($id_or_email instanceof WP_Comment){
			$user_id	= $id_or_email->user_id;
			$email		= $id_or_email->comment_author_email;
			$avatarurl	= get_comment_meta($id_or_email->comment_ID, 'avatarurl', true);
		}

		if(!$avatarurl && $user_id){
			$avatarurl	= get_user_meta($user_id, 'avatarurl', true);
		}

		return array_merge($args, ($avatarurl ? [
			'found_avatar'	=> true,
			'url'			=> wpjam_get_thumbnail($avatarurl, $args)
		] : array_filter([
			'user_id'	=> $user_id,
			'email'		=> $email,
		])));
	}

	public static function filter_query_vars($query_vars){
		return ['module', 'action', 'term_id', ...$query_vars];
	}

	public static function filter_current_theme_supports($check, $args, $current){
		return $args ? (is_array($current[0]) ? in_array($args[0], $current[0]) : false) : $check;
	}

	public static function filter_script_loader_tag($tag, $handle, $src){
		return ($handle == 'wpjam-ajax' && !is_login() && current_theme_supports('script', 'wpjam-ajax')) ? '' : $tag;
	}

	public static function on_parse_request($wp){
		$module	= $wp->query_vars['module'] ?? null;

		if($module){
			remove_action('template_redirect',	'redirect_canonical');

			$item	= wpjam_get_item('route', $module);
			$action	= $wp->query_vars['action'] ?? '';

			if($item){
				if(!empty($item['callback']) && is_callable($item['callback'])){
					call_user_func($item['callback'], $action, $module);
				}

				$file	= $item['file'] ?? '';
			}	

			if(empty($file)){
				$file	= STYLESHEETPATH.'/template/'.$module.'/'.($action ?: 'index').'.php';
				$file	= apply_filters('wpjam_template', $file, $module, $action);
			}

			if(is_file($file)){
				add_filter('template_include',	fn() => $file);
			}
		}
	}

	public static function on_loaded(){
		foreach(wpjam_get_items('route') as $item){
			if(isset($item['rewrite_rule'])){
				if(is_callable($item['rewrite_rule'])){
					$item['rewrite_rule']	= call_user_func($item['rewrite_rule']);
				}

				if($item['rewrite_rule']){
					wpjam_add_rewrite_rule($item['rewrite_rule']);
				}
			}
		}

		// add_filter('determine_current_user',	[self::class, 'filter_determine_current_user']);
		add_filter('wp_get_current_commenter',	[self::class, 'filter_current_commenter']);
		add_filter('pre_get_avatar_data',		[self::class, 'filter_pre_avatar_data'], 10, 2);

		add_filter('current_theme_supports-style',	[self::class, 'filter_current_theme_supports'], 10, 3);
		add_filter('current_theme_supports-script',	[self::class, 'filter_current_theme_supports'], 10, 3);
		add_filter('script_loader_tag',				[self::class, 'filter_script_loader_tag'], 10, 3);
	}

	public static function add($name, $args){
		if(!is_array($args) || wp_is_numeric_array($args)){
			$args	= is_callable($args) ? ['callback'=>$args] : (array)$args;
		}elseif(!empty($args['model'])){
			$model	= wpjam_pull($args, 'model');

			foreach(['callback'=>'redirect', 'rewrite_rule'=>'get_rewrite_rule'] as $key => $method){
				if(empty($args[$key]) && method_exists($model, $method)){
					$args[$key]	= [$model, $method];
				}
			}
		}

		return wpjam_add_item('route', $name, $args);
	}
}

class WPJAM_JSON extends WPJAM_Register{
	public function response(){
		$method		= $this->method ?: $_SERVER['REQUEST_METHOD'];
		$response	= apply_filters('wpjam_pre_json', [], $this->args, $this->name);
		$response	= wpjam_throw_if_error($response);
		$response	= array_merge($response, [
			'errcode'		=> 0,
			'current_user'	=> wpjam_try('wpjam_get_current_user', $this->pull('auth'))
		]);

		if($method != 'POST' && !str_ends_with($this->name, '.config')){
			$response	+= wp_array_slice_assoc($this, ['page_title', 'share_title', 'share_image']);
		}

		if($this->fields){
			$fields	= $this->fields;
			$fields	= is_callable($fields) ? wpjam_try($fields, $this->name) : $fields;
			$data	= wpjam_fields($fields)->get_parameter($method);
		}

		if($this->modules){
			$modules	= self::parse_modules($this->modules, $this->name);
			$results	= array_map([self::class, 'parse_module'], $modules);
		}else{
			$results	= [];
			$callback	= $this->pull('callback');

			if($callback){
				if(is_callable($callback)){
					$results[]	= wpjam_try($callback, ($this->fields ? $data : $this->args), $this->name);
				}
			}elseif($this->template){
				if(is_file($this->template)){
					$results[]	= include $this->template;
				}
			}else{
				$results[]	= $this->args;
			}
		}

		foreach($results as $result){
			wpjam_throw_if_error($result);

			if(is_array($result)){
				$attr		= wp_array_slice_assoc($response, ['page_title', 'share_title', 'share_image']);
				$response	= array_merge($response, array_diff_key($result, array_filter($attr)));
			}
		}

		$response	= apply_filters('wpjam_json', $response, $this->args, $this->name);

		if($method != 'POST' && !str_ends_with($this->name, '.config')){
			foreach(['page_title', 'share_title']  as $title_key){
				if(empty($response[$title_key])){
					$response[$title_key]	= html_entity_decode(wp_get_document_title());
				}
			}

			if(!empty($response['share_image'])){
				$response['share_image']	= wpjam_get_thumbnail($response['share_image'], '500x400');
			}
		}

		return $response;
	}

	public static function parse_modules($modules, $name){
		$modules	= is_callable($modules) ? call_user_func($modules, $name) : $modules;

		return wp_is_numeric_array($modules) ? $modules : [$modules];
	}

	public static function parse_module($module){
		$args	= array_get($module, 'args', []);
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');
		$parser	= array_get($module, 'parser') ?: wpjam_get_item('json_module_parser', array_get($module, 'type'));

		return $parser ? wpjam_catch($parser, $args) : $args;
	}

	public static function add_module_parser($type, $callback){
		return wpjam_add_item('json_module_parser', $type, $callback);
	}

	public static function redirect($action){
		if(!wpjam_doing_debug()){
			header('X-Content-Type-Options: nosniff');

			rest_send_cors_headers(false); 

			if('OPTIONS' === $_SERVER['REQUEST_METHOD']){
				status_header(403);
				exit;
			}

			$type	= wp_is_jsonp_request() ? 'javascript' : 'json';

			@header('Content-Type: application/'.$type.'; charset='.get_option('blog_charset'));
		}

		if(!str_starts_with($action, 'mag.')){
			return;
		}

		$part	= wp_is_jsonp_request() ? '_jsonp' : (wp_is_json_request() ? '_json' : '');

		add_filter('wp_die'.$part.'_handler',	[self::class, 'filter_die_handler']);

		$name	= wpjam_remove_prefix($action, 'mag.');
		$name	= wpjam_remove_prefix($name, 'mag.');	// 兼容
		$name	= str_replace('/', '.', $name);
		$name	= apply_filters('wpjam_json_name', $name);

		wpjam_set_current_var('json', $name);

		$current	= wpjam_get_current_user();

		if($current && !empty($current['user_id'])){
			wp_set_current_user($current['user_id']);
		}

		do_action('wpjam_api', $name);

		$object	= self::get($name);

		if(!$object){
			wp_die('接口未定义！', 'invalid_api');
		}

		if(is_wp_error($object)){
			self::send($object);
		}

		self::add_module_parser('post_type',	['WPJAM_Posts', 'parse_json_module']);
		self::add_module_parser('taxonomy',		['WPJAM_Terms', 'parse_json_module']);
		self::add_module_parser('setting',		['WPJAM_Setting', 'parse_json_module']);
		self::add_module_parser('media',		['WPJAM_Posts', 'parse_media_json_module']);
		self::add_module_parser('data_type',	['WPJAM_Data_Type', 'parse_json_module']);
		self::add_module_parser('config',		'wpjam_get_config');

		self::send(wpjam_catch([$object, 'response']));
	}

	public static function send($data=[], $status_code=null){
		$data	= wpjam_parse_error($data);
		$result	= self::encode($data);

		if(!headers_sent() && !wpjam_doing_debug()){
			if(!is_null($status_code)){
				status_header($status_code);
			}

			if(wp_is_jsonp_request()){
				$result	= '/**/' . $_GET['_jsonp'] . '(' . $result . ')';

				$type	= 'javascript';
			}else{
				$type	= 'json';
			}

			@header('Content-Type: application/'.$type.'; charset='.get_option('blog_charset'));
		}

		echo $result;

		exit;
	}

	public static function encode($data){
		return wp_json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	public static function decode($json, $assoc=true){
		$json	= wpjam_strip_control_characters($json);

		if(!$json){
			return new WP_Error('json_decode_error', 'JSON 内容不能为空！');
		}

		$result	= json_decode($json, $assoc);

		if(is_null($result)){
			$result	= json_decode(stripslashes($json), $assoc);

			if(is_null($result)){
				if(wpjam_doing_debug()){
					print_r(json_last_error());
					print_r(json_last_error_msg());
				}
				trigger_error('json_decode_error '. json_last_error_msg()."\n".var_export($json,true));
				return new WP_Error('json_decode_error', json_last_error_msg());
			}
		}

		return $result;
	}

	public static function filter_die_handler(){
		return fn($message, $title='', $code=0) => self::send(WPJAM_Error::convert($message, $title, $code));
	}

	public static function get_current($output='name'){
		$name	= wpjam_get_current_var('json');

		return $output == 'object' ? self::get($name) : $name;
	}

	public static function get_rewrite_rule(){
		return [
			['api/([^/]+)/(.*?)\.json?$',	['module'=>'json', 'action'=>'mag.$matches[1].$matches[2]'], 'top'],
			['api/([^/]+)\.json?$', 		'index.php?module=json&action=$matches[1]', 'top'],
		];
	}

	public static function get_defaults(){
		return [
			'post.list'		=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'post.calendar'	=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'post.get'		=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'media.upload'	=> ['modules'=>['type'=>'media', 'args'=>['media'=>'media']]],
			'site.config'	=> ['modules'=>['type'=>'config']],
		];
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['parse_post_list_module', 'parse_post_get_module'])){
			$args	= $args[0] ?? [];
			$action	= str_replace(['parse_post_', '_module'], '', $method);

			return self::parse_module(['type'=>'post_type', 'args'=>array_merge($args, ['action'=>$action])]);
		}
	}
}

class WPJAM_Error extends WPJAM_Model{
	public static function get_handler(){
		return wpjam_get_handler('wpjam_errors', [
			'option_name'	=> 'wpjam_errors',
			'primary_key'	=> 'errcode',
			'primary_title'	=> '代码',
		]);
	}

	public static function filter($data){
		$error	= self::get($data['errcode']);

		if($error){
			$data['errmsg']	= $error['errmsg'];

			if(!empty($error['show_modal'])){
				if(!empty($error['modal']['title']) && !empty($error['modal']['content'])){
					$data['modal']	= $error['modal'];
				}
			}
		}else{
			if(empty($data['errmsg'])){
				$item	= self::get_setting($data['errcode']);

				if($item){
					$data['errmsg']	= $item['message'];

					if($item['modal']){
						$data['modal']	= $item['modal'];
					}
				}
			}
		}

		return $data;
	}

	public static function parse($data){
		if(is_wp_error($data)){
			$errdata	= $data->get_error_data();
			$data		= [
				'errcode'	=> $data->get_error_code(),
				'errmsg'	=> $data->get_error_message(),
			];

			if($errdata){
				$errdata	= is_array($errdata) ? $errdata : ['errdata'=>$errdata];
				$data 		= $data + $errdata;
			}
		}else{
			if($data === true){
				return ['errcode'=>0];
			}elseif($data === false || is_null($data)){
				return ['errcode'=>'-1', 'errmsg'=>'系统数据错误或者回调函数返回错误'];
			}elseif(is_array($data)){
				if(!$data || !wp_is_numeric_array($data)){
					$data	= wp_parse_args($data, ['errcode'=>0]);
				}
			}
		}

		if(!empty($data['errcode'])){
			$data	= self::filter($data);
		}

		return $data;
	}

	public static function if($data, $err=[]){
		$err	= wp_parse_args($err,  [
			'errcode'	=> 'errcode',
			'errmsg'	=> 'errmsg',
			'detail'	=> 'detail',
			'success'	=> '0',
		]);

		$code	= wpjam_pull($data, $err['errcode']);

		if($code && $code != $err['success']){
			$msg	= wpjam_pull($data, $err['errmsg']);
			$detail	= wpjam_pull($data, $err['detail']);
			$detail	= is_null($detail) ? array_filter($data) : $detail;

			return new WP_Error($code, $msg, $detail);
		}

		return $data;
	}

	public static function convert($message, $title='', $code=0){
		if(is_wp_error($message)){
			return $message;
		}

		if($code){
			$detail	= $title ? ['modal'=>['title'=>$title, 'content'=>$message]] : [];

			return new WP_Error($code, $message, $detail);
		}

		$code	= $title;

		if(!$code){
			if(is_scalar($message) && self::get_setting($message)){
				[$code, $message]	= [$message, ''];
			}else{
				$code	= 'error';
			}
		}

		return new WP_Error($code, $message);
	}

	public static function callback($args, $code){
		if($code == 'undefined_method'){
			if(count($args) >= 2){
				return sprintf('「%s」%s未定义', ...$args);
			}elseif(count($args) == 1){
				return sprintf('%s方法未定义', ...$args);
			}
		}elseif($code == 'quota_exceeded'){
			if(count($args) >= 2){
				return sprintf('%s超过上限：%s', ...$args);
			}elseif(count($args) == 1){
				return sprintf('%s超过上限', ...$args);
			}else{
				return '超过上限';
			}
		}
	}

	public static function get_setting($code){
		$item	= wpjam_get_item('error', $code);

		if($item && $item['message']){
			return $item;
		}
	}

	public static function add_setting($code, $message, $modal=[]){
		wpjam_add_item('error', $code, ['message'=>$message, 'modal'=>$modal]);
	}

	public static function on_loaded(){
		add_action('wp_error_added', [self::class, 'on_wp_error_added'], 10, 4);

		foreach([
			['invalid_post_type',	'无效的文章类型'],
			['invalid_taxonomy',	'无效的分类模式'],
			['invalid_menu_page',	'页面%s「%s」未定义。'],
			['invalid_item_key',	'「%s」已存在，无法%s。'],
			['invalid_page_key',	'无效的%s页面。'],
			['invalid_name',		'%s不能为纯数字。'],
			['invalid_nonce',		'验证失败，请刷新重试。'],
			['invalid_code',		'验证码错误。'],
			['invalid_password',	'两次输入的密码不一致。'],
			['incorrect_password',	'密码错误。'],
			['bad_authentication',	'无权限'],
			['access_denied',		'操作受限'],
			['value_required',		'%s的值为空或无效。'],
			['undefined_method',	[self::class, 'callback']],
			['quota_exceeded',		[self::class, 'callback']],
		] as $args){
			self::add_setting(...$args);
		}
	}

	public static function on_wp_error_added($code, $message, $data, $wp_error){
		if($code && (!$message || is_array($message)) && count($wp_error->get_error_messages($code)) <= 1){
			$item	= self::get_setting($code);

			if(is_array($code)){
				trigger_error(var_export($code, true));
			}

			if($item){
				if($item['modal']){
					$data	= is_array($data) ? $data : [];
					$data	= array_merge($data, ['modal'=>$item['modal']]);
				}

				if(is_callable($item['message'])){
					$message	= call_user_func($item['message'], $message, $code);
				}else{
					$message	= is_array($message) ? sprintf($item['message'], ...$message) : $item['message'];
				}
			}elseif(str_starts_with($code, 'invalid_')){
				$msg	= is_array($message) ? implode($message) : '';
				$name	= wpjam_remove_prefix($code, 'invalid_');

				if($name == 'parameter'){
					$message	= $msg ? '无效的参数：'.$msg.'。' : '参数错误。';
				}elseif($name == 'callback'){
					$message	= '无效的回调函数'.($msg ? '：' : '').$msg.'。';
				}else{
					$map	= [
						'id'			=> ' ID',
						'appsecret'		=> '密钥',
						'post'			=> '文章',
						'term'			=> '分类',
						'user_id'		=> '用户 ID',
						'user'			=> '用户',
						'comment_type'	=> '评论类型',
						'comment_id'	=> '评论 ID',
						'comment'		=> '评论',
						'type'			=> '类型',
						'signup_type'	=> '登录方式',
						'email'			=> '邮箱地址',
						'data_type'		=> '数据类型',
						'submit_button'	=> '提交按钮',
						'qrcode'		=> '二维码',
					];

					$message	= '无效的'.$msg.($map[$name] ?? ' '.ucwords($name));
				}
			}elseif(str_starts_with($code, 'illegal_')){
				$name	= wpjam_remove_prefix($code, 'illegal_');
				$map	= [
					'access_token'	=> 'Access Token ',
					'refresh_token'	=> 'Refresh Token ',
					'verify_code'	=> '验证码',
				];

				$message	= ($map[$name] ?? ucwords($name).' ').'无效或已过期。';
			}elseif(str_ends_with($code, '_occupied')){
				$name	= wpjam_remove_postfix($code, '_occupied');
				$map	= [
					'phone'		=> '手机号码',
					'email'		=> '邮箱地址',
					'nickname'	=> '昵称',
				];

				$message	= ($map[$name] ?? ucwords($name).' ').'已被其他账号使用。';
			}

			if($message){
				$wp_error->remove($code);
				$wp_error->add($code, $message, $data);
			}
		}
	}
}

class WPJAM_Exception extends Exception{
	private $errcode	= '';

	public function __construct($errmsg, $errcode=null, Throwable $previous=null){
		if(is_array($errmsg)){
			$errmsg	= new WP_Error($errcode, $errmsg);
		}

		if(is_wp_error($errmsg)){
			$errcode	= $errmsg->get_error_code();
			$errmsg		= $errmsg->get_error_message();
		}else{
			$errcode	= $errcode ?: 'error';
		}

		$this->errcode	= $errcode;

		parent::__construct($errmsg, (is_numeric($errcode) ? (int)$errcode : 1), $previous);
	}

	public function get_error_code(){
		return $this->errcode;
	}

	public function get_error_message(){
		return $this->getMessage();
	}

	public function get_wp_error(){
		return new WP_Error($this->errcode, $this->getMessage());
	}
}

class WPJAM_Parameter{
	public static function get_value($name, $args=[], $method=''){
		if(is_array($name)){
			if(!$name){
				return [];
			}

			if(wp_is_numeric_array($name)){
				return wpjam_fill($name, fn($n) => self::get_value($n, $args, $method));
			}else{
				return wpjam_map($name, fn($v, $n) => self::get_value($n, $v, $method));
			}
		}

		$method	= $method ?: (array_get($args, 'method') ?: 'GET');
		$method	= strtoupper($method);
		$value	= self::get_by_name($name, $method);

		if($name){
			if(is_null($value) && !empty($args['fallback'])){
				$value	= self::get_by_name($args['fallback'], $method);
			}

			if(is_null($value)){
				if(isset($args['default'])){
					$value		= $args['default'];
				}else{
					$defaults	= wpjam_get_current_var('defaults') ?: [];
					$value		= $defaults[$name] ?? null;
				}
			}

			$args	= array_except($args, ['method', 'fallback', 'default']);

			if($args){
				$args['key']	= $name;
				$args['type']	??= '';

				if($args['type'] == 'int'){	// 兼容
					$args['type']	= 'number';
				}

				$field	= wpjam_field($args);

				if(!$args['type']){
					$field->set_schema(false);
				}

				$value	= wpjam_catch([$field, 'validate'], $value, 'parameter');

				if(is_wp_error($value) && array_get($args, 'send') !== false){
					wpjam_send_json($value);
				}
			}
		}

		return $value;
	}

	private static function get_by_name($name, $method){
		if($method == 'DATA'){
			if($name && isset($_GET[$name])){
				return wp_unslash($_GET[$name]);
			}

			$data	= self::get_data();
		}else{
			$data	= ['POST'=>$_POST, 'REQUEST'=>$_REQUEST][$method] ?? $_GET;

			if($name){
				if(isset($data[$name])){
					return wp_unslash($data[$name]);
				}

				if($_POST || !in_array($method, ['POST', 'REQUEST'])){
					return null;
				}
			}else{
				if($data || in_array($method, ['GET', 'REQUEST'])){
					return wp_unslash($data);
				}
			}

			$data	= self::get_input();
		}

		return $name ? ($data[$name] ?? null) : $data;
	}

	private static function get_input(){
		static $_input;

		if(!isset($_input)){
			$input	= file_get_contents('php://input');
			$input	= is_string($input) ? @wpjam_json_decode($input) : $input;
			$_input	= is_array($input) ? $input : [];
		}

		return $_input;
	}

	private static function get_data(){
		static $_data;

		return $_data	??= array_reduce(['defaults', 'data'], fn($data, $k) => wpjam_merge($data, wp_parse_args(self::get_by_name($k, 'POST') ?? [])), []);
	}
}

class WPJAM_Request{
	public static function request($url, $args=[], $err=[]){
		$args	= wp_parse_args($args, [
			'body'		=> [],
			'headers'	=> [],
			'sslverify'	=> false,
			'stream'	=> false,
		]);

		$method	= strtoupper(wpjam_pull($args, 'method', '')) ?: ($args['body'] ? 'POST' : 'GET');

		if($method == 'GET'){
			$response	= wp_remote_get($url, $args);
		}elseif($method == 'FILE'){
			$response	= (new WP_Http_Curl())->request($url, wp_parse_args($args, [
				'method'			=> $args['body'] ? 'POST' : 'GET',
				'sslcertificates'	=> ABSPATH.WPINC.'/certificates/ca-bundle.crt',
				'user-agent'		=> 'WordPress',
				'decompress'		=> true,
			]));
		}else{
			$response	= wp_remote_request($url, array_merge(self::encode($args), ['method'=>$method]));
		}

		$args['url']	= $url;

		if(is_wp_error($response)){
			return self::log($response, $args);
		}

		$code	= $response['response']['code'] ?? 0;

		if($code && $code != 200){
			return new WP_Error($code, '远程服务器错误：'.$code.' - '.$response['response']['message']);
		}

		$body	= &$response['body'];

		if($body && !$args['stream']){
			if(str_contains(wp_remote_retrieve_header($response, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}else{
				$body	= self::decode($body, $args, $err);
			}
		}

		return array_get($args, 'response') ? $response : $body;
	}

	private static function encode($args){
		$encode	= wpjam_pull($args, 'json_encode_required', wpjam_pull($args, 'need_json_encode'));

		if($encode){
			if(is_array($args['body'])){
				$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
			}

			if(empty($args['headers']['Content-Type'])){
				$args['headers']['Content-Type']	= 'application/json';
			}
		}

		return $args;
	}

	private static function decode($body, $args=[], $err=[]){
		if(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
			$decoded	= wpjam_json_decode($body);

			if(!is_wp_error($decoded)){
				$body	= WPJAM_Error::if($decoded, $err);

				if(is_wp_error($body)){
					self::log($body, $args);
				}
			}
		}

		return $body;
	}

	private static function log($error, $args=[]){
		$code	= $error->get_error_code();
		$msg	= $error->get_error_message();

		if(apply_filters('wpjam_http_response_error_debug', true, $code, $msg)){
			$detail	= $error->get_error_data();
			$detail	= $detail ? var_export($detail, true)."\n" : '';

			trigger_error($args['url']."\n".$code.' : '.$msg."\n".$detail.var_export($args['body'], true));
		}

		return $error;
	}
}