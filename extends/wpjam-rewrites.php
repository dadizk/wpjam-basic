<?php
/*
Name: Rewrite 优化
URI: https://blog.wpjam.com/m/wpjam-rewrite/
Description: Rewrites 扩展让可以优化现有 Rewrites 规则和添加额外的 Rewrite 规则。
Version: 2.0
*/
class WPJAM_Rewrite{
	public static function get_primary_key(){
		return 'rewrite_id';
	}

	public static function get_all(){
		return get_option('rewrite_rules') ?: [];
	}

	public static function get_items(){
		return wpjam_basic_get_setting('rewrites', []);
	}

	public static function update_items($rewrites){
		WPJAM_Basic::update_setting('rewrites', array_values($rewrites));
		flush_rewrite_rules();
		return true;
	}

	public static function is_added($id){
		if($current	= self::get($id)){
			foreach(self::get_items() as $i => $rewrite){
				if($rewrite['regex'] == $current['regex']){
					return $i;
				}
			}
		}

		return false;
	}

	public static function get($id){
		$rewrites	= self::get_all();
		$regex_arr	= array_keys($rewrites);
		$i			= $id-1;

		if($regex = $regex_arr[$i] ?? ''){
			return ['rewrite_id'=>$id, 'regex'=>$regex, 'query'=>$rewrites[$regex]];
		}

		return [];
	}

	public static function validate_data($data, $id=''){
		if(empty($data['regex']) || empty($data['query'])){
			wp_die('Rewrite 规则不能为空');
		}

		if(is_numeric($data['regex'])){
			wp_die('无效的 Rewrite 规则');
		}

		$rewrites	= self::get_all();

		if($id){
			$current	= self::get($id);

			if(empty($current)){
				wp_die('该 Rewrite 规则不存在');
			}elseif($current['regex'] != $data['regex'] && isset($rewrites[$data['regex']])){
				wp_die('该 Rewrite 规则已使用');
			}
		}else{
			if(isset($rewrites[$data['regex']])){
				wp_die('该 Rewrite 规则已存在');
			}
		}

		return $data;
	}

	public static function insert($data){
		$data	= self::validate_data($data);

		if(is_wp_error($data)){
			return $data;
		}

		$rewrites	= self::get_items();
		$rewrites	= array_merge([$data], $rewrites);

		self::update_items($rewrites);

		return 1;
	}

	public static function update($id, $data){
		$data	= self::validate_data($data, $id);

		if(is_wp_error($data)){
			return $data;
		}

		$i	= self::is_added($id);

		if($i !== false){
			$rewrites		= self::get_items();
			$rewrites[$i]	= $data;

			return self::update_items($rewrites);
		}

		return true;
	}

	public static function bulk_delete($ids){
		$rewrites	= self::get_items();

		foreach($ids as $id){
			$current	= self::get($id);

			if(empty($current)){
				wp_die('该 Rewrite 规则不存在');
			}

			$i	= self::is_added($id);

			if($i !== false){
				$rewrites	= array_except($rewrites, $i);
			}
		}

		return self::update_items($rewrites);
	}
	
	public static function delete($id){
		$current	= self::get($id);

		if(empty($current)){
			wp_die('该 Rewrite 规则不存在');
		}

		$i	= self::is_added($id);

		if($i !== false){
			$rewrites	= self::get_items();
			$rewrites	= array_except($rewrites, $i);

			return self::update_items($rewrites);
		}

		return true;
	}

	public static function optimize($data){
		WPJAM_Basic::update_setting($data);

		flush_rewrite_rules();

		return true;
	}

	public static function reset(){
		WPJAM_Basic::delete_setting('rewrites');

		flush_rewrite_rules();

		return true;
	}

	public static function query_items($args){
		$items		= [];
		$rewrite_id	= 0;

		foreach(self::get_all() as $regex => $query) {
			$rewrite_id++;
			$items[]	= compact('rewrite_id', 'regex', 'query');
		}

		return $items;
	}

	public static function column($item, $name){
		if(in_array($name, ['regex', 'query'])){
			return wpautop($item[$name]);
		}
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'first'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,		'bulk'=>true],
			'reset'		=> ['title'=>'重置',	'response'=>'list',	'overall'=>true,	'direct'=>true,		'confirm'=>true],
			'optimize'	=> ['title'=>'优化',	'response'=>'list',	'overall'=>true,	'class'=>'button-primary',	'value_callback'=>'wpjam_basic_get_setting'],
		];
	}

	public static function get_fields($action_key='', $id=0){
		if($action_key == 'optimize'){
			return [
				'remove_date_rewrite'		=> ['type'=>'checkbox',	'label'=>'移除日期 Rewrite 规则'],
				'remove_comment_rewrite'	=> ['type'=>'checkbox',	'label'=>'移除留言 Rewrite 规则'],
				'remove_feed=_rewrite'		=> ['type'=>'checkbox',	'label'=>'移除分类 Feed Rewrite 规则'],
			];
		}

		return [
			'regex'	=> ['title'=>'正则',	'type'=>'text',	'show_admin_column'=>true],
			'query'	=> ['title'=>'查询',	'type'=>'text',	'show_admin_column'=>true],
		];
	}

	public static function map_meta_cap($user_id, $args){
		if($args && !empty($args[0]) && WPJAM_Rewrite::is_added($args[0]) === false){
			return ['do_not_allow'];
		}

		return is_multisite() ? ['manage_sites'] : ['manage_options'];
	}

	public static function get_list_table(){
		return [
			'title'			=> 'Rewrite 规则',
			'plural'		=> 'rewrites',
			'singular'		=> 'rewrite',
			'model'			=> self::class,
			'capability'	=> 'manage_rewrites'
		];
	}

	public static function cleanup(&$rules){
		$remove = [];

		if(wpjam_basic_get_setting('remove_feed=_rewrite')){
			$remove[]	= 'feed=';
		}

		if(!get_option('wp_attachment_pages_enabled')){
			$remove[]	= 'attachment';
		}

		if(!get_option('page_comments')){
			$remove[]	= 'comment-page';
		}

		if(wpjam_basic_get_setting('disable_post_embed')){
			$remove[]	= '&embed=true';
		}

		if(wpjam_basic_get_setting('disable_trackbacks')){
			$remove[]	= '&tb=1';
		}

		if($remove){
			foreach($rules as $key => $rule){
				if($rule == 'index.php?&feed=$matches[1]'){
					continue;
				}

				foreach($remove as $r){
					if(strpos($key, $r) !== false || strpos($rule, $r) !== false){
						unset($rules[$key]);
					}
				}
			}
		}
	}

	public static function on_generate_rewrite_rules($wp_rewrite){
		self::cleanup($wp_rewrite->rules); 
		self::cleanup($wp_rewrite->extra_rules_top);

		$rewrites = wpjam_basic_get_setting('rewrites');

		if($rewrites){
			$wp_rewrite->rules = array_merge(array_column($rewrites, 'query', 'regex'), $wp_rewrite->rules);
		}
	}

	public static function add_hooks(){
		if(wpjam_basic_get_setting('remove_date_rewrite')){
			add_filter('date_rewrite_rules', '__return_empty_array');

			add_action('init', fn() => array_map('remove_rewrite_tag', ['%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%']));
		}

		if(wpjam_basic_get_setting('remove_comment_rewrite')){
			add_filter('comments_rewrite_rules', '__return_empty_array');
		}

		add_action('generate_rewrite_rules',	[self::class, 'on_generate_rewrite_rules']);
	}
}

wpjam_add_menu_page('wpjam-rewrites', [
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> 'Rewrites',
	'summary'		=> __FILE__,
	'capability'	=> 'manage_rewrites',
	'map_meta_cap'	=> ['WPJAM_Rewrite', 'map_meta_cap'],
	'hooks'			=> ['WPJAM_Rewrite', 'add_hooks'],
	'function'		=> 'list',
	'list_table'	=> 'WPJAM_Rewrite',
	'network'		=> false,
]);