<?php
trait WPJAM_Instance_Trait{
	use WPJAM_Call_Trait;

	protected static function call_instance($method, ...$args){
		$group	= self::get_called_name();
		$object	= wpjam_get_items_object($group);

		return call_user_func([$object, $method], ...$args);
	}

	protected static function get_instances(){
		return self::call_instance('get_items');
	}

	public static function instance_exists($name){
		return self::call_instance('get_item', $name) ?: false;
	}

	protected static function create_instance(...$args){
		return new static(...$args);
	}

	public static function add_instance($name, $instance){
		self::call_instance('add_item', $name, $instance);

		return $instance;
	}

	public static function delete_instance($name){
		self::call_instance('delete_item', $name);
	}

	public static function instance(...$args){
		$args	= wpjam_filter($args, 'isset');
		$name	= $args ? implode(':', $args) : 'singleton';

		return self::instance_exists($name) ?: self::add_instance($name, static::create_instance(...$args));
	}
}

abstract class WPJAM_Model implements ArrayAccess, IteratorAggregate{
	use WPJAM_Instance_Trait;

	protected $_id;
	protected $_data	= [];

	public function __construct($data=[], $id=null){
		if($id){
			$this->_id		= $id;
			$this->_data	= $data ? array_diff_assoc($data, static::get($id)) : [];
		}else{
			$key	= static::get_primary_key();
			$exist	= isset($data[$key]) ? static::get($data[$key]) : null;

			if($exist){
				$this->_id		= $data[$key];
				$this->_data	= array_diff_assoc($data, $exist);
			}else{
				$this->_data	= $data;
			}
		}
	}

	public function __get($key){
		$data	= $this->get_data();

		return array_key_exists($key, $data) ? $data[$key] : $this->meta_get($key);
	}

	public function __isset($key){
		return array_key_exists($key, $this->get_data()) || $this->meta_exists($key);
	}

	public function __set($key, $value){
		$this->set_data($key, $value);
	}

	public function __unset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		$data	= $this->get_data();

		return array_key_exists($key, $data);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->get_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->set_data($key, $value);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_data());
	}

	public function get_primary_id(){
		$key	= static::get_primary_key();

		return $this->get_data($key);
	}

	public function get_data($key=''){
		$data	= is_null($this->_id) ? [] : static::get($this->_id);
		$data	= array_merge($data, $this->_data);

		return $key ? ($data[$key] ?? null) : $data;
	}

	public function set_data($key, $value){
		if(!is_null($this->_id) && static::get_primary_key() == $key){
			trigger_error('不能修改主键的值');
		}else{
			$this->_data[$key]	= $value;
		}

		return $this;
	}

	public function unset_data($key){
		$this->_data[$key]	= null;
	}

	public function reset_data($key=''){
		if($key){
			unset($this->_data[$key]);
		}else{
			$this->_data	= [];
		}
	}

	public function to_array(){
		return $this->get_data();
	}

	public function is_deletable(){
		return true;
	}

	public function save($data=[]){
		$meta_type	= self::get_meta_type();
		$meta_input	= $meta_type ? wpjam_pull($data, 'meta_input') : null;

		$data	= array_merge($this->_data, $data);

		if($this->_id){
			$data	= array_except($data, static::get_primary_key());
			$result	= $data ? static::update($this->_id, $data) : false;
		}else{
			$result	= static::insert($data);

			if(!is_wp_error($result)){
				$this->_id	= $result;
			}
		}

		if(!is_wp_error($result)){
			if($this->_id && $meta_input){
				$this->meta_input($meta_input);
			}

			$this->reset_data();
		}

		return $result;
	}

	public function meta_get($key){
		return wpjam_get_metadata(self::get_meta_type(), $this->_id, $key);
	}

	public function meta_exists($key){
		return metadata_exists(self::get_meta_type(), $this->_id, $key);
	}

	public function meta_input(...$args){
		return wpjam_update_metadata(self::get_meta_type(), $this->_id, ...$args);
	}

	public static function find($id){
		return static::get_instance($id);
	}

	public static function get_instance($id){
		if($id){
			$object	= self::instance_exists($id);

			return $object ?: (static::get($id) ? static::add_instance($id, new static([], $id)) : null);
		}
	}

	public static function get_handler(){
		$handler	= WPJAM_Handler::get(self::get_called_name());

		if(!$handler && property_exists(get_called_class(), 'handler')){
			return static::$handler;
		}

		return $handler;
	}

	public static function set_handler($handler){
		return WPJAM_Handler::create(self::get_called_name(), $handler);
	}

	protected static function validate_data($data, $id=0){
		return true;
	}

	protected static function sanitize_data($data, $id=0){
		return $data;
	}

	protected static function before_delete($id){
		$object	= self::get_instance($id);

		return $object ? $object->is_deletable() : true;
	}

	public static function insert($data){
		$result	= static::validate_data($data);

		return is_wp_error($result) ? $result : static::insert_by_handler(static::sanitize_data($data));
	}

	public static function update($id, $data){
		$result	= static::validate_data($data, $id);

		return is_wp_error($result) ? $result : static::update_by_handler($id, static::sanitize_data($data, $id));
	}

	public static function delete($id){
		$result	= static::before_delete($id);

		return is_wp_error($result) ? $result : static::delete_by_handler($id);
	}

	public static function delete_multi($ids){
		foreach($ids as $id){
			$result	= static::before_delete($id);

			if(is_wp_error($result)){
				return $result;
			}
		}

		return static::delete_multi_by_handler($ids);
	}

	public static function insert_multi($data){
		foreach($data as &$item){
			$result	= static::validate_data($item);

			if(is_wp_error($result)){
				return $result;
			}

			$item	= static::sanitize_data($item);
		}

		return static::insert_multi_by_handler($data);
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'dismiss'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true, 'confirm'=>true,	'bulk'=>true],
		];
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['item_callback', 'render_item'])){
			return $args[0];
		}

		if(str_ends_with($method, '_by_handler')){
			$method	= wpjam_remove_postfix($method, '_by_handler');
		}

		return WPJAM_Handler::call(static::get_handler(), $method, ...$args);
	}
}

class WPJAM_Handler{
	public static function call($name, $method, ...$args){
		$handler	= is_object($name) ? $name : self::get($name);

		if(!$handler){
			return new WP_Error('undefined_handler');
		}

		if($handler instanceof WPJAM_DB){
			if(strtolower($method) == 'query'){
				if(!$args){
					return $handler;
				}
			}elseif($method == 'query_items'){
				if(is_array($args[0])){
					$method	= 'query';
					$args[]	= 'array';
				}
			}elseif(str_starts_with($method, 'cache_')){
				$method	.= '_force';
			}
		}

		if(in_array($method, [
			'get_primary_key',
			'get_meta_type',
			'get_searchable_fields',
			'get_filterable_fields'
		])){
			return $handler->{substr($method, 4)};
		}elseif(in_array($method, [
			'set_searchable_fields',
			'set_filterable_fields'
		])){
			return $handler->{substr($method, 4)}	= $args[0];
		}

		if(in_array($method, ['insert_multi', 'delete_multi'])){
			if(!method_exists($handler, $method)){
				$method	= wpjam_remove_postfix($method, '_multi');

				foreach($args[0] as $item){
					$result	= self::call($handler, $method, $item);

					if(is_wp_error($result)){
						return $result;
					}
				}

				return true;
			}
		}elseif($method == 'get_ids'){
			$method	= 'get_by_ids';
		}elseif($method == 'get_all'){
			$method	= 'get_results';
		}

		if(is_callable([$handler, $method])){
			return call_user_func_array([$handler, $method], $args);
		}

		return new WP_Error('undefined_method', [$method]);
	}

	public static function get($name, $args=null){
		if($name){
			if(is_array($name)){
				[$args, $name]	= [$name, md5(serialize($name))];
			}

			return wpjam_get_item('handler', $name) ?: ($args ? self::create($name, $args) : null);
		}
	}

	public static function create(...$args){
		if(count($args) >= 2){
			$name	= $args[0];
			$args	= $args[1];
		}else{
			$name	= null;
			$args	= $args[0];
		}

		if(is_object($args)){
			return self::add($name, $args);
		}

		if(!empty($args['items_model'])){
			return self::add($name, new WPJAM_Items($args));
		}

		$names	= ['option_items'=>'option_name', 'db'=>'table_name', 'cache_items'=>'cache_key', 'transient_items'=>'transient_key'];
		$type	= wpjam_pull($args, 'type');
		$key	= $type ? array_get($names, $type) : null;

		if($key){
			$type_name	= wpjam_pull($args, $key) ?: $name;

			return self::add($name, $args, $type_name, 'WPJAM_'.$type);
		}

		foreach($names as $type => $key){
			$type_name	= wpjam_pull($args, $key);

			if($type_name){
				$name	= $name ?: $type_name;

				return self::add($name, $args, $type_name, 'WPJAM_'.$type);
			}
		}
	}

	protected static function add($name, $args, $type_name='', $class=''){
		if($name){
			$handler	= is_object($args) ? $args : new $class($type_name, $args);

			wpjam_add_item('handler', $name, $handler);

			return $handler;
		}
	}
}

class WPJAM_DB extends WPJAM_Args{
	const OPERATORS	= [
		'not'		=> '!=',
		'lt'		=> '<',
		'lte'		=> '<=',
		'gt'		=> '>',
		'gte'		=> '>=',
		'in'		=> 'IN',
		'not_in'	=> 'NOT IN',
		'like'		=> 'LIKE',
		'not_like'	=> 'NOT LIKE',
	];

	protected $meta_query	= null;
	protected $query_vars	= [];
	protected $where		= [];

	public function __construct($table, $args=[]){
		$this->args	= wp_parse_args($args, [
			'table'			=> $table,
			'primary_key'	=> 'id',
			'cache'			=> true,
			'cache_group'	=> $table,
			'cache_time'	=> DAY_IN_SECONDS,
			'field_types'	=> [],
		]);

		foreach(['group_cache_key', 'lazyload_key', 'filterable_fields', 'searchable_fields'] as $key){
			$this->$key	= wpjam_array($this->$key);
		}

		if($this->cache_key	== $this->primary_key){
			$this->cache_key	= '';
		}elseif($this->cache_key){
			$this->group_cache_key	= [...$this->group_cache_key, $this->cache_key];
		}

		$this->clear();
	}

	public function __get($key){
		if(in_array($key, array_keys($this->query_vars))){
			return $this->query_vars[$key];
		}elseif(in_array($key, ['last_error', 'last_query', 'insert_id'])){
			return $GLOBALS['wpdb']->$key;
		}

		return parent::__get($key);
	}

	public function __call($method, $args){
		if(str_starts_with($method, 'where_')){
			$type	= wpjam_remove_prefix($method, 'where_');

			if(in_array($type, ['any', 'all'])){
				$data	= $args[0];
				$output	= $args[1] ?? 'object';

				if($data && is_array($data)){
					$where	= wpjam_map($data, fn($v, $k) => $this->where($k, $v, 'value'));
					$type	= $type == 'any' ? 'OR' : 'AND';
					$value	= $this->parse_where($where, $type);
				}else{
					$value	= '';
				}

				return $output == 'object' ? $this->where_fragment($value) : $value;
			}elseif($type == 'fragment'){
				if($args[0]){
					$this->where[] = ['compare'=>'fragment', 'fragment'=>' ( '.$args[0].' ) '];
				}
			}elseif(isset($args[1])){
				$compare	= self::OPERATORS[$type] ?? '';

				if($compare){
					$this->where[]	= ['column'=>$args[0], 'value'=>$args[1], 'compare'=>$compare];
				}
			}

			return $this;
		}elseif(in_array($method, array_keys($this->query_vars)) || in_array($method, ['search', 'order_by', 'group_by'])){
			$key	= $method;
			$value	= $args ? $args[0] : ($key == 'found_rows' ? true : null);

			if(!is_null($value)){
				if($key == 'order'){
					$value	= strcasecmp($value, 'ASC') ? 'DESC' : 'ASC';
				}elseif(in_array($key, ['limit', 'offset'])){
					$value	= (int)$value;
				}else{
					$key	= ['search'=>'search_term', 'order_by'=>'orderby', 'group_by'=>'groupby'][$key] ?? $key;
				}

				$this->query_vars[$key]	= $value;
			}

			return $this;
		}elseif(in_array($method, ['get_col', 'get_var', 'get_row'])){
			if($method != 'get_col'){
				$this->limit(1);
			}

			$field	= $args[0] ?? '';
			$args	= [$this->get_sql($field)];

			if($method == 'get_row'){
				$args[]	= ARRAY_A;
			}

			return call_user_func([$GLOBALS['wpdb'], $method], ...$args);
		}elseif(str_ends_with($method, '_by_db')){
			$method	= wpjam_remove_postfix($method, '_by_db');

			return call_user_func([$GLOBALS['wpdb'], $method], ...$args);
		}elseif(str_contains($method, '_meta')){
			$object	= wpjam_get_meta_type_object($this->meta_type);

			if($object){
				return call_user_func_array([$object, $method], $args);
			}
		}elseif(str_starts_with($method, 'cache_')){
			if(str_ends_with($method, '_force')){
				$method	= wpjam_remove_postfix($method, '_force');
			}else{
				if(!$this->cache){
					return false;
				}
			}

			if(!$this->cache_object){
				$global	= false;
				$group	= $this->cache_group;

				if(is_array($group)){
					[$group, $global]	= [$group[0], ($group[1] ?? false)];
				}

				$this->cache_object	= WPJAM_Cache::create([
					'group'		=> $group,
					'global'	=> $global,
					'prefix'	=> $this->cache_prefix,
					'time'		=> $this->cache_time
				]);
			}

			return call_user_func([$this->cache_object, $method], ...$args);
		}elseif(str_ends_with($method, '_last_changed')){
			$key	= 'last_changed';

			if($this->group_cache_key){
				$vars	= array_shift($args);

				if($vars && is_array($vars)){
					$vars	= wp_array_slice_assoc($vars, $this->group_cache_key);

					if($vars && count($vars) == 1 && !is_array(current($vars))){
						$key	.= ':'.array_key_first($vars).':'.current($vars);
					}
				}
			}

			if($method == 'get_last_changed'){
				$value	= $this->cache_get($key);

				if(!$value){
					$value	= microtime();

					$this->cache_set($key, $value);
				}

				return $value;
			}elseif($method == 'delete_last_changed'){
				$this->cache_delete($key);
			}
		}

		return new WP_Error('undefined_method', [$method]);
	}

	public function clear(){
		$this->where		= [];
		$this->meta_query	= null;
		$this->query_vars	= [
			'found_rows'	=> false,
			'limit'			=> 0,
			'offset'		=> 0,
			'orderby'		=> null,
			'order'			=> null,
			'groupby'		=> null,
			'having'		=> null,
			'search_term'	=> null,
		];
	}

	public function find_by($field, $value, $order='ASC', $method='get_results'){
		if(is_array($value)){
			$value	= array_map(fn($v) => $this->format($v, $field), $value);
			$value	= 'IN ('.implode(',', $value).')';
		}else{
			$value	= '= '.$this->format($value, $field);
		}

		$sql	= "SELECT * FROM `{$this->table}` WHERE `{$field}` {$value}";
		$sql	.= $order ? " ORDER BY `{$this->primary_key}` {$order}" : '';

		return call_user_func([$GLOBALS['wpdb'], $method], $sql, ARRAY_A);
	}

	public function find_one_by($field, $value, $order=''){
		return $this->find_by($field, $value, $order, 'get_row');
	}

	public function find_one($id){
		return $this->find_one_by($this->primary_key, $id);
	}

	public function get($id){
		$this->load_pending();

		if(!$id){
			return [];
		}

		$result	= $this->cache_get($id);

		if($result === false){
			$result	= $this->find_one($id);
			$time	= $result ? $this->cache_time : 60;

			$this->cache_set($id, $result, $time);
		}

		return $result;
	}

	public function get_one_by($field, $value, $order='ASC'){
		$items	= $this->get_by($field, $value, $order);

		return $items ? current($items) : [];
	}

	public function get_by($field, $value, $order='ASC'){
		if($field == $this->primary_key){
			return $this->get($value);
		}

		if($this->group_cache_key && in_array($field, $this->group_cache_key)){
			$this->load_pending($field, $order);

			return $this->query([$field=>$value, 'order'=>$order], 'items');
		}

		return $this->find_by($field, $value, $order);
	}

	public function get_by_values($field, $values, $order='ASC'){
		$values	= array_filter(array_unique($values));

		if(!$values){
			return [];
		}

		if($field == $this->primary_key){
			return $this->get_by_ids($values);
		}

		if(!$this->group_cache_key || !in_array($field, $this->group_cache_key)){
			return $this->find_by($field, $values, $order);
		}

		$data	= $ids = $uncache = [];

		foreach($values as $v){
			$result	= $this->query([$field=>$v, 'order'=>$order], 'cache');

			if($result[0] === false || !isset($result[0]['items'])){
				$uncache[$v]	= $result;
			}else{
				$data[$v]	= $result[0]['items'];
				$ids		= array_merge($ids, $data[$v]);
			}
		}

		if($uncache){
			$ids	= array_merge($ids, $this->query([
				$field.'__in'	=> array_keys($uncache),
				'order'			=> $order,
				'cache_results'	=> false
			], 'ids'));
		}

		$results	= array_values($this->get_by_ids($ids));
		$cache		= [];

		foreach($data as $v => $_ids){
			$data[$v]	= $_ids ? array_values($this->get_by_ids($_ids)) : [];
		}

		foreach($uncache as $v => $result){
			$data[$v]	= wp_list_filter($results, [$field => $v]) ?: [];

			$cache[$result[1]]	= [
				'data'			=>['items'=>array_column($data[$v], $this->primary_key)], 
				'last_changed'	=>$result[2]
			];
		}

		if($cache){
			$this->cache_set_multiple($cache);
		}

		return $data;
	}

	public function cache_delete_by($field, $value, $order='ASC'){
		trigger_error('123');
		if($this->group_cache_key && in_array($field, $this->group_cache_key)){
			foreach((array)$value as $v){
				$result	= $this->query([$field=>$v, 'order'=>$order], 'cache');
				trigger_error(var_export('cache_delete_by::'.$result[1], true));
				$this->cache_delete_force($result[1]);
			}
		}
	}

	public function update_caches($keys, $primary=false){
		if($primary || !$this->cache_key){
			return $this->get_by_ids($keys);
		}else{
			return $this->get_by_values($this->cache_key, $keys);
		}
	}

	protected function load_pending($field='', $order=''){
		if($this->pending_queue){
			$queue	= $this->pending_queue;
			$queue	= is_array($queue) ? $queue : [$this->primary_key => $queue];
			$field	= $field ?: $this->primary_key;

			if(isset($queue[$field])){
				$pending	= wpjam_pending_objects($queue[$field]);

				if(count($pending) > 1){
					if($field == $this->primary_key){
						$this->get_by_ids($pending);
					}else{
						$this->get_by_values($field, $pending, $order);
					}
				}
			}
		}
	}

	public function get_ids($ids){
		return $this->get_by_ids($ids);
	}

	public function get_by_ids($ids){
		$ids	= array_filter(array_unique($ids));

		if(!$ids){
			return [];
		}

		$data	= $this->cache_get_multiple($ids) ?: [];
		$data	= array_filter($data, 'is_array');
		$ids	= array_diff($ids, array_keys($data));

		if($ids){
			$results	= $this->find_by($this->primary_key, $ids);

			if($results){
				$results	= wpjam_array($results, fn($k, $v) => $v[$this->primary_key]);

				$this->cache_set_multiple($results);
			}

			foreach($ids as $id){
				if(isset($results[$id])){
					$data[$id]	= $results[$id];
				}else{
					$this->cache_set($id, [], 5);
				}
			}
		}

		if($data){
			$this->lazyload_meta(array_keys($data));

			wpjam_lazyload($this->lazyload_key, $data);
		}

		return $data;
	}

	public function get_clauses($fields=[]){
		$distinct	= '';
		$where		= '';
		$join		= '';
		$groupby	= $this->groupby ?: '';

		if($this->meta_query){
			$sql	= $this->meta_query->get_sql($this->meta_type, $this->table, $this->primary_key, $this);
			$where	= $sql['where'];
			$join	= $sql['join'];

			$groupby	= $groupby ?: $this->table.'.'.$this->primary_key;
			$fields		= $fields ?: $this->table.'.*';
		}

		if($fields){
			if(is_array($fields)){
				$fields	= '`'.implode( '`, `', $fields ).'`';
				$fields	= esc_sql($fields);
			}
		}else{
			$fields	= '*';
		}

		if($groupby){
			if(!str_contains($groupby, ',') && !str_contains($groupby, '(') && !str_contains($groupby, '.')){
				$groupby	= '`'.$groupby.'`';
			}

			$groupby	= ' GROUP BY '.$groupby;
		}

		$having		= $this->having ? ' HAVING '.$having : '';
		$orderby	= $this->orderby;

		if(is_null($orderby) && !$groupby && !$having){
			$orderby	= $this->get_arg('orderby') ?: $this->primary_key;
		}

		if($orderby){
			if(is_array($orderby)){
				$parsed		= wpjam_map($orderby, fn($v, $k) => $this->parse_orderby($k, $v));
				$parsed		= array_filter($parsed);
				$orderby	= $parsed ? implode(', ', $parsed) : '';
			}elseif(str_contains($orderby, ',') || (str_contains($orderby, '(') && str_contains($orderby, ')'))){
				$orderby	= $orderby;
			}else{
				$order		= $this->order ?: $this->get_arg('order');
				$orderby	= $this->parse_orderby($orderby, $order);
			}

			$orderby	= $orderby ? ' ORDER BY '.$orderby : '';
		}else{
			$orderby	= '';
		}

		$limits		= $this->limit ? ' LIMIT '.$this->limit : '';
		$limits		.= $this->offset ? ' OFFSET '.$this->offset : '';
		$found_rows	= ($limits && $this->found_rows) ? 'SQL_CALC_FOUND_ROWS' : '';
		$conditions	= $this->get_conditions();

		if(!$conditions && $where){
			$where	= 'WHERE 1=1 '.$where;
		}else{
			$where	= $conditions.$where;
			$where	= $where ? ' WHERE '.$where : '';
		}

		return compact('found_rows', 'distinct', 'fields', 'join', 'where', 'groupby', 'having', 'orderby', 'limits');
	}

	public function get_request($clauses=null){
		$clauses	= $clauses ?: $this->get_clauses();

		return sprintf("SELECT %s %s %s FROM `{$this->table}` %s %s %s %s %s %s", ...array_values($clauses));
	}

	public function get_sql($fields=[]){
		return $this->get_request($this->get_clauses($fields));
	}

	public function get_results($fields=[], $found_rows=null){
		$clauses	= $this->get_clauses($fields);
		$query_ids	= in_array($clauses['fields'], ['*', $this->table.'.*']);

		if($query_ids){
			$ids	= $this->query_ids($clauses);
		}else{
			$items	= $this->get_results_by_db($this->get_request($clauses), ARRAY_A);
		}

		if($found_rows){
			$total	= $this->find_total();
		}

		if($query_ids){
			$items	= array_values($this->get_by_ids($ids));
		}

		return $found_rows ? compact('items', 'total') : $items;
	}

	public function find($fields=[]){
		return $this->get_results($fields);
	}

	protected function query_ids($clauses){
		$clauses['fields']	= $this->table.'.'.$this->primary_key;

		return $this->get_col_by_db($this->get_request($clauses));
	}

	public function find_total(){
		return $this->get_var_by_db("SELECT FOUND_ROWS();");
	}

	protected function parse_orderby($orderby, $order){
		if($orderby == 'rand'){
			return 'RAND()';
		}elseif(preg_match('/RAND\(([0-9]+)\)/i', $orderby, $matches)){
			return sprintf('RAND(%s)', (int)$matches[1]);
		}elseif(str_ends_with($orderby, '__in')){
			return '';
			// $field	= str_replace('__in', '', $orderby);
		}

		$order	= (is_string($order) && 'ASC' === strtoupper($order)) ? 'ASC' : 'DESC';

		if($this->meta_query){
			$meta_clauses		= $this->meta_query->get_clauses();
			$primary_meta_query	= reset($meta_clauses);
			$primary_meta_key	= $primary_meta_query['key'] ?? '';

			if($orderby == $primary_meta_key || $orderby == 'meta_value'){
				if(!empty($primary_meta_query['type'])){
					return "CAST({$primary_meta_query['alias']}.meta_value AS {$primary_meta_query['cast']}) ".$order;
				}else{
					return "{$primary_meta_query['alias']}.meta_value ".$order;
				}
			}elseif($orderby == 'meta_value_num'){
				return "{$primary_meta_query['alias']}.meta_value+0 ".$order;
			}elseif(array_key_exists($orderby, $meta_clauses)){
				$meta_clause	= $meta_clauses[$orderby];

				return "CAST({$meta_clause['alias']}.meta_value AS {$meta_clause['cast']}) ".$order;
			}
		}

		if($orderby == 'meta_value_num' || $orderby == 'meta_value'){
			return '';
		}

		return '`'.$orderby.'` '.$order;
	}

	public function insert_multi($datas){	// 使用该方法，自增的情况可能无法无法删除缓存，请注意
		if(!$datas){
			return 0;
		}

		$datas	= array_filter(array_values($datas));

		$this->cache_delete_by_conditions([], $datas);

		$data		= reset($datas);
		$fields		= '`'.implode('`, `', array_keys($data)).'`';
		$updates	= implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_keys($data)));
		$values		= implode(', ', array_map([$this, 'format'], $datas));
		$sql		= "INSERT INTO `$this->table` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
		$result		= $this->query_by_db($sql);

		return (false === $result) ? new WP_Error('insert_error', $this->last_error) : $result;
	}

	public function insert($data){
		$this->cache_delete_by_conditions([], $data);

		$id	= $data[$this->primary_key] ?? null;

		if($id){
			$GLOBALS['wpdb']->check_current_query = false;

			$data		= wpjam_filter($data, 'isset', false);
			$fields		= implode(', ', array_keys($data));
			$updates	= implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_keys($data)));
			$values		= $this->format($data);
			$sql		= "INSERT INTO `$this->table` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
			$result		= $this->query_by_db($sql);
		}else{
			$result		= $this->insert_by_db($this->table, $data, $this->get_format($data));
		}

		if($result === false){
			return new WP_Error('insert_error', $this->last_error);
		}

		$id	= $id ?: $this->insert_id;

		$this->cache_delete($id);

		return $id;
	}

	/*
	update($id, $data);
	update($data, $where);
	update($data); // $where各种 参数通过 where() 方法事先传递
	*/
	public function update(...$args){
		if(count($args) == 2){
			if(is_array($args[0])){
				$data	= $args[0];
				$where	= $args[1];

				$conditions	= $this->where_all($where, 'fragment');
			}else{
				$id		= $args[0];
				$data	= $args[1];
				$where	= $conditions = [$this->primary_key => $id];

				$this->cache_delete($id);
			}

			$this->cache_delete_by_conditions($conditions, $data);

			$result	= $this->update_by_db($this->table, $data, $where, $this->get_format($data), $this->get_format($where));

			return $result === false ? new WP_Error('update_error', $this->last_error) : $result;
		}elseif(count($args) == 1){	// 如果为空，则需要事先通过各种 where 方法传递进去
			$data	= $args[0];
			$where	= $this->get_conditions();

			if($data && $where){
				$this->cache_delete_by_conditions($where, $data);

				$fields	= implode(', ', wpjam_map($data, fn($v, $k) => "`$k` = ".(is_null($v) ? 'NULL' : $this->format($v, $k))));

				return $this->query_by_db("UPDATE `{$this->table}` SET {$fields} WHERE {$where}");
			}

			return 0;
		}
	}

	/*
	delete($where);
	delete($id);
	delete(); // $where 参数通过各种 where() 方法事先传递
	*/
	public function delete($where = ''){
		$id	= null;

		if($where){	// 如果传递进来字符串或者数字，认为根据主键删除，否则传递进来数组，使用 wpdb 默认方式
			if(is_array($where)){
				$this->cache_delete_by_conditions($this->where_all($where, 'fragment'));
			}else{
				$id		= $where;
				$where	= [$this->primary_key => $id];

				$this->cache_delete($id);
				$this->cache_delete_by_conditions($where);
			}

			$result	= $this->delete_by_db($this->table, $where, $this->get_format($where));
		}else{	// 如果为空，则 $where 参数通过各种 where() 方法事先传递
			$where	= $this->get_conditions();

			if(!$where){
				return 0;
			}

			$this->cache_delete_by_conditions($where);

			$result = $this->query_by_db("DELETE FROM `{$this->table}` WHERE {$where}");
		}

		if(false === $result){
			return new WP_Error('delete_error', $this->last_error);
		}

		if($id){
			$this->delete_meta_by_id($id);
		}else{
			$this->delete_orphan_meta($this->table, $this->primary_key);
		}

		return $result;
	}

	public function delete_by($field, $value){
		return $this->delete([$field => $value]);
	}

	public function delete_multi($ids){
		if(!$ids){
			return 0;
		}

		$this->cache_delete_by_conditions([$this->primary_key => $ids]);

		array_walk($ids, [$this, 'cache_delete']);

		$values	= array_map(fn($id) => $this->format($id, $this->primary_key), $ids);
		$where	= 'WHERE `'.$this->primary_key.'` IN ('.implode(',', $values).') ';
		$sql	= "DELETE FROM `{$this->table}` {$where}";
		$result = $this->query_by_db($sql);

		if(false === $result ){
			return new WP_Error('delete_error', $this->last_error);
		}

		return $result ;
	}

	protected function cache_delete_by_conditions($conditions, $data=[]){
		$this->delete_last_changed();

		if($this->cache || $this->group_cache_key){
			if($data){
				$conditions	= $conditions ? (array)$conditions : [];
				$datas		= wp_is_numeric_array($data) ? $data : [$data];

				foreach($datas as $data){
					$key	= $this->primary_key;

					if(!empty($data[$key])){
						$this->cache_delete($data[$key]);

						$conditions[$key]	= isset($conditions[$key]) ? (array)$conditions[$key] : [];
						$conditions[$key][]	= $data[$key];
					}

					foreach($this->group_cache_key as $key){
						if(isset($data[$key])){
							$this->delete_last_changed([$key => $data[$key]]);
						}
					}
				}
			}

			if(is_array($conditions)){
				if(!$this->group_cache_key && count($conditions) == 1 && isset($conditions[$this->primary_key])){
					$conditions	= [];
				}

				$conditions	= $conditions ? $this->where_any($conditions, 'fragment') : null;
			}

			if($conditions){
				$fields		= [$this->primary_key, ...$this->group_cache_key];
				$fields		= implode(', ', $fields);
				$results	= $this->get_results_by_db("SELECT {$fields} FROM `{$this->table}` WHERE {$conditions}", ARRAY_A);

				if($results){
					$this->cache_delete_multiple(array_column($results, $this->primary_key));

					foreach($this->group_cache_key as $group_cache_key){
						$values	= array_unique(array_column($results, $group_cache_key));

						foreach($values as $value){
							$this->delete_last_changed([$group_cache_key => $value]);
						}
					}
				}
			}
		}
	}

	protected function get_conditions(){
		$where	= $this->parse_where($this->where, 'AND');
		$fields	= $this->searchable_fields;

		if($fields && $this->search_term){
			$search	= array_map(fn($k) => "`{$k}` LIKE '%".$this->esc_like_by_db($this->search_term)."%'", $fields);
			$where	.= ($where ? ' AND ' : '').'('.implode(' OR ', $search).')';
		}

		$this->clear();

		return $where;
	}

	public function get_wheres(){	// 以后放弃，目前统计在用
		return $this->get_conditions();
	}

	protected function format($value, $column=''){
		if(is_array($value)){
			$format	= '('.implode(', ', $this->get_format($value)).')';
			$value	= array_values($value);
		}else{
			$format	= str_contains($column, '%') ? $column : $this->get_format($column);
		}

		return $this->prepare_by_db($format, $value);
	}

	protected function get_format($column){
		if(is_array($column)){
			return array_map([$this, 'get_format'], array_keys($column));
		}else{
			return $this->field_types[$column] ?? '%s';
		}
	}

	protected function parse_where($qs=null, $type=''){
		$where	= [];
		$qs		??= $this->where;

		foreach($qs as $q){
			if(!$q || empty($q['compare'])){
				continue;
			}

			$compare	= strtoupper($q['compare']);

			if($compare == strtoupper('fragment')){
				$where[]	= $q['fragment'];

				continue;
			}

			$value	= $q['value'];
			$column	= $q['column'];

			if(in_array($compare, ['IN', 'NOT IN'])){
				$value	= is_array($value) ? $value : explode(',', $value);
				$value	= array_values(array_unique($value));
				$value	= array_map(fn($v) => $this->format($v, $column), $value);

				if(count($value) > 1){
					$value		= '('.implode(',', $value).')';
				}else{
					$compare	= $compare == 'IN' ? '=' : '!=';
					$value		= $value ? current($value) : '\'\'';
				}
			}elseif(in_array($compare, ['LIKE', 'NOT LIKE'])){
				$left	= str_starts_with($value, '%');
				$right	= str_ends_with($value, '%');
				$value	= trim($value, '%');
				$value	= ($left ? '%' : '').$this->esc_like_by_db($value).($right ? '%' : '');
				$value	= $this->format($value, '%s');
			}else{
				$value	= $this->format($value, $column);
			}

			if(!str_contains($column, '(')){
				$column	= '`'.$column.'`';
			}

			$where[]	= $column.' '.$compare.' '.$value;
		}

		return $type ? implode(' '.$type.' ', $where) : $where;
	}

	public function where($column, $value, $output='object'){
		if(is_array($value)){
			if(wp_is_numeric_array($value)){
				$value	= ['value'=>$value];
			}

			if(!isset($value['value'])){
				$value	= [];
			}else{
				if(is_numeric($column) || is_null($column)){
					if(!isset($value['column'])){
						$value = [];
					}
				}else{
					$value['column']	= $column;
				}

				if($value && (!isset($value['compare']) || !in_array(strtoupper($value['compare']), self::OPERATORS))){
					$value['compare']	= is_array($value['value']) ? 'IN' : '=';
				}
			}
		}else{
			if(is_null($value)){
				$value	= [];
			}else{
				if(is_numeric($column) || is_null($column)){
					$value	= ['compare'=>'fragment', 'fragment'=>'( '.$value.' )'];
				}else{
					$value	= ['compare'=>'=', 'column'=>$column, 'value'=>$value];
				}
			}
		}

		if($output != 'object'){
			return $value;
		}else{
			$this->where[]	= $value;

			return $this;
		}
	}

	public function query_items($limit, $offset){
		$this->limit($limit)->offset($offset)->found_rows();

		foreach(['orderby', 'order'] as $key){
			if(is_null($this->$key)){
				call_user_func([$this, $key], wpjam_get_data_parameter($key));
			}
		}

		if(is_null($this->search_term)){
			$this->search(wpjam_get_data_parameter('s'));
		}

		foreach($this->filterable_fields as $key){
			$this->where($key, wpjam_get_data_parameter($key));
		}

		return $this->get_results([], true);
	}

	public function query($query_vars, $output='object'){
		if(in_array($output, ['cache', 'items', 'ids'])){
			$query_vars['no_found_rows']	= true;

			$suppress_filters	= true;
		}else{
			$suppress_filters	= $query_vars['suppress_filters'] ?? false;
			$query_vars			= apply_filters('wpjam_query_vars', $query_vars, $this);

			if(isset($query_vars['groupby'])){
				$query_vars	= array_except($query_vars, ['first', 'cursor']);

				$query_vars['no_found_rows']	= true;
			}else{
				if(!isset($query_vars['number']) && empty($query_vars['no_found_rows'])){
					$query_vars['number']	= 50;
				}
			}
		}

		$qv				= $query_vars;
		$orderby		= $qv['orderby'] ?? $this->primary_key;
		$found_rows		= !wpjam_pull($qv, 'no_found_rows');
		$cache_results	= wpjam_pull($qv, 'cache_results', true);
		$fields			= wpjam_pull($qv, 'fields');

		if($this->meta_type){
			$meta_query	= wpjam_pull($qv, [
				'meta_key',
				'meta_value',
				'meta_compare',
				'meta_compare_key',
				'meta_type',
				'meta_type_key',
				'meta_query'
			]);

			if($meta_query){
				$this->meta_query	= new WP_Meta_Query();
				$this->meta_query->parse_query_vars($meta_query);
			}
		}

		foreach($qv as $key => $value){
			if(is_null($value)){
				continue;
			}

			if($key == 'number'){
				if($value == -1){
					$found_rows	= false;
				}else{
					$this->limit($value);
				}
			}elseif($key == 'offset'){
				$this->offset($value);
			}elseif($key == 'orderby'){
				$value	= is_array($value) ? wpjam_array($value, 'esc_sql') : esc_sql($value);

				$this->orderby($value);
			}elseif($key == 'order'){
				$this->order($value);
			}elseif($key == 'groupby'){
				$this->groupby(esc_sql($value));
			}elseif($key == 'cursor'){
				if($value > 0){
					$this->where_lt($orderby, $value);
				}
			}elseif($key == 'search' || $key == 's'){
				$this->search($value);
			}else{
				if(str_contains($key, '__')){
					foreach(self::OPERATORS as $operator => $compare){
						if(str_ends_with($key, '__'.$operator)){
							$key	= wpjam_remove_postfix($key, '__'.$operator);
							$value	= ['value'=>$value, 'compare'=>$compare];

							break;
						}
					}
				}

				$this->where($key, $value);
			}
		}

		if($found_rows){
			$this->found_rows(true);
		}

		$clauses	= $this->get_clauses($fields);

		if(!$suppress_filters){
			$clauses	= apply_filters_ref_array('wpjam_clauses', [$clauses, &$this]);
		}

		$request	= $this->get_request($clauses);

		if(!$suppress_filters){
			$request	= apply_filters_ref_array('wpjam_request', [$this->get_request($clauses), &$this]);
		}

		if($cache_results){
			if(str_contains(strtoupper($orderby), ' RAND(') || !in_array($clauses['fields'], ['*', $this->table.'.*'])){
				$cache_results	= false;
			}
		}

		$result	= $cache_key = false;

		if($cache_results){
			$last_changed	= $this->get_last_changed($query_vars);
			$cache_key		= md5(serialize($query_vars).$request);
			$result			= $this->cache_get_force($cache_key);

			if($result && is_array($result) && isset($result['last_changed']) && $result['last_changed'] == $last_changed){
				$result	= $result['data'];
			}else{
				$result	= false;
			}
		}

		if($output == 'cache'){
			return [$result, $cache_key, $last_changed];
		}

		if($result === false || !isset($result['items'])){
			if($cache_results || $output == 'ids'){
				$result	= ['items'=>$this->query_ids($clauses)];
			}else{
				$result	= ['items'=>$this->get_results_by_db($request, ARRAY_A)];
			}

			if($found_rows){
				$result['total']	= $this->find_total();
			}

			if($cache_results){
				$this->cache_set_force($cache_key, ['data'=>$result, 'last_changed'=>$last_changed], DAY_IN_SECONDS);
			}
		}

		if($output == 'ids'){
			return $result['items'];
		}

		if($cache_results){
			$result['items']	= array_values($this->get_by_ids($result['items']));
		}

		if($output == 'items'){
			return $result['items'];
		}

		if($found_rows){
			$result['next_cursor']	= 0;

			$number	= $qv['number'] ?? null;

			if($number && $number != -1){
				$result['max_num_pages']	= ceil($result['total'] / $number);

				if($result['items'] && $result['max_num_pages'] > 1){
					$result['next_cursor']	= (int)(end($result['items'])[$orderby]);
				}
			}
		}else{
			$result['total']	= count($result['items']);
		}

		$result['datas'] 		= $result['items'];	// 兼容
		$result['found_rows']	= $result['total'];	// 兼容
		$result['request']		= $request;

		return $output == 'object' ? (object)$result : $result;
	}
}

class WPJAM_Items extends WPJAM_Args{
	use WPJAM_Instance_Trait;

	public function __construct($args=[]){
		$this->args = wp_parse_args($args, [
			'item_type'		=> 'array',
			'primary_key'	=> 'id',
			'primary_title'	=> 'ID'
		]);

		if($this->item_type == 'array'){
			$this->lazyload_key	= wpjam_array($this->lazyload_key);
		}else{
			$this->primary_key	= null;
		}
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_items')){
			if($this->items_model && is_callable([$this->items_model, $method])){
				return call_user_func_array([$this->items_model, $method], $args);
			}

			if($method == 'get_items'){
				return [];
			}elseif($method == 'delete_items'){
				return $this->update_items([]);
			}else{
				return true;
			}
		}elseif(in_array($method, [
			'insert',
			'add',
			'update',
			'replace',
			'set',
			'delete',
			'remove',
			'empty',
			'move',
			'increment',
			'decrement'
		])){
			$retry	= $this->retry_times ?: 1;

			try{
				do{
					$retry	-= 1;
					$result	= $this->retry($method, ...$args);
				}while($result === false && $retry > 0);

				return $result;
			}catch(WPJAM_Exception $e){
				return $e->get_wp_error();
			}
		}
	}

	protected function retry($method, ...$args){
		$type	= $this->item_type;
		$items	= $this->get_items();

		if($type == 'array'){
			$items	= $items ?: [];
		}

		if($method == 'move'){
			$ids	= wpjam_try('wpjam_move', array_keys($items), ...$args);
			$items	= wp_array_slice_assoc($items, $ids);

			return $this->update_items($items);
		}

		$id		= ($method == 'insert' || ($method == 'add' && count($args) <= 1)) ? null : array_shift($args);
		$item	= array_shift($args);

		if(in_array($method, ['increment', 'decrement'])){
			if($type == 'array'){
				return;
			}

			$item	= $method == 'decrement' ? 0 - ($item ?: 1) : ($item ?: 1);
			$method	= 'increment';
		}elseif($method == 'replace'){
			$method	= 'update';
		}elseif($method == 'remove'){
			$method	= 'delete';
		}

		if(isset($id)){
			if(isset($items[$id])){
				if($method == 'add'){
					$this->exception($this->primary_title.'-「'.$id.'」已存在', 'duplicate_'.$this->primary_key);
				}
			}else{
				if(in_array($method, ['update', 'delete'])){
					$this->exception($this->primary_title.'-「'.$id.'」不存在', 'invalid_'.$this->primary_key);
				}elseif($method == 'set'){
					$method == 'add';	// set => add
				}
			}
		}

		if(in_array($method, ['add', 'insert']) && $this->max_items && count($items) >= $this->max_items){
			$this->exception('最大允许数量：'.$this->max_items, 'over_max_items');
		}

		if($type == 'array' && isset($item)){
			if(in_array($this->primary_key, ['option_key', 'id'])){
				if($this->unique_key){
					$title	= $this->unique_title ?: $this->unique_key;
					$value	= $item[$this->unique_key] ?? null;

					if(is_null($id) || isset($value)){
						if(!$value){
							$this->exception($title.'不能为空', 'empty_'.$this->unique_key);
						}

						foreach($items as $_id => $_item){
							if(isset($id) && $id == $_id){
								continue;
							}

							if($_item[$this->unique_key] == $value){
								$this->exception($title.'不能重复', 'duplicate_'.$this->unique_key);
							}
						}
					}
				}

				if($method == 'insert' || ($method == 'add' && is_null($id))){
					if($items){
						$ids	= array_map(fn($id) => (int)str_replace('option_key_', '', $id), array_keys($items));
						$id		= max($ids)+1;
					}else{
						$id		= 1;
					}

					$id	= $this->primary_key == 'option_key' ? 'option_key_'.$id : $id;
				}

				if(isset($id)){
					$item[$this->primary_key] = $id;
				}
			}else{
				if(is_null($id)){
					$id	= $item[$this->primary_key] ?? null;

					if(!$id){
						$this->exception($this->primary_title.'不能为空', 'empty_'.$this->primary_key);
					}

					if(isset($items[$id])){
						$this->exception($this->primary_title.'不能重复', 'duplicate_'.$this->primary_key);
					}
				}
			}

			$item	= wpjam_filter($item, 'isset');
		}

		if($method == 'insert'){
			if($type == 'array'){
				if($this->last){
					$items[$id]	= $item;
				}else{
					$items		= [$id=>$item]+$items;
				}
			}else{
				if($this->last){
					$items[]	= $item;
				}else{
					array_unshift($items, $item);
				}
			}
		}elseif($method == 'add'){
			if(isset($id)){
				$items[$id]	= $item;
			}else{
				$items[]	= $item;
			}
		}elseif($method == 'update'){
			if($type == 'array'){
				$item	= wp_parse_args($item, $items[$id]);
			}

			$items[$id]	= $item;
		}elseif($method == 'set'){
			$items[$id]	= $item;
		}elseif($method == 'empty'){
			$prev	= $items;
			$items	= [];
		}elseif($method == 'delete'){
			$items	= array_except($items, $id);
		}elseif($method == 'increment'){
			if(isset($items[$id])){
				$item	= (int)$items[$id] + $item;
			}

			$items[$id] = $item;
		}

		if($type == 'array' && $items && is_array($items) && in_array($this->primary_key, ['option_key','id'])){
			foreach($items as &$item){
				$item	= array_except($item, $this->primary_key);

				if($this->parent_key){
					$item	= array_except($item, $this->parent_key);
				}
			}
		}

		$result	= $this->update_items($items);

		if($result){
			if($method == 'insert'){
				if($type == 'array'){
					return ['id'=>$id,	'last'=>(bool)$this->last];
				}
			}elseif($method == 'empty'){
				return $prev;
			}elseif($method == 'increment'){
				return $item;
			}
		}

		return $result;
	}

	public function query_items($args){
		$items	= $this->parse_items();

		return ['items'=>$items, 'total'=>count($items)];
	}

	public function parse_items($items=null){
		$items	??= $this->get_items();

		if($items && is_array($items)){
			foreach($items as $id => &$item){
				$item	= $this->parse_item($item, $id);
			}

			if($this->item_type == 'array'){
				wpjam_lazyload($this->lazyload_key, $items);
			}

			return $items;
		}

		return [];
	}

	public function parse_item($item, $id){
		if($this->item_type == 'array'){
			$item	= is_array($item) ? $item : [];

			return array_merge($item, [$this->primary_key => $id]);
		}

		return $item;
	}

	public function get_results(){
		return $this->parse_items();
	}

	public function reset(){
		return $this->delete_items();
	}

	public function exists($value, $type='unique'){
		$items	= $this->get_items();

		if($items){
			if($this->item_type == 'array'){
				if($type == 'unique'){
					return in_array($value, array_column($items, $this->unique_key));
				}else{
					return isset($items[$value]);
				}
			}else{
				return in_array($value, $items);
			}
		}

		return false;
	}

	public function get($id){
		$items	= $this->get_items();
		$item	= $items[$id] ?? false;

		return $item ? $this->parse_item($item, $id) : false;
	}	
}

class WPJAM_Option_Items extends WPJAM_Items{
	public function __construct($option_name, $args=[]){
		if(is_array($args)){
			if(empty($args['items_field'])){
				$args	= wp_parse_args($args, ['primary_key'=>'option_key']);
			}
		}else{
			$args	= ['primary_key'=>$args];
		}

		parent::__construct(array_merge($args, ['option_name'=>$option_name]));
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_items')){
			$action	= wpjam_remove_postfix($method, '_items');

			if($this->items_field){
				$callback	= 'wpjam_'.$action.'_setting';
				$args		= [$this->items_field, ...$args];
			}else{
				$callback	= $action.'_option';
			}

			$result	= call_user_func($callback, $this->option_name, ...$args);

			return (!$result && $action == 'get') ? [] : $result;
		}elseif(str_contains($method, '_setting')){
			return call_user_func('wpjam_'.$method, $this->option_name, ...$args);
		}

		return parent::__call($method, $args);
	}

	public static function get_instance(){
		$r	= new ReflectionMethod(get_called_class(), '__construct');

		return $r->getNumberOfParameters() ? null : static::instance();
	}
}

class WPJAM_Meta_Items extends WPJAM_Items{
	public function __construct($meta_type, $object_id, $meta_key, $args=[]){
		parent::__construct(array_merge($args, [
			'meta_type'		=> $meta_type,
			'object_id'		=> $object_id,
			'meta_key'		=> $meta_key,
			'parent_key'	=> $meta_type.'_id',
		]));
	}

	public function __call($method, $args){
		if(in_array($method, ['get_items', 'update_items'])){
			$action	= wpjam_remove_postfix($method, '_items');
			$args	= $action == 'get' ? [true] : [array_shift($args), $this->get_items()];
			$result	= call_user_func($action.'_metadata', $this->meta_type, $this->object_id, $this->meta_key, ...$args);

			return $result ?: ($action == 'get' ? [] : false);
		}

		return parent::__call($method, $args);
	}
}

class WPJAM_Content_Items extends WPJAM_Items{
	public function __construct($post_id, $args=[]){
		parent::__construct(array_merge($args, ['post_id'=>$post_id, 'parent_key'=>'post_id']));
	}

	public function __call($method, $args){
		if($method == 'get_items'){
			$post	= get_post($this->post_id);
			$items	= $post ? $post->post_content : '';

			return $items ? unserialize($items) : [];
		}elseif($method == 'update_items'){
			$items	= array_shift($args);
			$items	= $items ? serialize($items) : '';
		
			return WPJAM_Post::update($this->post_id, ['post_content'=>$items]);
		}

		return parent::__call($method, $args);
	}
}

class WPJAM_Cache_Items extends WPJAM_Items{
	public function __construct($key, $args=[]){
		parent::__construct(wp_parse_args($args, [
			'item_type'		=> '',
			'retry_times'	=> 10,
			'key'			=> $key,
			'group'			=> 'list_cache',
		]));
	}

	public function __call($method, $args){
		if(in_array($method, ['get_items', 'update_items'])){
			$this->cache	??= WPJAM_Cache::create($this->get_args());
			$items			= $this->cache->get_with_cas($this->key, $token);

			if(!is_array($items)){
				$this->cache->set($this->key, []);

				$items	= $this->cache->get_with_cas($this->key, $token);
			}

			return $method == 'get_items' ? $items : $this->cache->cas($token, $this->key, array_shift($args));
		}

		return parent::__call($method, $args);
	}
}

class WPJAM_Transient_Items extends WPJAM_Items{
	public function __construct($key, $args=[]){
		parent::__construct(wp_parse_args($args, [
			'item_type'	=> '',
			'key'		=> $key,
		]));
	}

	public function __call($method, $args){
		if($method == 'get_items'){
			return get_transient($this->key) ?: [];
		}elseif($method == 'update_items'){
			return set_transient($this->key, array_shift($args), $this->expiration ?: DAY_IN_SECONDS);
		}

		return parent::__call($method, $args);
	}
}