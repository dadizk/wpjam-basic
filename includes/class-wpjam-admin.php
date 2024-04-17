<?php
class WPJAM_Admin{
	public static function menu(){
		do_action('wpjam_admin_init');

		$prefix		= self::get_prefix();
		$rendering	= doing_action($prefix.'admin_menu');

		if($rendering){
			$builtins	= array_filter(array_flip($GLOBALS['admin_page_hooks']), fn($s) => str_contains($s, '.php'));

			if(!$prefix){
				$builtins	= wpjam_array($builtins, fn($k, $v) => str_starts_with($v, 'edit.php?') && $k != 'pages' ? wpjam_get_post_type_setting($k, 'plural') : $k);
				$builtins	+=['themes'=>'themes.php', 'options'=>'options-general.php'];

				if(!current_user_can('edit_users')){
					$builtins['users']	= 'profile.php';
				}
			}
		}else{
			if(empty($GLOBALS['plugin_page'])){
				return;
			}
		}

		foreach(apply_filters('wpjam_'.$prefix.'pages', wpjam_get_items('menu_page')) as $slug => $args){
			$args	= wp_parse_args($args, ['menu_slug'=>$slug]);
			$menu	= wpjam_args($args);
			$slug	= $menu->menu_slug;

			if(($rendering && !isset($builtins[$slug])) || $GLOBALS['plugin_page'] == $slug){
				$menu	= self::parse_menu($menu, '', $rendering);

				if(!$rendering && (!$menu || !$menu->subs)){
					break;
				}
			}

			if(!$menu || !$menu->subs){
				continue;
			}

			$parent	= $builtins[$slug] ?? $slug;
			$subs	= $menu->subs;

			uasort($subs, fn($s1, $s2) => array_get($s1, 'position', 10) <=> array_get($s2, 'position', 10) ?: array_get($s2, 'order', 10) <=> array_get($s1, 'order', 10));

			if($parent == $slug){
				$sub	= $subs[$slug] ?? '';

				if(!$sub){
					$sub	= wpjam_except($args, ['position', 'subs', 'page_title']);

					if(!empty($sub['sub_title'])){
						$sub['menu_title']	= $sub['sub_title'];
					}
				}

				$subs	= array_merge([$slug=>$sub], $subs);
			}

			foreach($subs as $s => $sub){
				$sub['menu_slug'] ??= $s;

				if($rendering || $GLOBALS['plugin_page'] == $sub['menu_slug']){
					self::parse_menu(wpjam_args($sub), $parent, $rendering);

					if(!$rendering){
						break 2;
					}
				}
			}
		}
	}

	public static function load($type, ...$args){
		if($type == 'plugin_page'){
			do_action('wpjam_plugin_page_load', ...$args);	// 兼容
		}

		$loads	= [];

		foreach(wpjam_get_items('admin_load') as $load){
			if($load['type'] != $type){
				continue;
			}

			if($type == 'plugin_page'){
				$by_tab	= true;

				if(!empty($load['plugin_page'])){
					if(is_callable($load['plugin_page'])){
						if(!call_user_func($load['plugin_page'], ...$args)){
							continue;
						}

						$by_tab	= false;
					}else{
						if(!wpjam_compare($args[0], $load['plugin_page'])){
							continue;
						}
					}
				}

				if($by_tab){
					if(!empty($load['current_tab'])){
						if(!$args[1] || !wpjam_compare($args[1], $load['current_tab'])){
							continue;
						}
					}else{
						if($args[1]){
							continue;
						}
					}
				}
			}elseif($type == 'builtin_page'){
				if(!empty($load['screen']) && is_callable($load['screen']) && !call_user_func($load['screen'], $args[0])){
					continue;
				}

				if(wpjam_some(['base', 'post_type', 'taxonomy'], fn($k) => !empty($load[$k]) && !wpjam_compare($args[0]->$k, $load[$k]))){
					continue;
				}
			}

			$loads[]	= wp_parse_args($load, ['order'=>10]);
		}

		usort($loads, fn($l1, $l2) => $l2['order'] <=> $l1['order']);

		foreach($loads as $load){
			if(!empty($load['page_file'])){
				array_map(fn($file) => is_file($file) ? include $file : null, (array)$load['page_file']);
			}

			if(!empty($load['callback'])){
				if(is_callable($load['callback'])){
					call_user_func_array($load['callback'], $args);
				}
			}elseif(!empty($load['model'])){
				array_map(fn($method) => method_exists($load['model'], $method) ? call_user_func([$load['model'], $method], ...$args) : null, ['load', $type.'_load']);
			}
		}
	}

	public static function ajax($args){
		if(!$args || !$args['callback'] || !is_callable($args['callback'])){
			wpjam_send_error_json('invalid_callback');
		}

		if(!empty($args['fields'])){
			$data	= wpjam_catch([wpjam_fields($args['fields']), 'get_parameter'], 'POST');

			if(is_wp_error($data)){
				wpjam_send_json($data);
			}
		}else{
			$data	= wpjam_get_post_parameter();
		}

		add_filter('wp_die_ajax_handler', ['WPJAM_JSON', 'filter_die_handler']);

		wpjam_send_json(wpjam_catch($args['callback'], $data));
	}

	public static function get_prefix(){
		return is_network_admin() ? 'network_' : (is_user_admin() ? 'user_' : '');
	}

	public static function get_current_screen_id(){
		$screen_id	= $_POST['screen_id'] ?? ($_POST['screen'] ?? null);

		if(is_null($screen_id)){
			$action	= $_REQUEST['action'] ?? '';

			if($action == 'fetch-list'){
				$screen_id	= $_GET['list_args']['screen']['id'];
			}elseif($action == 'inline-save-tax'){
				$screen_id	= 'edit-'.sanitize_key($_POST['taxonomy']);
			}elseif(in_array($action, ['get-comments', 'replyto-comment'])){
				$screen_id	= 'edit-comments';
			}
		}

		if($screen_id){
			if(str_ends_with($screen_id, '-network')){
				if(!defined('WP_NETWORK_ADMIN')){
					define('WP_NETWORK_ADMIN', true);
				}
			}elseif(str_ends_with($screen_id, '-user')){
				if(!defined('WP_USER_ADMIN')){
					define('WP_USER_ADMIN', true);
				}
			}
		}

		return $screen_id;
	}

	public static function get_post_id(){
		return (int)($_GET['post'] ?? ($_POST['post_ID'] ?? 0));
	}

	public static function add_ajax($action, $args){
		$args	= wpjam_is_assoc_array($args) ? $args : ['callback'=>$args];
		$args	= wp_parse_args($args, ['callback'=>null, 'fields'=>[]]);

		add_action('wp_ajax_'.$action, fn() => self::ajax($args));
	}

	public static function add_menu($args=[]){
		if(!empty($args['menu_slug']) && !empty($args['menu_title'])){
			$object = wpjam_get_items_object('menu_page');
			$slug	= wpjam_pull($args, 'menu_slug');
			$parent	= wpjam_pull($args, 'parent');
			$args	= $parent ? ['subs' => [$slug => $args]] : wp_parse_args($args, ['subs'=>[]]);
			$slug	= $parent ?: $slug;
			$item	= $object->get_item($slug);

			if($item){
				$subs	= array_merge($item['subs'], $args['subs']);
				$args	= array_merge($item, $args, ['subs'=>$subs]);
			}

			$object->set_item($slug, $args);
		}
	}

	public static function add_load($args){
		$type	= $args['type'] ?? '';
		$type	= $type ?: (isset($args['base']) ? 'builtin_page' : (isset($args['plugin_page']) ? 'plugin_page' : ''));

		if($type && in_array($type, ['builtin_page', 'plugin_page'])){
			wpjam_add_item('admin_load', array_merge($args, ['type'=>$type]));
		}
	}

	public static function add_error($message='', $type='success'){
		if(is_wp_error($message)){
			$message	= $message->get_error_message();
			$type		= 'error';
		}

		if($message && $type){
			$tag	= wpjam_tag('div', ['is-dismissible', 'notice', 'notice-'.$type], ['p', [], $message]);

			add_action(self::get_prefix().'admin_notices',	fn() => print_r($tag->render()));
		}
	}

	private static function parse_menu($menu, $parent, $rendering){
		if(is_numeric($menu->menu_slug) || !$menu->menu_title){
			return;
		}

		$admin_page	= ($parent && strpos($parent, '.php')) ? $parent : 'admin.php';
		$network	= $menu->pull('network', ($admin_page == 'admin.php'));
		$slug		= $menu->menu_slug;

		if(($network === 'only' && !is_network_admin()) || (!$network && is_network_admin())){
			return;
		}

		$menu->page_title	??= $menu->menu_title;
		$menu->capability	??= 'manage_options';

		if(!str_contains($slug, '.php')){
			$menu->admin_url = add_query_arg(['page'=>$slug], $admin_page);

			$menu	= self::parse_query_data($menu, $GLOBALS['plugin_page'] == $slug);

			if(!$menu){
				return;
			}
		}

		$object	= null;

		if($GLOBALS['plugin_page'] == $slug && ($parent || (!$parent && !$menu->subs))){
			$GLOBALS['current_admin_url']	= call_user_func(self::get_prefix().'admin_url', $menu->admin_url);

			$object	= WPJAM_Plugin_Page::add($menu->get_args());
		}

		if($rendering){
			if(str_contains($slug, '.php')){
				if($GLOBALS['pagenow'] == explode('?', $slug)[0]){
					add_filter('parent_file', fn() => ($parent ?: $slug));
				}

				$callback	= null;
			}else{
				$callback	= $object ? [$object, 'render'] : '__return_true';
			}

			$args	= [$menu->page_title, $menu->menu_title, $menu->capability, $slug, $callback];

			if($parent){
				$hook	= add_submenu_page(...[$parent, ...$args, $menu->position]);
			}else{
				$icon	= str_starts_with($menu->icon, 'ri-') ? 'dashicons-'.$menu->icon : (string)$menu->icon;
				$hook	= add_menu_page(...[...$args, $icon, $menu->position]);
			}

			if($object){
				$object->page_hook	= $hook;
			}
		}

		return $menu;
	}

	public static function parse_data_type($args, $screen=null){
		$data_type	= is_string($args) ? $args : $args['data_type'];

		if($data_type){
			$screen	??= get_current_screen();
			$screen->add_option('data_type', $data_type);

			$object		= wpjam_get_data_type_object($data_type);
			$meta_type	= $object ? $object->get_meta_type($args) : '';

			if($meta_type){
				$screen->add_option('meta_type', $meta_type);
			}

			if(in_array($data_type, ['post_type', 'taxonomy'])
				&& !$screen->$data_type
				&& !is_string($args)
				&& !empty($args[$data_type])
			){
				$screen->$data_type	= $args[$data_type];
			}
		}
	}

	public static function parse_query_data($object, $current=false){
		if(!$object->query_args){
			return $object;
		}

		$query_data	= wpjam_get_data_parameter($object->query_args);
		$null_data	= array_filter($query_data, 'is_null');
		$admin_url	= $object->admin_url;

		if($null_data){
			if($current){
				wp_die('「'.implode('」,「', array_keys($null_data)).'」参数无法获取');
			}

			return false;
		}

		$object->admin_url	= add_query_arg($query_data, $object->admin_url);
		$object->query_data	= $query_data;

		add_filter('wpjam_html', fn($html) => str_replace("href='".esc_url($admin_url)."'", "href='".$object->admin_url."'", $html));

		return $object;
	}

	public static function parse_submit_button($object, $button, $name=null, $render=true){
		$button	= $button ?: [];
		$button	= is_array($button) ? $button : [$object->name => $button];

		foreach($button as $key => &$item){
			$item	= is_array($item) ? $item : ['text'=>$item];
			$item	= wp_parse_args($item, ['response'=>($object->response ?? $object->name), 'class'=>'primary']);
			$item	= $render ? get_submit_button($item['text'], $item['class'], $key, false) : $item;
		}

		if($name){
			return $button[$name] ?? wp_die('无效的提交按钮');
		}

		return $render ? implode('', $button) : $button;
	}

	private static function parse_nonce_action($name, $args=[]){
		$prefix	= $GLOBALS['plugin_page'] ?? $GLOBALS['current_screen']->id;

		if($args){
			if(!empty($args['bulk'])){
				$name	= 'bulk_'.$name;
			}elseif(!empty($args['id'])){
				$name	= $name.'-'.$args['id'];
			}
		}

		return $prefix.'-'.$name;
	}

	public static function create_nonce($name, $args=[]){
		return wp_create_nonce(self::parse_nonce_action($name, $args));
	}

	public static function verify_nonce($name, $args=[]){
		return check_ajax_referer(self::parse_nonce_action($name, $args), false, false);
	}

	public static function on_current_screen($screen=null){
		$object	= WPJAM_Plugin_Page::get_current();

		if(!$object && $screen){
			WPJAM_Builtin_Page::init($screen);

			if(!wp_doing_ajax() && $screen->get_option('page_summary')){
				$search		= '<hr class="wp-header-end">';
				$replace	= $search.wpautop($screen->get_option('page_summary'));

				add_filter('wpjam_html', fn($html) => str_replace($search, $replace, $html));
			}
		}
	}

	public static function on_enqueue_scripts(){
		$screen	= get_current_screen();

		if($screen->base == 'customize'){
			return;
		}

		wp_enqueue_media($screen->base == 'post' ? ['post'=>self::get_post_id()] : []);

		$ver	= get_plugin_data(WPJAM_BASIC_PLUGIN_FILE)['Version'];
		$static	= wpjam_url(dirname(__DIR__), 'relative').'/static';

		wpjam_register_remixincon_style();

		wp_enqueue_style('wpjam-style', $static.'/style.css', ['thickbox', 'remixicon', 'wp-color-picker', 'editor-buttons'], $ver);
		wp_enqueue_script('wpjam-script', $static.'/script.js', ['jquery', 'thickbox', 'wp-backbone', 'jquery-ui-sortable', 'jquery-ui-tooltip', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-ui-autocomplete', 'wp-color-picker'], $ver);
		wp_enqueue_script('wpjam-form', $static.'/form.js', ['wpjam-script', 'mce-view'], $ver);

		$setting	= [
			'screen_base'	=> $screen->base,
			'screen_id'		=> $screen->id,
			'post_type'		=> $screen->post_type,
			'taxonomy'		=> $screen->taxonomy,
			'admin_url'		=> $GLOBALS['current_admin_url'] ?? '',
		];

		$params	= wpjam_except($_REQUEST, wp_removable_query_args());
		$params	= wpjam_except($params, ['page', 'tab', '_wp_http_referer', '_wpnonce']);

		if($GLOBALS['plugin_page']){
			$setting['plugin_page']	= $GLOBALS['plugin_page'];
			$setting['current_tab']	= $GLOBALS['current_tab'] ?? null;

			$query_data	= wpjam_get_plugin_page_setting('query_data');

			if($query_data){
				$params	= array_diff_key($params, $query_data);

				$setting['query_data']	= array_map(fn($item) => is_null($item) ? null : sanitize_textarea_field($item), $query_data);
			}
		}else{
			$params	= wpjam_except($params, array_filter(['taxonomy', 'post_type'], fn($k) => $screen->$k));
		}

		if($params){
			if(isset($params['data'])){
				$params['data']	= urldecode($params['data']);
			}

			$params	= map_deep($params, 'sanitize_textarea_field');
		}

		$setting['params']	= $params ?: new stdClass();

		$list_table	= $screen->get_option('list_table');

		if($list_table){
			$setting['list_table']	= $list_table->get_setting()+['ajax'=>(get_screen_option('list_table_ajax') ?? true)];
		}

		wp_localize_script('wpjam-script', 'wpjam_page_setting', $setting);
	}

	public static function on_plugins_loaded(){
		if(wp_doing_ajax()){
			self::add_ajax('wpjam-page-action', [
				'callback'	=> ['WPJAM_Page_Action', 'ajax_response'],
				'fields'	=> ['page_action'=>[], 'action_type'=>[]]
			]);

			self::add_ajax('wpjam-upload', [
				'callback'	=> ['WPJAM_Image_Field', 'ajax_response'],
				'fields'	=> ['file_name'	=> ['required'=>true]]
			]);

			self::add_ajax('wpjam-query', [
				'callback'	=> ['WPJAM_Data_Type', 'ajax_response'],
				'fields'	=> ['data_type'	=> ['required'=>true]]
			]);

			$screen_id	= self::get_current_screen_id();

			if($screen_id){
				if($screen_id == 'upload'){
					[$GLOBALS['hook_suffix'], $screen_id]	= [$screen_id, ''];
				}

				$GLOBALS['plugin_page']	= $_POST['plugin_page'] ?? null;

				add_action('admin_init', fn() => self::menu() || set_current_screen($screen_id), 9);
			}
		}else{
			add_action(self::get_prefix().'admin_menu',	[self::class, 'menu'], 9);
			add_action('admin_enqueue_scripts', 		[self::class, 'on_enqueue_scripts'], 9);

			add_filter('wpjam_html',	fn($html) => str_replace('dashicons-before dashicons-ri-', 'wp-menu-ri ri-', $html));
		}

		add_filter('admin_url', fn($url, $path, $blog_id=null) => $path && is_string($path) && str_starts_with($path, 'page=') ? get_site_url($blog_id, 'wp-admin/', 'admin').'admin.php?'.$path : $url, 9, 3);

		add_action('current_screen',	[self::class, 'on_current_screen'], 9);
	}
}

class WPJAM_Page_Action extends WPJAM_Register{
	public function is_allowed($type=''){
		$capability	= $this->capability ?? ($type ? 'manage_options' : '');

		return $capability ? current_user_can($capability, $this->name) : true;
	}

	public function callback($type=''){
		if($type == 'form'){
			$page_title	= wpjam_get_post_parameter('page_title');

			if(!$page_title){
				$key	= wpjam_find(['page_title', 'button_text', 'submit_text'], fn($k) => $this->$k && !is_array($this->$k));

				if($key){
					$page_title	= $this->$key;
				}
			}

			return [
				'form'			=> $this->get_form(),
				'width'			=> (int)$this->width,
				'modal_id'		=> $this->modal_id ?: 'tb_modal',
				'page_title'	=> $page_title
			];
		}

		if(!WPJAM_Admin::verify_nonce($this->name)){
			wp_die('invalid_nonce');
		}

		if(!$this->is_allowed($type)){
			wp_die('access_denied');
		}

		$callback	= '';
		$submit		= null;

		if($type == 'submit'){
			$submit		= wpjam_get_post_parameter('submit_name', ['default'=>$this->name]);
			$button		= $this->get_submit_button($submit);
			$callback	= $button['callback'] ?? '';
			$response	= $button['response'];
		}else{
			$response	= $this->response ?? $this->name;
		}

		$response	= ['type'=>$response];
		$callback	= $callback ?: $this->callback;

		if(!$callback || !is_callable($callback)){
			wp_die('无效的回调函数');
		}

		if($this->validate){
			$data	= wpjam_fields($this->get_fields())->get_parameter('data');
			$result	= wpjam_try($callback, $data, $this->name, $submit);
		}else{
			$result	= wpjam_try($callback, $this->name, $submit);
		}

		if(is_array($result)){
			$response	= array_merge($response, $result);
		}elseif($result === false || is_null($result)){
			$response	= new WP_Error('invalid_callback', ['返回错误']);
		}elseif($result !== true){
			$key		= $this->response == 'redirect' ? 'url' : 'data';
			$response	= array_merge($response, [$key=>$result]);
		}

		return apply_filters('wpjam_ajax_response', $response);
	}

	public function get_data(){
		$data		= $this->data ?: [];
		$callback	= $this->data_callback;

		return $callback && is_callable($callback) ? array_merge($data, wpjam_try($callback, $this->name, $this->get_fields())) : $data;
	}

	public function get_button($args=[]){
		if(!$this->is_allowed()){
			return '';
		}

		$this->update_args(wpjam_except($args, 'data'));

		$data	= $this->generate_data_attr(['data'=>wpjam_pull($args, 'data') ?: []]);
		$tag	= $this->tag ?: 'a';
		$text	= $this->button_text ?? '保存';
		$class	= $this->class ?? 'button-primary large';
		$attr	= [
			'title'	=> $this->page_title ?: $text,
			'class'	=> ['wpjam-button', ...wp_parse_list($class)],
			'style'	=> $this->style,
			'data'	=> $data
		];

		return wpjam_tag($tag, $attr, $text);
	}

	public function get_form(){
		if(!$this->is_allowed()){
			return '';
		}

		$attr	= [
			'method'	=> 'post',
			'action'	=> '#',
			'id'		=> $this->form_id ?: 'wpjam_form',
			'data'		=> $this->generate_data_attr([], 'form')
		];

		$args	= array_merge($this->args, ['data'=>$this->get_data()]);
		$form	= wpjam_fields($this->get_fields())->render($args, false)->wrap('form', $attr);
		$button	= $this->get_submit_button();

		return $button ? $form->append('p', ['submit'], $button) : $form;
	}

	protected function get_fields(){
		$fields	= $this->fields;
		$fields	= ($fields && is_callable($fields)) ? wpjam_try($fields, $this->name) : $fields;

		return $fields ?: [];
	}

	protected function get_submit_button($name=null, $render=null){
		$render	??= is_null($name);

		if(!is_null($this->submit_text)){
			$button	= $this->submit_text;
			$button	= ($button && is_callable($button)) ? wpjam_try($button, $this->name) : $button;
		}else{
			$button = wp_strip_all_tags($this->page_title);
		}

		return WPJAM_Admin::parse_submit_button($this, $button, $name, $render);
	}

	public function generate_data_attr($args=[], $type='button'){
		return array_merge([
			'action'	=> $this->name,
			'nonce'		=> WPJAM_Admin::create_nonce($this->name)
		], ($type == 'button' ? [
			'title'		=> $this->page_title ?: $this->button_text,
			'data'		=> wp_parse_args(($args['data'] ?? []), ($this->data ?: [])),
			'direct'	=> $this->direct,
			'confirm'	=> $this->confirm
		] : []));
	}

	public static function ajax_response($data){
		$object	= self::get($data['page_action']);

		if($object){
			return $object->callback($data['action_type']);
		}

		do_action_deprecated('wpjam_page_action', [$data['page_action'], $data['action_type']], 'WPJAM Basic 4.6');

		$callback	= wpjam_get_filter_name($GLOBALS['plugin_page'], 'ajax_response');

		if(is_callable($callback)){
			$result	= call_user_func($callback, $data['page_action']);
			$result	= (is_wp_error($result) || is_array($result)) ? $result : [];

			wpjam_send_json($result);
		}else{
			wp_die('invalid_callback');
		}
	}
}

class WPJAM_Plugin_Page extends WPJAM_Register{
	public function __get($key){
		if($key == 'tab_page'){
			return is_a($this, 'WPJAM_Tab_Page');
		}elseif($key == 'is_tab'){
			return $this->function == 'tab';
		}elseif($key == 'cb_args'){
			return [$GLOBALS['plugin_page'], ($this->tab_page ? $this->name : '')];
		}

		$value	= parent::__get($key);

		if($key == 'function'){
			if(!$value){
				return wpjam_get_filter_name($this->name, 'page');
			}elseif($value == 'list'){
				return 'list_table';
			}
		}

		return $value;
	}

	protected function load_callback($callable=true){
		// 一般 load_callback 优先于 load_file 执行
		// 如果 load_callback 不存在，尝试优先加载 load_file
		if($callable){
			WPJAM_Admin::load('plugin_page', ...$this->cb_args);
		}

		$included	= false;
		$callback	= $callable ? $this->load_callback : false;

		if($callback){
			if(!is_callable($callback)){
				$this->load_callback(false);

				$included	= true;
			}

			if(is_callable($callback)){
				call_user_func($callback, $this->name);
			}
		}

		if(!$included){
			$key	= ($this->tab_page ? 'tab' : 'page').'_file';
			$file	= (array)$this->$key ?: [];

			array_walk($file, fn($f) => include $f);
		}
	}

	public function load($screen=null){
		$name	= null;

		if(!$this->is_tab){
			$function	= $this->function;
			$model		= 'WPJAM_Admin_Page';

			if(is_string($function) && in_array($function, ['option', 'list_table', 'form', 'dashboard'])){
				$model	= 'WPJAM_'.ucwords($function, '_').'_Page';
				$name	= $this->{$function.'_name'} ?: $GLOBALS['plugin_page'];
			}

			$args	= wpjam_try([$model, 'preprocess'], $name, $this);
			$args	= ($args && is_array($args)) ? wpjam_parse_data_type($args) : [];

			if($args){
				$this->update_args($args);
			}

			WPJAM_Admin::parse_data_type($this, $screen);
		}

		$this->load_callback();
		$this->set_defaults();

		if($this->chart && !is_object($this->chart)){
			$this->chart	= wpjam_chart_form($this->chart);
		}

		if($this->editor){
			add_action('admin_footer', 'wp_enqueue_editor');
		}

		try{
			if($this->is_tab){
				$object	= WPJAM_Tab_Page::get_current($this);
				$object->load();
			}else{
				$object	= wpjam_try([$model, 'create'], $name, $this);

				add_action('load-'.$this->page_hook, [$object, 'load']);
			}

			if(wp_doing_ajax()){
				return $this->is_tab ? null : $object->load();
			}

			$this->render	= [$object, 'render'];

			if($name){
				$this->page_title	= $object->title ?: $this->page_title;
				$this->subtitle		= $object->get_subtitle() ?: $this->subtitle;
				$this->summary		= $this->summary ?: $object->get_summary();
				$this->query_data	= $this->query_data ?: [];
				$this->query_data	+= wpjam_get_data_parameter($object->query_args);
			}
		}catch(WPJAM_Exception $e){
			wpjam_add_admin_error($e->get_wp_error());
		}
	}

	public function render(){
		$title		= $this->page_title	?? $this->title;
		$summary	= $this->summary;
		$tag		= $this->tab_page ? wpjam_tag('h2', [], $title.$this->subtitle) : wpjam_tag('h1', ['wp-heading-inline'], $title)->after($this->subtitle)->after('hr', ['wp-header-end']);

		if($summary){
			if(is_callable($summary)){
				$summary	= call_user_func($summary, ...$this->cb_args);
			}elseif(is_array($summary)){
				$summary[1]	??= '';

				[$summary, $link]	= $summary;

				$summary	.= $link ? '，详细介绍请点击：'.wpjam_tag('a', ['href'=>$link, 'target'=>'_blank'], $this->menu_title) : '';
			}elseif(is_file($summary)){
				$summary	= wpjam_get_file_summary($summary);
			}
		}

		$summary	.= get_screen_option(($this->tab_page ? 'tab' : 'page').'_summary');

		if($summary){
			$tag->after($summary, 'p');
		}

		if($this->is_tab){
			$callback	= wpjam_get_filter_name($this->name, 'page');

			if(is_callable($callback)){
				$tag->after(wpjam_ob_get_contents($callback));	// 所有 Tab 页面都执行的函数
			}

			if(count($this->tabs) > 1){
				$tag->after(array_reduce($this->tabs, fn($nav, $tab) => $nav->append($tab->nav()), wpjam_tag('nav', ['nav-tab-wrapper', 'wp-clearfix'])));
			}
		}

		if($this->render){
			$tag->after(wpjam_ob_get_contents($this->render));
		}

		echo $this->tab_page ? $tag : $tag->wrap('div', ['wrap']);
	}

	public function set_defaults($defaults=[]){
		if($defaults){
			$this->defaults	= array_merge(($this->defaults ?: []), $defaults);
		}

		if($this->defaults){
			wpjam_set_current_var('defaults', $this->defaults);
		}
	}

	public function get_setting($key='', $using_tab=false){
		if(str_ends_with($key, '_name')){
			$using_tab	= $this->is_tab;
			$default	= $GLOBALS['plugin_page'];
		}else{
			$using_tab	= $using_tab ? $this->is_tab : false;
			$default	= null;
		}

		if($using_tab){
			$object	= WPJAM_Tab_Page::get_current();

			if(!$object){
				return null;
			}
		}else{
			$object	= $this;
		}

		return $key ? ($object->$key ?: $default) : $object->to_array();
	}

	public static function get_current(){
		return self::get($GLOBALS['plugin_page']);
	}

	public static function add($args, $name=null){
		$name	= $name ?: wpjam_pull($args, 'menu_slug');
		$object	= $name ? self::register($name, $args) : null;

		if($object){
			add_action('current_screen', [$object, 'load'], 9);
		}

		return $object;
	}
}

/**
* @config orderby=order model=0
**/
#[config(['orderby'=>'order', 'model'=>false])]
class WPJAM_Tab_Page extends WPJAM_Plugin_Page{
	public function nav(){
		$title	= $this->tab_title ?: $this->title;
		$class	= ['nav-tab', $GLOBALS['current_tab'] == $this->name ? 'nav-tab-active' : ''];

		return wpjam_tag('a', ['class'=>$class, 'href'=>$this->admin_url], $title);
	}

	public static function get_current($plugin_page=null){
		$plugin_page	??= WPJAM_Plugin_Page::get_current();
		$current_tab	= $GLOBALS['current_tab'] ?? '';

		if($current_tab){
			return $plugin_page->tabs[$current_tab] ?? null;
		}

		$page	= $plugin_page->name;
		$tabs	= $plugin_page->tabs ?: [];
		$tabs	= is_callable($tabs) ? call_user_func($tabs, $page) : $tabs;
		$tabs	= apply_filters(wpjam_get_filter_name($page, 'tabs'), $tabs);
		$result	= array_walk($tabs, [self::class, 'add']);
		$args	= wp_doing_ajax() ? ['current_tab', [], 'POST'] : ['tab'];
		$tab	= sanitize_key(wpjam_get_parameter(...$args));
		$tabs	= [];

		foreach(self::get_registereds() as $object){
			if(($object->plugin_page && $object->plugin_page != $page) || ($object->network === false && is_network_admin())){
				continue;
			}

			if($object->capability && !current_user_can($object->capability)){
				continue;
			}

			$name	= $object->name;
			$tab	= $tab ?: $name;

			$object->admin_url	= $plugin_page->admin_url.'&tab='.$name;

			$object	= WPJAM_Admin::parse_query_data($object, $tab == $name);

			if(!$object){
				continue;
			}

			$tabs[$name]	= $object;
		}

		$GLOBALS['current_tab']	= $tab;
		$plugin_page->tabs		= $tabs;

		if(!$tabs){
			throw new WPJAM_Exception('Tabs 未设置');
		}

		$object	= $tabs[$tab] ?? null;

		if(!$object){
			throw new WPJAM_Exception('无效的 Tab');
		}elseif(!$object->function){
			throw new WPJAM_Exception('Tab 未设置 function');
		}elseif(!$object->function == 'tab'){
			throw new WPJAM_Exception('Tab 不能嵌套 Tab');
		}

		$object->chart		??= $plugin_page->chart;
		$object->page_hook	= $plugin_page->page_hook;

		$GLOBALS['current_admin_url']	= $object->admin_url;

		return $object;
	}

	public static function add($args, $name=null){
		$name	= $name ?: wpjam_pull($args, 'tab_slug');

		if($name && !empty($args['title'])){
			$tab	= new self($name, $args);
			$page	= $args['plugin_page'] ?? '';
			$name	= wpjam_join(':', [$page, $name]);

			return self::register($name, $tab);
		}
	}
}

class WPJAM_Admin_Page extends WPJAM_Args{
	public function __call($method, $args){
		if($this->object && method_exists($this->object, $method)){
			return call_user_func_array([$this->object, $method], $args);
		}elseif($method == 'get_subtitle'){
			return $this->subtitle;
		}elseif($method == 'get_summary'){
			return $this->summary;
		}
	}

	public function __get($key){
		if(empty($this->args['object']) || in_array($key, ['object', 'tab_page', 'chart'])){
			return parent::__get($key);
		}

		return $this->object->$key;
	}

	public function render(){
		echo $this->chart ? $this->chart->render() : '';

		if(is_callable($this->function)){
			call_user_func($this->function);
		}
	}

	public static function preprocess($name, $menu){
		return [];
	}

	public static function create($name, $menu){
		if(!is_callable($menu->function)){
			return new WP_Error('invalid_menu_page', ['函数', $menu->function]);
		}

		return new self($menu->to_array());
	}
}

class WPJAM_Form_Page extends WPJAM_Admin_Page{
	public function render(){
		try{
			echo $this->get_form();
		}catch(WPJAM_Exception $e){
			wp_die($e->get_wp_error());
		}
	}

	public static function preprocess($name, $menu){
		$object	= WPJAM_Page_Action::get($name);

		if($object){
			return $object->to_array();
		}

		if($menu->form && is_callable($menu->form)){
			$menu->form	= call_user_func($menu->form, $name);
		}

		return $menu->form;
	}

	public static function create($name, $menu){
		$object	= WPJAM_Page_Action::get($name);

		if(!$object){
			$args	= self::preprocess($name, $menu);
			$args	= $args ?: ($menu->callback ? $menu->to_array() : []);

			if(!$args){
				return new WP_Error('invalid_menu_page', ['Page Action', $name]);
			}

			$object	= WPJAM_Page_Action::register($name, $args);
		}

		return new self(array_merge($menu->to_array(), ['object'=>$object]));
	}
}

class WPJAM_Option_Page extends WPJAM_Admin_Page{
	public function __get($key){
		$value	= parent::__get($key);

		return $key == 'object' ? $value->get_current() : $value;
	}

	public function load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-option-action',	[$this, 'ajax_response']);
		}
	}

	public function render(){
		echo $this->render_sections($this->tab_page);
	}

	public static function preprocess($name, $menu){
		$object	= WPJAM_Option_Setting::get($name);

		if($object){
			return $object->to_array();
		}

		if($menu->option && is_callable($menu->option)){
			$menu->option	= call_user_func($menu->option, $name);
		}

		return $menu->option;
	}

	public static function create($name, $menu){
		$object	= WPJAM_Option_Setting::get($name);

		if(!$object){
			if($menu->model && method_exists($menu->model, 'register_option')){	// 舍弃 ing
				$object	= call_user_func([$menu->model, 'register_option'], $menu->delete_arg('model')->to_array());
			}else{
				$args	= self::preprocess($name, $menu);
				$args	= $args ?: (($menu->sections || $menu->fields) ? $menu->to_array() : []);

				if(!$args){
					$args	= apply_filters(wpjam_get_filter_name($name, 'setting'), []); // 舍弃 ing

					if(!$args){
						return new WP_Error('invalid_menu_page', ['Option', $name]);
					}
				}

				$object	= WPJAM_Option_Setting::create($name, $args);
			}
		}

		return new self(array_merge($menu->to_array(), ['object'=>$object]));
	}
}

class WPJAM_List_Table_Page extends WPJAM_Admin_Page{
	public function load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-list-table-action',	[$this, 'ajax_response']);
		}elseif(wpjam_get_parameter('export_action')){
			$this->export_action();
		}else{
			$result = wpjam_catch([$this, 'prepare_items']);

			if(is_wp_error($result)){
				wpjam_add_admin_error($result);
			}
		}
	}

	public function render(){
		echo $this->chart ? $this->chart->render() : '';

		$views	= wpjam_ob_get_contents([$this, 'views']);
		$form	= wpjam_ob_get_contents([$this, 'display']);
		$form	= ($this->is_searchable() ? wpjam_ob_get_contents([$this, 'search_box'], '搜索', 'wpjam') : '').$form;
		$form	= wpjam_tag('form', ['action'=>'#', 'id'=>'list_table_form', 'method'=>'POST'], $form)->before($views);

		if($this->layout == 'left'){
			$form	= $form->wrap('div', ['list-table', 'col-wrap'])->wrap('div', ['id'=>'col-right']);
			$left	= wpjam_tag('div', ['left', 'col-wrap'], $this->get_col_left())->wrap('div', ['id'=>'col-left']);

			echo $form->before($left)->wrap('div', ['id'=>'col-container', 'class'=>'wp-clearfix']);
		}else{
			echo wpjam_tag('div', ['list-table', ($this->layout ? 'layout-'.$this->layout : '')], $form);
		}
	}

	public static function preprocess($name, $menu){
		$args	= wpjam_get_item('list_table', $name) ?: $menu->list_table;

		if($args){
			if(is_string($args) && class_exists($args) && method_exists($args, 'get_list_table')){
				$args	= [$args, 'get_list_table'];
			}

			if(is_callable($args)){
				$args	= call_user_func($args, $name);
			}

			return $menu->list_table = $args;
		}
	}

	public static function create($name, $menu){
		$args	= self::preprocess($name, $menu);

		if($args){
			if(isset($args['defaults'])){
				$menu->set_defaults($args['defaults']);
			}
		}else{
			if($menu->model){
				$args	= wpjam_except($menu->to_array(), 'defaults');
			}else{
				$args	= apply_filters(wpjam_get_filter_name($name, 'list_table'), []);
			}

			if(!$args){
				return new WP_Error('invalid_menu_page', ['List Table', $name]);
			}
		}

		if(empty($args['model']) || !class_exists($args['model'])){
			return new WP_Error('invalid_menu_page', ['List Table 的 Model', $args['model']]);
		}

		foreach(['admin_head', 'admin_footer'] as $admin_hook){
			if(method_exists($args['model'], $admin_hook)){
				add_action($admin_hook,	[$args['model'], $admin_hook]);
			}
		}

		$args	= wp_parse_args($args, ['primary_key'=>'id', 'name'=>$name, 'singular'=>$name, 'plural'=>$name.'s', 'layout'=>'']);

		if($args['layout'] == 'left' || $args['layout'] == '2'){
			$args['layout']	= 'left';

			$object	= new WPJAM_Left_List_Table($args);
		}elseif($args['layout'] == 'calendar'){
			$args['query_args']	??= [];
			$args['query_args']	= ['year', 'month', ...$args['query_args']];

			$object	= new WPJAM_Calendar_List_Table($args);
		}else{
			$object	= new WPJAM_List_Table($args);
		}

		return new self(array_merge($menu->to_array(), ['object'=>$object]));
	}
}

class WPJAM_Dashboard_Page extends WPJAM_Admin_Page{
	public function load(){
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		// wp_dashboard_setup();

		wp_enqueue_script('dashboard');

		if(wp_is_mobile()){
			wp_enqueue_script('jquery-touch-punch');
		}

		$this->widget();
	}

	public function widget(){
		$widgets	= $this->widgets ?: [];
		$widgets	= is_callable($widgets) ? call_user_func($widgets, $this->name) : $widgets;
		$widgets	= array_map(fn($widget) => array_merge($widget, ['dashboard'=>$this->name]), $widgets);
		$widgets	= array_merge($widgets, wpjam_get_items('dashboard_widget'));
		$widgets	= $this->name == 'dashboard' ? apply_filters('wpjam_dashboard_widgets', $widgets) : $widgets;

		foreach($widgets as $widget_id => $widget){
			$widget['dashboard']	??= 'dashboard';

			if($widget['dashboard'] == $this->name){
				$widget_id	= $widget['id'] ?? $widget_id;
				$title		= $widget['title'];
				$callback	= $widget['callback'] ?? wpjam_get_filter_name($widget_id, 'dashboard_widget_callback');
				$context	= $widget['context'] ?? 'normal';	// 位置，normal 左侧, side 右侧
				$priority	= $widget['priority'] ?? 'core';
				$args		= $widget['args'] ?? [];

				// 传递 screen_id 才能在中文的父菜单下，保证一致性。
				add_meta_box($widget_id, $title, $callback, get_current_screen(), $context, $priority, $args);
			}
		}
	}

	public function render(){
		$tag	= wpjam_tag('div', ['id'=>'dashboard-widgets-wrap'], wpjam_ob_get_contents('wp_dashboard'));

		if($this->welcome_panel && is_callable($this->welcome_panel)){
			$welcome_panel	= wpjam_ob_get_contents($this->welcome_panel, $this->name);

			$tag->before('div', ['id'=>'welcome-panel', 'class'=>'welcome-panel wpjam-welcome-panel'], $welcome_panel);
		}

		echo $tag;
	}

	public static function preprocess($name, $menu){
		return wpjam_get_item('dashboard', $name) ?: $menu->dashboard;
	}

	public static function create($name, $menu){
		$args	= self::preprocess($name, $menu);
		$args	= $args ?: ($menu->widgets ? $menu->to_array() : []);

		if(!$args){
			return new WP_Error('invalid_menu_page', ['Dashboard', $name]);
		}

		return new self(array_merge($args, ['name'=>$name]));
	}
}

class WPJAM_Builtin_Page{
	protected $screen;

	protected function __construct($screen){
		$this->screen	= $screen;
	}

	public function __get($key){
		$screen	= $this->screen;

		if(isset($screen->$key)){
			return $screen->$key;
		}

		$object	= $screen->get_option('object');

		return $object ? $object->$key : null;
	}

	public function __call($method, $args){
		$object	= $this->screen->get_option('object');

		if($object){
			return call_user_func_array([$object, $method], $args);
		}
	}

	public static function init($screen){
		$admin_url	= set_url_scheme('http://'.$_SERVER['HTTP_HOST'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

		if($GLOBALS['plugin_page']){
			$admin_url	= add_query_arg(['page' => $GLOBALS['plugin_page']], $admin_url);
		}else{
			foreach(['taxonomy', 'post_type'] as $key){
				if($screen->$key && isset($_REQUEST[$key])){
					$args[$key]	= $_REQUEST[$key];
				}
			}

			if(!empty($args)){
				$admin_url	= add_query_arg($args, $admin_url);
			}
		}

		$GLOBALS['current_admin_url']	= $admin_url;

		if(in_array($screen->base, ['edit', 'upload', 'post', 'term', 'edit-tags'])){
			if(in_array($screen->base, ['edit', 'upload', 'post'])){
				$object	= wpjam_get_post_type_object($screen->post_type);
			}elseif(in_array($screen->base, ['term', 'edit-tags'])){
				$object	= wpjam_get_taxonomy_object($screen->taxonomy);
			}

			if(!$object){
				return;
			}

			$screen->add_option('object', $object);
		}

		WPJAM_Admin::load('builtin_page', $screen);

		foreach([
			['model'=>'WPJAM_Post_Builtin_Page',	'base'=>'post'],
			['model'=>'WPJAM_Posts_List_Table',		'base'=>['edit', 'upload']],
			['model'=>'WPJAM_Users_List_Table',		'base'=>'users'],
			['model'=>'WPJAM_Term_Builtin_Page',	'base'=>['term', 'edit-tags']],
			['model'=>'WPJAM_Terms_List_Table',		'base'=>'edit-tags'],
		] as $load){
			if(in_array($screen->base, (array)$load['base'])){
				call_user_func([$load['model'], 'load'], $screen);
			}
		}
	}

	public static function load($screen){
		return new static($screen);
	}
}

class WPJAM_Post_Builtin_Page extends WPJAM_Builtin_Page{
	protected function __construct($screen){
		parent::__construct($screen);

		$fragment	= parse_url(wp_get_referer(), PHP_URL_FRAGMENT);
		$typenow	= $GLOBALS['typenow'];
		$label		= $this->labels->name;
		$hook		= $typenow == 'page' ? 'edit_page_form' : 'edit_form_advanced';

		if(!in_array($typenow, ['post', 'page', 'attachment'])){
			add_filter('post_updated_messages',	fn($ms) => $ms+[$typenow => array_map(fn($m) => str_replace('文章', $label, $m), $ms['post'])]);
		}

		if($fragment){
			add_filter('redirect_post_location', fn($location) => $location.(parse_url($location, PHP_URL_FRAGMENT) ? '' : '#'.$fragment));
		}

		if($this->thumbnail_size){
			add_filter('admin_post_thumbnail_html', fn($content) => $content.wpautop('尺寸：'.$this->thumbnail_size));
		}

		add_action($hook,					[$this, 'on_edit_form'], 99);
		add_action('add_meta_boxes',		[$this, 'on_add_meta_boxes']);
		add_action('wp_after_insert_post',	[$this, 'on_after_insert_post'], 999, 2);
	}

	public function on_edit_form($post){	// 下面代码 copy 自 do_meta_boxes
		$meta_boxes		= $GLOBALS['wp_meta_boxes'][$this->id]['wpjam'] ?? [];
		$tab_title		= wpjam_tag('ul');
		$tab_content	= wpjam_tag('div', ['inside']);
		$tab_count		= 0;

		foreach(wp_array_slice_assoc($meta_boxes, ['high', 'core', 'default', 'low']) as $_meta_boxes){
			foreach((array)$_meta_boxes as $meta_box){
				if(empty($meta_box['id']) || empty($meta_box['title'])){
					continue;
				}

				$tab_count++;

				$meta_id	= 'tab_'.$meta_box['id'];

				$tab_title->append('li', [], wpjam_tag('a', ['class'=>'nav-tab', 'href'=>'#'.$meta_id], $meta_box['title']));
				$tab_content->append('div', ['id'=>$meta_id], wpjam_ob_get_contents($meta_box['callback'], $post, $meta_box));
			}
		}

		if(!$tab_count){
			return;
		}

		if($tab_count == 1){
			$tab_title	= wpjam_tag('h2', ['hndle'], strip_tags($tab_title))->wrap('div', ['postbox-header']);
		}else{
			$tab_title->wrap('h2', ['nav-tab-wrapper']);
		}

		echo $tab_title->after($tab_content)->wrap('div', ['id'=>'wpjam', 'class'=>['postbox','tabs']])->wrap('div', ['id'=>'wpjam-sortables']);
	}

	public function meta_box_cb($post, $meta_box){
		$object	= $meta_box['args'][0];
		$id		= $GLOBALS['current_screen']->action == 'add' ? false : $post->ID;
		$type	= $object->context == 'side' ? 'list' : 'table';

		echo $object->summary ? wpautop($object->summary) : '';

		$object->render($id, ['fields_type'=>$type]);
	}

	public function on_add_meta_boxes($post_type){
		$context	= use_block_editor_for_post_type($post_type) ? 'normal' : 'wpjam';

		foreach(wpjam_get_post_options($post_type, ['list_table'=>false]) as $object){
			$context	= $object->context ?: $context;
			$callback	= $object->meta_box_cb ?: [$this, 'meta_box_cb'];

			add_meta_box($object->name, $object->title, $callback, $post_type, $context, $object->priority, [$object]);
		}
	}

	public function on_after_insert_post($post_id, $post){
		// 只有 POST 方法提交才处理，自动草稿、自动保存和预览情况下不处理
		if($_SERVER['REQUEST_METHOD'] != 'POST'
			|| $post->post_status == 'auto-draft'
			|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
			|| (!empty($_POST['wp-preview']) && $_POST['wp-preview'] == 'dopreview')
		){
			return;
		}

		foreach(wpjam_get_post_options($this->post_type, ['list_table'=>false]) as $object){
			$result	= $object->callback($post_id);

			wpjam_die_if_error($result);
		}
	}
}

class WPJAM_Term_Builtin_Page extends WPJAM_Builtin_Page{
	protected function __construct($screen){
		parent::__construct($screen);

		$taxnow	= $GLOBALS['taxnow'];
		$label	= $this->labels->name;

		if(!in_array($taxnow, ['post_tag', 'category'])){
			add_filter('term_updated_messages',	fn($ms) => $ms+[$taxnow => array_map(fn($m) => str_replace(['项目', 'Item'], [$label, ucfirst($label)], $m), $ms['_item'])]);
		}

		if($this->base == 'edit-tags'){
			if(wp_doing_ajax()){
				if($_POST['action'] == 'add-tag'){
					add_filter('pre_insert_term',	[$this, 'filter_pre_insert'], 10, 2);
					add_action('created_term',		[$this, 'on_created'], 10, 3);
				}
			}else{
				add_action('edited_term',	[$this, 'on_edited'], 10, 3);
			}

			add_action($taxnow.'_add_form_fields', fn($taxonomy) => $this->get_form_fields('add', ['fields_type'=>'div', 'wrap_class'=>'form-field', 'id'=>false]));
		}else{
			add_action($taxnow.'_edit_form_fields', fn($term) => $this->get_form_fields('edit', ['fields_type'=>'tr', 'wrap_class'=>'form-field', 'id'=>$term->term_id]));
		}
	}

	public function get_form_fields($action, $args){
		foreach(wpjam_get_term_options($this->taxonomy, ['action'=>$action, 'list_table'=>false]) as $object){
			$object->render($args['id'], wp_parse_args($args, $object->to_array()));
		}
	}

	protected function update_data($action, $term_id=null){
		foreach(wpjam_get_term_options($this->taxonomy, ['action'=>$action, 'list_table'=>false]) as $object){
			$result	= $term_id ? $object->callback($term_id) : $object->validate();

			wpjam_die_if_error($result);
		}

		return true;
	}

	public function on_created($term_id, $tt_id, $taxonomy){
		if($taxonomy == $this->taxonomy){
			$this->update_data('add', $term_id);
		}
	}

	public function on_edited($term_id, $tt_id, $taxonomy){
		if($taxonomy == $this->taxonomy){
			$list_table	= wpjam_get_builtin_list_table('WP_Terms_List_Table');

			if($list_table->current_action() == 'editedtag'){
				$this->update_data('edit', $term_id);
			}
		}
	}

	public function filter_pre_insert($term, $taxonomy){
		if($taxonomy == $this->taxonomy){
			$this->update_data('add');
		}

		return $term;
	}
}

class WPJAM_Chart extends WPJAM_Args{
	public function line($args=[], $type='Line'){
		$this->update_args(wp_parse_args($args, [
			'data'			=> [],
			'labels'		=> [],
			'day_key'		=> 'day',
			'day_label'		=> '时间',
			'day_labels'	=> [],
			'show_table'	=> true,
			'show_chart'	=> true,
			'show_sum'		=> true,
			'show_avg'		=> true,
		]));

		$chart_id	= $this->chart_id ?: 'daily-chart';
		$show_table	= $this->show_table;
		$keys		= $labels = [];

		foreach($this->labels as $key => $label){
			if(strpos($label,'%') === false && strpos($label,'#') === false){ // %,# 数据不写入 Chart
				$keys[]		= $key;
				$labels[]	= $label;
			}
		}

		$data	= $total = [];

		if($show_table){
			$thead	= wpjam_tag('thead')->append($this->day_row('head'));
			$tbody	= wpjam_tag('tbody');
		}

		foreach($this->data as $day => $counts){
			$counts	= (array)$counts;
			$key	= $this->day_key;
			$day	= $counts[$key] ?? $day;

			if(strpos($day, '%') === false && strpos($day, '#') === false){
				$label	= $this->day_labels[$day] ?? $day;
				$item 	= [$key => $label];

				foreach($keys as $key){
					$count		= $counts[$key] ?? 0;
					$item[$key]	= $count;

					if(is_numeric($count)){
						$total[$key]	= $total[$key] ?? 0;
						$total[$key]	+= $count;
					}
				}

				$data[]	= $item;
			}

			if($show_table){
				$tbody->append($this->day_row($day, (array)$counts, true));
			}
		}

		$tag	= wpjam_tag();

		if($this->show_chart){
			$options	= ['xkey'=>$this->day_key, 'ykeys'=>$keys, 'data'=>$data, 'labels'=>$labels];

			$tag->append('div', ['class'=>'chart', 'id'=>$chart_id, 'data'=>['type'=>$type, 'options'=>$options]]);
		}

		if($show_table && $this->data){
			foreach(['sum', 'avg'] as $key){
				if($this->{'show_'.$key}){
					$this->day_row($key, $total)->append_to($tbody);
				}
			}

			$thead->after($tbody)->wrap('table', ['class'=>'wp-list-table widefat striped'])->append_to($tag);
		}

		echo $tag;
	}

	public function bar($args=[]){

	}

	public function donut($args=[]){
		$this->update_args(wp_parse_args($args, [
			'data'			=> [],
			'total'			=> 0,
			'title'			=> '名称',
			'key'			=> 'type',
			'total_link'	=> $GLOBALS['current_admin_url'],
			'show_table'	=> true,
			'show_chart'	=> true,
			'show_line_num'	=> false,
			'show_link'		=> false,
			'labels'		=> []
		]));

		$show_table	= $this->show_table;
		$chart_id	= $this->chart_id ?: 'chart_'.wp_generate_password(6, false, false);
		$total		= 0;
		$summary	= [];

		if($show_table){
			$thead	= wpjam_tag('thead')->append($this->summary_row('head'));
			$tbody	= wpjam_tag('tbody');
		}

		foreach(array_values($this->data) as $i => $count){
			$count	= (array)$count;
			$label 	= $count['label'] ?? '/';
			$link	= $count['link'] ?? '';

			if($this->show_link){
				$value	= $count[$this->key] ?? $label;
				$link	= $this->total_link.'&'.$this->key.'='.$value;
			}else{
				$link	= '';
			}

			$label 		= $this->labels[$label] ?? $label;
			$count		= $count['count'];
			$summary[]	= ['label'=>$label, 'value'=>$count];

			if($show_table){
				$total	+= $count;

				$this->summary_row($i+1, $count, $label, $link)->append_to($tbody);
			}
		}

		$tag	= wpjam_tag();

		if($this->show_chart){
			$tag->append('div', ['class'=>'chart', 'id'=>$chart_id, 'data'=>['options'=>['data'=>$summary], 'type'=>'Donut']]);
		}

		if($show_table){
			if($this->total){
				$this->summary_row('total', $total)->append_to($tbody);
			}

			$thead->after($tbody)->wrap('table', ['wp-list-table', 'widefat', 'striped'])->append_to($tag);
		}

		echo $tag->wrap('div', ['class'=>'donut-chart-wrap']);
	}

	protected function day_row($day, $counts=[], $day_row=false){
		if($day_row){
			$type	= '';
			$label	= $this->day_labels[$day] ?? $day;
		}else{
			$type	= $day;

			if($type == 'sum'){
				$label	= '累加';
			}elseif($type == 'avg'){
				$label	= '平均';
				$number	= count($this->data);
			}
		}

		$row	= wpjam_tag('tr');

		if($type == 'head'){
			$row->append($this->day_label, 'th', ['scope'=>'col', 'id'=>$this->day_key, 'class'=>['column-'.$this->day_key, 'column-primary']]);
		}else{
			$toggle	= wpjam_tag('button', ['type'=>'button', 'class'=>'toggle-row'], ['显示详情', 'span', ['screen-reader-text']]);

			$row->append($label.$toggle, 'td', ['class'=>['column-'.$this->day_key, 'column-primary'],  'data-colname'=>$this->day_label]);
		}

		foreach($this->labels as $key => $label){
			if($type == 'head'){
				$row->append($label, 'th', ['scope'=>'col',	'id'=>$key,	'class'=>['column-'.$this->day_key]]);
			}else{
				$count	= $counts[$key] ?? 0;

				if($type == 'avg'){
					$count	= $count ? round($count/$number) : '';
				}

				$row->append($count, 'td', ['class'=>['column-'.$key], 'data-colname'=>$label]);
			}
		}

		return $row;
	}

	protected function summary_row($i='total', $count=0, $label='', $link=''){
		if(is_numeric($i)){
			if($this->total){
				$rate	= round($count/$this->total*100, 2);
			}
		}elseif($i == 'total'){
			$label	= '所有';
			$link	= $this->show_link ? $this->total_link : '';
			$rate	= 100;
		}

		$row	= wpjam_tag('tr');

		if($this->show_line_num){
			if($i === 'head'){
				$row->append('排名', 'th', ['style'=>'width:40px;']);
			}else{
				$row->append($i, 'td');
			}
		}

		if($i === 'head'){
			$row->append($this->title, 'th')->append('数量', 'th');
		}else{
			$row->append(($link ? '<a href="'.$link.'">'.$label.'</a>' : $label), 'td')->append($count, 'td');
		}

		if($this->total){
			if($i === 'head'){
				$row->append('比例', 'th');
			}else{
				$row->append($rate.'%', 'td');
			}
		}

		return $row;
	}

	public static function form(){
		echo wpjam_chart_form()->render();
	}

	public static function init($args=[]){
		wpjam_chart_form($args);
	}
}

class WPJAM_Chart_Form extends WPJAM_Args{
	public function get_parameter($key){
		if(str_contains($key, 'timestamp')){
			$date_key	= str_replace('timestamp', 'date', $key);
			$postfix	= str_starts_with($key, 'end_') ? '23:59:59' : '00:00:00';
			$value		= $this->get_parameter($date_key).' '.$postfix;

			return wpjam_strtotime($value);
		}elseif($key == 'date_format'){
			$date_type	= $this->get_parameter('date_type') ?: '按天';

			return $this->get_date_format($date_type);
		}

		$value	= wpjam_get_post_parameter($key);

		if($value){
			wpjam_set_cookie($key, $value, HOUR_IN_SECONDS);
		}else{
			$value	= $_COOKIE[$key] ?? null;

			if(!$value){
				if($key == 'start_date'){
					$value	= wpjam_date('Y-m-d', time() - DAY_IN_SECONDS*30);
				}elseif($key == 'end_date'){
					$value	= wpjam_date('Y-m-d', time());
				}elseif($key == 'date'){
					$value	= wpjam_date('Y-m-d', time() - DAY_IN_SECONDS);
				}elseif($key == 'start_date_2'){
					$start	= $this->get_parameter('start_timestamp');
					$diff	= $this->get_parameter('end_timestamp') - $start;
					$value	= wpjam_date('Y-m-d', $start - DAY_IN_SECONDS - $diff);
				}elseif($key == 'end_date_2'){
					$start	= $this->get_parameter('start_timestamp');
					$value	= wpjam_date('Y-m-d', $start - DAY_IN_SECONDS);
				}elseif($key == 'date_type'){
					$value	= '按天';
				}elseif($key == 'compare'){
					$value	= 0;
				}
			}
		}

		if($key == 'date_type'){
			$value	= $value == '显示' ? '按天' : $value;
		}

		return $value;
	}

	protected function get_date_format($name=null){
		$formats	= [
			'按分钟'	=> '%Y-%m-%d %H:%i',
			'按小时'	=> '%Y-%m-%d %H:00',
			'按天'	=> '%Y-%m-%d',
			'按周'	=> '%Y%U',
			'按月'	=> '%Y-%m'
		];

		return $name ? array_get($formats, $name) : $formats;
	}

	public function render(){
		if(!$this->show_form){
			return;
		}

		$current	= wpjam_get_parameter('type', ['default'=>-1]);
		$current	= $current == 'all' ? '-1' : $current;

		if($this->show_start_date){
			$fields['date_view']	= ['type'=>'view',	'value'=>'日期：'];
			$fields['start_date']	= ['type'=>'date',	'value'=>$this->get_parameter('start_date')];
			$fields['sep_view']		= ['type'=>'view',	'value'=>'-'];
			$fields['end_date']		= ['type'=>'date',	'value'=>$this->get_parameter('end_date')];
		}else{
			$fields['date']			= ['type'=>'date',	'value'=>$this->get_parameter('date')];
		}

		if($this->show_date_type){
			foreach($this->get_date_format() as $date_type => $date_format){
				$class	= $this->get_parameter('date_type') == $date_type ? 'button button-primary' : 'button';

				$fields['date_type_'.$date_type]	= ['type'=>'submit',	'name'=>'date_type',	'value'=>$date_type,	'class'=>$class];
			}
		}else{
			$fields['date_type']	= ['type'=>'submit', 'name'=>'date_type', 'value'=>'显示', 'class'=>'button button-secondary'];
		}

		if($current !=-1 && $this->show_start_date && $this->show_compare){
			$fields['date_view_2']	= ['type'=>'view',		'value'=>'对比： '];
			$fields['start_date_2']	= ['type'=>'date',		'value'=>$this->get_parameter('start_date_2')];
			$fields['sep_view']		= ['type'=>'view',		'value'=>'-'];
			$fields['end_date_2']	= ['type'=>'date',		'value'=>$this->get_parameter('end_date_2')];
			$fields['compare']		= ['type'=>'checkbox',	'value'=>$this->get_parameter('compare')];
		}

		$fields	= apply_filters('wpjam_chart_fields', $fields);
		$action	= $GLOBALS['current_admin_url'];
		$action	= $current == -1 ? $action : $action.'&type='.$current;

		return wpjam_fields($fields)->render(['fields_type'=>''])
			->wrap('form', ['method'=>'POST', 'action'=>$action, 'target'=>'_self', 'class'=>'chart-form'])
			->after('div', ['clear']);
	}

	public static function get_instance(){
		static $object;

		if(!isset($object)){
			$object	= new WPJAM_Chart_Form();

			$offset	= (int)get_option('gmt_offset');
			$offset	= $offset >= 0 ? '+'.$offset.':00' : $offset.':00';

			$GLOBALS['wpdb']->query("SET time_zone = '{$offset}';");

			wp_enqueue_style('morris',		'https://cdn.staticfile.org/morris.js/0.5.1/morris.css');
			wp_enqueue_script('raphael',	'https://cdn.staticfile.org/raphael/2.3.0/raphael.min.js');
			wp_enqueue_script('morris',		'https://cdn.staticfile.org/morris.js/0.5.1/morris.min.js', ['raphael']);
		}

		return $object;
	}
}
