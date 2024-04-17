<?php
trait WPJAM_Call_Trait{
	protected static $_closures	= [];

	protected static function dynamic_method($action, $method, ...$args){
		$name	= self::get_called_name();

		if($action == 'add'){
			if(is_closure($args[0])){
				self::$_closures[$name][$method]	= $args[0];
			}
		}elseif($action == 'remove'){
			unset(self::$_closures[$name][$method]);
		}elseif($action == 'get'){
			if($method){
				$name		= $args ? strtolower($args[0]) : $name;
				$closure	= self::$_closures[$name][$method] ?? null;

				if(!$closure){
					$parent		= get_parent_class($name);
					$closure	= $parent ? self::dynamic_method('get', $method, $parent) : null;
				}

				return $closure;
			}
		}
	}

	protected static function get_dynamic_method($method){
		return self::dynamic_method('get', $method);
	}

	public static function add_dynamic_method($method, $closure){
		self::dynamic_method('add', $method, $closure);
	}

	public static function remove_dynamic_method($method){
		self::dynamic_method('remove', $method);
	}

	protected static function get_called_name(){
		return strtolower(get_called_class());
	}

	public static function exception(...$args){
		throw new WPJAM_Exception(...$args);
	}

	protected function bind_if_closure($closure){
		return is_closure($closure) ? $closure->bindTo($this, get_called_class()) : $closure;
	}

	protected function call_dynamic_method($method, ...$args){
		$closure	= is_closure($method) ? $method : $this->get_dynamic_method($method);

		return $closure ? call_user_func_array($this->bind_if_closure($closure), $args) : null;
	}

	public function call($method, ...$args){	// delete 2024-06-30
		trigger_error('call');

		try{
			return $this->try($method, ...$args);
		}catch(WPJAM_Exception $e){
			return $e->get_wp_error();
		}catch(Exception $e){
			return new WP_Error($e->getCode(), $e->getMessage());
		}
	}

	protected function try($method, ...$args){	// delete 2024-06-30
		trigger_error('try');

		try{
			if(is_closure($method)){
				$callback	= $this->bind_if_closure($method);
			}elseif(method_exists($this, $method)){
				$callback	= [$this, $method];
			}else{
				$closure	= $this->get_dynamic_method($method);
				$callback	= $closure ? $this->bind_if_closure($closure): [$this, $method];
			}

			return wpjam_throw_if_error(call_user_func_array($callback, $args));
		}catch(Exception $e){
			throw $e;
		}
	}

	protected function map($data, $method, ...$args){	// delete 2024-06-30
		trigger_error('map');

		if($data && is_array($data)){
			foreach($data as $key => &$item){
				$item	= $this->try($method, $item, ...[...$args, $key]);
			}
		}

		return $data;
	}
}

trait WPJAM_Items_Trait{
	public function get_items($field=''){
		$field	= $field ?: '_items';

		return $this->$field ?: [];
	}

	public function update_items($items, $field=''){
		$field	= $field ?: '_items';

		$this->$field	= $items;

		return $this;
	}

	public function item_exists($key, $field=''){
		return array_key_exists($key, $this->get_items($field));
	}

	public function get_item($key, $field=''){
		$items	= $this->get_items($field);

		return $items[$key] ?? null;
	}

	public function get_item_arg($key, $arg, $field=''){
		$item	= $this->get_item($key, $field);

		return $item ? array_get($item, $arg) : null;
	}

	public function has_item($item, $field=''){
		$items	= $this->get_items($field);

		return in_array($item, $items);
	}

	public function add_item(...$args){
		$key	= (count($args) == 1 || is_array($args[0])) ? null : array_shift($args);
		$item	= array_shift($args);
		$field	= array_shift($args) ?: '';

		return $this->item_action('add', $key, $item, $field);
	}

	public function remove_item($item, $field=''){
		return $this->item_action('remove', null, $item, $field);
	}

	public function edit_item($key, $item, $field=''){
		return $this->item_action('edit', $key, $item, $field);
	}

	public function replace_item($key, $item, $field=''){
		return $this->item_action('replace', $key, $item, $field);
	}

	public function set_item($key, $item, $field=''){
		return $this->item_action('set', $key, $item, $field);
	}

	public function delete_item($key, $field=''){
		$result	= $this->item_action('delete', $key, null, $field);

		if(!is_wp_error($result)){
			$this->after_delete_item($key, $field);
		}

		return $result;
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		$items	= $this->get_items($field);
		$new	= wpjam_pull($items, $orders);

		return $this->update_items(array_merge($new, $items), $field);
	}

	protected function item_action($action, $key, $item, $field=''){
		$result	= $this->validate_item($item, $key, $action, $field);

		if(is_wp_error($result)){
			return $result;
		}

		$items	= $this->get_items($field);

		if(isset($key)){
			if($this->item_exists($key, $field)){
				if($action == 'add'){
					return new WP_Error('invalid_item_key', '「'.$key.'」已存在，无法添加');
				}
			}else{
				if(in_array($action, ['edit', 'replace'])){
					return new WP_Error('invalid_item_key', '「'.$key.'不存在，无法编辑');
				}elseif($action == 'delete'){
					return new WP_Error('invalid_item_key', '「'.$key.'不存在，无法删除');
				}
			}

			if(isset($item)){
				$items[$key]	= $this->sanitize_item($item, $key, $action, $field);
			}else{
				$items	= array_except($items, $key);
			}
		}else{
			if($action == 'add'){
				$items[]	= $this->sanitize_item($item, $key, $action, $field);
			}elseif($action == 'remove'){
				$items	= array_diff($items, [$item]);
			}else{
				return new WP_Error('invalid_item_key', '必须设置key');
			}
		}

		return $this->update_items($items, $field);
	}

	protected function validate_item($item=null, $key=null, $action='', $field=''){
		return true;
	}

	protected function sanitize_item($item, $id=null, $field=''){
		return $item;
	}

	protected function after_delete_item($key, $field=''){
	}

	public static function item_list_action($id, $data, $action_key=''){
		if(!method_exists(get_called_class(), 'get_instance')){
			wp_die($model.'->get_instance() 为定义');
		}

		$object	= static::get_instance($id);

		if(!$object){
			wp_die('invaid_id');
		}

		$i	= wpjam_get_data_parameter('i');

		if($action_key == 'add_item'){
			return $object->add_item($i, $data);
		}elseif($action_key == 'edit_item'){
			return $object->edit_item($i, $data);
		}elseif($action_key == 'del_item'){
			return $object->del_item($i);
		}elseif($action_key == 'move_item'){
			$orders	= wpjam_get_data_parameter('item') ?: [];

			return $object->move_item($orders);
		}
	}

	public static function item_data_action($id){
		if(!method_exists(get_called_class(), 'get_instance')){
			wp_die($model.'->get_instance() 为定义');
		}

		$object	= static::get_instance($id);

		if(!$object){
			wp_die('invaid_id');
		}

		$i	= wpjam_get_data_parameter('i');

		return $object->get_item($i);
	}

	public static function get_item_actions(){
		$item_action	= [
			'callback'		=> [self::class, 'item_list_action'],
			'data_callback'	=> [self::class, 'item_data_action'],
			'row_action'	=> false,
		];

		return [
			'add_item'	=>['title'=>'添加项目',	'page_title'=>'添加项目',	'dismiss'=>true]+$item_action,
			'edit_item'	=>['title'=>'修改项目',	'page_title'=>'修改项目',	]+$item_action,
			'del_item'	=>['title'=>'删除项目',	'page_title'=>'删除项目',	'direct'=>true,	'confirm'=>true]+$item_action,
			'move_item'	=>['title'=>'移动项目',	'page_title'=>'移动项目',	'direct'=>true]+$item_action,
		];
	}
}

class WPJAM_Args implements ArrayAccess, IteratorAggregate, JsonSerializable{
	use WPJAM_Call_Trait;

	protected $args;

	public function __construct($args=[]){
		$this->args	= $args;
	}

	public function __get($key){
		$args	= $this->get_args();

		return array_key_exists($key, $args) ? $args[$key] : ($key == 'args' ? $args : null);
	}

	public function __set($key, $value){
		$this->filter_args();

		$this->args[$key]	= $value;
	}

	public function __isset($key){
		if(array_key_exists($key, $this->get_args())){
			return true;
		}

		return $this->$key !== null;
	}

	public function __unset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->get_args()[$key] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->filter_args();

		if(is_null($key)){
			$this->args[]		= $value;
		}else{
			$this->args[$key]	= $value;
		}
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return array_key_exists($key, $this->get_args());
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_args());
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize(){
		return $this->get_args();
	}

	protected function filter_args(){
		if(!$this->args && !is_array($this->args)){
			$this->args = [];
		}

		return $this->args;
	}

	public function get_args(){
		return $this->filter_args();
	}

	public function set_args($args){
		$this->args	= $args;

		return $this;
	}

	public function update_args($args, $replace=true){
		$callback	= $replace ? 'array_merge' : 'wp_parse_args';
		$this->args	= call_user_func($callback, $this->get_args(), $args);

		return $this;
	}

	public function get_arg($key, $default=null){
		return array_get($this->get_args(), $key, $default);
	}

	public function update_arg($key, $value=null){
		$this->args	= array_set($this->get_args(), $key, $value);

		return $this;
	}

	public function delete_arg($key){
		$this->args	= array_except($this->get_args(), $key);

		return $this;
	}

	public function pull($key, $default=null){
		if(isset($this->$key)){
			return wpjam_pull($this->args, $key);
		}

		return $default;
	}

	public function to_array(){
		return $this->get_args();
	}

	public function sandbox($callback, ...$args){
		try{
			$archive	= $this->get_args();

			return call_user_func($this->bind_if_closure($callback), ...$args);
		}finally{
			$this->args	= $archive;
		}
	}

	protected function error($errcode, $errmsg){
		return new WP_Error($errcode, $errmsg);
	}
}

class WPJAM_Register extends WPJAM_Args{
	use WPJAM_Items_Trait;

	protected $name;
	protected $_group;
	protected $_filtered;

	public function __construct($name, $args=[], $group=''){
		$this->name		= $name;
		$this->args		= $args;
		$this->_group	= self::parse_group($group);

		if($this->is_active() || !empty($args['active'])){
			$this->args	= $this->preprocess_args($args);
		}

		$this->args	= array_merge($this->args, ['name'=>$name]);
	}

	protected function preprocess_args($args){
		$group	= $this->_group;
		$config	= $group->get_config('model') ?? true;
		$model	= $config ? array_get($args, 'model') : null;

		if($model || !empty($args['hooks']) || !empty($args['init'])){
			$file	= wpjam_pull($args, 'file');

			if($file && is_file($file)){
				include_once $file;
			}
		}

		if($model && is_subclass_of($model, 'WPJAM_Register')){
			trigger_error('「'.(is_object($model) ? get_class($model) : $model).'」是 WPJAM_Register 子类');
		}

		if($model){
			if($config === 'object'){
				if(!is_object($model)){
					if(class_exists($model, true)){
						$model = $args['model']	= new $model(array_merge($args, ['object'=>$this]));
					}else{
						trigger_error('model 无效');
					}
				}
			}else{
				$group->add_model($model, $this);
			}

			if(!isset($args['hooks']) || $args['hooks'] === true){
				$args['hooks']	= $this->parse_method('add_hooks', $model);
			}

			if((isset($args['init']) && $args['init'] === true) || (!isset($args['init']) && $group->get_config('init'))){
				$args['init']	= $this->parse_method('init', $model);
			}

			if(isset($args['map_meta_cap']) && $args['map_meta_cap'] === true){
				$args['map_meta_cap']	= $this->parse_method('map_meta_cap', $model);
			}
		}

		return wpjam_preprocess_args($args);
	}

	protected function filter_args(){
		if(!$this->_filtered){
			$this->_filtered	= true;

			$class		= self::get_called_name();
			$filter		= $class == 'wpjam_register' ? 'wpjam_'.$this->_group->name.'_args' : $class.'_args';
			$this->args	= apply_filters($filter, $this->args, $this->name);
		}

		return $this->args;
	}

	public function get_arg($key, $default=null, $do_callback=true){
		$value	= parent::get_arg($key);

		if(is_callable($value)){
			$value	= $this->bind_if_closure($value);
		}elseif(is_null($value) && $this->model && $key && is_string($key) && !str_contains($key, '.')){
			$value	= $this->parse_method('get_'.$key, 'model');
		}

		if($do_callback && $value && is_callable($value)){
			return call_user_func($value, $this->name);
		}

		return $value ?? $default;
	}

	protected function parse_method($method, $type=null){
		if(!$type || $type != 'property'){
			$model	= ($type == 'model' || !$type) ? $this->model : $type;

			if($model && method_exists($model, $method)){
				return [$model, $method];
			}
		}

		if(!$type || $type == 'property'){
			if($this->$method && is_callable($this->$method)){
				return $this->bind_if_closure($this->$method);
			}
		}
	}

	protected function call_method($method, ...$args){
		$called	= $this->parse_method($method);

		if($called){
			return call_user_func_array($called, $args);
		}

		if(str_starts_with($method, 'filter_')){
			return array_shift($args);
		}
	}

	public function get_parent(){
		return $this->sub_name ? self::get($this->name) : null;
	}

	public function is_sub(){
		return (bool)$this->sub_name;
	}

	public function get_sub($name){
		return $this->get_item($name, 'subs');
	}

	public function get_subs(){
		return $this->get_items('subs');
	}

	public function register_sub($name, $args){
		$args	= array_merge($args, ['sub_name'=>$name]);
		$sub	= new static($this->name, $args);

		$this->add_item($name, $sub, 'subs');

		return self::register($this->name.':'.$name, $sub);
	}

	public function unregister_sub($name){
		$this->delete_item($name, 'subs');

		return self::unregister($this->name.':'.$name);
	}

	public function is_active(){
		return true;
	}

	protected static function validate_name($name){
		$prefix	= self::class.'的注册 name';

		if(empty($name)){
			trigger_error($prefix.' 为空');
			return;
		}elseif(is_numeric($name)){
			trigger_error($prefix.'「'.$name.'」'.'为纯数字');
			return;
		}elseif(!is_string($name)){
			trigger_error($prefix.'「'.var_export($name, true).'」不为字符串');
			return;
		}

		return true;
	}

	protected static function sanitize_name($name, $args){
		return $name;
	}

	protected static function parse_group($name=''){
		$called	= get_called_class();
		$name	= strtolower($name ?: $called);
		$group 	= WPJAM_Register_Group::get_instance($name);

		if($name == strtolower($called) && !$group->called){
			$group->called		= $called;
			$group->defaults	= method_exists($called, 'get_defaults') ? static::get_defaults() : [];

			if($group->get_config('route')){
				wpjam_register_route($group->get_config('route'), ['model'=>$called]);
			}

			if($group->get_config('loaded')){
				add_action('wp_loaded', fn() => static::call_active('loaded'));
			}

			if($group->get_config('register_json')){
				add_action('wpjam_api', fn($json) => static::call_active('register_json', $json));
			}

			if(is_admin()){
				foreach(['menu_page', 'admin_load'] as $key){
					if($group->get_config($key)){
						add_action('wpjam_admin_init', fn() => array_map('wpjam_add_'.$key, static::get_active($key)));
					}
				}
			}
		}

		return $group;
	}

	public static function call_group($method, ...$args){
		[$method, $group]	= str_contains($method, '_by_') ? explode('_by_', $method) : [$method, ''];

		return call_user_func([self::parse_group($group), $method], ...$args);
	}

	public static function register($name, $args=[]){
		if(is_object($name)){
			$args	= $name;
			$name	= $args->name ?? null;
		}elseif(is_array($name)){
			[$args, $name]	= [$name, $args];

			$name	= wpjam_pull($args, 'name') ?: ($name ?: '__'.count(self::call_group('get_objects')));
		}

		if(!self::validate_name($name)){
			return;
		}

		if(is_object($args)){
			$object	= $args;
		}else{
			if(!empty($args['admin']) && !is_admin()){
				return;
			}

			$object	= new static($name, $args);
			$name	= static::sanitize_name($name, $args);
		}

		self::call_group('add_object', $name, $object);

		if(method_exists($object, 'registered')){
			$object->registered();
		}

		return $object;
	}

	public static function re_register($name, $args, $merge=true){
		self::unregister($name);

		return self::register($name, $args);
	}

	public static function unregister($name){
		self::call_group('remove_object', $name);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		$defaults	= self::call_group('get_default');

		if($defaults){
			wpjam_map($defaults, fn($args, $name) => self::register($name, $args));
		}

		$objects	= self::call_group('get_objects', $args, $operator);

		return $output == 'names' ? array_keys($objects) : $objects;
	}

	public static function get_by(...$args){
		$args	= $args ? (is_array($args[0]) ? $args[0] : [$args[0] => $args[1]]) : [];

		return self::get_registereds($args);
	}

	public static function get($name, $by='', $top=''){
		$object	= self::call_group('get_object', $name, $by, $top);

		if(!$object && !$by && $name){
			$args	= self::call_group('get_default', $name);

			if($args){
				return self::register($name, $args);
			}
		}

		return $object;
	}

	public static function exists($name){
		return self::get($name) ? true : false;
	}

	public static function get_setting_fields($args=[]){
		$fields	= [];
		$single	= self::call_group('get_config', 'single');

		if($single){
			$args	= wp_parse_args($args, [
				'name'				=> wpjam_remove_prefix(self::get_called_name(), 'wpjam_'),
				'title'				=> '',
				'title_field'		=> 'title',
				'show_option_none'	=> __('&mdash; Select &mdash;'),
				'option_none_value'	=> ''
			]);

			$options	= $args['show_option_none'] ? [$args['option_none_value'] => $args['show_option_none']] : [];
			$fields		= [$args['name'] => ['title'=>$args['title'], 'type'=>'select', 'options'=>&$options]];
		}

		foreach(self::get_registereds() as $name => $object){
			if($single){
				$options[$name]	= ['title'=>$object->{$args['title_field']}, 'description'=>$object->description];

				$fields	+= array_map(fn($field) => $field+['show_if'=>['key'=>$args['name'], 'value'=>$name]], $object->get_arg('fields') ?: []);
			}else{
				if(is_null($object->active)){
					$fields[$name]	= wp_parse_args(($object->field ?: []), ['type'=>'checkbox', 'label'=>$object->title]);
				}
			}
		}

		return $fields;
	}

	public static function get_active($key=null){
		$objects	= array_filter(self::get_registereds(), fn($object) => $object->active ?? $object->is_active());

		return $key ? wpjam_filter(array_map(fn($object) => $object->get_arg($key), $objects), 'isset', false) : $objects;
	}

	public static function call_active($method, ...$args){
		$type	= str_starts_with($method, 'filter_') ? 'filter' : (str_starts_with($method, 'get_') ? 'get' : '');

		foreach(self::get_active() as $object){
			$result	= $object->call_method($method, ...$args);	// 不能调用对象本身的方法，会死循环

			if(is_wp_error($result)){
				return $result;
			}

			if($type == 'filter'){
				$args[0]	= $result;
			}elseif($type == 'get'){
				if($result && is_array($result)){
					$return	= array_merge(($return ?? []), $result);
				}
			}
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return ?? [];
		}
	}
}

class WPJAM_Register_Group extends WPJAM_Args{
	use WPJAM_Items_Trait;

	protected function parse_model($model){
		return ($model && is_string($model)) ? strtolower($model) : null;
	}

	public function get_objects($args=[], $operator='AND'){
		if($args){
			if(wp_is_numeric_array($args)){
				return wpjam_fill($args, [$this, 'get_item']);
			}else{
				return array_filter($this->get_items(), fn($obj) => wpjam_match($obj, $args, $operator));
			}
		}

		return $this->get_items();
	}

	public function get_object($name, $by='', $top=''){
		if($by == 'model'){
			if($name && strcasecmp($name, $top) !== 0){
				$name	= strtolower($name);
				$object	= $this->get_item($name, 'models');

				return $object ?: $this->get_object(get_parent_class($name), $by, $top);
			}
		}else{
			return $name ? $this->get_item($name) : null;
		}
	}

	public function add_object($name, $object){
		if($this->get_item($name)){
			trigger_error($this->name.'「'.$name.'」已经注册。');
		}

		$orderby	= $this->get_config('orderby');
		$order		= $this->get_config('order');

		if($orderby){
			$orderby	= $orderby === true ? 'order' : $orderby;
			$is_desc	= !$order || strcasecmp($order, 'DESC') === 0;
			$current	= $object->$orderby ?? 10;
			$objects	= $this->get_objects();
			$index		= wpjam_find($objects, fn($obj) => $is_desc === ($current > ($obj->$orderby ?? 10)), 'index');

			if($index !== false){
				return $this->update_items(array_slice($objects, 0, $index)+[$name=>$object]+array_slice($objects, $index));
			}
		}

		return $this->add_item($name, $object);
	}

	public function remove_object($name){
		$object	= $this->get_item($name);

		if($object){
			$model	= $this->parse_model($object->model);

			if($model){
				$this->delete_item($model, 'models');
			}

			$this->delete_item($name);
		}
	}

	public function add_model($model, $object){
		$model	= $this->parse_model($model);

		if($model){
			// if($this->get_item($model, 'models')){
			// 	trigger_error($model.'已经被使用');
			// }

			$this->add_item($model, $object, 'models');
		}
	}

	public function get_default($name=''){
		if($name){
			$defaults	= $this->defaults;

			if($defaults && isset($defaults[$name])){
				$args	= wpjam_pull($defaults, $name);

				$this->defaults	= $defaults;
			
				return $args;
			}
		}else{
			return $this->pull('defaults');
		}
	}

	public function get_config($key){
		$config	= $this->called ? $this->config : [];

		if(is_null($config)){
			$ref	= new ReflectionClass($this->called);

			if(method_exists($ref, 'getAttributes')){
				$attribute	= $ref->getAttributes('config');

				$args	= $attribute ? $attribute[0]->getArguments() : [];
				$args	= $args ? (is_array($args[0]) ? $args[0] : $args) : [];
			}else{
				$args	= preg_match_all('/@config\s+([^\r\n]*)/', $ref->getDocComment(), $matches) ? wp_parse_list($matches[1][0]) : [];
			}

			$config	= $this->config = wpjam_array($args, fn($k, $v) => is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v) : [$v, true]) : [$k, $v]);
		}

		return $config[$key] ?? null;
	}

	protected static $_groups	= [];

	public static function get_instance($name){
		return self::$_groups[$name]	??= new self(['name'=>$name]);
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(str_ends_with($method, '_option')){
			$method	= wpjam_remove_postfix($method, '_option');	// get_option register_option unregister_option
			$name	= array_shift($args);

			if($method == 'register'){
				$args	= array_merge(array_shift($args), ['meta_type'=>$this->name]);

				if($this->name == 'post'){
					$args	= wp_parse_args($args, ['fields'=>[], 'priority'=>'default']);

					if(!isset($args['post_type']) && isset($args['post_types'])){
						$args['post_type']	= wpjam_pull($args, 'post_types') ?: null;
					}
				}elseif($this->name == 'term'){
					if(!isset($args['taxonomy']) && isset($args['taxonomies'])){
						$args['taxonomy']	= wpjam_pull($args, 'taxonomies') ?: null;
					}

					if(!isset($args['fields'])){
						$args['fields']		= [$name => array_except($args, 'taxonomy')];
						$args['from_field']	= true;
					}
				}

				$object	= new WPJAM_Meta_Option($name, $args);
				$args	= [$object];
			}

			return call_user_func(['WPJAM_Meta_Option', $method], $this->name.':'.$name, ...$args); 
		}elseif(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
			array_unshift($args, $this->name);

			$callback	= str_replace('data', 'metadata', $method);
		}elseif(str_ends_with($method, '_by_mid')){
			array_unshift($args, $this->name);

			$callback	= str_replace('_by_mid', '_metadata_by_mid', $method);
		}elseif(str_ends_with($method, '_meta')){
			$callback	= [$this, str_replace('_meta', '_data', $method)];
		}elseif(str_contains($method, '_meta')){
			$callback	= [$this, str_replace('_meta', '', $method)];
		}

		if(empty($callback)){
			trigger_error('无效的方法'.$method);
		}

		return call_user_func_array($callback, $args);
	}

	protected function preprocess_args($args){
		$table_name	= $args['table_name'] ?? $this->name.'meta';
		$wpdb		= $GLOBALS['wpdb'];

		if(!isset($wpdb->$table_name)){
			$wpdb->$table_name = $args['table'] ?? $wpdb->prefix.$this->name.'meta';
		}

		return parent::preprocess_args($args);
	}

	public function register_lazyloader(){
		return wpjam_register_lazyloader($this->name.'_meta', [
			'filter'	=> 'get_'.$this->name.'_metadata',
			'callback'	=> [$this, 'update_cache']
		]);
	}

	public function lazyload_data($ids){
		wpjam_lazyload($this->name.'_meta', $ids);
	}

	public function get_options($args=[]){
		return wpjam_array(WPJAM_Meta_Option::get_by(array_merge($args, ['meta_type'=>$this->name])), fn($k, $v) => $v->name);
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object'){
		if($name == 'object'){
			return $this->name.'_id';
		}elseif($name == 'id'){
			return 'user' == $this->name ? 'umeta_id' : 'meta_id';
		}
	}

	protected function parse_value($value){
		if(wp_is_numeric_array($value)){
			return maybe_unserialize($value[0]);
		}else{
			return array_merge($value, ['meta_value'=>maybe_unserialize($value['meta_value'])]);
		}
	}

	public function get_data_with_default($id, ...$args){
		if(!$args){
			return $this->get_data($id);
		}

		if(is_array($args[0])){
			$keys	= wpjam_array($args[0], fn($k, $v) => is_numeric($k) ? [$v, null] : [$k, $v]);

			return ($id && $args[0]) ? wpjam_map($keys, fn($v, $k) => $this->get_data_with_default($id, $k, $v)) : [];
		}else{
			if($id && $args[0]){
				if($args[0] == 'meta_input'){
					return array_map([$this, 'parse_value'], $this->get_data($id));
				}

				if($this->data_exists($id, $args[0])){
					return $this->get_data($id, $args[0], true);
				}
			}

			return $args[1] ?? null;
		}
	}

	public function get_by_key(...$args){
		global $wpdb;

		if(empty($args)){
			return [];
		}

		if(is_array($args[0])){
			$key	= $args[0]['meta_key'] ?? ($args[0]['key'] ?? '');
			$value	= $args[0]['meta_value'] ?? ($args[0]['value'] ?? '');
		}else{
			$key	= $args[0];
			$value	= $args[1] ?? null;
		}

		if($key){
			$where[]	= $wpdb->prepare('meta_key=%s', $key);
		}

		if(!is_null($value)){
			$where[]	= $wpdb->prepare('meta_value=%s', maybe_serialize($value));
		}

		if(empty($where)){
			return [];
		}

		$where	= implode(' AND ', $where);
		$table	= $this->get_table();
		$data	= $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A) ?: [];

		return array_map([$this, 'parse_value'], $data);
	}

	public function update_data_with_default($id, ...$args){
		if(is_array($args[0])){
			$data	= $args[0];

			if(wpjam_is_assoc_array($data)){
				$defaults	= (isset($args[1]) && is_array($args[1])) ? $args[1] : [];

				if(isset($data['meta_input']) && wpjam_is_assoc_array($data['meta_input'])){
					$this->update_data_with_default($id, wpjam_pull($data, 'meta_input'), wpjam_pull($defaults, 'meta_input'));
				}

				wpjam_map($data, fn($v, $k) => $this->update_data_with_default($id, $k, $v, wpjam_pull($defaults, $k)));
			}

			return true;
		}else{
			$key		= $args[0];
			$value		= $args[1];
			$default	= $args[2] ?? null;

			if(is_array($value)){
				if($value && (!is_array($default) || array_diff_assoc($default, $value))){
					return $this->update_data($id, $key, $value);
				}
			}else{
				if(isset($value) && ((is_null($default) && $value) || (!is_null($default) && $value != $default))){
					return $this->update_data($id, $key, $value);
				}
			}

			return $this->delete_data($id, $key);
		}
	}

	public function cleanup(){
		if($this->object_key){
			$object_key		= $this->object_key;
			$object_table	= $GLOBALS['wpdb']->{$this->name.'s'};
		}else{
			$object_model	= $this->object_model;

			if($object_model && is_callable([$object_model, 'get_table'])){
				$object_table	= call_user_func([$object_model, 'get_table']);
				$object_key		= call_user_func([$object_model, 'get_primary_key']);
			}else{
				$object_table	= '';
				$object_key		= '';
			}
		}

		$this->delete_orphan_data($object_table, $object_key);
	}

	public function delete_orphan_data($object_table=null, $object_key=null){
		if($object_table && $object_key){
			$wpdb	= $GLOBALS['wpdb'];
			$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$object_table." t ON t.".$object_key." = m.".$this->get_column('object')." WHERE t.".$object_key." IS NULL") ?: [];

			array_walk($mids, [$this, 'delete_by_mid']);
		}
	}

	public function delete_empty_data(){
		$wpdb	= $GLOBALS['wpdb'];
		$mids	= $wpdb->get_col("SELECT ".$this->get_column('id')." FROM ".$this->get_table()." WHERE meta_value = ''") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function delete_by_key($key, $value=''){
		return delete_metadata($this->name, null, $key, $value, true);
	}

	public function delete_by_id($id){
		$wpdb	= $GLOBALS['wpdb'];
		$table	= $this->get_table();
		$column	= $this->get_column();
		$mids	= $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$table} WHERE {$column} = %d ", $id)) ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function update_cache($ids){
		update_meta_cache($this->name, $ids);
	}

	public function create_table(){
		$table	= $this->get_table();

		if($GLOBALS['wpdb']->get_var("show tables like '{$table}'") != $table){
			$column	= $this->name.'_id';

			$GLOBALS['wpdb']->query("CREATE TABLE {$table} (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				{$column} bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY  (meta_id),
				KEY {$column} ({$column}),
				KEY meta_key (meta_key(191))
			)");
		}
	}

	public static function get_defaults(){
		return array_merge([
			'post'	=> ['order'=>50,	'object_model'=>'WPJAM_Post',	'object_column'=>'title',	'object_key'=>'ID'],
			'term'	=> ['order'=>40,	'object_model'=>'WPJAM_Term',	'object_column'=>'name',	'object_key'=>'term_id'],
			'user'	=> ['order'=>30,	'object_model'=>'WPJAM_User',	'object_column'=>'display_name','object_key'=>'ID'],
		], (is_multisite() ? [
			'blog'	=> ['order'=>5,	'object_key'=>'blog_id'],
			'site'	=> ['order'=>5],
		] : []));
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_Meta_Option extends WPJAM_Register{
	public function __call($method, $args){
		if(str_ends_with($method, '_by_fields')){
			$id		= array_shift($args);
			$fields	= $this->get_fields($id);
			$object	= wpjam_fields($fields);
			$method	= wpjam_remove_postfix($method, '_by_fields');

			return call_user_func_array([$object, $method], $args);
		}
	}

	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'list_table'){
			if(is_null($value) && did_action('current_screen') && !empty($GLOBALS['plugin_page'])){
				return true;
			}
		}elseif($key == 'callback'){
			if(!$value){
				return $this->update_callback;
			}
		}

		return $value;
	}

	public function get_fields($id=null){
		$fields	= $this->fields;

		return is_callable($fields) ? call_user_func($fields, $id, $this->name) : $fields;
	}

	public function parse_list_table_args(){
		return wp_parse_args($this->get_args(), [
			'page_title'	=> '设置'.$this->title,
			'submit_text'	=> '设置',
			'meta_type'		=> $this->name,
			'fields'		=> [$this, 'get_fields']
		]);
	}

	public function prepare($id=null){
		if($this->callback){
			return [];
		}

		return $this->prepare_by_fields($id, array_merge($this->get_args(), ['id'=>$id]));
	}

	public function validate($id=null, $data=null){
		return $this->validate_by_fields($id, $data);
	}

	public function render($id, $args=[]){
		echo $this->render_by_fields($id, array_merge($this->get_args(), ['id'=>$id], $args));
	}

	public function callback($id, $data=null){
		$fields	= $this->get_fields($id);
		$object	= wpjam_fields($fields);
		$data	= $object->validate($data);

		if(is_wp_error($data)){
			return $data;
		}elseif(!$data){
			return true;
		}

		if($this->callback){
			$result	= is_callable($this->callback) ? call_user_func($this->callback, $id, $data, $fields) : false;

			return $result === false ? new WP_Error('invalid_callback') : $result;
		}else{
			return wpjam_update_metadata($this->meta_type, $id, $data, $object->get_defaults());
		}
	}

	public static function create($name, $args){
		$meta_type	= array_get($args, 'meta_type');

		if($meta_type){
			$object	= new self($name, $args);

			return self::register($meta_type.':'.$name, $object);
		}
	}

	public static function get_by(...$args){
		$args		= is_array($args[0]) ? $args[0] : [$args[0] => $args[1]];
		$list_table	= wpjam_pull($args, 'list_table');
		$meta_type	= array_get($args, 'meta_type');

		if(!$meta_type){
			return [];
		}

		if(isset($list_table)){
			$args['title']		= true;
			$args['list_table']	= $list_table ? true : ['compare'=>'!=', 'strict'=>true, 'value'=>'only'];
		}

		if($meta_type == 'post'){
			$post_type	= wpjam_pull($args, 'post_type');

			if($post_type){
				$object	= wpjam_get_post_type_object($post_type);

				if($object){
					$object->register_option($list_table);
				}

				$args['post_type']	= ['value'=>$post_type, 'if_null'=>true, 'callable'=>true];
			}
		}elseif($meta_type == 'term'){
			$taxonomy	= wpjam_pull($args, 'taxonomy');
			$action		= wpjam_pull($args, 'action');

			if($taxonomy){
				$object	= wpjam_get_taxonomy_object($taxonomy);

				if($object){
					$object->register_option($list_table);
				}

				$args['taxonomy']	= ['value'=>$taxonomy, 'if_null'=>true, 'callable'=>true];
			}

			if($action){
				$args['action']		= ['value'=>$action, 'if_null'=>true, 'callable'=>true];
			}
		}

		return static::get_registereds($args);
	}
}

class WPJAM_Lazyloader extends WPJAM_Register{
	private $pending	= [];

	public function callback($check){
		if($this->pending){
			call_user_func($this->callback, $this->pending);

			$this->pending	= [];
		}

		$this->remove_filter();

		return $check;
	}

	public function queue_objects($object_ids){
		if($object_ids){
			$this->pending	= array_merge($this->pending, $object_ids);
			$this->pending	= array_unique($this->pending);

			$this->add_filter();
		}
	}

	public function add_filter(){
		if(!$this->filter_added){
			add_filter($this->filter, [$this, 'callback']);

			$this->filter_added	= true;
		}
	}

	public function remove_filter(){
		remove_filter($this->filter, [$this, 'callback']);

		unset($this->filter_added);
	}
}

class WPJAM_AJAX extends WPJAM_Register{
	public function registered(){
		add_action('wp_ajax_'.$this->name, [$this, 'callback']);

		if($this->nopriv){
			add_action('wp_ajax_nopriv_'.$this->name, [$this, 'callback']);
		}
	}

	public function callback(){
		if(!$this->callback || !is_callable($this->callback)){
			wpjam_send_error_json('invalid_callback');
		}

		if($this->fields){
			$data	= wpjam_catch([wpjam_fields($this->fields), 'get_parameter'], 'data');

			if(is_wp_error($data)){
				wpjam_send_json($data);
			}
		}else{
			$data	= wpjam_get_data_parameter();
		}

		if($this->verify !== false && !check_ajax_referer($this->get_nonce_action($data), false, false)){
			wpjam_send_error_json('invalid_nonce');
		}

		$result	= wpjam_catch($this->callback, $data, $this->name);
		$result	= $result === true ? [] : $result;  

		wpjam_send_json($result);
	}

	public function get_attr($data=[], $return=null){
		$attr	= ['action'=>$this->name, 'data'=>$data];

		if($this->verify !== false){
			$attr['nonce']	= wp_create_nonce($this->get_nonce_action($data));
		}

		return $return ? $attr : wpjam_attr($attr, 'data');
	}

	protected function get_nonce_action($data){
		$nonce_action	= $this->name;
		$nonce_data		= $this->nonce_keys ? array_filter(wp_array_slice_assoc($data, $this->nonce_keys)) : [];
		$nonce_action	.= $nonce_data ? ':'.implode(':', $nonce_data) : '';

		return $nonce_action;
	}

	public static function on_enqueue_scripts(){
		wp_register_script('wpjam-ajax', wpjam_url(dirname(__DIR__).'/static/ajax.js'), ['jquery']);
		wp_add_inline_script('wpjam-ajax', 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";', 'before');
	}
}

class WPJAM_Verification_Code extends WPJAM_Register{
	public function __call($method, $args){
		$cache	= $this->cache ??= WPJAM_Cache::create(['group'=>'verification_code', 'global'=>true, 'prefix'=>$this->name]);
		$key	= array_shift($args);

		if($this->failed_times && (int)$cache->get($key.':failed_times') > $this->failed_times){
			return new WP_Error('quota_exceeded', ['尝试的失败次数', '请15分钟后重试。']);
		}

		if($method == 'generate'){
			if($this->interval && $cache->get($key.':time') !== false){
				return new WP_Error('error', '验证码'.((int)($this->interval/60)).'分钟前已发送了。');
			}

			$code = rand(100000, 999999);

			$cache->set($key.':code', $code, $this->cache_time);

			if($this->interval){
				$cache->set($key.':time', time(), MINUTE_IN_SECONDS);
			}

			return $code;
		}elseif($method == 'verify'){
			$code		= array_shift($args);
			$current	= $cache->get($key.':code');

			if(!$code || $current === false){
				return new WP_Error('invalid_code');
			}

			if($code != $current){
				if($this->failed_times){
					$failed_times	= $cache->get($key.':failed_times') ?: 0;
					$failed_times	= $failed_times + 1;

					$cache->set($key.':failed_times', $failed_times, $this->cache_time/2);
				}

				return new WP_Error('invalid_code');
			}

			return true;
		}
	}

	protected function preprocess_args($args){
		return wp_parse_args($args, [
			'failed_times'	=> 5,
			'cache_time'	=> MINUTE_IN_SECONDS*30,
			'interval'		=> MINUTE_IN_SECONDS,
		]);
	}

	public static function get_instance($name, $args=[]){
		return self::get($name) ?: self::register($name, $args);
	}
}

class WPJAM_Verify_TXT extends WPJAM_Register{
	public function get_fields(){
		return [
			'name'	=>['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$this->get_data('name'),	'class'=>'all-options'],
			'value'	=>['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$this->get_data('value')]
		];
	}

	public function get_data($key=''){
		$data	= wpjam_get_setting('wpjam_verify_txts', $this->name) ?: [];

		return $key ? ($data[$key] ?? '') : $data;
	}

	public function set_data($data){
		return wpjam_update_setting('wpjam_verify_txts', $this->name, $data) || true;
	}

	public static function __callStatic($method, $args){	// 放弃
		$name	= $args[0];

		if($object = self::get($name)){
			if(in_array($method, ['get_name', 'get_value'])){
				return $object->get_data(str_replace('get_', '', $method));
			}elseif($method == 'set' || $method == 'set_value'){
				return $object->set_data(['name'=>$args[1], 'value'=>$args[2]]);
			}
		}
	}

	public static function filter_root_rewrite_rules($root_rewrite){
		if(empty($GLOBALS['wp_rewrite']->root)){
			$home_path	= parse_url(home_url());

			if(empty($home_path['path']) || '/' == $home_path['path']){
				$root_rewrite	= array_merge(['([^/]+)\.txt?$'=>'index.php?module=txt&action=$matches[1]'], $root_rewrite);
			}
		}

		return $root_rewrite;
	}

	public static function get_rewrite_rule(){
		add_filter('root_rewrite_rules',	[self::class, 'filter_root_rewrite_rules']);
	}

	public static function redirect($action){
		$txts = wpjam_get_option('wpjam_verify_txts');
		$txt	= $txts ? wpjam_find($txts, fn($txt) => $txt['name'] == str_replace('.txt', '', $action).'.txt') : '';

		if($txt){
			header('Content-Type: text/plain');
			echo $txt['value'];

			exit;
		}
	}
}