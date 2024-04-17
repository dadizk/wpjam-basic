<?php
/*
Name: 样式定制
URI: https://mp.weixin.qq.com/s/Hpu1vz7zPUKEeHTF3wqyWw
Description: 对网站的前后台和登录界面的样式进行个性化设置。
Version: 2.0
*/
class WPJAM_Custom extends WPJAM_Option_Model{
	public static function get_sections(){
		return [
			'wpjam-custom'	=> ['title'=>'前台定制',	'fields'=>[
				'head'		=> ['title'=>'前台 Head 代码',	'type'=>'textarea',	'class'=>''],
				'footer'	=> ['title'=>'前台 Footer 代码',	'type'=>'textarea',	'class'=>''],
			]],
			'admin-custom'	=> ['title'=>'后台定制',	'fields'=>[
				'admin_logo'	=> ['title'=>'工具栏左上角 Logo',	'type'=>'img',	'item_type'=>'url',	'description'=>'建议大小：20x20。如果前台也显示工具栏，也会被修改。'],
				'admin_head'	=> ['title'=>'后台 Head 代码 ',	'type'=>'textarea',	'class'=>''],
				'admin_footer'	=> ['title'=>'后台 Footer 代码',	'type'=>'textarea',	'class'=>'']
			]],
			'login-custom'	=> ['title'=>'登录界面', 	'fields'=>[
				'login_head'		=> ['title'=>'登录界面 Head 代码',		'type'=>'textarea',	'class'=>''],
				'login_footer'		=> ['title'=>'登录界面 Footer 代码',	'type'=>'textarea',	'class'=>''],
				'login_redirect'	=> ['title'=>'登录之后跳转的页面',		'type'=>'text'],
				'disable_language_switcher'	=> ['title'=>'登录界面语言切换器',		'type'=>'checkbox',	'description'=>'屏蔽登录界面语言切换器'],
			]]
		];
	}

	public static function get_menu_page(){
		$menu_page	= [
			'parent'	=> 'wpjam-basic',
			'menu_slug'	=> 'wpjam-custom',
			'function'	=> 'option',
			'position'	=> 1,
			'summary'	=> __FILE__,
		];

		if(wpjam_get_user_signups(['bind'=>true])){
			return [$menu_page, [
				'parent'		=> 'users',
				'menu_slug'		=> 'wpjam-bind',
				'menu_title'	=> '账号绑定',
				'order'			=> 20,
				'capability'	=> 'read',
				'function'		=> 'tab',
				'tabs'			=> [self::class, 'get_tabs']
			]];
		}

		return $menu_page;
	}

	public static function get_admin_load(){
		if(wpjam_get_user_signups()){
			return [
				'base'	=> 'users',
				'model'	=> self::class
			];
		}
	}

	public static function ajax_callback($data, $name){
		$action	= $data['action'] ?? '';
		$type	= $data['type'] ?? '';
		$object	= wpjam_get_user_signup_object($type);

		if(!$object){
			wp_die('无效的类型');
		}

		if($name == 'get-signup'){
			$attr	= wpjam_throw_if_error($object->get_attr($action));

			$attr['fields']	= wpjam_fields($attr['fields'])->render(['wrap_tag'=>'p']);

			return array_merge(wpjam_get_ajax_data_attr('signup')->to_array(), $attr);
		}else{
			if($action == 'bind'){
				$result	= $object->bind($data);
			}elseif($action == 'unbind'){
				$result	= $object->unbind();
			}else{
				$result	= $object->signup($data);
			}

			return is_wp_error($result) ? $result : true;
		}
	}

	public static function on_admin_bar_menu($wp_admin_bar){
		remove_action('admin_bar_menu',	'wp_admin_bar_wp_menu', 10);

		$logo	= self::get_setting('admin_logo');
		$logo	= $logo ? wpjam_get_thumbnail($logo, 40, 40) : '';
		$title	= $logo ? wpjam_tag('img', ['src'=>$logo, 'style'=>'height:20px; padding:6px 0;']) : wpjam_tag('span', ['ab-icon']);

		$wp_admin_bar->add_menu([
			'id'    => 'wp-logo',
			'title' => $title,
			'href'  => is_admin() ? self_admin_url() : site_url(),
			'meta'  => ['title'=>get_bloginfo('name')]
		]);
	}

	public static function filter_admin_title($admin_title){
		return str_replace(' &#8212; WordPress', '', $admin_title);
	}

	public static function filter_admin_footer_text($text){
		return self::get_setting('admin_footer') ?: '<span id="footer-thankyou">感谢使用<a href="https://wordpress.org/" target="_blank">WordPress</a>进行创作。</span> | <a href="https://wpjam.com/" title="WordPress JAM" target="_blank">WordPress JAM</a>';
	}

	public static function filter_login_headerurl(){
		return home_url();
	}

	public static function filter_login_redirect($redirect_to, $request){
		return $request ?: (self::get_setting('login_redirect') ?: $redirect_to);
	}

	public static function on_custom(){
		$name	= current_action();

		if(in_array($name, ['wp_head', 'wp_footer'])){
			$name	= wpjam_remove_prefix($name, 'wp_');
		}

		echo self::get_setting($name);

		if($name == 'footer' && wpjam_basic_get_setting('optimized_by_wpjam')){
			echo '<p id="optimized_by_wpjam_basic">Optimized by <a href="https://blog.wpjam.com/project/wpjam-basic/">WPJAM Basic</a>。</p>';
		}
	}

	public static function on_login_init(){
		$action	= wpjam_get_request_parameter('action', ['default'=>'login']);

		wpjam_set_current_var('login_form_action', $action);

		wp_enqueue_script('wpjam-ajax');

		if(in_array($action, ['login', 'bind'])){
			$objects	= wpjam_get_user_signups([$action=>true]);

			if($objects){
				wp_enqueue_script('wpjam-login', wpjam_url(dirname(__DIR__).'/static/login.js'), ['wpjam-ajax']);

				add_action('login_form_'.$action,	[self::class, 'on_login_action']);
				add_action('login_form',			[self::class, 'on_login_form']);

				$type	= wpjam_get_request_parameter($action.'_type');

				if($action == 'login'){
					$type	= $type ?: apply_filters('wpjam_default_login_type', 'login');

					if(!$type && $_SERVER['REQUEST_METHOD'] == 'POST'){
						$type = 'login';
					}
				}

				if($type != 'login' && (!$type || !isset($objects[$type]))){
					$type	= array_key_first($objects);
				}

				wpjam_set_current_var($action.'_type', $type);
			}
		}

		wp_add_inline_style('login', join("\n", [
			'.login .message, .login #login_error{margin-bottom: 0;}',
			'.code_wrap label:last-child{display:flex;}',
			'.code_wrap input.button{margin-bottom:10px;}',
			'.login form .input, .login input[type=password], .login input[type=text]{font-size:20px; margin-bottom:10px;}',

			'p.types{line-height:2; float:left; clear:left; margin-top:10px;}',
			'p.types a{text-decoration: none; display:block;}',
			'p.types a.current{display:none;}',
			'div.fields{margin-bottom:10px;}',
		]));
	}

	public static function on_login_action(){
		$action	= wpjam_get_current_var('login_form_action');

		if($action == 'login'){
			$type	= wpjam_get_current_var($action.'_type');
			$object	= wpjam_get_user_signup_object($type);

			if($object && $object->login_action && is_callable($object->login_action)){
				call_user_func($object->login_action);
			}

			if(empty($_COOKIE[TEST_COOKIE])){
				$_COOKIE[TEST_COOKIE]	= 'WP Cookie check';
			}
		}else{
			if(!is_user_logged_in()){
				wp_die('登录之后才能执行绑定操作！');
			}

			add_filter('login_display_language_dropdown', '__return_false');
		}
	}

	public static function on_login_form(){
		$action	= wpjam_get_current_var('login_form_action');
		$type	= wpjam_get_current_var($action.'_type');
		$tag	= wpjam_tag('p', ['class'=>'types', 'data'=>['action'=>$action]]);

		foreach(wpjam_get_user_signups([$action=>true]) as $name => $object){
			$data	= wpjam_get_ajax_data_attr('get-signup');
			$data	= $data->update_args(['type'=>$name, 'data'=>['action'=>$action, 'type'=>$name]]);
			$title	= $action == 'bind' ? '绑定'.$object->title : $object->login_title;
			$class	= $type == $name ? 'current' : '';

			$tag->append('a', ['class'=>$class, 'data'=>$data], $title);

			add_action('login_footer',	[$object, $action.'_script'], 1000);
		}

		if($action == 'login'){
			$class	= $type == 'login' ? 'current' : '';

			$tag->append('a', ['class'=>$class, 'data'=>['type'=>'login']], '使用账号和密码登录');
		}

		echo $tag;
	}

	public static function get_tabs(){
		$tabs	= [];

		foreach(wpjam_get_user_signups(['bind'=>true]) as $name => $object){
			$tabs[$name]	= [
				'title'			=> $object->title,
				'capability'	=> 'read',
				'function'		=> 'form',
				'form_name'		=> $name.'_bind',
				'form'			=> [self::class, 'get_bind_form']
			];
		}

		return $tabs;
	}

	public static function get_bind_form(){
		$name	= $GLOBALS['current_tab'];
		$object	= wpjam_get_user_signup_object($name);

		if(!wp_doing_ajax() && method_exists($object, 'bind_script')){
			add_action('admin_footer', [$object, 'bind_script']);
		}

		return array_merge($object->get_attr('bind', 'admin'), [
			'callback'		=> [self::class, 'ajax_callback'],
			'capability'	=> 'read',
			'validate'		=> true,
			'response'		=> 'redirect'
		]);
	}

	public static function builtin_page_load(){
		wpjam_register_list_table_column('openid', [
			'title'		=> '绑定账号',
			'order'		=> 20,
			'callback'	=> [self::class, 'openid_column_callback']
		]);
	}

	public static function openid_column_callback($user_id){
		$values	= [];

		foreach(wpjam_get_user_signups() as $object){
			$openid = $object->get_openid($user_id);

			if($openid){
				$values[]	= $object->title.'：<br />'.$openid;
			}
		}

		return $values ? implode('<br /><br />', $values) : '';
	}

	public static function init(){
		wpjam_register_bind('phone', '', ['domain'=>'@phone.sms']);

		wpjam_register_ajax('get-signup', [
			'nopriv'	=> true,
			'verify'	=> false,
			'callback'	=> [self::class, 'ajax_callback']
		]);

		wpjam_register_ajax('signup', [
			'nopriv'	=> true,
			'callback'	=> [self::class, 'ajax_callback']
		]);

		add_action('admin_bar_menu',	[self::class, 'on_admin_bar_menu'], 1);

		if(is_admin()){
			add_action('admin_head',		[self::class, 'on_custom']);
			add_filter('admin_title', 		[self::class, 'filter_admin_title']);
			add_filter('admin_footer_text',	[self::class, 'filter_admin_footer_text']);
		}elseif(is_login()){
			add_filter('login_headerurl',	[self::class, 'filter_login_headerurl']);
			add_filter('login_headertext',	'get_bloginfo');

			add_action('login_head', 		[self::class, 'on_custom']);
			add_action('login_footer',		[self::class, 'on_custom']);
			add_filter('login_redirect',	[self::class, 'filter_login_redirect'], 10, 2);

			if(wp_using_ext_object_cache()){
				add_action('login_init',	[self::class, 'on_login_init']);
			}

			if(self::get_setting('disable_language_switcher')){
				add_filter('login_display_language_dropdown',	'__return_false');
			}
		}else{
			add_action('wp_head',	[self::class, 'on_custom'], 1);
			add_action('wp_footer', [self::class, 'on_custom'], 99);
		}
	}
}

wpjam_register_option('wpjam-custom', [
	'title'			=> '样式定制',
	'model'			=> 'WPJAM_Custom',
	'site_default'	=> true,
]);

