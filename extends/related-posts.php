<?php
/*
Name: 相关文章
URI: https://mp.weixin.qq.com/s/J6xYFAySlaaVw8_WyDGa1w
Description: 相关文章扩展根据文章的标签和分类自动生成相关文章列表，并显示在文章末尾。
Version: 1.0
*/
class WPJAM_Related_Posts extends WPJAM_Option_Model{
	public static function get_fields(){
		$options 	= self::get_post_types();
		$support	= get_theme_support('related-posts');
		$show_if	= ['key'=>'thumb', 'value'=>1];
		$fields		= [
			'title'	=> ['title'=>'列表标题',	'type'=>'text',		'value'=>'相关文章',	'class'=>''],
			'list'	=> ['title'=>'列表设置',	'type'=>'fields',	'fields'=>[
				'number'	=> ['type'=>'number',	'value'=>5,	'class'=>'small-text',	'before'=>'显示',	'after'=>'篇相关文章，'],
				'days'		=> ['type'=>'number',	'value'=>0,	'class'=>'small-text',	'before'=>'从最近',	'after'=>'天的文章中筛选，0则不限制。'],
			]],
			'item'	=> ['title'=>'列表内容',	'type'=>'fieldset',	'fields'=>[
				'excerpt'	=> ['type'=>'checkbox',	'description'=>'显示文章摘要。',	'id'=>'_excerpt'],
				'thumb'		=> ['type'=>'checkbox',	'description'=>'显示文章缩略图。',	'group'=>'size',	'value'=>1],
				'size'		=> ['type'=>'fields',	'fields_type'=>'size',	'group'=>'size',	'show_if'=>$show_if,	'before'=>'缩略图尺寸：'],
				'_view'		=> ['type'=>'view',		'show_if'=>$show_if,	'value'=>'如勾选之后缩略图不显示，请到「<a href="'.admin_url('page=wpjam-thumbnail').'">缩略图设置</a>」勾选「无需修改主题，自动应用 WPJAM 的缩略图设置」。']
			]],
			'style'	=> ['title'=>'列表样式',	'type'=>'fieldset',	'fields'=>[
				'div_id'	=> ['type'=>'text',	'class'=>'',	'value'=>'related_posts',	'before'=>'外层 DIV id： &emsp;',	'after'=>'不填则无外层 DIV。'],
				'class'		=> ['type'=>'text',	'class'=>'',	'value'=>'',	'before'=>'列表 UL class：'],
			]],
			'auto'	=> ['title'=>'自动附加',	'type'=>'checkbox',	'value'=>1,	'description'=>'自动附加到文章末尾。'],
		];

		if(count($options) > 1){
			$fields['post_types']	= ['title'=>'文章类型',	'before'=>'显示相关文章的文章类型：',	'type'=>'checkbox',	'options'=>$options];
		}

		if($support){
			unset($fields['auto']);
			unset($fields['style']);
		}

		return $fields;
	}

	public static function get_menu_page(){
		return [
			'tab_slug'		=> 'related-posts',
			'plugin_page'	=> 'wpjam-posts',
			'order'			=> 19,
			'function'		=> 'option',
			'option_name'	=> 'wpjam-related-posts',
			'summary'		=> __FILE__,
		];
	}

	public static function get_post_types(){
		$ptypes	= ['post'=>__('Post')];

		foreach(get_post_types(['_builtin'=>false]) as $ptype){
			if(is_post_type_viewable($ptype) && get_object_taxonomies($ptype)){
				$ptypes[$ptype]	= wpjam_get_post_type_setting($ptype, 'title');
			}
		}

		return $ptypes;
	}

	public static function get_args($for=''){
		$args		= self::get_setting() ?: [];
		$support	= get_theme_support('related-posts');

		if($support){
			$support	= is_array($support) ? current($support) : [];
			$args		= array_except($args, ['div_id', 'class', 'auto']);
			$args		= array_merge($support, $args);
		}

		if(!empty($args['thumb'])){
			$ratio	= $for == 'api' ? 2 : 1;

			if(isset($args['width']) || isset($args['height'])){
				$args['size']	= wp_array_slice_assoc($args, ['width', 'height']);
			}

			$args['size']	= wpjam_parse_size($args['size'], $ratio);
		}

		return $args;
	}

	public static function filter_the_content($content){
		if(get_the_ID() == get_queried_object_id()){
			return $content.wpjam_get_related_posts(get_the_ID(), self::get_args('auto'));
		}

		return $content;
	}

	public static function filter_post_json($post_json){
		if($post_json['id'] == get_queried_object_id()){
			$post_json['related']	= wpjam_get_related_posts($post_json['id'], self::get_args('api'), true);
		}

		return $post_json;
	}

	public static function on_the_post($post, $wp_query){
		if($wp_query->is_main_query()
			&& !$wp_query->is_page()
			&& $wp_query->is_singular($post->post_type)
			&& $post->ID == $wp_query->get_queried_object_id()
		){
			$ptypes	= self::get_post_types();

			if(count($ptypes) > 1){
				$setting	= self::get_setting('post_types');
				$ptypes		= $setting ? wp_array_slice_assoc($ptypes, $setting) : $ptypes;

				$has	= isset($ptypes[$post->post_type]);
			}else{
				$has 	= $post->post_type == 'post';
			}

			if($has){
				$args	= self::get_args();

				if(current_theme_supports('related-posts')){
					add_theme_support('related-posts', $args);
				}

				if(wpjam_is_json_request() && empty($args['rendered'])){
					add_filter('wpjam_post_json',	[self::class, 'filter_post_json'], 10, 2);
				}else{
					if(!empty($args['auto'])){
						add_filter('the_content',	[self::class, 'filter_the_content'], 11);
					}
				}
			}
		}
	}

	public static function shortcode($atts, $content=''){
		$atts	= shortcode_atts(['tag'=>''], $atts);
		$tags	= $atts['tag'] ? explode(",", $atts['tag']) : '';

		return $tags ? wpjam_render_query(wpjam_query([
			'post_type'		=> 'any',
			'no_found_rows'	=> true,
			'post_status'	=> 'publish',
			'post__not_in'	=> [get_the_ID()],
			'tax_query'		=> [[
				'taxonomy'	=> 'post_tag',
				'terms'		=> $tags,
				'operator'	=> 'AND',
				'field'		=> 'name'
			]]
		]), ['thumb'=>false, 'class'=>'related-posts']) : '';
	}

	public static function add_hooks(){
		if(!is_admin()){
			add_action('the_post', [self::class, 'on_the_post'], 10, 2);
		}

		add_shortcode('related', [self::class, 'shortcode']);
	}
}

wpjam_register_option('wpjam-related-posts',	['model'=>'WPJAM_Related_Posts', 'title'=>'相关文章']);
