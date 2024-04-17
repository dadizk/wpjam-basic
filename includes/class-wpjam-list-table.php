<?php
if(!class_exists('WP_List_Table')){
	include ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

class WPJAM_List_Table extends WP_List_Table{
	use WPJAM_Call_Trait;

	public function __construct($args=[]){
		$this->_args	= $args	= wp_parse_args($args, [
			'title'			=> '',
			'plural'		=> '',
			'singular'		=> '',
			'data_type'		=> 'model',
			'capability'	=> 'manage_options',
			'per_page'		=> 50
		]);

		$key	= $this->get_primary_key_by_model();

		if($key){
			$args['primary_key']	= $key;
		}else{
			$args['primary_key']	??= 'id';
		}

		$GLOBALS['wpjam_list_table']	= $this;

		parent::__construct($this->parse_args($args));
	}

	public function __get($name){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name;
		}

		return $this->_args[$name] ?? null;
	}

	public function __set($name, $value){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name	= $value;
		}

		return $this->_args[$name]	= $value;
	}

	public function __isset($name){
		return $this->$name !== null;
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_by_model')){
			$method	= wpjam_remove_postfix($method, '_by_model');

			if(method_exists($this->model, $method)){
				if($method == 'value_callback'){
					return wpjam_value_callback([$this->model, $method], ...$args);
				}

				if($method == 'query_items' && count(wpjam_get_callback_parameters([$this->model, $method])) >= 2){
					$args	= [$args[0]['number'], $args[0]['offset']];
				}

				return wpjam_catch([$this->model, $method], ...$args);
			}
			
			$fallback	= ['render_item'=>'item_callback', 'get_views'=>'views'][$method] ?? null;

			if($fallback && method_exists($this->model, $fallback)){
				return wpjam_catch([$this->model, $fallback], ...$args);
			}

			if($method == 'get_actions'){
				return $this->_builtin ? [] : WPJAM_Model::get_actions();
			}elseif($method == 'value_callback'){
				return $args[2];
			}elseif(in_array($method, [
				'render_item',
				'render_date'
			])){
				return $args[0];
			}elseif(in_array($method, [
				'column',
				'get_fields',
				'get_views',
				'get_subtitle',
				'extra_tablenav',
				'before_single_row',
				'after_single_row',
				'col_left'
			])){
				return;
			}

			if(method_exists($this->model, '__callStatic')){
				$result	= wpjam_catch([$this->model, $method], ...$args);
			}else{
				$result	= new WP_Error('undefined_method', [$this->model.'->'.$method.'()']);
			}

			if(!is_wp_error($result) || !in_array($method, [
				'get_filterable_fields',
				'get_searchable_fields',
				'get_primary_key',
			])){
				return $result;
			}
		}

		return parent::__call($method, $args);
	}

	protected function parse_args($args){
		$this->screen	= $args['screen'] = get_current_screen();
		$this->_args	= array_merge(($this->_args ?? []), $args);

		add_screen_option('list_table', $this);

		if(wp_doing_ajax() && wpjam_get_post_parameter('action_type') == 'query_items'){
			$_REQUEST	= array_merge($_REQUEST, wpjam_get_data_parameter());	// 兼容
		}

		$style		= [$this->style];
		$sortable	= $this->sortable ?: [];
		$data_type	= $this->data_type;
		$meta_type	= get_screen_option('meta_type');
		$option		= get_screen_option('sortable') ?: [];

		if($sortable || $option){
			$sortable	= is_array($sortable) ? $sortable : ['items'=>' >tr'];
			$sortable	= array_merge($sortable, $option);
			$action		= wpjam_pull($sortable, 'action') ?: [];

			$this->register('move',	$action+['direct'=>true,	'page_title'=>'拖动',	'dashicon'=>'move']);
			$this->register('up',	$action+['direct'=>true,	'page_title'=>'向上移动',	'dashicon'=>'arrow-up-alt']);
			$this->register('down',	$action+['direct'=>true,	'page_title'=>'向下移动',	'dashicon'=>'arrow-down-alt']);

			$args['sortable'] = $sortable;
		}

		foreach(($this->actions ?? $this->get_actions_by_model()) as $key => $action){
			$this->register($key, $action+['order'=>10.5]);
		}

		if($meta_type){
			$m_args	= ['list_table'=>true];

			if($data_type && in_array($data_type, ['post_type', 'taxonomy'])){
				$m_args[$data_type]	= $args[$data_type] ?? '';
			}

			foreach(wpjam_get_meta_options($meta_type, $m_args) as $name => $option){
				$key	= $option->action_name ?: 'set_'.$name;

				if(!$this->get_object($key)){
					$this->register($key, $option->parse_list_table_args());
				}
			}
		}

		foreach(WPJAM_Fields::flatten($this->get_fields_by_model() ?: []) as $key => $field){
			if(!empty($field['show_admin_column'])){
				$field	= wpjam_strip_data_type($field);
				$field	= wp_parse_args(array_except($field, 'style'), ['order'=>10.5]);

				if(!isset($field['filterable'])){
					$filterable	= $this->get_filterable_fields_by_model();

					$field['filterable']	= $filterable && in_array($key, $filterable);
				}

				$this->register($key, $field, 'column');
			}
		}

		foreach($this->get_objects('action') as $object){
			$key	= $object->name;

			if($object->overall){
				$args['overall_actions'][]	= $key;
			}else{
				if($object->bulk && $object->is_allowed()){
					$args['bulk_actions'][$key]	= $object;
				}

				if($object->next && $object->response == 'form'){
					$args['next_actions'][$key]	= $object->next;
				}

				if($key == 'add'){
					if($this->layout == 'left'){
						$args['overall_actions'][]	= $key;
					}
				}else{
					if($object->row_action){
						$args['row_actions'][$key]	= $key;
					}
				}
			}
		}

		foreach($this->get_objects('column') as $object){
			$key		= $object->name;
			$style[]	= $object->get_style();

			$args['columns'][$key]	= $object->column_title ?? $object->title;

			if($object->sortable_column){
				$args['sortable_columns'][$key] = [$key, true];
			}

			if($object->sticky_column){
				$args['sticky_columns'][]	= $key;
			}
		}

		if(!$this->_builtin && !empty($args['bulk_actions'])){
			$args['columns']	= ['cb'=>true]+($args['columns'] ?? []);
		}

		wp_add_inline_style('list-tables', implode("\n", array_filter($style)));

		add_shortcode('filter',		fn($attrs, $title) => $this->get_filter_link($attrs, $title, wpjam_pull($attrs, 'class')));
		add_shortcode('row_action',	fn($attrs, $title) => $this->get_row_action(wpjam_pull($attrs, 'name'), $attrs, $title));

		if(!empty($args['sticky_columns'])){
			add_filter('wpjam_html', fn($html) => preg_replace('/(class=["\'][^\'"]*)(\b('.implode('|', array_map(fn($column) => 'column-'.preg_quote($column), $this->sticky_columns)).'|check-column)\b)/', '$1$2 sticky-column', $html));
		}

		return $args;
	}

	protected function parse_query_vars($vars){
		$fields	= $this->get_filterable_fields_by_model() ?: [];
		$fields	= ['orderby', 'order', 's', ...$fields];

		return wpjam_filter(array_merge($vars, wpjam_get_data_parameter($fields)), 'isset', false);
	}

	protected function do_shortcode($content, $id){
		return wpjam_do_shortcode(str_replace('[row_action ', '[row_action id="'.$id.'" ', $content), ['filter', 'row_action']);
	}

	protected function get_objects($type='action'){
		$args		= [];
		$data_type	= $this->data_type;

		if($data_type){
			$args['data_type']	= ['value'=>$data_type, 'if_null'=>true, 'callable'=>true];

			if(in_array($data_type, ['post_type', 'taxonomy'])){
				$args[$data_type]	= ['value'=>$this->$data_type, 'if_null'=>true, 'callable'=>true];
			}
		}

		return wpjam_call([$this->get_class($type), 'get_registereds'], $args);
	}

	protected function get_object($name, $type='action'){
		$objects	= $this->get_objects($type);

		if(isset($objects[$name])){
			return $objects[$name];
		}

		$objects	= wp_filter_object_list($objects, ['name'=>$name]);

		if(count($objects) == 1){
			return current($objects);
		}
	}

	public function get_setting(){
		return [
			'layout'			=> $this->layout,
			'left_key'			=> $this->left_key,
			'sortable'			=> $this->sortable,
			'bulk_actions'		=> $this->bulk_actions,
			'sticky_columns'	=> $this->sticky_columns ?: []
		];
	}

	protected function get_row_actions($id){
		$actions	= array_diff(($this->row_actions ?: []), ($this->next_actions ?: []));

		return array_filter(array_map(fn($action) => $this->get_row_action($action, ['id'=>$id]), $actions));
	}

	public function overall_actions(){
		$actions	= $this->overall_actions ?: [];
		$actions	= array_filter(array_map(fn($action) => $this->get_row_action($action, ['class'=>'button']), $actions));

		return $actions ? wpjam_tag('div', ['alignleft', 'actions', 'overallactions'], implode('', $actions)) : '';
	}

	public function get_row_action($action, $args=[], $title=''){
		$object = $this->get_object($action);

		if($object){
			if($title){
				$args['title']	= $title;
			}

			if(isset($args['data'])){
				$args['data']	= wp_parse_args($args['data']);
			}

			return $object->get_row_action($args);
		}
	}

	public function get_filter_link($filter, $label, $class=[]){
		$query_args	= $this->query_args ?: [];
		$query_args	= array_diff($query_args, array_keys($filter));
		$filter		= array_merge($filter, wpjam_get_data_parameter($query_args));

		return "\n".wpjam_wrap($label, 'a', [
			'title'	=> wp_strip_all_tags((string)$label, true),
			'class'	=> ['list-table-filter', ...wp_parse_list($class ?: [])],
			'data'	=> ['filter'=>($filter ?: new stdClass())],
		]);
	}

	public function get_single_row($id){
		return wpjam_ob_get_contents([$this, 'single_row'], $id);
	}

	public function single_row($item){
		$raw	= $item	= $this->parse_item($item);

		if(!$item){
			return;
		}

		$this->before_single_row_by_model($item);

		$attr	= [];
		$id		= $item[$this->primary_key];

		if($id){
			$item['row_actions']	= $this->get_row_actions($id);

			if($this->primary_key == 'id'){
				$item['row_actions']['id']	= 'ID：'.$id;
			}

			$id	= str_replace('.', '-', $id);

			$attr['id']		= $this->singular.'-'.$id;
			$attr['data']	= ['id'=>$id];
		}else{
			$item['row_actions']	= ['error'=>'通过 primary_key 未获取到 ID'];
		}

		$item	= $this->render_item_by_model($item);

		$attr['class']	= wp_parse_list($item['class'] ?? []);
		$attr['style']	= $item['style'] ?? '';

		if($id && $this->multi_rows){
			$attr['class'][]	= 'tr-'.$id;
		}

		echo wpjam_tag('tr', $attr, wpjam_ob_get_contents([$this, 'single_row_columns'], $item));

		$this->after_single_row_by_model($item, $raw);
	}

	protected function parse_item($item){
		if(!is_array($item)){
			if($item instanceof WPJAM_Register){
				$item	= $item->to_array();
			}else{
				$item	= $this->get_by_model($item);
				$item 	= is_wp_error($item) ? null : ($item ? (array)$item : $item);
			}
		}

		return $item;
	}

	protected function get_column_value($id, $name, $value=null){
		$object	= $this->get_object($name, 'column');

		if($object){
			$value	??= $this->value_callback_by_model($name, $id, $object->default);
			$value	= $object->callback($id, $value);
		}

		if(is_array($value)){
			if(isset($value['row_action'])){
				$value	= $this->get_row_action($value['row_action'], array_merge(array_get($value, 'args', []), ['id'=>$id]));
			}elseif(isset($value['filter'])){
				$value	= $this->get_filter_link($value['filter'], array_get($value, 'label'), array_get($value, 'class'));
			}elseif(isset($value['items'])){
				$value	= $this->render_column_items($id, $value['items'], array_get($value, 'args', []));
			}else{
				trigger_error(var_export($value, true));
				$value	= '';
			}

			return (string)wpjam_wrap($value, array_get($value, 'wrap'));
		}

		return ($this->_builtin || !$value) ? $value : $this->do_shortcode($value, $id);
	}

	public function column_default($item, $name){
		$value	= $this->column_by_model($item, $name) ?? ($item[$name] ?? null);
		$id		= $item[$this->primary_key];

		return $id ? $this->get_column_value($id, $name, $value) : $value;
	}

	public function column_cb($item){
		$id	= $item[$this->primary_key];

		if(!is_null($id) && current_user_can($this->capability, $id)){
			$column	= $this->get_primary_column_name();
			$name	= strip_tags($item[$column] ?? $id);
			$cb_id	= 'cb-select-'.$id;

			return wpjam_tag('input', ['type'=>'checkbox', 'name'=>'ids[]', 'value'=>$id, 'id'=>$cb_id])->before('选择'.$name, 'label', ['for'=>$cb_id, 'class'=>'screen-reader-text']);
		}

		return wpjam_tag('span', ['dashicons', 'dashicons-minus']);
	}

	public function render_column_items($id, $items, $args=[]){
		$item_type	= $args['item_type'] ?? 'image';
		$item_key	= $args[$item_type.'_key'] ?? $item_type;
		$max_items	= $args['max_items'] ?? 0;
		$per_row	= $args['per_row'] ?? 0;
		$sortable	= $args['sortable'] ?? 0;
		$width		= $args['width'] ?? 60;
		$height		= $args['height'] ?? 60;
		$style		= (array)($args['style'] ?? []);

		$add_item	= $args['add_item'] ?? 'add_item';
		$edit_item	= $args['edit_item'] ?? 'edit_item';
		$move_item	= $args['move_item'] ?? 'move_item';
		$del_item	= $args['del_item'] ?? 'del_item';

		$rendered	= wpjam_tag();

		foreach($items as $i => $item){
			$color	= $item['color'] ?? null;
			$data	= compact('i');
			$args	= ['id'=>$id, 'data'=>$data];
			$attr	= ['id'=>'item_'.$i, 'data'=>$data, 'class'=>'item'];

			if($item_type == 'image'){
				$image	= $item[$item_key] ? wpjam_get_thumbnail($item[$item_key], $width*2, $height*2) : '';
				$image	= $image ? wpjam_tag('img', ['src'=>$image, 'width'=>$width, 'height'=>$height]) : ' ';
				$item	= $image.(!empty($item['title']) ? wpjam_tag('span', ['item-title'], $item['title']) : '');
				$attr	+= ['style'=>'width:'.$width.'px;'];
			}else{
				$item	= $item[$item_key] ?: ' ';
			}

			$item	= $this->get_row_action($move_item,	$args+[
				'class'		=> 'move-item '.$item_type,
				'style'		=> ['color'=>$color],
				'title'		=> $item
			]).wpjam_tag('span', ['row-actions'], $this->get_row_action($move_item, $args+[
				'class'		=> 'move-item',
				'dashicon'	=> 'move',
				'wrap'		=> wpjam_tag('span', [$move_item]),
			]).$this->get_row_action($edit_item, $args+[
				'dashicon'	=> 'edit',
				'wrap'		=> wpjam_tag('span', [$edit_item]),
			]).$this->get_row_action($del_item, $args+[
				'class'		=> 'del-icon',
				'dashicon'	=> 'no-alt',
				'wrap'		=> wpjam_tag('span', [$del_item])
			]));

			$rendered->append('div', $attr, $item);
		}

		if(!$max_items || count($items) <= $max_items){
			$add_args	= ['id'=>$id, 'class'=>'add-item item'];

			if($item_type == 'image'){
				$add_args	+= ['dashicon'=>'plus-alt2', 'style'=>'width:'.$width.'px; height:'.$height.'px;'];
			}else{
				$add_args	+= ['title'=>'新增'];
			}

			$rendered->append($this->get_row_action($add_item, $add_args));
		}

		if($per_row){
			$style['width']	= ($per_row * ($width+30)).'px';
		}

		$class	= ['items', $item_type.'-list', ($sortable ? 'sortable' : '')];

		return $rendered->wrap('div', ['class'=>$class, 'style'=>$style]);
	}

	public function is_searchable(){
		return $this->search ?? $this->get_searchable_fields_by_model();
	}

	protected function get_form(){
		return wpjam_ob_get_contents([$this, 'display']);
	}

	protected function response($response){
		if(wp_doing_ajax()){
			$this->prepare_items();
		}

		if(in_array($response['type'], ['list','left'])){
			$response['table']	= $this->get_form();
		}

		return $response+[
			'views'		=> wpjam_ob_get_contents([$this, 'views']),
			'setting'	=> $this->get_setting()
		];
	}

	public function export_action(){
		return $this->callback('export');
	}
	
	public function ajax_response(){
		return $this->callback(wpjam_get_post_parameter('action_type'));
	}

	public function callback($type=''){
		if($type != 'export'){
			$referer	= wpjam_get_referer();
			$parts		= $referer ? parse_url($referer) : wp_die('非法请求');

			if($parts['host'] == $_SERVER['HTTP_HOST']){
				$_SERVER['REQUEST_URI']	= $parts['path'];
			}
		}

		if($type == 'query_item'){
			$id	= wpjam_get_post_parameter('id', ['default'=>'']);

			return ['type'=>'add',	'id'=>$id, 'data'=>$this->get_single_row($id)];
		}elseif($type == 'query_items'){
			return $this->response(['type'=>'list']);
		}

		if($type == 'export'){
			$args	= ['method'=>'get'];
			$action	= wpjam_get_parameter('export_action');
			$data	= wpjam_get_parameter('data') ?: [];
		}else{
			$args	= ['method'=>'post'];
			$action	= wpjam_get_post_parameter('list_action');
			$data	= wpjam_get_data_parameter();
		}

		$object		= ($type && $action) ? $this->get_object($action) : null;
		$response	= $object ? $object->callback([
			'id'	=> wpjam_get_parameter('id',	$args+['default'=>'']),
			'bulk'	=> wpjam_get_parameter('bulk',	$args+['sanitize_callback'=>'intval']),
			'ids'	=> wpjam_get_parameter('ids',	$args+['sanitize_callback'=>'wp_parse_args', 'default'=>[]]),
			'data'	=> $data,
		], $type) : wp_die('无效的操作');

		if($this->layout == 'calendar'){
			if(!empty($response['data'])){
				$response['data']	= $this->render_dates($response['data']);
			}
		}

		if($response['type'] == 'list'){
			return $this->response($response);
		}elseif($response['type'] == 'items'){
			if(isset($response['items'])){
				foreach($response['items'] as $id => &$item){
					$item['id']	= $id;

					if($id && !in_array($item['type'], ['delete', 'append'])){
						$item['data']	= $this->get_single_row($id);
					}
				}
			}
		}elseif(!in_array($response['type'], ['append', 'redirect', 'delete', 'move', 'up', 'down', 'form'])){
			if($response['bulk']){
				$ids	= array_filter($response['ids']);
				$data	= $this->get_by_ids_by_model($ids);

				$response['data']	= wpjam_fill($ids, [$this, 'get_single_row']);
			}else{
				if($response['id']){
					$response['data']	= $this->get_single_row($response['id']);
				}
			}
		}

		return $this->response($response);
	}

	public function prepare_items(){
		$_GET		= array_merge($_GET, array_filter(wpjam_get_data_parameter(['orderby', 'order'])));
		$per_page	= $this->per_page;

		if(!$per_page || !is_numeric($per_page)){
			$per_page	= 50;
		}

		$offset	= ($this->get_pagenum()-1) * $per_page;
		$args	= $this->parse_query_vars(['number'=>$per_page, 'offset'=>$offset]);
		$result	= wpjam_throw_if_error($this->query_items_by_model($args));

		if(wp_is_numeric_array($result)){
			$this->items	= $result;
		}else{
			$this->items	= $result['items'] ?? [];
			$total_items	= $result['total'] ?? 0;
		}

		if(empty($total_items)){
			$total_items	= $per_page	= count($this->items);
		}

		$this->set_pagination_args(['total_items'=>$total_items, 'per_page'=>$per_page]);		
	}

	protected function get_bulk_actions(){
		return wp_list_pluck($this->bulk_actions, 'title');
	}

	public function get_subtitle(){
		$subtitle	= $this->get_subtitle_by_model();
		$search		= wpjam_get_data_parameter('s');
		$subtitle 	.= $search ? ' “'.esc_html($search).'”的搜索结果' : '';
		$subtitle	= $subtitle ? wpjam_tag('span', ['subtitle'], $subtitle) : '';

		if($this->layout != 'left'){
			$subtitle	= ' '.$this->get_row_action('add', ['class'=>'page-title-action', 'subtitle'=>true]).$subtitle;
		}

		return $subtitle;
	}

	protected function get_table_classes() {
		$classes	= parent::get_table_classes();
		$classes	= $this->fixed ? $classes : array_diff($classes, ['fixed']);

		return [...$classes, ($this->sticky_columns ? 'sticky-columns' : '')];
	}

	public function get_singular(){
		return $this->singular;
	}

	protected function get_default_primary_column_name(){
		return $this->primary_column;
	}

	protected function handle_row_actions($item, $column_name, $primary){
		return ($primary === $column_name && !empty($item['row_actions'])) ? $this->row_actions($item['row_actions'], false) : '';
	}

	public function row_actions($actions, $always_visible=true){
		return parent::row_actions($actions, $always_visible);
	}

	public function get_columns(){
		return $this->columns ?: [];
	}

	public function get_sortable_columns(){
		return $this->sortable_columns ?: [];
	}

	public function get_views(){
		foreach(($this->get_views_by_model() ?:[]) as $key => $view){
			if($view){
				$this->register($key, $view, 'view');
			}
		}

		$views	= [];

		foreach($this->get_objects('view') as $object){
			$key	= $object->name;
			$view	= $object->get_link();

			if($view && is_array($view)){
				$view	= $view['label'] ? $this->get_filter_link($view['filter'], $view['label'], $view['class']) : null;
			}

			if($view){
				$views[$key]	= $view;
			}
		}

		return $views;
	}

	public function extra_tablenav($which='top'){
		if(!$this->_builtin){
			$this->extra_tablenav_by_model($which);

			do_action(wpjam_get_filter_name($this->plural, 'extra_tablenav'), $which);
		}

		if($which == 'top'){
			echo $this->overall_actions();
		}
	}

	public function current_action(){
		return wpjam_get_request_parameter('list_action', ['default'=>parent::current_action()]);
	}

	protected static function get_class($type){
		return 'WPJAM_List_Table_'.$type;
	}

	protected static function sanitize_name($name, $args){
		$data_type	= wpjam_parse_data_type($args);

		return $data_type ? $name.'__'.md5(serialize($data_type)) : $name;
	}

	public static function register($name, $args, $type='action'){
		if($type == 'view'){
			$name	= is_numeric($name) ? 'view_'.$name : $name;
			$args	= (is_string($args) || is_object($args)) ? ['view'=>$args] : $args;
		}

		$class	= self::get_class($type);
		$object	= new $class($name, $args);
		$name	= self::sanitize_name($name, $args);

		return wpjam_call([$class, 'register'], $name, $object);
	}

	public static function unregister($name, $args=[], $type='action'){
		$class	= self::get_class($type);
		$name	= self::sanitize_name($name, $args);

		return wpjam_call([$class, 'unregister'], $name);
	}
}

class WPJAM_Left_List_Table extends WPJAM_List_Table{
	public function col_left(){
		$result	= $this->col_left_by_model();

		if($result && is_array($result)){
			$args	= wp_parse_args($result, [
				'total_items'	=> 0,
				'total_pages'	=> 0,
				'per_page'		=> 10,
			]);

			$total_pages	= $args['total_pages'] ?: ($args['per_page'] ? ceil($args['total_items']/$args['per_page']) : 0);

			if($total_pages){
				$keys	= ['prev', 'text', 'next', 'goto'];
				$pages	= array_map(fn($k) => $this->get_left_page_link($k, $total_pages), $keys);
				$class	= 'tablenav-pages';
				$class	= $total_pages < 2 ? ['one-page', $class] : [$class];

				echo wpjam_tag('span', ['left-pagination-links'], join(' ', array_filter($pages)))->wrap('div', $class)->wrap('div', ['tablenav', 'bottom']);
			}
		}
	}

	public function callback($type=''){
		return $type == 'left' ? $this->response(['left'=>$this->get_col_left(), 'type'=>'left']) : parent::callback($type);
	}

	protected function get_left_page_link($type, $total){
		$current	= (int)wpjam_get_data_parameter('left_paged') ?: 1;

		if($type == 'text'){
			return wpjam_tag('span', ['current-page'], $current)
			->after(' / ')
			->after('span', ['total-pages'], number_format_i18n($total))
			->wrap('span', ['tablenav-paging-text']);
		}elseif($type == 'goto'){
			if($total < 2){
				return '';
			}

			return wpjam_tag('input', [
				'type'	=> 'text',
				'name'	=> 'paged',
				'value'	=> $current,
				'size'	=> strlen($total),
				'id'	=> 'left-current-page-selector',
				'class'	=> 'current-page',
				'aria-describedby'	=> 'table-paging',
			])->after('a', ['left-pagination', 'button', 'goto'], '&#10132;')
			->wrap('span', ['paging-input']);
		}elseif($type == 'prev'){
			$value	= 1;
			$paged	= max(1, $current - 1);
			$text	= '&lsaquo;';
			$reader	= __('Previous page');
		}else{
			$value	= $total;
			$paged	= min($value, $current + 1);
			$text	= '&rsaquo;';
			$reader	= __('Next page');
		}

		$attr	= ['aria-hidden'=>'true'];

		if($value == $current){
			$attr['class']	= ['tablenav-pages-navspan', 'button', 'disabled'];
		}

		$tag	= wpjam_tag('span', $attr, $text);

		if($value != $current){
			$tag->before('span', ['screen-reader-text'], $reader)->wrap('a', ['data'=>['left_paged'=>$paged], 'class'=>['left-pagination', 'button', $type.'-page']]);
		}

		return $tag;
	}

	public function get_col_left(){
		return wpjam_ob_get_contents([$this, 'col_left']);
	}
}

class WPJAM_Calendar_List_Table extends WPJAM_List_Table{
	public function __get($name){
		if(in_array($name, ['year', 'month'])){
			$value	= (int)wpjam_get_data_parameter($name) ?: wpjam_date(['year'=>'Y', 'month'=>'m'][$name]);

			return $name == 'year' ? max(min($value, 2200), 1970) : max(min($value, 12), 1);
		}

		return parent::__get($name);
	}

	public function prepare_items(){
		$args	= ['year'=>$this->year, 'month'=>$this->month, 'layout'=>$this->layout];
		$args	= $this->parse_query_vars($args);

		$this->items	= wpjam_throw_if_error($this->query_items_by_model($args));
	}

	protected function render_date($raw, $date){
		if(wp_is_numeric_array($raw)){
			$raw		= array_filter(array_map([$this, 'parse_item'], $raw));
			$actions	= $this->get_row_actions($raw[$this->primary_key]);
		}else{
			$raw		= $this->parse_item($raw);
			$actions	= ['add'=>$this->get_row_action('add', ['data'=>['date'=>$date]])];
		}

		$links	= wpjam_map($actions, fn($v, $k) => wpjam_tag('span', [$k], $v));
		$links	= wpjam_tag('div', ['row-actions', 'alignright'], implode(' ', $links));

		$item	= $this->render_date_by_model($raw, $date) ?: '';
		$day	= explode('-', $date)[2];
		$class	= $date == wpjam_date('Y-m-d') ? ['day', 'today'] :  ['day'];

		return $links->before('span', [$class], $day)->wrap('div', ['date-meta'])->after('div', ['date-content'], $item);
	}

	public function render_dates($result){
		$dates	= $result['dates'] ?? $result;

		return wpjam_map($dates, [$this, 'render_date']);
	}

	public function display(){
		$this->display_tablenav('top');

		$year	= $this->year;
		$month	= zeroise($this->month, 2);
		$m_ts	= mktime(0, 0, 0, $this->month, 1, $this->year);	// 每月开始的时间戳
		$days	= date('t', $m_ts);
		$start	= (int)get_option('start_of_week');
		$pad	= calendar_week_mod(date('w', $m_ts) - $start);
		$tr		= wpjam_tag('tr');

		for($wd_count = 0; $wd_count <= 6; $wd_count++){
			$weekday	= ($wd_count + $start) % 7;
			$name		= $GLOBALS['wp_locale']->get_weekday($weekday);

			$tr->append('th', [
				'scope'	=> 'col',
				'class'	=> in_array($weekday, [0, 6]) ? 'weekend' : 'weekday',
				'title'	=> $name
			], $GLOBALS['wp_locale']->get_weekday_abbrev($name));
		}

		$thead	= wpjam_tag('thead')->append(wp_clone($tr));
		$tfoot	= wpjam_tag('tfoot')->append(wp_clone($tr));
		$tbody	= wpjam_tag('tbody', ['id'=>'the-list', 'data'=>['wp-lists'=>'list:'.$this->singular]]);
		$tr		= wpjam_tag('tr');

		if($pad){
			$tr->append('td', ['colspan'=>$pad, 'class'=>'pad']);
		}

		for($day=1; $day<=$days; ++$day){
			$date	= $year.'-'.$month.'-'.zeroise($day, 2);
			$item	= $this->items[$date] ?? [];
			$item	= $this->render_date($item, $date);

			$tr->append('td', [
				'id'	=> 'date_'.$date,
				'class'	=> in_array($pad+$start, [0, 6, 7]) ? 'weekend' : 'weekday'
			], $item);

			$pad++;

			if($pad%7 == 0){
				$tbody->append($tr);

				$pad	= 0;
				$tr	= wpjam_tag('tr');
			}
		}

		if($pad){
			$tr->append('td', ['colspan'=>(7-$pad), 'class'=>'pad']);

			$tbody->append($tr);
		}

		echo $tbody->before($tfoot)->before($thead)->wrap('table', ['cellpadding'=>10, 'cellspacing'=>0, 'class'=>'widefat fixed']);

		$this->display_tablenav('bottom');
	}

	public function extra_tablenav($which='top'){
		if($which == 'top'){
			echo wpjam_tag('h2', [], sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($this->month), $this->year));
		}

		parent::extra_tablenav($which);
	}

	public function pagination($which){
		echo array_reduce(['prev', 'current', 'next'], [$this, 'append_month_link'], wpjam_tag('span', ['pagination-links']))->wrap('div', ['tablenav-pages']);
	}

	protected function append_month_link($pagination, $type){
		if($type == 'prev'){
			$text	= '&lsaquo;';
			$class	= 'prev-month';

			if($this->month == 1){
				$year	= $this->year - 1;
				$month	= 12;
			}else{
				$year	= $this->year;
				$month	= $this->month - 1;
			}
		}elseif($type == 'next'){
			$text	= '&rsaquo;';
			$class	= 'next-month';

			if($this->month == 12){
				$year	= $this->year + 1;
				$month	= 1;
			}else{
				$year	= $this->year;
				$month	= $this->month + 1;
			}
		}else{
			$text	= '今日';
			$class	= 'current-month';
			$year	= wpjam_date('Y');
			$month	= wpjam_date('m');
		}

		if($type){
			$reader	= sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($month), $year);
			$text	= wpjam_tag('span', ['aria-hidden'=>'true'], $text)->before('span', ['screen-reader-text'], $reader);
		}

		return $pagination->append($this->get_filter_link(['year'=>$year, 'month'=>$month], $text, $class.' button'));
	}

	public function get_views(){
		return [];
	}

	public function get_bulk_actions(){
		return [];
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_Action extends WPJAM_Register{
	public function __get($key){
		$value	= parent::__get($key);

		if(is_null($value)){
			if(in_array($key, ['primary_key', 'layout', 'model', 'data_type', 'capability', 'next_actions']) 
				|| ($this->data_type && $this->data_type == $key)
			){
				return get_screen_option('list_table', $key);
			}elseif($key == 'page_title'){
				return $this->title ? wp_strip_all_tags($this->title.get_screen_option('list_table', 'title')) : '';
			}elseif($key == 'response'){
				return $this->overall ? 'list' : $this->name;
			}elseif($key == 'row_action'){
				return true;
			}
		}

		return $value;
	}

	public function __call($method, $args){
		if($method == 'get_next_action'){
			return self::get($this->next);
		}elseif($method == 'get_prev_action'){
			$prev	= $this->prev ?: array_search($this->name, ($this->next_actions ?: []));

			return self::get($prev);
		}else{
			trigger_error('undefined_method「'.$method.'」');
		}
	}

	public function jsonSerialize(){
		return array_filter($this->generate_data_attr(['bulk'=>true]));
	}

	protected function parse_id_arg($args){
		if(wpjam_is_assoc_array($args)){
			return $args['bulk'] ? $args['ids'] : ($args['id'] ?? null);
		}

		return $args;
	}

	public function callback($args, $type=null){
		if($this->export && in_array($type, ['submit', 'direct'])){
			$args	= array_filter($args)+['export_action'=>$this->name, '_wpnonce'=>WPJAM_Admin::create_nonce($this->name, $args)];

			return ['type'=>'redirect', 'url'=>add_query_arg($args, $GLOBALS['current_admin_url'])];
		}

		$id		= $args['id'];
		$ids	= $args['ids'];
		$data	= $args['data'];

		if(!$type){
			if($args['bulk']){
				$callback	= $args['bulk_callback'];
				$cb_args	= [$ids, $data];

				if(!$callback && method_exists($this->model, 'bulk_'.$this->name)){
					$callback	= [$this->model, 'bulk_'.$this->name];
				}

				if(!$callback){
					return $this->bulk_callback($args);
				}
			}else{
				$callback	= $args['callback'];
				$cb_args	= [$id, $data];

				if(!$callback){
					return $this->call_by_model($id, $data, $args['fields']);
				}

				if($this->overall){
					$cb_args	= [$data];
				}elseif($this->response == 'add' && !is_null($data)){
					$parameters	= wpjam_get_callback_parameters($callback);

					if(count($parameters) == 1 || $parameters[0]->name == 'data'){
						$cb_args	= [$data];
					}
				}
			}

			$errmsg	= '「'.$this->title.'」的回调函数';

			if(!is_callable($callback)){
				wp_die($errmsg.'无效');
			}

			$cb_args	= [...$cb_args, $this->name, $args['submit_name']];
			$result		= wpjam_try($callback, ...$cb_args);

			if(is_null($result)){
				wp_die($errmsg.'没有正确返回');
			}

			return $result;
		}

		if(!$this->is_allowed($args)){
			wp_die('access_denied');
		}

		$form_args	= $args;
		$response	= [
			'list_action'	=> $this->name,
			'page_title'	=> $this->page_title,
			'type'			=> $this->response,
			'layout'		=> $this->layout,
			'last'			=> (bool)$this->last,
			'width'			=> (int)$this->width,
			'bulk'			=> &$args['bulk'],
			'id'			=> &$id,
			'ids'			=> $ids
		];

		if($type == 'form'){
			return array_merge($response, ['type'=>'form',	'form'=>$this->get_form($form_args, $type)]);
		}

		if(!WPJAM_Admin::verify_nonce($this->name, $args)){
			wp_die('invalid_nonce');
		}

		if($args['bulk'] === 2){
			$args['bulk']	= 0;
		}

		$cb_keys	= ['callback', 'bulk_callback'];
		$args		= array_reduce($cb_keys, fn($args, $k) => $args+[$k => $this->$k], $args);
		$fields		= $submit_name = null;

		if($type == 'submit'){
			$data	= $this->get_fields($args, true, 'object')->validate($data);

			if($this->response == 'form'){
				$form_args['data']	= $data;
			}else{
				$form_args['data']	= wpjam_get_post_parameter('defaults',	['sanitize_callback'=>'wp_parse_args', 'default'=>[]]);
				$submit_name		= wpjam_get_post_parameter('submit_name', ['default'=>$this->name]);
				$submit_button		= $this->get_submit_button($args, $submit_name);
				$response['type']	= $submit_button['response'];

				$args	= array_merge($args, array_filter(wp_array_slice_assoc($submit_button, $cb_keys)));
			}
		}

		if($this->response == 'form'){
			$result	= null;
		}else{
			$result	= $this->callback(array_merge($args, compact('data', 'fields', 'submit_name')));

			if(is_array($result) && !empty($result['errmsg']) && $result['errmsg'] != 'ok'){ // 第三方接口可能返回 ok
				$response['errmsg'] = $result['errmsg'];
			}elseif($type == 'submit'){
				$response['errmsg'] = $submit_button['text'].'成功';
			}
		}

		if(is_array($result) && array_intersect(array_keys($result), ['type', 'bulk', 'ids', 'id', 'items'])){
			$response	= array_merge($response, $result);
		}elseif(in_array($response['type'], ['add', 'duplicate']) || in_array($this->name, ['add', 'duplicate'])){
			if(is_array($result)){
				$dates	= $result['dates'] ?? $result;
				$date	= current($dates);
				$id		= is_array($date) ? $date[$this->primary_key] : null;

				if(is_null($id)){
					wp_die('无效的 ID');
				}
			}else{
				$id	= $result;
			}
		}

		if($response['type'] == 'append'){
			return array_merge($response, ['data'=>$result]);
		}elseif($response['type'] == 'redirect'){
			return is_string($result) ? array_merge($response, ['url'=>$result]) : $response;
		}else{
			if($this->layout == 'calendar'){
				if(is_array($result)){
					$response['data']	= $result;
				}
			}else{
				if(!$response['bulk'] && in_array($response['type'], ['add', 'duplicate'])){
					$form_args['id'] = $id;
				}
			}
		}

		if($type == 'submit'){
			if($response['type'] == 'delete'){
				$response['dismiss']	= true;
			}else{
				if($this->next){
					$response['next']		= $this->next;
					$response['page_title']	= $this->get_next_action()->page_title;

					if($response['type'] == 'form'){
						$response['errmsg']	= '';
					}
				}elseif($this->dismiss){
					$response['dismiss']	= true;
				}
			}

			if(empty($response['dismiss'])){
				$response['form']	= $this->get_form($form_args, $type);
			}
		}

		return $response;
	}

	protected function bulk_callback($args){
		$data	= [];

		foreach($args['ids'] as $id){
			$result	= $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]));

			if(is_array($result)){
				$data	= wpjam_merge($data, $result);
			}
		}

		return $data ?: $result;
	}

	protected function call_by_model($id, $data, $fields=null){
		$method	= $this->name;

		if($method == 'add'){
			$method	= 'insert';
		}elseif($method == 'edit'){
			$method	= 'update';
		}elseif(in_array($method, ['up', 'down'], true)){
			$method	= 'move';
		}elseif($method == 'duplicate' && !$this->direct){
			$method	= 'insert';
		}

		$errmsg		= '「'.$this->model.'」未定义相应的操作';
		$defaults	= $fields ? $fields->get_defaults() : null;
		$callback	= [$this->model, $method];

		if($this->overall || $method == 'insert' || $this->response == 'add'){
			if(!is_callable($callback)){
				wp_die($errmsg);
			}

			$cb_args	= [$data];
		}else{
			if(method_exists($this->model, $method)){
				$cb_args	= ($this->direct && is_null($data)) ? [$id] : [$id, $data];
			}elseif(!$this->meta_type && method_exists($this->model, '__callStatic')){
				$cb_args	= [$id, $data];
			}elseif(method_exists($this->model, 'update_callback')){
				$cb_args	= [$id, $data, $defaults];
				$callback	= [$this->model, 'update_callback'];
			}else{
				$meta_type	= get_screen_option('meta_type');

				if(!$meta_type){
					wp_die($errmsg);
				}

				$cb_args	= [$meta_type, $id, $data, $defaults];
				$callback	= 'wpjam_update_metadata';
			}
		}

		$result	= wpjam_try($callback, ...$cb_args);

		return is_null($result) ? true : $result;
	}

	protected function show_if($id){
		$show_if	= $this->show_if;

		if($show_if){
			if(is_callable($show_if)){
				$result	= wpjam_catch($show_if, $id, $this->name);

				return is_wp_error($result) ? false : $result;
			}elseif(is_array($show_if) && $id){
				return wpjam_show_if($this->get_data($id), $show_if);
			}
		}

		return true;

	}

	public function is_allowed($id=0){
		if($this->capability != 'read'){
			$id	= $this->parse_id_arg($id);

			foreach((array)$id as $_id){
				if(!current_user_can($this->capability, $_id, $this->name)){
					return false;
				}
			}
		}

		return true;
	}

	public function get_data($id, $include_prev=false, $by_callback=false){
		$data	= null;

		if($include_prev || $by_callback){
			$callback	= $this->data_callback;

			if($callback){
				if(!is_callable($callback)){
					wp_die($this->title.'的 data_callback 无效');
				}

				$data	= wpjam_try($callback, $id, $this->name);
			}
		}

		if($include_prev){
			$prev	= $this->get_prev_action();
			$prev	= $prev ? $prev->get_data($id, true) : [];

			return $data ? array_merge($prev, $data) : $prev;
		}else{
			if(!$by_callback || is_null($data)){
				$callback	= [$this->model, 'get'];

				if(!is_callable($callback)){
					wp_die(implode('->', $callback),' 未定义');
				}

				$data	= wpjam_try($callback, $id);

				if($data instanceof WPJAM_Register){
					$data	= $data->to_array();
				}
			}

			return $data ?: ($id ? wp_die('无效的 ID') : []);
		}
	}

	public function get_form($args=[], $type=''){
		$object	= $this;
		$prev	= null;

		if($type == 'submit' && $this->next){
			if($this->response == 'form'){
				$prev	= $this;
			}

			$object	= $this->get_next_action();
		}

		$id	= ($args['bulk'] || $object->overall) ? null : $args['id'];

		$fields	= ['id'=>$id, 'data'=>$args['data']];

		if($id){
			if($type != 'submit' || $this->response != 'form'){
				$data	= $object->get_data($id, false, true);
				$data	= is_array($data) ? array_merge($args['data'], $data) : $data;

				$fields['data']	= $data;
			}

			$fields['meta_type']	= get_screen_option('meta_type');

			if(method_exists($object->model, 'value_callback')){
				$fields['value_callback']	= [$object->model, 'value_callback'];
			}
		}

		if($object->value_callback){
			$fields['value_callback']	= $object->value_callback;
		}

		$fields	= $object->get_fields($args, false, 'object')->render($fields, false);
		$prev	= $prev ?: $object->get_prev_action();
		$button	= '';

		if($prev){
			$button	.= wpjam_tag('input', [
				'type'	=> 'button',
				'value'	=> '上一步',
				'class'	=> ['list-table-action', 'button','large'],
				'data'	=> $prev->generate_data_attr($args)
			]);

			if($id && $type == 'form'){
				$args['data']	= array_merge($args['data'], $prev->get_data($id, true));
			}
		}

		if($object->next && $object->response == 'form'){
			$button	.= get_submit_button('下一步', 'primary', 'next', false);
		}else{
			$button	.= $object->get_submit_button($args);
		}

		$form	= $fields->wrap('form', [
			'method'	=> 'post',
			'action'	=> '#',
			'id'		=> 'list_table_action_form',
			'data'		=> $object->generate_data_attr($args, 'form')
		]);

		if($button){
			$form->append($button, 'p', ['submit']);
		}

		return $form;
	}

	public function get_fields($args, $include_prev=false, $output=''){
		if($this->direct){
			return [];
		}

		$fields	= wpjam_throw_if_error($this->fields);
		$id_arg	= $this->parse_id_arg($args);

		if($fields && is_callable($fields)){
			$fields	= wpjam_try($fields, $id_arg, $this->name);
		}

		$fields	= $fields ?: wpjam_try([$this->model, 'get_fields'], $this->name, $id_arg);
		$fields	= is_array($fields) ? $fields : [];

		if($include_prev){
			$prev	= $this->get_prev_action();

			if($prev){
				$fields	= array_merge($fields, $prev->get_fields($id_arg, true, ''));
			}
		}

		if(method_exists($this->model, 'filter_fields')){
			$fields	= wpjam_try([$this->model, 'filter_fields'], $fields, $id_arg, $this->name);
		}else{
			if(!in_array($this->name, ['add', 'duplicate']) && isset($fields[$this->primary_key])){
				$fields[$this->primary_key]['type']	= 'view';
			}
		}

		return $output == 'object' ? wpjam_fields($fields) : $fields;
	}

	public function get_submit_button($args, $name=null, $render=null){
		$render	??= is_null($name);

		if(!is_null($this->submit_text)){
			$button	= $this->submit_text;

			if($button && is_callable($button)){
				$id_arg	= $this->parse_id_arg($args);
				$button	= wpjam_try($button, $id_arg, $this->name);
			}
		}else{
			$button = wp_strip_all_tags($this->title) ?: $this->page_title;
		}

		return WPJAM_Admin::parse_submit_button($this, $button, $name, $render);
	}

	public function get_row_action($args=[]){
		$args	= wp_parse_args($args, ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[], 'class'=>[], 'title'=>'']);

		if(($this->layout == 'calendar' && !$this->calendar) || !$this->show_if($args['id'])){
			return '';
		}

		if(!$this->is_allowed($args['id'])){
			$fallback	= array_get($args, 'fallback');

			return $fallback === true ? $args['title'] : (string)$fallback;
		}

		$tag	= $args['tag'] ?? 'a';
		$class	= [...wp_parse_list($args['class']), ...wp_parse_list($this->class ?: [])]; 
		$attr	= ['title'=>$this->page_title, 'style'=>($args['style'] ?? ''), 'class'=>$class];
		$data	= $this->data ?: [];

		if($this->redirect){
			$tag	= 'a';

			$attr['href']	= str_replace('%id%', $args['id'], $this->redirect);
			$class_part		= 'redirect';
		}elseif($this->filter){
			if(!$this->overall){
				$item	= (array)$this->get_data($args['id']);
				$data	= array_merge($data, wp_array_slice_assoc($item, wp_parse_list($this->filter)));
			}

			$attr['data']	= ['filter'=>wp_parse_args($args['data'], $data)];
			$class_part		= 'filter';
		}else{
			$attr['data']	= $this->generate_data_attr($args);
			$class_part		= (in_array($this->response, ['move', 'move_item']) ? 'move-' : '').'action';
		}

		$attr['class'][]	= 'list-table-'.$class_part;

		if(!empty($args['dashicon'])){
			$title	= wpjam_tag('span', ['dashicons dashicons-'.$args['dashicon']]);
		}elseif($args['title']){
			$title	= $args['title'];
		}elseif($this->dashicon && empty($args['subtitle']) && ($this->layout == 'calendar' || !$this->title)){
			$title	= wpjam_tag('span', ['dashicons dashicons-'.$this->dashicon]);
		}else{
			$title	= $this->title ?: $this->page_title;
		}

		return (string)wpjam_tag($tag, $attr, $title)->wrap(array_get($args, 'wrap'), $this->name);
	}

	public function generate_data_attr($args=[], $type='button'){
		$args	= wp_parse_args($args, ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[]]);
		$data	= $this->data ?: [];
		$attr	= [
			'action'	=> $this->name,
			'nonce'		=> WPJAM_Admin::create_nonce($this->name, $args),
			'data'		=> wp_parse_args($args['data'], $data),
		];

		if($args['bulk']){
			$attr['ids']	= $args['ids'];
			$attr['bulk']	= $this->bulk;
			$attr['title']	= $this->title;
		}elseif(!$this->overall){
			$attr['id']		= $args['id'];
		}

		if($type == 'button'){
			$attr['direct']		= $this->direct;
			$attr['confirm']	= $this->confirm;
		}else{
			$attr['next']		= $this->next;
		}

		return $attr;
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_Column extends WPJAM_Register{
	public function callback($id, $value){
		$callback	= $this->column_callback ?: $this->callback;

		if($callback && is_callable($callback)){
			return wpjam_catch($callback, $id, $this->name, $value);
		}

		return $this->parse_value($value);
	}

	protected function parse_value($value){
		if(is_array($value)){
			return wp_is_numeric_array($value) ? implode(',', array_map([$this, 'parse_value'], $value)) : $value;
		}

		if($value && has_shortcode($value, 'filter')){
			return $value;
		}

		$parsed	= $value;

		if($this->options){
			$this->_field	??= wpjam_field(['type'=>'view', 'key'=>$this->name, 'options'=>$this->options]);
			$parsed			= $this->_field->val($value)->value_callback();
		}

		return $this->filterable ? '[filter '.$this->name.'="'.$value.'"]'.$parsed.'[/filter]' : $parsed;
	}

	public function get_style(){
		$style	= $this->column_style ?: $this->style;

		if($style && !preg_match('/\{([^\}]*)\}/', $style)){
			return '.manage-column.column-'.$this->name.'{ '.$style.' }';
		}

		return $style;
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_View extends WPJAM_Register{
	public function get_link(){
		if($this->view){
			return $this->view;
		}

		$callback	= $this->callback;

		if($callback && is_callable($callback)){
			$result	= wpjam_catch($callback, $this->name);

			if(is_wp_error($result)){
				return;
			}elseif(!is_array($result)){
				return $result;
			}

			$this->update_args($result);
		}

		if($this->label){
			if(is_numeric($this->count)){
				$this->label	.= wpjam_tag('span', ['count'], '（'.$this->count.'）');
			}

			$this->filter	??= [];
			$this->class	??= $this->parse_class();

			return $this->get_args();
		}
	}

	protected function parse_class(){
		foreach($this->filter as $key => $value){
			$current	= wpjam_get_data_parameter($key);

			if((($current === null) xor ($value === null)) || $current != $value){
				return '';
			}
		}

		return 'current';
	}
}

class WPJAM_Builtin_List_Table extends WPJAM_List_Table{
	public function __construct($args, $class_name){
		$screen	= get_current_screen();

		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-list-table-action',	[$this, 'ajax_response']);

			$args['_builtin']	= wpjam_get_builtin_list_table($class_name, $screen);
		}elseif(wpjam_get_parameter('export_action')){
			$this->export_action();
		}else{
			$args['_builtin']	= true;
		}

		if(!wp_doing_ajax() || !wp_is_json_request()){
			add_filter('wpjam_html',	[$this, 'filter_html']);
		}

		add_filter('views_'.$screen->id,		[$this, 'filter_views']);
		add_filter('bulk_actions-'.$screen->id,	[$this, 'filter_bulk_actions']);

		add_filter('manage_'.$screen->id.'_columns',			[$this, 'filter_columns']);
		add_filter('manage_'.$screen->id.'_sortable_columns',	[$this, 'filter_sortable_columns']);

		if(isset($args['hook_part'])){
			if(in_array($args['hook_part'][0], ['pages', 'posts', 'media'])){
				add_action('manage_'.$args['hook_part'][0].'_custom_column',	[$this, 'on_custom_column'], 10, 2);
			}else{
				add_filter('manage_'.$args['hook_part'][0].'_custom_column',	[$this, 'filter_custom_column'], 10, 3);
			}

			add_filter($args['hook_part'][1].'_row_actions',	[$this, 'filter_row_actions'], 1, 2);
		}

		WPJAM_Admin::parse_data_type($args['data_type']);

		$this->_args	= $this->parse_args($args);	// 一定要最后执行
	}

	public function views(){
		if($this->screen->id != 'upload'){
			$this->_builtin->views();
		}
	}

	public function display(){
		$this->_builtin->display(); 
	}

	public function prepare_items(){
		$data	= wpjam_get_data_parameter();
		$_GET	= array_merge($_GET, $data);
		$_POST	= array_merge($_POST, $data);

		$this->_builtin->prepare_items();
	}

	public function get_form(){
		return $this->single_row_replace(parent::get_form());
	}

	public function get_single_row($id){
		return $this->single_row_replace(parent::get_single_row($id), $id);
	}

	protected function single_row_replace($html, $id=null){
		if(is_null($id)){
			return preg_replace_callback('/<tr id="'.$this->singular.'-(\d+)".*?>.*?<\/tr>/is', fn($m) => $this->single_row_replace($m[0], $m[1]), $html);
		}else{
			return $this->do_shortcode(apply_filters('wpjam_single_row', $html, $id), $id);
		}
	}

	public function filter_views($views){
		return array_merge($views, $this->get_views());
	}

	public function filter_bulk_actions($bulk_actions=[]){
		return array_merge($bulk_actions, $this->get_bulk_actions());
	}

	public function filter_columns($columns){
		$columns	= array_merge(array_slice($columns, 0, -1), $this->get_columns(), array_slice($columns, -1));
		$removed	= wpjam_get_items($this->screen->id.'_removed_columns');

		return array_except($columns, $removed);
	}

	public function filter_sortable_columns($sortable_columns){
		return array_merge($sortable_columns, $this->get_sortable_columns());
	}

	public function filter_custom_column($value, $name, $id){
		return $this->get_column_value($id, $name, $value);
	}

	public function filter_html($html){
		return $this->single_row_replace($html);
	}

	public static function load($screen){
		$GLOBALS['wpjam_list_table']	= new static($screen);
	}
}

class WPJAM_Posts_List_Table extends WPJAM_Builtin_List_Table{
	public function __construct($screen){
		$post_type	= $screen->post_type;
		$object		= $screen->get_option('object');
		$args		= [
			'title'			=> $object->title,
			'model'			=> $object->model,
			'singular'		=> 'post',
			'capability'	=> 'edit_post',
			'data_type'		=> 'post_type',
			'post_type'		=> $post_type,
		];

		if($post_type == 'attachment'){
			$class_name			= 'WP_Media_List_Table';
			$args['hook_part']	= ['media', 'media'];
		}else{
			$class_name			= 'WP_Posts_List_Table';
			$args['hook_part']	= $object->hierarchical ? ['pages', 'page'] : ['posts', 'post'];

			add_filter('map_meta_cap',	[$this, 'filter_map_meta_cap'], 10, 4);
		}

		add_action('manage_posts_extra_tablenav',	[$this, 'extra_tablenav']);
		add_action('pre_get_posts',					[$this, 'on_pre_get_posts']);

		parent::__construct($args, $class_name);
	}

	public function prepare_items(){
		$_GET['post_type']	= $this->post_type;

		parent::prepare_items();
	}

	public function single_row($raw_item){
		global $post, $authordata;

		if($post = is_numeric($raw_item) ? get_post($raw_item) : $raw_item){
			$authordata = get_userdata($post->post_author);

			if($post->post_type == 'attachment'){
				$owner	= (get_current_user_id() == $post->post_author) ? 'self' : 'other';
				$attr	= ['id'=>'post-'.$post->ID, 'class'=>['author-'.$owner, 'status-'.$post->post_status]];

				echo wpjam_tag('tr', $attr, wpjam_ob_get_contents([$this->_builtin, 'single_row_columns'], $post));
			}else{
				$this->_builtin->single_row($post);
			}
		}
	}

	public function filter_map_meta_cap($caps, $cap, $user_id, $args){
		if($cap == 'edit_post' && empty($args[0])){
			$object	= get_screen_option('object');

			return $object->map_meta_cap ? [$object->cap->edit_posts] : [$object->cap->$cap];
		}

		return $caps;
	}

	public function filter_bulk_actions($bulk_actions=[]){
		$split	= array_search((isset($bulk_actions['trash']) ? 'trash' : 'untrash'), array_keys($bulk_actions), true);

		return array_merge(array_slice($bulk_actions, 0, $split), $this->get_bulk_actions(), array_slice($bulk_actions, $split));
	}

	public function filter_row_actions($row_actions, $post){
		foreach($this->get_row_actions($post->ID) as $key => $row_action){
			$object	= $this->get_object($key);
			$status	= get_post_status($post);

			if($status == 'trash'){
				if($object->post_status && in_array($status, (array)$object->post_status)){
					$row_actions[$key]	= $row_action;
				}
			}else{
				if(is_null($object->post_status) || in_array($status, (array)$object->post_status)){
					$row_actions[$key]	= $row_action;
				}
			}
		}

		$row_actions	+= wpjam_pull($row_actions, ['trash', 'view']);

		return array_merge(array_filter($row_actions), ['id'=>'ID: '.$post->ID]);
	}

	public function on_custom_column($name, $post_id){
		echo $this->get_column_value($post_id, $name, null) ?: '';
	}

	public function filter_html($html){
		if(!wp_doing_ajax()){
			$object	= $this->get_object('add');

			if($object){
				$button	= $object->get_row_action(['class'=>'page-title-action']);
				$html	= preg_replace('/<a href=".*?" class="page-title-action">.*?<\/a>/i', $button, $html);
			}
		}

		return parent::filter_html($html);
	}

	public function on_pre_get_posts($wp_query){
		$orderby	= $wp_query->get('orderby');
		$object		= ($orderby && is_string($orderby)) ? $this->get_object($orderby, 'column') : null;

		if($object){
			$orderby_type	= $object->sortable_column ?? 'meta_value';

			if(in_array($orderby_type, ['meta_value_num', 'meta_value'])){
				$wp_query->set('meta_key', $orderby);
				$wp_query->set('orderby', $orderby_type);
			}else{
				$wp_query->set('orderby', $orderby);
			}
		}
	}

	public static function load($screen){
		if($screen->base == 'upload'){
			$mode	= get_user_option('media_library_mode', get_current_user_id()) ?: 'grid';

			if(isset($_GET['mode']) && in_array($_GET['mode'], ['grid', 'list'], true)){
				$mode	= $_GET['mode'];
			}

			if($mode == 'grid'){
				return;
			}
		}else{
			// if(!$GLOBALS['typenow'] || !post_type_exists($GLOBALS['typenow'])){
			//	return;
			// }
		}

		$GLOBALS['wpjam_list_table']	= new static($screen);
	}
}

class WPJAM_Terms_List_Table extends WPJAM_Builtin_List_Table{
	public function __construct($screen){
		$taxonomy	= $screen->taxonomy;
		$object		= $screen->get_option('object');
		$args		= [
			'title'			=> $object->title,
			'capability'	=> $object->cap->edit_terms,
			'levels'		=> $object->levels,
			'hierarchical'	=> $object->hierarchical,
			'model'			=> $object->model,
			'singular'		=> 'tag',
			'data_type'		=> 'taxonomy',
			'taxonomy'		=> $taxonomy,
			'post_type'		=> $screen->post_type,
			'hook_part'		=> [$taxonomy, $taxonomy],
		];

		foreach(['slug', 'description'] as $key){
			if(!$object->supports($key)){
				wpjam_add_item($screen->id.'_removed_columns', $key);
			}
		}

		add_action('parse_term_query',	[$this, 'on_parse_term_query'], 0);

		parent::__construct($args, 'WP_Terms_List_Table');
	}

	public function get_form(){
		return $this->append_extra_tablenav(parent::get_form());
	}

	public function single_row($raw_item){
		$term	= is_numeric($raw_item) ? get_term($raw_item) : $raw_item;

		if($term){
			$object = wpjam_term($term);
			$level	= $object ? $object->level : 0;

			$this->_builtin->single_row($term, $level);
		}
	}

	protected function append_extra_tablenav($html){
		$extra	= apply_filters('wpjam_terms_extra_tablenav', '', $this->taxonomy);
		$extra	.= $this->overall_actions();

		return $extra ? preg_replace('#(<div class="tablenav top">\s+?<div class="alignleft actions bulkactions">.*?</div>)#is', '$1 '.$extra, $html) : $html;
	}

	public function filter_html($html){
		return parent::filter_html($this->append_extra_tablenav($html));
	}

	public function filter_row_actions($row_actions, $term){
		$row_actions	= array_merge($row_actions, $this->get_row_actions($term->term_id));
		$row_actions	= array_except($row_actions, ['inline hide-if-no-js']);
		$row_actions	+= wpjam_pull($row_actions, ['delete', 'view']);

		return array_merge(array_filter($row_actions), ['id'=>'ID：'.$term->term_id]);
	}

	public function on_parse_term_query($term_query){
		if(!in_array('WP_Terms_List_Table', array_column(debug_backtrace(), 'class'))){
			return;
		}

		$term_query->query_vars['list_table_query']	= true;

		$orderby	= $term_query->query_vars['orderby'];
		$object		= ($orderby && is_string($orderby)) ? $this->get_object($orderby, 'column') : null;

		if($object){
			$orderby_type	= $object->sortable_column ?? 'meta_value';

			if(in_array($orderby_type, ['meta_value_num', 'meta_value'])){
				$term_query->query_vars['meta_key']	= $orderby;
				$term_query->query_vars['orderby']	= $orderby_type;
			}else{
				$term_query->query_vars['orderby']	= $orderby;
			}
		}
	}
}

class WPJAM_Users_List_Table extends WPJAM_Builtin_List_Table{
	public function __construct($screen){
		add_action('manage_users_extra_tablenav',	[$this, 'extra_tablenav']);

		parent::__construct([
			'title'			=> '用户',
			'singular'		=> 'user',
			'capability'	=> 'edit_user',
			'data_type'		=> 'user',
			'model'			=> 'WPJAM_User',
			'hook_part'		=> ['users', 'user']
		], 'WP_Users_List_Table');
	}

	public function single_row($raw_item){
		$user	= is_numeric($raw_item) ? get_userdata($raw_item) : $raw_item;

		echo $user ? $this->_builtin->single_row($raw_item) : '';
	}

	public function filter_row_actions($row_actions, $user){
		foreach($this->get_row_actions($user->ID) as $key => $row_action){
			$object	= $this->get_object($key);

			if(is_null($object->roles) || array_intersect($user->roles, (array)$object->roles)){
				$row_actions[$key]	= $row_action;
			}
		}

		$row_actions	+= wpjam_pull($row_actions, ['delete', 'remove', 'view']);

		return array_merge(array_filter($row_actions), ['id'=>'ID: '.$user->ID]);
	}
}