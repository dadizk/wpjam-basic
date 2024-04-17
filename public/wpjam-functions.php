<?php
// register
function wpjam_register($group, $name, $args=[]){
	if($group && $name){
		$object	= new WPJAM_Register($name, $args, $group);

		WPJAM_Register::call_group('add_object_by_'.$group, $name, $object);

		return $object;
	}
}

function wpjam_unregister($group, $name){
	if($group && $name){
		WPJAM_Register::call_group('remove_object_by_'.$group, $name);
	}
}

function wpjam_get_registered_object($group, $name){
	if($group && $name){
		return WPJAM_Register::call_group('get_object_by_'.$group, $name);
	}
}

function wpjam_get_registereds($group){
	return $group ? WPJAM_Register::call_group('get_objects_by_'.$group) : [];
}

function wpjam_args($args=[]){
	return new WPJAM_Args(wpjam_array($args));
}

// Items
function wpjam_get_items_object($name){
	return wpjam_get_registered_object('items', $name) ?: wpjam_register('items', $name);
}

function wpjam_get_items($name){
	return wpjam_get_items_object($name)->get_items();
}

function wpjam_get_item($name, $key){
	return wpjam_get_items_object($name)->get_item($key);
}

function wpjam_add_item($name, ...$args){
	return wpjam_get_items_object($name)->add_item(...$args);
}

// Handler
function wpjam_register_handler(...$args){
	return WPJAM_Handler::create(...$args);
}

function wpjam_get_handler($name, $args=null){
	return WPJAM_Handler::get($name, $args);
}

function wpjam_call_handler($name, $method, ...$args){
	return WPJAM_Handler::call($name, $method, ...$args);
}

// Platform & path
function wpjam_register_platform($name, $args){
	return WPJAM_Platform::register($name, $args);
}

function wpjam_get_platform_object($name){
	return WPJAM_Platform::get($name);
}

// wpjam_get_current_platform(['weapp', 'template'], $ouput);	// 从一组中（空则全部）根据顺序获取
// wpjam_get_current_platform(['path'=>true], $ouput);			// 从已注册路径的根据优先级获取
function wpjam_get_current_platform($args=[], $output='name'){
	return WPJAM_Platform::get_current($args, $output);
}

// 获取已经注册路径的平台
function wpjam_get_current_platforms($output='names'){
	$objects	= WPJAM_Platform::get_by(['path'=>true]);

	return $output == 'names' ? array_keys($objects) : $objects;
}

function wpjam_is_platform($name){
	return (WPJAM_Platform::get($name))->verify();
}

function wpjam_add_platform_dynamic_method($method, Closure $closure){
	return WPJAM_Platform::add_dynamic_method($method, $closure);
}

function wpjam_get_platform_options($output='bit'){
	return WPJAM_Platform::get_options($output);
}

function wpjam_has_path($platform, $page_key, $strict=false){
	$object	= WPJAM_Platform::get($platform);

	return $object ? $object->has_path($page_key, $strict) : false;
}

function wpjam_get_path($platform, $page_key, $args=[]){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return '';
	}

	if(is_array($page_key)){
		$args		= $page_key;
		$page_key	= wpjam_pull($args, 'page_key');
	}

	return $object->get_path($page_key, $args);
}

function wpjam_get_tabbar($platform, $page_key=''){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return [];
	}

	if($page_key){
		return $object->get_tabbar($page_key);
	}

	return array_filter(wpjam_fill(array_keys($object->get_items()), [$object, 'get_tabbar']));
}

function wpjam_get_page_keys($platform, $args=null, $operator='AND'){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return [];
	}

	$items	= $object->get_items();

	if(is_string($args) && in_array($args, ['with_page', 'page'])){
		$items	= array_map(fn($pk)=>['page'=>$object->get_page($pk), 'page_key'=>$pk], array_keys($items));

		return array_values(array_filter($items, fn($item) => !empty($item['page'])));
	}else{
		$items	= is_array($args) ? wp_list_filter($items, $args, $operator) : $items;

		return array_keys($items);
	}
}

function wpjam_register_path($name, ...$args){
	return WPJAM_Path::create($name, ...$args);
}

function wpjam_unregister_path($name, $platform=''){
	return WPJAM_Path::remove($name, $platform);
}

function wpjam_get_path_fields($platforms=null, $args=[]){
	$object	= WPJAM_Platforms::get_instance($platforms);

	if(!$object){
		return [];
	}

	$args	= is_array($args) ? $args : ['for'=>$args];
	$strict	= wpjam_pull($args, 'for') == 'qrcode';

	return $object->get_fields($args, $strict);
}

function wpjam_parse_path_item($item, $platform=null, $postfix=''){
	$object	= WPJAM_Platforms::get_instance($platform);

	return $object ? $object->parse_item($item, $postfix) : ['type'=>'none'];
}

function wpjam_validate_path_item($item, $platforms, $postfix='', $title=''){
	$object	= WPJAM_Platforms::get_instance($platforms);

	return $object ? $object->validate_item($item, $postfix, $title) : true;
}

function wpjam_get_path_item_link_tag($parsed, $text){
	if($parsed['type'] == 'none'){
		return $text;
	}elseif($parsed['type'] == 'external'){
		return '<a href_type="web_view" href="'.$parsed['url'].'">'.$text.'</a>';
	}elseif($parsed['type'] == 'web_view'){
		return '<a href_type="web_view" href="'.$parsed['src'].'">'.$text.'</a>';
	}elseif($parsed['type'] == 'mini_program'){
		return '<a href_type="mini_program" href="'.$parsed['path'].'" appid="'.$parsed['appid'].'">'.$text.'</a>';
	}elseif($parsed['type'] == 'contact'){
		return '<a href_type="contact" href="" tips="'.$parsed['tips'].'">'.$text.'</a>';
	}elseif($parsed['type'] == ''){
		return '<a href_type="path" page_key="'.$parsed['page_key'].'" href="'.$parsed['path'].'">'.$text.'</a>';
	}
}

// Data Type
function wpjam_register_data_type($name, $args=[]){
	return WPJAM_Data_Type::register($name, $args);
}

function wpjam_get_data_type_object($name){
	return WPJAM_Data_Type::get($name);
}

function wpjam_strip_data_type($args){
	$value	= wpjam_pull($args, 'data_type');

	return $value ? wpjam_except($args, $value) : $args;
}

function wpjam_parse_data_type(&$args, $strip=false){
	$data_type	= $strip ? wpjam_pull($args, 'data_type') : array_get($args, 'data_type');

	if($data_type){
		$type_value	= $strip ? wpjam_pull($args, $data_type) : array_get($args, $data_type);

		return ['data_type'=>$data_type, $data_type=>($type_value ?: '')];
	}

	return [];
}

function wpjam_get_data_type_field($name, $args){
	$object	= WPJAM_Data_Type::get($name);

	return $object ? $object->get_field($args) : [];
}

function wpjam_get_post_id_field($post_type='post', $args=[]){
	return wpjam_get_data_type_field('post_type', ['post_type'=>$post_type]+$args);
}

function wpjam_get_authors($args=[], $return='users'){
	return get_users(array_merge($args, ['capability'=>'edit_posts']));
}

function wpjam_get_video_mp4($id_or_url){
	return WPJAM_Video_Data_Type::get_video_mp4($id_or_url);
}

function wpjam_get_qqv_mp4($vid){
	return WPJAM_Video_Data_Type::get_qqv_mp4($vid);
}

function wpjam_get_qqv_id($id_or_url){
	return WPJAM_Video_Data_Type::get_qqv_id($id_or_url);
}

// Setting
function wpjam_setting($type, $option, $blog_id=0){
	return WPJAM_Setting::get_instance($type, $option, $blog_id);
}

function wpjam_get_setting($option, $name, $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->get_setting($name);
}

function wpjam_update_setting($option, $name, $value='', $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->update_setting($name, $value);
}

function wpjam_delete_setting($option, $name, $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->delete_setting($name);
}

function wpjam_get_option($option, $blog_id=0, ...$args){
	return wpjam_setting('option', $option, $blog_id)->get_option(...$args);
}

function wpjam_update_option($option, $value, $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->update_option($value);
}

function wpjam_get_site_setting($option, $name){
	return wpjam_setting('site_option', $option)->get_setting($name);
}

function wpjam_get_site_option($option, $default=[]){
	return wpjam_setting('site_option', $option)->get_option($default);
}

function wpjam_update_site_option($option, $value){
	return wpjam_setting('site_option', $option)->update_option($value);
}

// Option
function wpjam_register_option($name, $args=[]){
	return WPJAM_Option_Setting::create($name, $args);
}

function wpjam_get_option_object($name, $by=''){
	return WPJAM_Option_Setting::get($name, $by);
}

function wpjam_add_option_section($option_name, ...$args){
	return WPJAM_Option_Section::add($option_name, ...$args);
}

// Meta Type
function wpjam_register_meta_type($name, $args=[]){
	return WPJAM_Meta_Type::register($name, $args);
}

function wpjam_get_meta_type_object($name){
	return WPJAM_Meta_Type::get($name);
}

function wpjam_register_meta_option($meta_type, $name, $args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->register_option($name, $args) : null;
}

function wpjam_unregister_meta_option($meta_type, $name){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->unregister_option($name) : null;
}

function wpjam_get_meta_options($meta_type, $args=[]){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_options($args) : [];
}

function wpjam_get_meta_option($meta_type, $name, $return='object'){
	$object	= WPJAM_Meta_Type::get($meta_type);
	$option	= $object ? $object->get_option($name) : null;

	return $return == 'object' ? $option : ($option ? $option->to_array() : []);
}

function wpjam_get_by_meta($meta_type, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_by_key(...$args) : [];
}

// wpjam_get_metadata($meta_type, $object_id, $meta_keys)
// wpjam_get_metadata($meta_type, $object_id, $meta_key, $default)
function wpjam_get_metadata($meta_type, $object_id, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_data_with_default($object_id, ...$args) : null;
}

// wpjam_update_metadata($meta_type, $object_id, $data, $defaults=[])
// wpjam_update_metadata($meta_type, $object_id, $meta_key, $meta_value, $default=null)
function wpjam_update_metadata($meta_type, $object_id, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->update_data_with_default($object_id, ...$args) : null;
}

function wpjam_delete_metadata($meta_type, $object_id, $key){
	$object	= WPJAM_Meta_Type::get($meta_type);

	if($object && $key){
		wpjam_map((array)$key, fn($k) => $object->delete_data($object_id, $k));
	}

	return true;
}

// LazyLoader
function wpjam_register_lazyloader($name, $args){
	if(!in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
		return WPJAM_Lazyloader::register($name, $args);
	}
}

function wpjam_lazyload($name, $ids){
	if(is_array($name)){
		if(wp_is_numeric_array($name)){
			array_walk($name, fn($n) => wpjam_lazyload($n, $ids));
		}else{
			array_walk($name, fn($n, $k) => wpjam_lazyload($n, array_column($ids, $k)));
		}	

		return;
	}

	$ids	= array_unique($ids);
	$ids	= array_filter($ids);

	if(!$ids){
		return;
	}

	if(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
		$name	= wpjam_remove_postfix($name, '_meta');
		$object	= wp_metadata_lazyloader();
		$object->queue_objects($name, $ids);
	}elseif(in_array($name, ['blog', 'site'])){
		_prime_site_caches($ids);
	}elseif($name == 'post'){
		_prime_post_caches($ids, false, false);

		wpjam_lazyload('post_meta', $ids);
	}elseif($name == 'term'){
		_prime_term_caches($ids);
	}elseif($name == 'comment'){
		_prime_comment_caches($ids);
	}else{
		$object	= WPJAM_Lazyloader::get($name);

		if(!$object && str_ends_with($name, '_meta')){
			$meta_type	= wpjam_remove_postfix($name, '_meta');
			$mt_object	= wpjam_get_meta_type_object($meta_type);

			if($mt_object){
				$object	= $mt_object->register_lazyloader();
			}
		}

		if($object){
			$object->queue_objects($ids);
		}else{
			$object	= wpjam_get_items_object('lazyloader');
			$items	= $object->get_items($name);
			$items	= array_unique(array_merge($items, $ids));

			$object->update_items($items, $name);
		}
	}
}

function wpjam_pending_objects($name){
	$object	= wpjam_get_items_object('lazyloader');
	$items	= $object->get_items($name);

	if($items){
		$object->update_items([], $name);
	}

	return $items;
}

if(!function_exists('get_post_type_support')){
	function get_post_type_support($post_type, $feature){
		$supports	= get_all_post_type_supports($post_type);
		$support	= $supports[$feature] ?? false;

		if($support && is_array($support) && wp_is_numeric_array($support) && count($support) == 1){
			return current($support);
		}

		return $support;
	}
}

// Post Type
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, $args);
}

function wpjam_get_post_type_object($name){
	if(is_numeric($name)){
		$name	= get_post_type($name);
	}

	return WPJAM_Post_Type::get($name);
}

function wpjam_add_post_type_field($post_type, ...$args){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object){
		if(is_array($args[0])){
			array_walk($args[0], fn($field, $key) => $object->add_item($key, $field, '_fields'));
		}else{
			$object->add_item($args[0], $args[1], '_fields');
		}
	}
}

function wpjam_remove_post_type_field($post_type, $key){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object){
		$object->delete_item($key, '_fields');
	}
}

function wpjam_get_post_type_setting($post_type, $key, $default=null){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object && isset($object->$key)){
		return $object->$key;
	}

	return $default;
}

function wpjam_update_post_type_setting($post_type, $key, $value){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object){
		$object->$key	= $value;
	}
}

// Post Option
function wpjam_register_post_option($meta_box, $args=[]){
	return wpjam_register_meta_option('post', $meta_box, $args);
}

function wpjam_unregister_post_option($meta_box){
	wpjam_unregister_meta_option('post', $meta_box);
}

function wpjam_get_post_options($post_type='', $args=[]){
	return wpjam_get_meta_options('post', array_merge($args, ['post_type'=>$post_type]));
}

function wpjam_get_post_option($name, $return='object'){
	return wpjam_get_meta_option('post', $name, $return);
}

// Post Column
function wpjam_register_posts_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['data_type'=>'post_type']));
	}
}

function wpjam_unregister_posts_column($name){
	if(is_admin() && did_action('current_screen')){
		wpjam_add_item(get_current_screen()->id.'_removed_columns', $name);
	}
}

// Post
function wpjam_post($post, $wp_error=false){
	return WPJAM_Post::get_instance($post, null, $wp_error);
}

function wpjam_get_post_object($post, $post_type=null){
	return WPJAM_Post::get_instance($post, $post_type);
}

function wpjam_get_post($post, $args=[]){
	$object	= wpjam_post($post);

	return $object ? $object->parse_for_json($args) : null;
}

function wpjam_get_posts($query, $parse=false){
	if($parse !== false){
		$args	= is_array($parse) ? $parse : [];
		$parse	= true;
	}

	if(is_string($query) || wp_is_numeric_array($query)){
		$ids	= wp_parse_id_list($query);
		$posts	= WPJAM_Post::get_by_ids($ids);

		return $parse ? array_values(wpjam_filter(array_map(fn($p) => wpjam_get_post($p, $args), $ids), 'isset', false)) : $posts;
	}else{
		return $parse ? wpjam_parse_query($query, $args) : (WPJAM_Posts::query($query))->posts;
	}
}

function wpjam_get_post_views($post=null){
	$post	= get_post($post);

	return $post ? (int)get_post_meta($post->ID, 'views', true) : 0;
}

function wpjam_update_post_views($post=null, $offset=1){
	$post	= get_post($post);

	if($post){
		$views	= wpjam_get_post_views($post);

		if(is_single() && $post->ID == get_queried_object_id()){
			static $viewd = false;

			if($viewd){	// 确保只加一次
				return $views;
			}

			$viewd	= true;
		}

		$views	+= $offset;

		update_post_meta($post->ID, 'views', $views);

		return $views;
	}

	return null;
}

function wpjam_get_post_excerpt($post=null, $length=0, $more=null){
	$post	= get_post($post);

	if($post){
		if($post->post_excerpt){
			return wp_strip_all_tags($post->post_excerpt, true);
		}

		$excerpt	= get_the_content('', false, $post);
		$excerpt	= strip_shortcodes($excerpt);
		$excerpt	= excerpt_remove_blocks($excerpt);
		$excerpt	= wp_strip_all_tags($excerpt, true);
		$length		= $length ?: apply_filters('excerpt_length', 200);
		$more		= $more ?? apply_filters('excerpt_more', ' &hellip;');

		return mb_strimwidth($excerpt, 0, $length, $more, 'utf-8');
	}

	return '';
}

function wpjam_get_post_content($post=null, $raw=false){
	$content	= get_the_content('', false, $post);

	return $raw ? $content : str_replace(']]>', ']]&gt;', apply_filters('the_content', $content));
}

function wpjam_get_post_first_image_url($post=null, $size='full'){
	$post		= get_post($post);
	$content	= $post ? $post->post_content : '';

	if($content){
		if(preg_match('/class=[\'"].*?wp-image-([\d]*)[\'"]/i', $content, $matches)){
			return wp_get_attachment_image_url($matches[1], $size);
		}

		if(preg_match('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $content, $matches)){
			return wpjam_get_thumbnail($matches[1], $size);
		}
	}

	return '';
}

function wpjam_get_post_images($post=null, $large='', $thumbnail='', $full=true){
	$object	= wpjam_post($post);

	return $object ? $object->parse_images($large, $thumbnail, $full) : [];
}

function wpjam_get_post_thumbnail_url($post=null, $size='full', $crop=1){
	$object	= wpjam_post($post);

	return $object ? $object->get_thumbnail_url($size, $crop) : '';
}

// Post Query
function wpjam_query($args=[]){
	return new WP_Query(wp_parse_args($args, [
		'no_found_rows'			=> true,
		'ignore_sticky_posts'	=> true,
	]));
}

function wpjam_parse_query($wp_query, $args=[], $parse=true){
	if($parse){
		$args	= array_merge($args, ['list_query'=>true]);
		$method	= 'parse';
	}else{
		$method	= 'render';
	}

	return call_user_func(['WPJAM_Posts', $method], $wp_query, $args);
}

function wpjam_render_query($wp_query, $args=[]){
	return WPJAM_Posts::render($wp_query, $args);
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query(...$args){
	if(count($args) <= 1){
		$post	= get_the_ID();
		$args	= ['number'=>$args[0] ?? 5];
	}else{
		$post	= $args[0];
		$args	= $args[1];
	}

	return WPJAM_Posts::get_related_query($post, $args);
}

function wpjam_get_related_object_ids($tt_ids, $number, $page=1){
	return WPJAM_Posts::get_related_object_ids($tt_ids, $number, $page);
}

function wpjam_related_posts($args=[]){
	echo wpjam_get_related_posts(null, $args, false);
}

function wpjam_get_related_posts($post=null, $args=[], $parse=false){
	$wp_query	= wpjam_get_related_posts_query($post, $args);

	return wpjam_parse_query($wp_query, $args, $parse);
}

function wpjam_get_new_posts($args=[], $parse=false){
	return wpjam_parse_query([
		'posts_per_page'	=> 5,
		'orderby'			=> 'date',
	], $args, $parse);
}

function wpjam_get_top_viewd_posts($args=[], $parse=false){
	return wpjam_parse_query([
		'posts_per_page'	=> 5,
		'orderby'			=> 'meta_value_num',
		'meta_key'			=> 'views',
	], $args, $parse);
}


// Taxonomy
function wpjam_register_taxonomy($name, ...$args){
	$args	= count($args) == 2 ? array_merge($args[1], ['object_type'=>$args[0]]) : $args[0];

	return WPJAM_Taxonomy::register($name, $args);
}

function wpjam_get_taxonomy_object($name){
	if(is_numeric($name)){
		$name	= get_term_taxonomy($name);
	}

	return WPJAM_Taxonomy::get($name);
}

function wpjam_add_taxonomy_field($taxonomy, ...$args){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object){
		if(is_array($args[0])){
			wpjam_map($args[0], fn($field, $k) => $object->add_item($k, $field, '_fields'));
		}else{
			$object->add_item($args[0], $args[1], '_fields');
		}
	}
}

function wpjam_remove_taxonomy_field($taxonomy, $key){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object){
		$object->delete_item($key, '_fields');
	}
}

function wpjam_get_taxonomy_setting($taxonomy, $key, $default=null){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object && isset($object->$key)){
		return $object->$key;
	}

	return $default;
}

function wpjam_update_taxonomy_setting($taxonomy, $key, $value){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object){
		$object->$key	= $value;
	}
}

if(!function_exists('taxonomy_supports')){
	function taxonomy_supports($taxonomy, $feature){
		$object	= WPJAM_Taxonomy::get($taxonomy);

		return $object ? $object->supports($feature) : false;
	}
}

if(!function_exists('add_taxonomy_support')){
	function add_taxonomy_support($taxonomy, $feature){
		$object	= WPJAM_Taxonomy::get($taxonomy);

		return $object ? $object->add_support($feature) : null;
	}
}

if(!function_exists('remove_taxonomy_support')){
	function remove_taxonomy_support($taxonomy, $feature){
		$object	= WPJAM_Taxonomy::get($taxonomy);

		return $object ? $object->remove_support($feature) : null;
	}
}	

function wpjam_get_taxonomy_query_key($taxonomy){
	return ['category'=>'cat', 'post_tag'=>'tag_id'][$taxonomy] ?? $taxonomy.'_id';
}

function wpjam_get_term_id_field($taxonomy='category', $args=[]){
	return wpjam_get_data_type_field('taxonomy', ['taxonomy'=>$taxonomy]+$args);
}

// Term Option
function wpjam_register_term_option($name, $args=[]){
	return wpjam_register_meta_option('term', $name, $args);
}

function wpjam_unregister_term_option($name){
	wpjam_unregister_meta_option('term', $name);
}

function wpjam_get_term_options($taxonomy='', $args=[]){
	return wpjam_get_meta_options('term', array_merge($args, ['taxonomy'=>$taxonomy]));
}

function wpjam_get_term_option($name, $return='object'){
	return wpjam_get_meta_option('term', $name, $return);
}

// Term Column
function wpjam_register_terms_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['data_type'=>'taxonomy']));
	}
}

function wpjam_unregister_terms_column($name){
	if(is_admin() && did_action('current_screen')){
		wpjam_add_item(get_current_screen()->id.'_removed_columns', $name);
	}
}

// Term
function wpjam_term($term, $wp_error=false){
	return WPJAM_Term::get_instance($term, null, $wp_error);
}

function wpjam_get_term_object($term, $taxonomy=''){
	return WPJAM_Term::get_instance($term, $taxonomy);
}

function wpjam_get_term($term, $args=[]){
	$args		= is_array($args) ? $args : ['taxonomy'=>$args];
	$taxonomy	= wpjam_pull($args, 'taxonomy');
	$object		= WPJAM_Term::get_instance($term, $taxonomy, false);

	return $object ? $object->parse_for_json($args) : null;
}

// $args, $max_depth
// $term_ids, $args
function wpjam_get_terms(...$args){
	if(is_string($args[0]) || wp_is_numeric_array($args[0])){
		$ids	= wp_parse_id_list(array_shift($args));
		$terms	= WPJAM_Term::get_by_ids($ids);
		$args	= array_shift($args);

		if(is_bool($args)){
			$parse	= $args;
			$args	= [];
		}else{
			$args	= is_array($args) ? $args : [];
			$parse	= wpjam_pull($args, 'parse');
		}

		return $parse ? array_map(fn($term) => wpjam_get_term($term, $args), $terms) : $terms;
	}else{
		$args	= isset($args[1]) ? array_merge($args[0], ['max_depth'=>$args[1]]) : $args[0];
		
		return WPJAM_Terms::parse($args);
	}
}

function wpjam_get_all_terms($taxonomy){
	return get_terms([
		'suppress_filter'	=> true,
		'taxonomy'			=> $taxonomy,
		'hide_empty'		=> false,
		'orderby'			=> 'none',
		'get'				=> 'all'
	]);
}

if(!function_exists('get_term_taxonomy')){
	function get_term_taxonomy($id){
		$term	= get_term($id);

		return ($term && !is_wp_error($term)) ? $term->taxonomy : null;
	}
}

function wpjam_get_term_thumbnail_url($term=null, $size='full', $crop=1){
	$object	= wpjam_term($term);

	return $object ? $object->get_thumbnail_url($size, $crop) : '';
}

// User
function wpjam_user($user, $wp_error=false){
	return WPJAM_User::get_instance($user, $wp_error);
}

function wpjam_get_user_object($user){
	return wpjam_user($user);
}

function wpjam_get_user($user, $size=96){
	$object	= wpjam_user($user);

	return $object ? $object->parse_for_json($size) : null;
}

// Bind
function wpjam_register_bind($type, $appid, $args){
	$object	= wpjam_get_bind_object($type, $appid);

	return $object ?: WPJAM_Bind::create($type, $appid, $args);
}

function wpjam_get_bind_object($type, $appid){
	return WPJAM_Bind::get($type.':'.$appid);
}

// User Signup
function wpjam_register_user_signup($name, $args){
	return WPJAM_User_Signup::create($name, $args);
}

function wpjam_get_user_signups($args=[], $output='objects', $operator='and'){
	return WPJAM_User_Signup::get_registereds($args, $output, $operator);
}

function wpjam_get_user_signup_object($name){
	return WPJAM_User_Signup::get($name);
}

// AJAX
function wpjam_register_ajax($name, $args){
	return WPJAM_AJAX::register($name, $args);
}

function wpjam_get_ajax_data_attr($name, $data=[], $return=null){
	$object	= WPJAM_AJAX::get($name);

	return $object ? $object->get_attr($data, $return) : ($return ? null : []);
}

// Capability
function wpjam_map_meta_cap(...$args){
	if(count($args) >=4){	// $caps, $cap, $user_id, $args
		$map	= (in_array('do_not_allow', $args[0]) || !$args[2]) ? null : wpjam_get_item('map_meta_cap', $args[1]);

		if($map && is_callable($map)){
			return call_user_func($map, $args[2], $args[3], $args[1]);
		}

		return $map ?: $args[0];
	}elseif(count($args) >= 2){	// $cap, $map_meta_cap
		if($args[0] && $args[1] && (is_callable($args[1]) || wp_is_numeric_array($args[1]))){
			if(!wpjam_get_items('map_meta_cap')){
				add_filter('map_meta_cap', 'wpjam_map_meta_cap', 10, 4);
			}

			wpjam_add_item('map_meta_cap', $args[0], $args[1]);
		}
	}
}

// Verification Code
function wpjam_generate_verification_code($key, $group='default'){
	$object	= WPJAM_Verification_Code::get_instance($group);

	return $object->generate($key);
}

function wpjam_verify_code($key, $code, $group='default'){
	$object	= WPJAM_Verification_Code::get_instance($group);

	return $object->verify($key, $code);
}

// Verify TXT
function wpjam_register_verify_txt($name, $args){
	return WPJAM_Verify_TXT::register($name, $args);
}

// Upgrader
function wpjam_register_plugin_updater($hostname, $update_url){
	return WPJAM_Updater::create('plugin', $hostname, $update_url);
}

function wpjam_register_theme_updater($hostname, $update_url){
	return WPJAM_Updater::create('theme', $hostname, $update_url);
}

// Notice
function wpjam_add_admin_notice($notice, $blog_id=0){
	if(is_multisite() && $blog_id && !get_site($blog_id)){
		return;
	}

	return (WPJAM_Notice::get_instance('admin', $blog_id))->insert($notice);
}

function wpjam_add_user_notice($user_id, $notice){
	if($user_id && !get_userdata($user_id)){
		return;
	}

	return (WPJAM_Notice::get_instance('user', $user_id))->insert($notice);
}

function wpjam_preprocess_args($args){
	$hooks	= wpjam_pull($args, 'hooks');
	$init	= wpjam_pull($args, 'init');

	if($hooks && $hooks !== true){
		wpjam_hooks($hooks);
	}

	if($init && $init !== true){
		wpjam_load('init', $init);
	}

	if(!empty($args['capability']) && !empty($args['map_meta_cap'])){
		wpjam_map_meta_cap($args['capability'], wpjam_pull($args, 'map_meta_cap'));
	}

	return $args;
}

// Rewrite Rule
function wpjam_add_rewrite_rule($args){
	if($args && is_array($args)){
		if(is_array($args[0])){
			array_walk($args, 'wpjam_add_rewrite_rule');
		}else{
			add_rewrite_rule(...[$GLOBALS['wp_rewrite']->root.array_shift($args), ...$args]);
		}
	}
}

// Menu Page
function wpjam_add_menu_page(...$args){
	if(is_array($args[0])){
		$menu_page	= $args[0];
	}else{
		$page_type	= !empty($args[1]['plugin_page']) ? 'tab_slug' : 'menu_slug';
		$menu_page	= array_merge($args[1], [$page_type => $args[0]]);

		if(!is_admin() && isset($menu_page['function']) && $menu_page['function'] == 'option'){
			if(!empty($menu_page['sections']) || !empty($menu_page['fields'])){
				$option_name	= $menu_page['option_name'] ?? $menu_slug;

				wpjam_register_option($option_name, $menu_page);
			}
		}
	}

	if(wp_is_numeric_array($menu_page)){
		array_walk($menu_page, 'wpjam_add_menu_page');
	}else{
		$menu_page	= wpjam_preprocess_args($menu_page);

		if(is_admin()){
			if(!empty($menu_page['tab_slug'])){
				WPJAM_Tab_Page::add($menu_page);
			}else{
				WPJAM_Admin::add_menu($menu_page);
			}
		}
	}
}


