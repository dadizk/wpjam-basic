<?php
class WPJAM_Field extends WPJAM_Attr{
	protected function __construct($args){
		$this->args	= $args;

		$this->_data_type	= wpjam_get_data_type_object($this->data_type);

		if($this->_data_type){
			$this->query_args	= $this->_data_type->parse_query_args($this) ?: new StdClass;
		}

		$this->parse_name();
	}

	public function __get($key){
		$value	= parent::__get($key);

		if(is_null($value)){
			if($key == '_editable'){
				return $this->show_admin_column !== 'only' && !$this->disabled && !$this->readonly && !$this->is('view');
			}elseif($key == '_name'){
				return array_reverse($this->names)[0];
			}elseif($key == '_title'){
				return $this->title.'「'.$this->key.'」';
			}elseif($key == '_options'){
				return $this->parse_options();
			}elseif($key == '_fields'){
				return $this->_fields = WPJAM_Fields::create($this->fields, $this);
			}elseif($key == '_item'){
				$args	= array_except($this->get_args(), ['required', 'show_in_rest']);

				return $this->_item = self::create(array_merge($args, ['type'=>$this->item_type]));
			}
		}

		return $value;
	}

	public function __call($method, $args){
		if(str_contains($method, '_by_')){
			[$method, $type]	= explode('_by', $method);

			if($this->$type){
				if($type == '_data_type'){
					$args[]	= array_merge((array)$this->query_args, ['title'=>$this->_title]);
				}

				return wpjam_try([$this->$type, $method], ...$args);
			}

			return array_shift($args);
		}elseif(str_ends_with($method, '_from_schema')){
			return wpjam_try('rest_'.$method, $args[0], ($args[1] ?: $this->get_schema()), $this->_title);
		}

		trigger_error($method);
	}

	public function is($type, $strict=false){
		$type	= wp_parse_list($type);

		if(!$strict){
			if(in_array('mu', $type)){
				if(is_a($this, 'WPJAM_MU_Field')){
					return true;
				}
			}

			if(in_array('mu-checkbox', $type)){
				if($this->is('checkbox') && $this->options){
					return true;
				}
			}

			if(in_array('fieldset', $type)){
				$type[]	= 'fields';
			}

			if(in_array('view', $type)){
				$type	= ['hr', 'br', ...$type];
			}
		}

		return in_array($this->type, $type, $strict);
	}

	public function get_default(){
		return $this->show_in_rest('default') ?? $this->value;
	}

	public function get_schema(){
		return $this->_schema	??= $this->parse_schema();
	}

	protected function prepare_schema(){
		$schema	= ['type'=>'string'];

		if($this->is('email')){
			$schema['format']	= 'email';
		}elseif($this->is('color')){
			$schema['format']	= 'hex-color';
		}elseif($this->is('url')){
			$schema['format']	= 'uri';
		}elseif($this->is('number, range')){
			$step	= $this->step ?: '';

			if($step == 'any' || strpos($step, '.')){
				$schema['type']	= 'number';
			}else{
				$schema['type']	= 'integer';

				if($step > 1){
					$schema['multipleOf']	= $step;
				}
			}
		}elseif($this->is('timestamp')){
			$schema['type']	= 'integer';
		}elseif($this->is('checkbox')){
			$schema['type']	= 'boolean';
		}

		return $schema;
	}

	protected function parse_schema(...$args){
		if($args){
			$schema	= $args[0];
		}else{
			$schema	= $this->prepare_schema();
			$map	= [];

			if(in_array($schema['type'], ['number', 'integer'])){
				$map	= [
					'minimum'	=> 'min',
					'maximum'	=> 'max',
					'patter'	=> 'pattern',
				];
			}elseif($schema['type'] == 'string'){
				$map	= [
					'minLength'	=> 'minlength',
					'maxLength'	=> 'maxlength',
					'pattern'	=> 'pattern'
				];
			}elseif($schema['type'] == 'array'){
				$map	= [
					'maxItems'		=> 'max_items',
					'minItems'		=> 'min_items',
					'uniqueItems'	=> 'unique_items',
				];
			}

			$schema		= $schema+filter_null(array_map(fn($k) => $this->$k, $map));
			$_schema	= $this->show_in_rest('schema');
			$_type		= $this->show_in_rest('type');

			if(is_array($_schema)){
				$schema	= merge_deep($schema, $_schema);
			}

			if($_type){
				if($schema['type'] == 'array' && $_type != 'array'){
					$schema['items']['type']	= $_type;
				}else{
					$schema['type']	= $_type;
				}
			}

			if($this->required && !$this->show_if){	// todo 以后可能要改成 callback
				$schema['required']	= true;
			}
		}

		$type	= $schema['type'];

		if($type != 'object'){
			unset($schema['properties']);
		}elseif($type != 'array'){
			unset($schema['items']);
		}

		if(isset($schema['enum'])){
			$callback		= ['integer'=>'intval', 'number'=>'floatval'][$type] ?? 'strval';
			$schema['enum']	= array_map($callback, $schema['enum']);
		}elseif(isset($schema['properties'])){
			$schema['properties']	= array_map([$this, 'parse_schema'], $schema['properties']);
		}elseif(isset($schema['items'])){
			$schema['items']	= $this->parse_schema($schema['items']);
		}

		return $schema;
	}

	protected function parse_options(...$args){
		$parsed	= [];

		foreach(($args ? $args[0] : $this->options) as $opt => $item){
			if(is_array($item)){
				if(isset($item['options'])){
					$parsed	= array_replace($parsed, $this->parse_options($item['options']));
				}elseif(!empty($item['title'])){
					$parsed[$opt]	= $item['title'];
				}elseif(!empty($item['label'])){
					$parsed[$opt]	= $item['label'];
				}
			}else{
				$parsed[$opt]	= $item;
			}
		}

		return $parsed;
	}

	protected function parse_show_if(...$args){
		$show_if	= $args ? $args[0] : $this->show_if;

		if(!is_array($show_if) || empty($show_if['key'])){
			return false;
		}

		if(isset($show_if['compare']) || !isset($show_if['query_arg'])){
			$show_if	= wp_parse_args($show_if, ['value'=>true]);
			$value		= WPJAM_Compare::parse($show_if['value']);

			$show_if['value']	= is_array($value) ? array_map('strval', $value) : $value;	// JS Array.indexof is strict
		}

		foreach(['postfix', 'prefix'] as $fix){
			$show_if['key']	= wpjam_call('wpjam_add_'.$fix, $show_if['key'], array_pull($show_if, $fix, $this->{'_'.$fix}));
		}

		return $show_if;
	}

	protected function parse_name(...$args){
		$name	= $args ? $args[0] : (string)$this->pull('prepend_name');
		$names	= $name ? [$name] : [];
		$arr	= str_contains($this->name, '[') ? wp_parse_args($this->name) : [$this->name=>''];

		while($arr){
			$n			= array_key_first($arr);
			$arr		= $arr[$n];
			$names[]	= $n;
			$name		.= $name ? '['.$n.']' : $n;
		}

		$this->name		= $name;
		$this->names	= $names;
	}

	public function show_in_rest($key=null){
		$value	= $this->show_in_rest ?? $this->_editable;

		return $key ? array_get($value, $key) : $value;
	}

	public function show_if($values){
		return wpjam_show_if($values, $this->parse_show_if());
	}

	public function validate($value, $schema=null){
		$value	= wpjam_try([$this, 'validate_value'], $value);

		if($this->required && is_blank($value)){
			$this->exception([$this->_title], 'value_required');
		}

		$value	= $this->sanitize_value($value);

		if(is_array($value) || !is_blank($value)){	// 空值只需 required 验证
			if($this->pattern && $this->custom_validity){
				if(!rest_validate_json_schema_pattern($this->pattern, $value)){
					$this->exception($this->_title.' '.$this->custom_validity, 'rest_invalid_pattern');
				}
			}

			$this->validate_value_from_schema($value, $schema);
		}

		return $value;
	}

	public function validate_value($value){
		return $this->validate_value_by_data_type($value);
	}

	protected function sanitize_value($value, $schema=null){
		if(!$schema){
			$schema	= $this->get_schema();
			$value	= isset($value) ? parent::sanitize_value($value) : (!empty($schema['required']) ? false : $value);
		}

		$type	= $schema['type'];

		if($type == 'array'){
			if(is_array($value)){
				$value	= array_map(fn($v) => $this->sanitize_value($v, $schema['items']), $value);
			}
		}elseif($type == 'integer'){
			if(is_numeric($value)){
				$value	= (int)$value;
			}
		}elseif($type == 'number'){
			if(is_numeric($value)){
				$value	= (float)$value;
			}
		}elseif($type == 'string'){
			if(is_scalar($value)){
				$value	= (string)$value;
			}
		}elseif($type == 'null'){
			if(is_blank($value)){
				$value	= null;
			}
		}elseif($type == 'boolean'){
			if(is_scalar($value) || is_null($value)){
				$value	= rest_sanitize_boolean($value);
			}
		}

		return $value;
	}

	public function pack($value){
		return array_reduce(array_reverse($this->names), fn($v, $sub) => [$sub => $v], $value);
	}

	public function unpack($data){
		return _wp_array_get($data, $this->names);
	}

	public function value_callback($args=[]){
		$value	= null;

		if($args && (!$this->is('view') || is_null($this->value))){
			$name	= $this->names[0];
			$id		= array_get($args, 'id');

			if($this->value_callback){
				$value	= wpjam_value_callback($this->value_callback, $name, $id);
			}elseif(!empty($args['data']) && isset($args['data'][$name])){
				$value	= $args['data'][$name];
			}else{
				if(!empty($args['value_callback'])){
					$value	= wpjam_value_callback($args['value_callback'], $name, $id);
				}

				if($id && !empty($args['meta_type']) && (is_wp_error($value) || is_null($value))){
					$value	= wpjam_get_metadata($args['meta_type'], $id, $name);
				}
			}

			$value	= (is_wp_error($value) || is_null($value)) ? null : $this->unpack([$name=>$value]);
		}

		$value	= is_null($value) ? $this->get_default() : $value;

		if($this->is('view') && $this->options){
			$values	= $value ? [$value] : ['', 0];
			$values	= wp_array_slice_assoc($this->_options, $values);

			return $values ? current($values) : $value;
		}

		return $value;
	}

	public function prepare($args, $schema=null){
		$value	= $this->sanitize_value($this->value_callback($args));

		if(is_array($value) || !is_blank($value)){
			$value	= $this->sanitize_value_from_schema($value, $schema);
		}

		return $this->prepare_value($value);
	}

	public function prepare_value($value){
		return $this->prepare_value_by_data_type($value, $this->parse_required);
	}

	public function wrap($tag, $args=[]){
		$args	= wpjam_array($args, 'object');
		$class	= array_merge(...array_map('wp_parse_list', array_filter([$this->wrap_class, $args['wrap_class']])));
		$class	= [...$class, $this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')];
		$data 	= ['show_if'=>$this->parse_show_if()];
		$tag	= $tag ?: ($data['show_if'] ? 'span' : '');
		$field	= $this->render($args, false);

		if($args['creator'] && !$args['creator']->is('fields')){
			$class[]	= 'sub-field';
			$label		= $this->label(null, true);

			if($label){
				$label->add_class('sub-field-label');
				$field->wrap('div', ['sub-field-detail']);
			}
		}else{
			$label	= $this->label();
		}

		if($tag == 'tr'){
			if($label){
				$label->wrap('th', ['scope'=>'row']);
			}

			$field->wrap('td', ['colspan'=>($label ? false : 2)]);
		}elseif($tag == 'p'){
			$label	.= $label ? wpjam_tag('br') : '';
		}

		return $field->before($label)->wrap($tag, ['class'=>$class, 'data'=>$data, 'id'=>$tag.'_'.esc_attr($this->id)]);
	}

	public function render($args=[], $to_string=true){
		if(is_null($this->class)){
			if($this->is('textarea')){
				$this->add_class('large-text');
			}elseif($this->is('text, password, url, image, file, mu-image, mu-file')){
				$this->add_class('regular-text');
			}
		}

		$this->class	= $this->class();
		$this->value	= $this->value_callback($args);

		if($this->render){
			$tag	= call_user_func($this->render, $args, $this);
		}else{
			$tag	= $this->is('fieldset') ? $this->render_by_fields($args) : $this->render_component();
		}

		$tag	= wpjam_wrap($tag);

		if($args){
			$tag->before($this->before ? $this->before.' ' : '')->after($this->after  ? ' '.$this->after : '');

			if($this->buttons){
				$tag->after(' '.implode(' ', array_map([self::class, 'create'], $this->buttons, array_keys($this->buttons))));
			}

			if($this->before || $this->after || $this->label || $this->buttons){
				$this->label($tag);
			}

			if($this->description){
				$tag->after($this->description, 'p', ['description']);
			}

			if($this->is('fieldset')){
				if($this->is('fieldset', true)){
					if($this->summary){
						$tag->before([$this->summary, 'strong'], 'summary')->wrap('details');
					}

					if($this->group){
						$this->add_class('field-group');
					}
				}

				if($this->class || $this->data() || $this->style){
					$tag->wrap('div', ['data'=>$this->data()+['key'=>$this->key], 'class'=>$this->class, 'style'=>$this->style]);
				}
			}
		}

		return $to_string ? (string)$tag : $tag;
	}

	protected function render_component(){
		if($this->is('view')){
			return $this->value;
		}elseif($this->is('hr')){
			return wpjam_tag('hr');
		}elseif($this->is('editor, textarea')){
			$this->cols	??= 50;

			if($this->is('editor') && user_can_richedit()){
				$this->rows	??= 12;
				$this->id	= 'editor_'.$this->id;

				if(!wp_doing_ajax()){
					$editor	= wpjam_ob_get_contents('wp_editor', ($this->value ?: ''), $this->id, [
						'textarea_name'	=> $this->name,
						'textarea_rows'	=> $this->rows
					]);

					return wpjam_tag('div', ['style'=>$this->style], $editor);
				}

				$this->data('editor', ['tinymce'=>true, 'quicktags'=>true, 'mediaButtons'=>current_user_can('upload_files')]);
			}else{
				$this->rows	??= 6;
			}

			return $this->tag([], 'textarea', esc_textarea(implode("\n", (array)$this->value)));
		}elseif($this->is('checkbox')){
			return $this->tag(['value'=>1, 'checked'=>($this->value == 1)])->after($this->label);
		}elseif($this->is('timestamp')){
			return $this->tag(['type'=>'datetime-local', 'value'=>($this->value ? wpjam_date('Y-m-d\TH:i', $this->value) : '')]);
		}else{
			if($this->_data_type){
				$this->attr('_query', $this->query_label_by_data_type($this->value) ?: '');
			}

			return $this->tag();
		}
	}

	protected function label($tag=null, $force=false){
		$tag	= $tag ?: ($this->title ? wpjam_wrap($this->title) : null);

		if($tag){
			$for	= $this->is('view, mu, fieldset, img, uploader, radio, mu-checkbox') ? null : $this->id;

			return ($for || $force) ? $tag->wrap('label', ['for'=>$for]) : $tag;
		}
	}

	protected function tag($attr=[], $tag='input', $text=''){
		$attr	= wpjam_attr($this->get_args())->attr($attr);

		$attr->data($attr->pulls(['key', 'data_type', 'query_args', 'custom_validity']));
		$attr->add_class('field-key-'.$this->key);
		$attr->delete_arg(['default', 'options', 'title', 'names', 'label', 'before', 'after', 'description', 'item_type', 'max_items', 'min_items', 'unique_items', 'direction', 'group', 'buttons', 'button_text', 'custom_input', 'size', 'post_type', 'taxonomy', 'sep', 'fields', 'mime_types', 'drap_drop', 'parse_required', 'show_if', 'show_in_rest', 'sortable_column', 'column_style', 'show_admin_column', 'wrap_class']);

		if($tag == 'input'){
			if(!isset($attr['inputmode'])){
				if(in_array($attr['type'], ['url', 'tel', 'email', 'search'])){
					$attr['inputmode']	= $attr['type'];
				}elseif($attr['type'] == 'number'){
					$attr['inputmode']	= ($attr['step'] == 'any' || strpos(($attr['step'] ?: ''), '.')) ? 'decimal' : 'numeric';
				}
			}
		}else{
			$attr	= array_except($attr, ['type', 'value']);
		}

		$tag	= wpjam_tag($tag, $attr->to_array(), $text);

		if(isset($this->_query)){
			self::get_icon('dismiss')->after($this->_query)->wrap('span', [...$this->class, 'query-title'])->insert_after($tag);
		}

		return $tag;
	}

	public function affix($affix_by, $i=null, $item=null){
		$prepend	= $affix_by->name;
		$prefix		= $affix_by->key.'__';
		$postfix	= '';

		if(isset($i)){
			$prepend	.= '['.$i.']';
			$postfix	= $this->_postfix = '__'.$i;

			if(is_array($item) && isset($item[$this->name])){
				$this->value	= $item[$this->name];
			}
		}

		$this->parse_name($prepend);

		$this->_prefix	= $prefix.$this->_prefix ;
		$this->id		= $prefix.$this->id.$postfix;
		$this->key		= $prefix.$this->key.$postfix;

		return $this;
	}

	public static function get_icon($name){
		return array_reduce(wp_parse_list($name), fn($i, $n) => $i->after(...([
			'sortable'	=> ['span', ['dashicons', 'dashicons-menu']],
			'multiply'	=> ['span', ['dashicons', 'dashicons-no-alt']],
			'dismiss'	=> ['span', ['dashicons', 'dashicons-dismiss']],
			'del_btn'	=> ['删除', 'a', ['button', 'del-item']],
			'del_icon'	=> ['a', ['dashicons', 'dashicons-no-alt', 'del-item']],
			'del_img'	=> ['a', ['dashicons', 'dashicons-no-alt', 'del-img']],
		][$n])), wpjam_tag());
	}

	public static function create($args, $key=''){
		if($key){
			$args['key']	= $key;
		}

		if(is_numeric($args['key'])){
			trigger_error('Field 的 key「'.$args['key'].'」'.'不能为纯数字');
			return;
		}elseif(!$args['key']){
			trigger_error('Field 的 key 不能为空');
			return;
		}

		$total	= array_pull($args, 'total');
		if($total && !isset($args['max_items'])){
			$args['max_items']	= $total;
		}

		$field	= self::process($args);

		if(!empty($field['size'])){
			$size	= $field['size'] = wpjam_parse_size($field['size']);

			if(!isset($field['description']) && !empty($size['width']) && !empty($size['height'])){
				$field['description']	= '建议尺寸：'.$size['width'].'x'.$size['height'];
			}
		}

		if(empty($fields['buttons']) && !empty($field['button'])){
			$fields['buttons']	= [$field['button']];
		}

		$field['options']	= wp_parse_args(array_get($field, 'options'));
		$field['id']		= array_get($field, 'id') ?: $field['key'];
		$field['name']		= array_get($field, 'name') ?: $field['key'];
		$field['type']		= $type	= array_get($field, 'type') ?: ($field['options'] ? 'select' : 'text');

		if(in_array($type, ['image', 'mu-image'])){
			$field['item_type']	= 'image';
		}elseif($type == 'mu-text'){
			$field['item_type']	??= 'text';

			if(!isset($field['class']) && $field['item_type'] != 'select'){
				if(isset($field['direction']) && $field['direction'] == 'row'){
					$field['class']	= 'medium-text';
				}else{
					$field['class']	= 'regular-text';
				}
			}
		}elseif($type == 'timestamp'){
			$field['sanitize_callback']	??= 'wpjam_strtotime';
		}elseif(in_array($type, ['fieldset', 'fields'])){
			if(!empty($field['data_type'])){
				$field['fieldset_type']	= 'array';
			}
		}elseif($type == 'checkbox'){
			if(!$field['options'] && !isset($field['label']) && !empty($field['description'])){
				$field['label']	= array_pull($field, 'description');
			}
		}

		if(in_array($type, ['select', 'radio']) || ($type == 'checkbox' && $field['options'])){
			return new WPJAM_Options_Field($field);
		}elseif(in_array($type, ['img', 'image', 'file', 'uploader'])){
			return new WPJAM_Image_Field($field);
		}elseif(str_starts_with($type, 'mu-')){
			return new WPJAM_MU_Field($field);
		}

		return new WPJAM_Field($field);
	}
}

class WPJAM_Options_Field extends WPJAM_Field{
	protected function call_custom($action, $value){
		$values	= array_map('strval', array_keys($this->_options));
		$input	= $this->custom_input;

		if($input){
			$field	= $this->_custom;

			if(is_null($field)){
				$title	= is_string($input)	? $input : '其他';
				$custom	= is_array($input)	? $input : [];
				$field	= $this->_custom = self::create(wp_parse_args($custom, [
					'title'			=> $title,
					'placeholder'	=> '请输入其他选项',
					'id'			=> $this->id.'__custom_input',
					'key'			=> $this->key.'__custom_input',
					'type'			=> 'text',
					'class'			=> '',
					'required'		=> true,
					'data-wrap_id'	=> $this->is('select') ? '' : $this->id.'_options',
					'show_if'		=> ['key'=>$this->key, 'value'=>'__custom'],
				]));
			}

			if($action == 'render'){
				$value	= $this->value;
			}elseif($action == 'checked'){
				return !is_null($field->value);
			}

			if($this->is('checkbox')){
				$value	= $value ?: [];
				$value	= array_diff($value, ['__custom']);
				$diff	= array_diff($value, $values);

				if($diff){
					$field->val(current($diff));

					if($action == 'validate'){
						if(count($diff) > 1){
							$field->exception($field->_title.'只能传递一个其他选项值', 'too_many_custom_value');
						}

						$field->validate(current($diff), $this->get_schema()['items']);
					}
				}
			}else{
				if($value && !in_array($value, $values)){
					$field->val($value);

					if($action == 'validate'){
						$field->validate($value, $this->get_schema());
					}
				}
			}

			if($action == 'render'){
				$this->options	+= ['__custom'=>$field->title];

				return $field->attr(['title'=>'', 'name'=>$this->name])->wrap('span');
			}
		}else{
			if($action == 'prepare_schema'){
				$value	+= ['enum'=>$values];
			}
		}

		return $value;
	}

	protected function prepare_schema(){
		$schema	= $this->call_custom('prepare_schema', ['type'=>'string']);

		return $this->is('checkbox') ? ['type'=>'array', 'items'=>$schema] : $schema;
	}

	public function prepare_value($value){
		return $this->call_custom('prepare', $value);
	}

	public function validate_value($value){
		return $this->call_custom('validate', $value);
	}

	protected function render_component(){
		if($this->is('checkbox')){
			$this->name	.= '[]';
		}

		$custom	= $this->call_custom('render', '');
		$items	= $this->render_options($this->options);

		if($this->is('select')){
			return $this->tag([], 'select', implode('', $items))->after($custom ? '&emsp;'.$custom : '');
		}else{
			$dir	= $this->direction ?: ($this->sep ? '' : 'row');
			$sep	= $this->sep ?? ($dir ? '' : '&emsp;');

			return wpjam_tag('span', [
				'id'	=> $this->id.'_options',
				'class'	=> [($dir ? 'direction-'.$dir : ''), ($this->is('checkbox') ? 'mu-checkbox' : '')],
				'data'	=> ['max_items'=>$this->max_items]
			], implode($sep, $items).($custom ? $sep.$custom : ''));
		}
	}

	protected function render_options($options, $value=null){
		$value	??= $this->value;
		$items	= [];

		foreach($options as $opt => $label){
			$attr	= $data = $class = [];

			if(is_array($label)){
				$arr	= $label;
				$label	= current(array_pulls($arr, ['label', 'title']));
				$image	= array_pull($arr, 'image');

				if($image){
					$image	= is_array($image) ? array_slice($image, 0, 2) : [$image];
					$label	= implode('', array_map(fn($i) => wpjam_tag('img', ['src'=>$i, 'alt'=>$label]), $image));
					$class	= ['image-'.$this->type];
				}

				foreach($arr as $k => $v){
					if(is_numeric($k)){
						if(self::is_bool($v)){
							$attr[$v]	= $v;
						}
					}elseif(self::is_bool($k)){
						if($v){
							$attr[$k]	= $k;
						}
					}elseif($k == 'show_if'){
						$data['show_if']	= $this->parse_show_if($v);
					}elseif($k == 'class'){
						$class	= [...$class, ...wp_parse_list($v)];
					}elseif($k == 'description'){
						$this->description	.= wpjam_wrap($v, 'span', ['data-show_if'=>$this->parse_show_if(['key'=>$this->key, 'value'=>$opt])]);
					}elseif($k == 'options'){
						$attr[$k]	= $v;
					}elseif(!is_array($v)){
						$data[$k]	= $v;
					}
				}
			}

			if($opt === '__custom'){
				$checked	= $this->call_custom('checked', false);
			}else{
				if($this->is('checkbox')){
					$checked	= is_array($value) && in_array($opt, $value);
				}else{
					$value 		??= $opt;
					$checked	= $value ? ($opt == $value) : !$opt;
				}
			}

			if($this->is('select')){
				$attr	= $attr+['data'=>$data, 'class'=>$class];
				$sub	= array_pull($attr, 'options');

				if(isset($sub)){
					$sub		= $sub ? implode('', $this->render_options($sub, $value)) : '';
					$items[]	= wpjam_tag('optgroup', array_merge($attr, ['label'=>$label]), $sub);
				}else{
					$items[]	= wpjam_tag('option', array_merge($attr, ['value'=>$opt, 'selected'=>$checked]), $label);
				}
			}else{
				$opt_id		= $this->id.'_'.$opt;
				$wrap_id	= $this->id.'_options';
				$attr		= ['required'=>false, 'checked'=>$checked, 'id'=>$opt_id, 'data-wrap_id'=>$wrap_id, 'value'=>$opt]+$attr;
				$items[]	= $this->tag($attr)->after($label)->wrap('label', ['data'=>$data, 'class'=>$class, 'for'=>$opt_id]);
			}
		}

		return $items;
	}
}

class WPJAM_Image_Field extends WPJAM_Field{
	protected function prepare_schema(){
		if($this->is('uploader')){
			return ['type'=>'string'];
		}elseif($this->is('img') && $this->item_type != 'url'){
			return ['type'=>'integer'];
		}

		return ['type'=>'string', 'format'=>'uri'];
	}

	public function prepare_value($value){
		return $this->is('uploader') ? $value : wpjam_get_thumbnail($value, $this->size);
	}

	protected function render_component(){
		if(!current_user_can('upload_files')){
			$this->attr('disabled', 'disabled');
		}

		if($this->is('uploader')){
			$class		= ['hide-if-no-js', 'plupload'];
			$mime_types	= $this->mime_types ?: ['title'=>'图片', 'extensions'=>'jpeg,jpg,gif,png'];
			$btn_id		= 'plupload_button__'.$this->key;
			$btn_text	= $this->button_text ?: __('Select Files');
			$btn_attr	= ['type'=>'button', 'class'=>'button', 'id'=>$btn_id, 'value'=>$btn_text];
			$container	= 'plupload_container__'.$this->key;
			$plupload	= [
				'browse_button'		=> $btn_id,
				'container'			=> $container,
				'file_data_name'	=> $this->key,
				'filters'			=> [
					'mime_types'	=> wp_is_numeric_array($mime_types) ? $mime_types : [$mime_types],
					'max_file_size'	=> (wp_max_upload_size()?:0).'b'
				],
				'multipart_params'	=> [
					'_ajax_nonce'	=> wp_create_nonce('upload-'.$this->key),
					'action'		=> 'wpjam-upload',
					'file_name'		=> $this->key,
				]
			];

			$data	= ['key'=>$this->key, 'plupload'=>&$plupload];
			$title	= $this->value ? array_slice(explode('/', $this->value), -1)[0] : '';
			$tag	= $this->attr('_query', $title)->tag(['type'=>'hidden'])->before('input', $btn_attr);

			if($this->drap_drop && !wp_is_mobile()){
				$dd_id		= 'plupload_drag_drop__'.$this->key;
				$plupload	+= ['drop_element'=>$dd_id];
				$class[]	= 'drag-drop';

				$tag->wrap('p', ['drag-drop-buttons'])
				->before('p', [], _x('or', 'Uploader: Drop files here - or - Select Files'))
				->before('p', ['drag-drop-info'], __('Drop files to upload'))
				->wrap('div', ['drag-drop-inside'])
				->wrap('div', ['id'=>$dd_id, 'class'=>'plupload-drag-drop']);
			}

			$progress	= wpjam_tag('div', ['progress', 'hidden'], ['div', ['percent']])->append('div', ['bar']);

			return $tag->after($progress)->wrap('div', ['id'=>$container, 'class'=>$class, 'data'=>$data]);
		}elseif($this->is('img')){
			$size	= $this->size ?: '600x0';
			$size	= wpjam_parse_size($size, [600, 600]);
			$attr	= array_filter(['width'=>(int)($size['width']/2), 'height'=>(int)($size['height']/2)]);
			$data	= ['item_type'=>$this->item_type, 'thumb_args'=>wpjam_get_thumbnail_args($size)];
			$img	= $this->value ? wpjam_get_thumbnail($this->value, $size) : '';
			$img	= wpjam_tag('img', $attr+['src'=>$img, 'class'=>($img ? '' : 'hidden')]);
			$button	= wpjam_tag('span', ['wp-media-buttons-icon'])->after($this->button_text ?: '添加图片')->wrap('button', ['button', 'add_media'])->wrap('div', ['wp-media-buttons']);

			return $this->tag(['type'=>'hidden'])->before($img.$button.self::get_icon('del_img'), 'div', ['class'=>'wpjam-img', 'data'=>$data]);
		}else{
			$title	= '选择'.($this->is('image') ? '图片' : '文件');

			return $this->tag(['type'=>'url'])->after($title, 'a', ['class'=>'button', 'data'=>['item_type'=>$this->item_type]])->wrap('div', ['wpjam-file']);
		}
	}
}

class WPJAM_MU_Field extends WPJAM_Field{
	protected function prepare_schema(){
		if($this->is('mu-fields')){
			$items	= $this->get_schema_by_fields();
		}elseif($this->is('mu-text')){
			$items	= $this->get_schema_by_item();
		}else{
			$items	= ($this->is('mu-img') && $this->item_type != 'url') ? ['type'=>'integer'] : ['type'=>'string', 'format'=>'uri'];
		}

		return ['type'=>'array', 'items'=>$items];
	}

	public function prepare_value($value){
		if($this->is('mu-fields')){
			return array_map([$this, 'prepare_value_by_fields'], $value);
		}elseif($this->is('mu-text')){
			return array_map([$this, 'prepare_value_by_item'], $value);
		}else{
			return array_filter(array_map(fn($v) => wpjam_get_thumbnail($v, $this->size), $value));
		}
	}

	public function validate_value($value){
		if($value){
			$value	= is_array($value) ? filter_blank($value, true) : wpjam_json_decode($value);
		}

		if(!$value || is_wp_error($value)){
			return [];
		}

		$value	= array_values($value);

		if($this->is('mu-fields')){
			return array_map([$this, 'validate_value_by_fields'], $value);
		}elseif($this->is('mu-text')){
			return array_map([$this, 'validate_value_by_item'], $value);
		}

		return $value;
	}

	protected function render_component(){
		if($this->is('mu-img, mu-image, mu-file')){
			if(!current_user_can('upload_files')){
				$this->disabled	= 'disabled';
			}

			$data	= ['item_type'=>$this->item_type];
		}

		$value	= $this->value ?: [];
		$value	= is_array($value) ? array_values(filter_blank($value, true)) : (array)$value;

		$last		= count($value);
		$value[]	= null;

		if($this->is('mu-text')){
			if(count($value) <= 1 && $this->direction == 'row' && $this->item_type != 'select'){
				$last ++;

				$value[]	= null;
			}
		}elseif($this->is('mu-img')){
			$this->direction	= 'row';
		}

		if(!$this->is('mu-fields, mu-img') && $this->max_items && $last >= $this->max_items){
			unset($value[$last]);

			$last --;
		}

		$args	= ['id'=>'', 'name'=>$this->name.'[]'];
		$items	= [];
		$text	= $this->button_text ?: '添加'.(($this->title && mb_strwidth($this->title) <= 8) ? $this->title : '选项');

		$sortable	= $this->_editable ? ($this->sortable ?? true) : false;
		$sortable	= $sortable ? 'sortable' : '';

		foreach($value as $i => $item){
			$args['value']	= $item;

			if($this->is('mu-fields')){
				if($last === $i){
					$item	= $this->render_by_fields(['i'=>'{{ data.i }}']);
					$item	= $item->wrap('script', ['type'=>'text/html', 'id'=>'tmpl-'.md5($this->id)]);
				}else{
					$item	= $this->render_by_fields(['i'=>$i, 'item'=>$item]);
				}
			}elseif($this->is('mu-text')){
				if($this->item_type == 'select' && $last === $i){
					$options	= $this->attr_by_item('options');

					if(!in_array('', array_keys($options))){
						$args['options']	= array_replace([''=>['title'=>'请选择', 'disabled', 'hidden']], $options);
					}
				}

				$item	= $this->sandbox_by_item(fn() => $this->attr($args)->render());
			}elseif($this->is('mu-img')){
				$img	= $item ? wpjam_get_thumbnail($item) : '';
				$thumb	= wpjam_get_thumbnail($item, [200, 200]);
				$item	= $this->tag($args+['type'=>'hidden']);

				if($img){
					$item->before('a', ['href'=>$img, 'class'=>'wpjam-modal'], ['img', ['src'=>$thumb]]);
				}
			}else{
				$item	= $this->tag($args+['type'=>'url']);
			}

			$icon	= ($this->direction == 'row' ? 'del_icon' : 'del_btn').','.$sortable;
			$item	.= self::get_icon($icon);

			if($last === $i){
				$class	= 'button';

				if($this->is('mu-text')){
					$data	= [];
				}elseif($this->is('mu-fields')){
					$data	= ['i'=>$i, 'tmpl_id'=>md5($this->id)];
				}elseif($this->is('mu-img')){
					$data	+= ['thumb_args'=>wpjam_get_thumbnail_args([200, 200])];
					$text	= '';
					$class	= 'dashicons dashicons-plus-alt2';
				}else{
					$data	+= ['title'=>($this->item_type == 'image' ? '选择图片' : '选择文件')];
					$text	= $data['title'].'[多选]';
				}

				$item	.= wpjam_tag('a', ['class'=>'new-item '.$class, 'data'=>$data], $text);
			}

			$items[]	= wpjam_tag('div', ['mu-item', ($this->group ? 'field-group' : '')], $item);
		}

		return wpjam_tag('div', [
			'id'	=> $this->id,
			'class'	=> [$this->type, $sortable, 'direction-'.($this->direction ?: 'column')],
			'data'	=> ['max_items'=>$this->max_items]
		], implode("\n", $items));
	}
}

class WPJAM_Fields extends WPJAM_Attr{
	private $fields		= [];
	private $creator	= null;

	private function __construct($fields, $creator=null){
		$this->fields	= $fields ?: [];
		$this->creator	= $creator;
	}

	public function	__call($method, $args){
		$data	= [];

		foreach($this->fields as $field){
			if(in_array($method, ['get_schema', 'get_defaults', 'get_show_if_values'])){
				if(!$field->_editable){
					continue;
				}
			}elseif($method == 'prepare'){
				if(!$field->show_in_rest()){
					continue;
				}
			}

			if($field->is('fieldset') && !$field->_data_type){
				$value	= wpjam_try([$field, $method.'_by_fields'], ...$args);
			}else{
				if($method == 'prepare'){
					$value	= $field->pack($field->prepare(...$args));
				}elseif($method == 'get_defaults'){
					$value	= $field->pack($field->get_default());
				}elseif($method == 'get_show_if_values'){ // show_if 判断基于key，并且array类型的fieldset的key是 ${key}__{$sub_key}
					$item	= wpjam_catch([$field, 'validate'], $field->unpack($args[0]));
					$value	= [$field->key => is_wp_error($item) ? null : $item];
				}elseif($method == 'get_schema'){
					$value	= [$field->_name => $field->get_schema()];
				}elseif(in_array($method, ['prepare_value', 'validate_value'])){
					$item	= $args[0][$field->_name] ?? null;
					$value	= is_null($item) ? [] : [$field->_name => wpjam_try([$field, $method], $item)];
				}else{
					$value	= wpjam_try([$field, $method], ...$args);
				}
			}

			$data	= merge_deep($data, $value);
		}

		if($method == 'get_schema'){
			return ['type'=>'object', 'properties'=>$data];
		}

		return $data;
	}

	public function	__invoke($args=[]){
		return $this->render($args);
	}

	public function validate($values=null){
		$values	??= wpjam_get_post_parameter();

		if(!$this->fields){
			return $values;
		}

		if($this->creator && isset($this->creator->_if_values)){
			$if_values	= $this->creator->_if_values;
			$if_show	= $this->creator->_if_show;
		}else{
			$if_values	= $this->get_show_if_values($values);
			$if_show	= true;
		}

		$data	= [];

		foreach($this->fields as $field){
			if(!$field->_editable){
				continue;
			}

			$show	= $if_show ? $field->show_if($if_values) : false;

			if($field->is('fieldset') && !$field->_data_type){
				$field->_if_values	= $if_values;
				$field->_if_show	= $show;

				$value	= $field->validate_by_fields($values);
			}else{
				if($show){
					$value	= $field->unpack($values);
					$value	= $field->validate($value);
				}else{	// 第一次获取的值都是经过 json schema validate 的，可能存在 show_if 的字段在后面
					$value	= $if_values[$field->key] = null;
				}

				$value	= $field->pack($value);
			}

			$data	= merge_deep($data, $value);
		}

		return $data;
	}

	public function render($args=null, $to_string=false){
		$args		??= $this->get_args();
		$args		= wpjam_array($args, 'object');
		$creator	= $args['creator'] = $this->creator;

		if($creator){
			$type	= '';
			$sep	= $creator->sep;

			if($creator->is('fields')){
				$sep	??= ' ';
				$tag	= '';
			}else{
				$sep	??= "\n";
				$tag	= 'div';
				$group	= current($this->fields)->group;
				$last	= array_key_last($this->fields);

				if($creator->is('mu-fields')){
					$i		= $args->pull('i');
					$item	= $args->pull('item');
				}
			}
		}else{
			$sep	= "\n";
			$type	= $args->pull('fields_type') ?? 'table';
			$tag	= $args->pull('wrap_tag') ?? (['table'=>'tr', 'list'=>'li'][$type] ?? $type);
		}

		$fields	= [];

		foreach($this->fields as $key => $field){
			if($field->show_admin_column === 'only'){
				continue;
			}

			if($creator && !$creator->is('fields')){
				if($field->group != $group){
					[$groups[], $wrappeds[], $group, $wrapped]	= [$group, $wrapped, $field->group, []];
				}

				if($creator->is('mu-fields')){
					$wrapped[]	= $field->sandbox(fn() => $this->affix($creator, $i, $item)->wrap($tag, $args));
				}else{
					$wrapped[]	= $field->wrap($tag, $args);
				}

				if($last == $key){
					[$groups[], $wrappeds[]]	= [$group, $wrapped];

					$fields		= array_map(fn($wrapped, $group) => wpjam_wrap(implode($sep, $wrapped), ($group ? 'div' : ''), ['field-group']), $wrappeds, $groups);

					if(!$creator->group){
						$sep	= "\n";
					}
				}
			}else{
				$fields[]	= $field->wrap($tag, $args);
			}
		}

		$fields	= wpjam_wrap(implode($sep, array_filter($fields)));

		if($type == 'table'){
			$fields->wrap('tbody')->wrap('table', ['cellspacing'=>0, 'class'=>'form-table']);
		}elseif($type == 'list'){
			$fields->wrap('ul');
		}

		return $to_string ? (string)$fields : $fields;
	}

	public static function create($fields, $creator=null){
		$fields		= $fields ?: [];
		$objects	= [];
		$prefix		= '';
		$propertied	= false;

		if($creator){
			$parser	= $creator->fields_parser;
			$parser	= $parser ?: ($creator->fields_type == 'size' ? [self::class, 'callback'] : null);
			$fields	= $parser ? wpjam_catch($parser, $fields, $creator->fields_type) : $fields;

			if($creator->is('fieldset')){
				if($creator->fieldset_type == 'array'){
					$propertied	= true;
				}else{
					if($creator->prefix){
						$prefix	= $creator->prefix;
						$prefix	= $prefix === true ? $creator->key : $prefix;
					}
				}
			}elseif($creator->is('mu-fields')){
				$propertied	= true;
			}

			$sink	= wp_array_slice_assoc($creator, ['readonly', 'disabled']);
		}

		foreach((array)$fields as $key => $field){
			if(isset($field['type']) && $field['type'] == 'fields'){
				$field['prefix']	= $prefix;
			}else{
				$key	= wpjam_join('_', [$prefix, $key]);
			}

			$object	= WPJAM_Field::create($field, $key);

			if(!$object){
				continue;
			}

			if($propertied){
				if(count($object->names) > 1){
					trigger_error($creator->_title.'子字段不允许[]模式:'.$object->name);

					continue;
				}

				if($object->is('fieldset', true) || $object->is('mu-fields')){
					trigger_error($creator->_title.'子字段不允许'.$object->type.':'.$object->name);

					continue;
				}
			}

			$objects[$key]	= $object;

			if($creator){
				if($creator->is('fieldset')){
					if($creator->fieldset_type == 'array'){
						$object->affix($creator);
					}else{
						$object->show_in_rest	??= $creator->show_in_rest;
					}
				}

				$object->attr($sink);
			}
		}

		return new self($objects, $creator);
	}

	protected static function callback($fields, $type='size'){
		if($type == 'size'){
			$parsed	= [];

			foreach(['width', 'x', 'height'] as $key){
				if($key == 'x'){
					$parsed['x']	= ['type'=>'view',	'value'=>WPJAM_Field::get_icon('multiply')];
				}else{
					$field	= $fields[$key] ?? [];
					$field	= wp_parse_args($field, ['type'=>'number', 'class'=>'small-text']);
					$key	= array_pull($field, 'key') ?: $key;

					$parsed[$key]	= $field;
				}
			}

			return $parsed;
		}

		return $fields;
	}

	public static function flatten($fields){
		$parsed	= [];

		foreach($fields as $key => $field){
			if(array_get($field, 'type') == 'fieldset' && array_get($field, 'fieldset_type') != 'array'){
				$parsed	= array_merge($parsed, $field['fields']);
			}else{
				$parsed[$key]	= $field;
			}
		}

		return $parsed;
	}
}