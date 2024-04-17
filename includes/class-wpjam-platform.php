<?php
/**
* @config orderby=order order=ASC
**/
#[config(['orderby'=>'order', 'order'=>'ASC'])]
class WPJAM_Platform extends WPJAM_Register{
	public function __get($key){
		if($key == 'path'){
			return (bool)$this->get_items();
		}

		return parent::__get($key);
	}

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	public function verify(){
		return call_user_func($this->verify);
	}

	public function get_item($page_key, $field=null){	// delete 2024-06-01
		if(is_array($page_key)){
			trigger_error('page_key is array');

			return $page_key;
		}

		return parent::get_item($page_key, $field);
	}

	public function get_tabbar($page_key){
		$item	= $this->get_item($page_key);

		if($item && !empty($item['tabbar'])){
			$tabbar	= $item['tabbar'] === true ? [] : $item['tabbar'];

			return $tabbar+['text'=>$item['title'] ?? ''];
		}
	}

	public function get_page($page_key){
		$path	= $this->get_item_arg($page_key, 'path');

		return $path ? explode('?', $path)[0] : '';
	}

	protected function get_data_type($page_key){
		return wpjam_get_data_type_object($this->get_item_arg($page_key, 'page_type'));
	}

	public function get_fields($page_key){
		$item	= $this->get_item($page_key);
		$fields	= $item['fields'] ?? '';

		if($fields){
			if(is_callable($fields)){
				$fields	= call_user_func($fields, $item, $page_key);
			}
		}else{
			$object	= $this->get_data_type($page_key);
			$fields	= $object ? $object->get_fields($item) : [];
		}

		return $fields ?: [];
	}

	public function has_path($page_key, $strict=false){
		$item	= $this->get_item($page_key);

		if(!$item || ($strict && isset($item['path']) && $item['path'] === false)){
			return false;
		}

		return isset($item['path']) || isset($item['callback']);
	}

	public function get_path($page_key, $args=[]){
		$item		= $this->get_item($page_key);
		$callback	= wpjam_pull($item, 'callback');
		$args		= is_array($args) ? wp_parse_args(wpjam_filter($args, 'isset', false), $item) : $args;

		if($callback){
			if(is_callable($callback) && is_array($args)){
				return call_user_func($callback, $args, $page_key) ?: '';
			}
		}else{
			$object	= $this->get_data_type($page_key);

			if($object){
				return $object->get_path($args, $item);
			}
		}

		if(isset($item['path'])){
			return (string)$item['path'];
		}
	}

	public function get_paths($page_key, $query_args=[]){
		$paths	= [];
		$object	= $this->get_data_type($page_key);

		if($object){
			$args	= $this->get_item($page_key);
			$name	= $object->name;

			if(!empty($args[$name])){
				$query_args[$name]	= $args[$name];
			}

			$items	= $object->query_items($query_args, false) ?: [];
			$paths	= array_map(fn($item) => $this->get_path($page_key, $item['value']), $items);
			$paths	= array_filter($paths, fn($path) => $path && !is_wp_error($path));
		}

		return $paths;
	}

	public function parse_path($args, $postfix=''){
		$page_key	= wpjam_pull($args, 'page_key'.$postfix);

		if($page_key == 'none'){
			if(!empty($args['video'])){
				return [
					'type'	=> 'video',
					'video'	=> $args['video'],
					'vide'	=> wpjam_get_qqv_id($args['video'])
				];
			}

			return ['type'=>'none'];
		}elseif($page_key){
			if(!$this->get_item($page_key)){
				return [];
			}

			$args	= $postfix ? wpjam_map($this->get_fields($page_key), fn($v, $k) => $args[$k.$postfix] ?? null) : $args;
			$path	= $this->get_path($page_key, $args);

			if(is_wp_error($path)){
				return $path;
			}elseif(is_array($path)){
				return $path;
			}elseif(isset($path)){
				return ['type'=>'', 'page_key'=>$page_key, 'path'=>$path];
			}
		}

		return [];
	}

	public function registered(){
		if($this->name == 'template'){
			wpjam_register_path('home',		'template',	['title'=>'首页',		'path'=>home_url(),	'group'=>'tabbar']);
			wpjam_register_path('category',	'template',	['title'=>'分类页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('post_tag',	'template',	['title'=>'标签页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('author',	'template',	['title'=>'作者页',		'path'=>'',	'page_type'=>'author']);
			wpjam_register_path('post',		'template',	['title'=>'文章详情页',	'path'=>'',	'page_type'=>'post_type']);
			wpjam_register_path('external', 'template',	['title'=>'外部链接',		'path'=>'',	'fields'=>['url'=>['type'=>'url', 'required'=>true, 'placeholder'=>'请输入链接地址。']],	'callback'=>fn($args) => ['type'=>'external', 'url'=>$args['url']]]);
		}
	}

	public static function get_options($output=''){
		return wp_list_pluck(self::get_registereds(), 'title', $output);
	}

	public static function get_current($args=[], $output='object'){
		if($output == 'bit' && wp_is_numeric_array($args)){
			$bits	= wp_list_pluck(self::get_by(['path'=>true]), 'bit');
			$args	= array_values(wp_array_slice_assoc(array_flip($bits), $args));
		}

		$args	= $args ?: ['path'=>true];
		$object	= wpjam_find(self::get_by($args), fn($object) => $object && $object->verify());

		if($object){
			if($output == 'bit'){
				return $object->bit;
			}elseif($output == 'object'){
				return $object;
			}else{
				return $object->name;
			}
		}
	}

	protected static function get_defaults(){
		return [
			'weapp'		=> ['bit'=>1,	'order'=>4,		'title'=>'小程序',	'verify'=>'is_weapp'],
			'weixin'	=> ['bit'=>2,	'order'=>4,		'title'=>'微信网页',	'verify'=>'is_weixin'],
			'mobile'	=> ['bit'=>4,	'order'=>8,		'title'=>'移动网页',	'verify'=>'wp_is_mobile'],
			'template'	=> ['bit'=>8,	'order'=>10,	'title'=>'网页',		'verify'=>'__return_true']
		];
	}
}

class WPJAM_Platforms{
	use WPJAM_Instance_Trait;

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	private $platforms	= [];
	private $cache		= [];

	protected function __construct($platforms){
		$this->platforms	= $platforms;
	}

	protected function has_path($page_key, $operator='AND', $strict=false){
		$fn	= fn($pf) => $pf->has_path($page_key, $strict);

		if($operator == 'AND'){
			return wpjam_every($this->platforms, $fn);
		}elseif($operator == 'OR'){
			return wpjam_some($this->platforms, $fn);
		}
	}

	protected function add_fields(&$fields, $path, $postfix){
		$group		= $path->group ?: ($path->tabbar ? 'tabbar' : 'others');
		$options	= &$fields['page_key'.$postfix]['options'][$group]['options'];

		$options[$path->name]	= $path->title;

		if($group == 'tabbar' && isset($options['none'])){
			$options['none']	= wpjam_pull($options, 'none');
		}

		foreach($path->get_fields($this->platforms) as $key => $field){
			$key	= $key.$postfix;

			if(isset($field['show_if'])){
				$fields[$key]	= $field;
			}else{
				if(isset($fields[$key])){
					$fields[$key]['show_if']['value'][]	= $path->name;
				}else{
					$fields[$key]	= array_merge($field, [
						'title'		=> '',
						'show_if'	=> ['key'=>'page_key'.$postfix, 'compare'=>'IN', 'value'=>[$path->name]]
					]);
				}
			}
		}
	}

	public function get_fields($args, $strict=false){
		$prepend	= wpjam_pull($args, 'prepend_name');
		$postfix	= wpjam_pull($args, 'postfix');
		$title		= wpjam_pull($args, 'title') ?: '页面';
		$backup		= (count($this->platforms) > 1 && !$strict);
		$key		= 'page_key'.$postfix;
		$paths		= WPJAM_Path::get_by($args);
		$cache_key	= md5(serialize(['postfix'=>$postfix, 'strict'=>$strict, 'page_keys'=>array_keys($paths)]));
		$cache		= $this->cache[$cache_key] ?? null;

		if(!$cache){
			$groups				= WPJAM_Path::get_groups($strict);
			$cache['fields']	= [$key=>['options'=>$groups]];

			if($backup){
				$cache['show_if']	= ['key'=>$key, 'compare'=>'IN', 'value'=>[]];
				$cache['backup']	= [$key.'_backup'=>['options'=>$groups]];
			}

			foreach($paths as $path){
				if($this->has_path($path->name, 'OR', $strict)){
					$this->add_fields($cache['fields'], $path, $postfix);

					if($backup){
						if($this->has_path($path->name, 'AND')){
							$this->add_fields($cache['backup'], $path, $postfix.'_backup');
						}else{
							$cache['show_if']['value'][]	= $path->name;
						}
					}
				}
			}

			$this->cache[$cache_key] = $cache;
		}

		$fields	= [$key.'_set'=>['type'=>'fieldset', 'title'=>$title, 'fields'=>$cache['fields'], 'prepend_name'=>$prepend]];

		if($backup){
			$fields	+= [$key.'_backup_set'=>['type'=>'fieldset', 'title'=>'备用'.$title, 'fields'=>$cache['backup'], 'prepend_name'=>$prepend, 'show_if'=>$cache['show_if']]];
		}

		return $fields;
	}

	public function get_current($output='object'){
		if(count($this->platforms) == 1){
			return $output == 'object' ? reset($this->platforms) : reset($this->platforms)->name;
		}else{
			return WPJAM_Platform::get_current(array_keys($this->platforms), $output);
		}
	}

	public function parse_item($item, $postfix=''){
		$platform	= $this->get_current();
		$parsed		= $platform->parse_path($item, $postfix);

		if((!$parsed || is_wp_error($parsed)) && count($this->platforms) > 1){
			$parsed	= $platform->parse_path($item, $postfix.'_backup');
		}

		return ($parsed && !is_wp_error($parsed)) ? $parsed : ['type'=>'none'];
	}

	public function validate_item($item, $postfix='', $title=''){
		foreach($this->platforms as $platform){
			$result	= $platform->parse_path($item, $postfix);

			if(is_wp_error($result) || $result){
				if(!$result && count($this->platforms) > 1 && !str_ends_with($postfix, '_backup')){
					return $this->validate_item($item, $postfix.'_backup', '备用'.$title);
				}

				return $result ?: new WP_Error('invalid_page_key', [$title]);
			}
		}

		return $result;
	}

	public static function get_instance($platforms=null){
		$args		= is_null($platforms) ? ['path'=>true] : (array)$platforms;
		$objects	= array_filter(WPJAM_Platform::get_by($args));

		if($objects){
			$key	= implode('-', array_keys($objects));
			$object	= self::instance_exists($key);

			return $object ?: self::add_instance($key, new self($objects));
		}
	}
}

class WPJAM_Path extends WPJAM_Register{
	public function __get($key){
		if(in_array($key, ['platform', 'path_type'])){
			return array_keys($this->get_items());
		}

		return parent::__get($key);
	}

	public function get_fields($platforms){
		return array_reduce($platforms, fn($fields, $pf) => array_merge($fields, $pf->get_fields($this->name)), []);
	}

	public static function get_groups($strict=false){
		$groups	= array_merge(
			['tabbar'=>['title'=>'菜单栏/常用', 'options'=>[]]],
			wpjam_get_items('path_group'),
			['others'=>['title'=>'其他页面', 'options'=>[]]]
		);

		if(!$strict){
			$groups['tabbar']['options']['none']	= '只展示不跳转';
		}

		return $groups;
	}

	public static function create($name, ...$args){
		$object	= self::get($name);
		$object	= $object ?: self::register($name, []);
		$args	= count($args) == 2 ? $args[1]+['platform'=>$args[0]] : $args[0];
		$args	= wp_is_numeric_array($args) ? $args : [$args];

		foreach($args as $_args){
			$pfs	= wp_array_slice_assoc($_args, ['platform', 'path_type']);
			$pfs	= array_merge(...array_map('wpjam_array', array_values($pfs)));

			foreach($pfs as $pf){
				$platform	= WPJAM_Platform::get($pf);

				if($platform){
					$page_type	= array_get($_args, 'page_type');

					if($page_type && in_array($page_type, ['post_type', 'taxonomy']) && empty($_args[$page_type])){
						$_args[$page_type]	= $name;
					}

					if(isset($_args['group']) && is_array($_args['group'])){
						$group	= wpjam_pull($_args, 'group');

						if(isset($group['key'], $group['title'])){
							wpjam_add_item('path_group', $group['key'], ['title'=>$group['title'], 'options'=>[]]);

							$_args['group']	= $group['key'];
						}
					}

					$_args	= array_merge($_args, ['platform'=>$pf, 'path_type'=>$pf]);

					$object->update_args($_args, false)->add_item($pf, $_args);

					$platform->add_item($name, $_args);
				}
			}
		}

		return $object;
	}

	public static function remove($name, $pf=''){
		if($pf){
			$object		= self::get($name);
			$platform	= WPJAM_Platform::get($pf);

			if($object){
				$object->delete_item($pf);
			}

			if($platform){
				$platform->delete_item($name);
			}
		}else{
			self::unregister($name);

			array_walk(WPJAM_Platform::get_registereds(), fn($pf) => $pf->delete_item($name));
		}
	}
}

class WPJAM_Data_Type extends WPJAM_Register{
	public function __call($method, $args){
		if($method == 'prepare_value'){
			$value	= array_shift($args);
			$parse	= array_shift($args);

			return $parse ? $this->parse_value($value, ...$args) : $value;
		}elseif($method == 'parse_query_args'){
			$args		= array_shift($args);
			$query_args	= $args['query_args'] ?? [];
			$query_args	= $query_args ? wp_parse_args($query_args) : [];

			if(!empty($args[$this->name])){
				$query_args[$this->name]	= $args[$this->name];
			}

			return $this->filter_query_args($query_args, $args);
		}elseif($method == 'get_meta_type'){
			if($this->meta_type){
				return $this->meta_type;
			}
		}elseif($method == 'query_items'){
			$args[0]	= wpjam_filter($args[0], 'isset', false);
			$wp_error	= $args[1] ?? true;
		}

		if($this->parse_method($method)){
			$result	= $this->call_method($method, ...$args);

			if($method == 'query_items'){
				if(is_wp_error($result)){
					return $wp_error ? $result : [];
				}

				return array_values(array_map(fn($item) => $this->parse_item($item, $args[0]), $result));
			}

			return $result;
		}

		if($method == 'query_items'){
			return $wp_error ? new WP_Error('undefined_method', ['query_items', '回调函数']) : [];
		}elseif(in_array($method, ['get_field', 'get_fields'])){
			return [];
		}elseif(str_ends_with($method, '_value') || in_array($method, ['parse_item', 'query_label', 'filter_query_args'])){
			return $args[0];
		}
	}

	public static function parse_json_module($args){
		$data_type	= wpjam_pull($args, 'data_type');
		$object		= self::get($data_type);

		if(!$object){
			return new WP_Error('invalid_data_type');
		}

		$query_args	= array_get($args, 'query_args', $args);
		$query_args	= $query_args ? wp_parse_args($query_args) : [];
		$query_args	= array_merge($query_args, ['search'=>wpjam_get_parameter('s')]);

		return ['items'=>$object->query_items($query_args, false)];
	}

	public static function get_defaults(){
		return [
			'post_type'	=> ['model'=>'WPJAM_Post_Type_Data_Type',	'meta_type'=>'post'],
			'taxonomy'	=> ['model'=>'WPJAM_Taxonomy_Data_Type',	'meta_type'=>'term'],
			'author'	=> ['model'=>'WPJAM_Author_Data_Type',		'meta_type'=>'user'],
			'model'		=> ['model'=>'WPJAM_Model_Data_Type'],
			'video'		=> ['model'=>'WPJAM_Video_Data_Type'],
		];
	}

	public static function ajax_response($data){
		$items	= [];
		$object	= self::get($data['data_type']);

		if($object){
			$args	= $data['query_args'] ?: [];
			$items	= $object->query_items($args) ?: [];

			if(is_wp_error($items)){
				return [['label'=>$items->get_error_message(), 'value'=>$items->get_error_code()]];
			}
		}

		return ['items'=>$items];
	}
}

class WPJAM_Post_Type_Data_Type{
	public static function filter_query_args($query_args, $args){
		if(!empty($args['size'])){
			$query_args['thumbnal_size']	= $args['size'];
		}

		return $query_args;
	}

	public static function query_items($args){
		if(!isset($args['s']) && isset($args['search'])){
			$args['s']	= $args['search'];
		}

		return wpjam_get_posts(wp_parse_args($args, [
			'posts_per_page'	=> $args['number'] ?? 10,
			'suppress_filters'	=> false,
		])) ?: [];
	}

	public static function parse_item($post){
		return ['label'=>$post->post_title, 'value'=>$post->ID];
	}

	public static function query_label($post_id){
		if($post_id && is_numeric($post_id)){
			return get_the_title($post_id) ?: (int)$post_id;
		}

		return '';
	}

	public static function validate_value($value, $args){
		if(!$value){
			return null;
		}

		$current 	= is_numeric($value) ? get_post_type($value) : null;

		if($current){
			$post_type	= array_get($args, 'post_type') ?: $current;

			if(in_array($current, (array)$post_type, true)){
				return (int)$value;
			}
		}

		return new WP_Error('invalid_post_id', [$args['title']]);
	}

	public static function parse_value($value, $args=[]){
		return wpjam_get_post($value, $args);
	}

	public static function update_caches($ids){
		return WPJAM_Post::update_caches($ids);
	}

	public static function get_path(...$args){
		$post_type	= is_array($args[0]) ? $args[0]['post_type'] : get_post_type($args[0]);
		$pt_object	= wpjam_get_post_type_object($post_type);

		return $pt_object ? $pt_object->get_path(...$args) : '';
	}

	public static function get_field($args){
		$title		= wpjam_pull($args, 'title');
		$post_type	= wpjam_pull($args, 'post_type');

		if(is_null($title) && $post_type && is_string($post_type)){
			$title	= wpjam_get_post_type_setting($post_type, 'title');
		}

		return wp_parse_args($args, [
			'title'			=> $title,
			'type'			=> 'text',
			'class'			=> 'all-options',
			'data_type'		=> 'post_type',
			'post_type'		=> $post_type,
			'placeholder'	=> '请输入'.$title.'ID或者输入关键字筛选',
			'show_in_rest'	=> ['type'=>'integer']
		]);
	}

	public static function get_fields($args){
		$post_type	= $args['post_type'];
		$object		= get_post_type_object($post_type);

		return $object ? [$post_type.'_id' => self::get_field(['post_type'=>$post_type, 'required'=>true])] : [];
	}
}

class WPJAM_Taxonomy_Data_Type{
	public static function filter_query_args($query_args, $args){
		if($args['creatable']){
			$query_args['creatable']	= $args['creatable'];
		}

		unset($args['creatable']);

		return $query_args;
	}

	public static function query_items($args){
		return get_terms(wp_parse_args($args, [
			'number'		=> (isset($args['parent']) ? 0 : 10),
			'hide_empty'	=> 0
		])) ?: [];
	}

	public static function parse_item($term){
		if(is_object($term)){
			return ['label'=>$term->name, 'value'=>$term->term_id];
		}else{
			return ['label'=>$term['name'], 'value'=>$term['id']];
		}
	}

	public static function query_label($term_id, $args){
		if($term_id && is_numeric($term_id)){
			return get_term_field('name', $term_id, $args['taxonomy']) ?: (int)$term_id;
		}

		return '';
	}

	public static function validate_value($value, $args){
		if(!$value){
			return null;
		}

		$taxonomy	= $args['taxonomy'];

		if(is_array($value)){
			$object	= wpjam_get_taxonomy_object($taxonomy);
			$levels	= $object ? $object->levels : 0;
			$prev	= 0;

			for($level=0; $level < $levels; $level++){
				$_value	= $value['level_'.$level] ?? 0;

				if(!$_value){
					return $prev;
				}

				$prev	= $_value;
			}

			return $prev;
		}elseif(is_numeric($value)){
			if(get_term($value, $taxonomy)){
				return (int)$value;
			}
		}else{
			$result	= term_exists($value, $taxonomy);

			if($result){
				return is_array($result) ? $result['term_id'] : $result;
			}elseif(!empty($args['creatable'])){
				return WPJAM_Term::insert(['name'=>$value, 'taxonomy'=>$taxonomy]);
			}
		}

		return new WP_Error('invalid_term_id', [$args['title']]);
	}

	public static function parse_value($value, $args=[]){
		return wpjam_get_term($value, $args);
	}

	public static function update_caches($ids){
		return WPJAM_Term::update_caches($ids);
	}

	public static function get_path(...$args){
		$taxonomy	= is_array($args[0]) ? $args[0]['taxonomy'] : get_term_taxonomy($args[0]);
		$object		= wpjam_get_taxonomy_object($taxonomy);

		return $object ? $object->get_path(...$args) : '';
	}

	public static function get_field($args){
		$object	= isset($args['taxonomy']) && is_string($args['taxonomy']) ? wpjam_get_taxonomy_object($args['taxonomy']) : null;
		$type	= $args['type'] ?? '';
		$title	= $args['title'] ??= $object ? $object->title : null;
		$args	= array_merge($args, ['data_type'=>'taxonomy', 'show_in_rest'=>['type'=>'integer']]);

		if($object && ($object->hierarchical || ($type == 'select' || $type == 'mu-select'))){
			if(is_admin() && !$type && $object->levels > 1 && $object->selectable){
				return array_merge($args, ['type'=>'fields', 'render'=>[self::class, 'render_callback']]);
			}

			if(!$type || ($type == 'mu-text' && empty($args['item_type']))){
				if(!is_admin() || $object->selectable){
					$type	= $type ? 'mu-select' : 'select';
				}
			}elseif($type == 'mu-text' && $args['item_type'] == 'select'){
				$type	= 'mu-select';
			}

			if($type == 'select' || $type == 'mu-select'){
				return array_merge($args, ['type'=>$type, 'options'=>[self::class, 'options_callback']]);
			}
		}

		return wp_parse_args($args, ['type'=>'text', 'class'=>'all-options', 'placeholder'=>'请输入'.$title.'ID或者输入关键字筛选']);
	}

	public static function render_callback($args, $field){
		$taxonomy	= $field->taxonomy;
		$values		= $field->value ? array_reverse([$field->value, ...get_ancestors($field->value, $taxonomy, 'taxonomy')]) : [];
		$object		= wpjam_get_taxonomy_object($taxonomy);
		$terms		= get_terms(['taxonomy'=>$taxonomy, 'hide_empty'=>0]);
		$defaults	= $field->parse_option_all(true);
		$parent		= 0;

		for($level=0; $level < $object->levels; $level++){
			$options	= is_null($parent) ? [] : array_column(wp_list_filter($terms, ['parent'=>$parent]), 'name', 'term_id');
			$sub_key	= 'level_'.$level;
			$value		= $values[$level] ?? 0;
			$parent		= $value ?: null;

			$fields[$sub_key]	= [
				'type'		=> 'select',
				'value'		=> $value,
				'options'	=> $defaults+$options
			];

			if($level > 0){
				$fields[$sub_key]	+= [
					'data_type'	=> 'taxonomy',
					'taxonomy'	=> $taxonomy,
					'show_if'	=> ['key'=>'level_'.($level-1), 'compare'=>'!=', 'value'=>0, 'query_arg'=>'parent'],
				];
			}
		}

		return $field->update_arg('fields', $fields)->render_by_fields($args);
	}

	public static function options_callback($field){
		$terms	= wpjam_get_terms(['taxonomy'=>$field->taxonomy, 'hide_empty'=>0, 'format'=>'flat', 'parse'=>false]);

		return $field->parse_option_all(true)+($terms ? array_column($terms, 'name', 'term_id') : []);
	}

	public static function get_fields($args){
		$object	= wpjam_get_taxonomy_object($args['taxonomy']);

		return $object ? [$object->query_key => self::get_field(['taxonomy'=>$args['taxonomy'], 'required'=>true])] : [];
	}
}

class WPJAM_Author_Data_Type{
	public static function get_path(...$args){
		if(is_array($args[0])){
			$args	= $args[0];
			$author	= (int)wpjam_pull($args, 'author');
		}else{
			$author	= $args[0];
			$args	= $args[1];
		}

		if(!$author){
			return new WP_Error('invalid_author', ['作者']);
		}

		if($args['platform'] == 'template'){
			return get_author_posts_url($author);
		}

		return str_replace('%author%', $author, $args['path']);
	}

	public static function get_fields(){
		return ['author' => ['type'=>'select',	'options'=>wp_list_pluck(wpjam_get_authors(), 'display_name', 'ID')]];
	}
}

class WPJAM_Video_Data_Type{
	public static function get_video_mp4($id_or_url){
		if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
			if(preg_match('#http://www.miaopai.com/show/(.*?).htm#i',$id_or_url, $matches)){
				return 'http://gslb.miaopai.com/stream/'.esc_attr($matches[1]).'.mp4';
			}elseif(preg_match('#https://v.qq.com/x/page/(.*?).html#i',$id_or_url, $matches)){
				return self::get_qqv_mp4($matches[1]);
			}elseif(preg_match('#https://v.qq.com/x/cover/.*/(.*?).html#i',$id_or_url, $matches)){
				return self::get_qqv_mp4($matches[1]);
			}else{
				return wpjam_zh_urlencode($id_or_url);
			}
		}else{
			return self::get_qqv_mp4($id_or_url);
		}
	}

	public static function get_qqv_id($id_or_url){
		if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
			foreach([
				'#https://v.qq.com/x/page/(.*?).html#i',
				'#https://v.qq.com/x/cover/.*/(.*?).html#i'
			] as $pattern){
				if(preg_match($pattern,$id_or_url, $matches)){
					return $matches[1];
				}
			}

			return '';
		}

		return $id_or_url;
	}

	public static function _get_qqv_mp4($vid){
		$response	= wpjam_remote_request('http://vv.video.qq.com/getinfo?otype=json&platform=11001&vid='.$vid, ['timeout'=>4]);

		if(is_wp_error($response)){
			return $response;
		}

		$response	= trim(substr($response, strpos($response, '{')),';');
		$response	= wpjam_try('wpjam_json_decode', $response);

		if(empty($response['vl'])){
			return new WP_Error('error', '腾讯视频不存在或者为收费视频！');
		}

		$u	= $response['vl']['vi'][0];
		$p0	= $u['ul']['ui'][0]['url'];
		$p1	= $u['fn'];
		$p2	= $u['fvkey'];

		return $p0.$p1.'?vkey='.$p2;
	}

	public static function get_qqv_mp4($vid){
		if(strlen($vid) > 20){
			return new WP_Error('error', '无效的腾讯视频');
		}

		return wpjam_cache($vid, fn() => self::_get_qqv_mp4($vid), 'qqv_mp4', HOUR_IN_SECONDS*6);
	}

	public static function query_items($args){
		return [];
	}

	public static function parse_value($value, $args=[]){
		return self::get_video_mp4($value);
	}
}

class WPJAM_Model_Data_Type{
	public static function filter_query_args($query_args, $args){
		$model	= array_get($query_args, 'model');

		if(!$model || !class_exists($model)){
			wp_die(' model 未定义');
		}

		return $query_args;
	}

	public static function query_items($args){
		$args	= array_except($args, ['label_key', 'id_key']);
		$args	= wp_parse_args($args, ['number'=>10]);
		$model	= wpjam_pull($args, 'model');
		$query	= wpjam_catch([$model, 'query'], $args);

		return is_wp_error($query) ? $query : $query->items;
	}

	public static function parse_item($item, $args){
		$label_key	= wpjam_pull($args, 'label_key', 'title');
		$id_key		= wpjam_pull($args, 'id_key', 'id');

		return ['label'=>$item[$label_key], 'value'=>$item[$id_key]];
	}

	public static function query_label($id, $args){
		$model	= wpjam_pull($args, 'model');
		$data	= wpjam_catch([$model, 'get'], $id);

		if($data && !is_wp_error($data)){
			$label_key	= $args['label_key'];

			return $data[$label_key] ?: $id;
		}

		return '';
	}

	public static function validate_value($value, $args){
		if($value){
			$model	= wpjam_pull($args, 'model');
			$result	= wpjam_catch([$model, 'get'], $value);

			return is_wp_error($result) ? $result : $value;
		}

		return null;
	}

	public static function get_meta_type($args){
		$model		= wpjam_pull($args, 'model');
		$meta_type	= wpjam_catch([$model, 'get_meta_type']);

		return is_wp_error($meta_type) ? '' : $meta_type;
	}
}