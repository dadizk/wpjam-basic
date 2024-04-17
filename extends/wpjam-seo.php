<?php
/*
Name: 简单 SEO
URI: https://mp.weixin.qq.com/s/LzGWzKCEl5SdJCQdBvFipg
Description: 简单 SEO 扩展实现最简单快捷的方式设置 WordPress 站点的 SEO。
Version: 2.0
*/
class WPJAM_SEO extends WPJAM_Option_Model{
	public static function sanitize_callback($value){
		flush_rewrite_rules();

		return $value;
	}

	public static function get_fields(){
		if(file_exists(ABSPATH.'robots.txt')){
			$robots_field	= ['type'=>'view',	'value'=>'博客的根目录下已经有 robots.txt 文件。<br />请直接编辑或者删除之后在后台自定义。'];
		}else{
			$robots_field	= ['type'=>'textarea',	'class'=>'',	'rows'=>10,	'value'=>self::get_default_robots()];
		}

		if(file_exists(ABSPATH.'sitemap.xml')){
			$wpjam_sitemap	= '博客的根目录下已经有 sitemap.xml 文件。<br />删除之后才能使用插件自动生成的 sitemap.xml。';
		}else{
			$wpjam_sitemap	= '首页/分类/标签：<a href="'.home_url('/sitemap.xml').'" target="_blank">'.home_url('/sitemap.xml').'</a>
			<br />前1000篇文章：<a href="'.home_url('/sitemap-1.xml').'" target="_blank">'.home_url('/sitemap-1.xml').'</a>
			<br />1000-2000篇文章：<a href="'.home_url('/sitemap-2.xml').'" target="_blank">'.home_url('/sitemap-2.xml').'</a>
			<br />以此类推...';
		}

		$wp_sitemap	= 'sitemap 地址：<a href="'.home_url('/wp-sitemap.xml').'" target="_blank">'.home_url('/wp-sitemap.xml').'</a>';
		$auto_view	= '<p>文章摘要作为页面的 Meta Description，文章的标签作为页面的 Meta Keywords。<br />分类和标签的描述作为页面的 Meta Description，页面没有 Meta Keywords。</p>';
		$unique		= '如果当前主题或其他插件也会生成摘要和关键字，可以通过勾选该选项移除。<br />如果当前主题没有<code>wp_head</code>Hook，也可以通过勾选该选项确保生成摘要和关键字。';

		$fields	= [
			'home_set'	=> ['title'=>'首页设置',	'type'=>'fieldset',	'summary'=>'设置首页 SEO',	'fields'=>[
				'home_title'		=> ['title'=>'标题',		'type'=>'text',	'placeholder'=>'不填则使用标题'],
				'home_description'	=> ['title'=>'描述',		'type'=>'textarea', 'class'=>''],
				'home_keywords'		=> ['title'=>'关键字',	'type'=>'text' ],
			]],
			'post_set'	=> ['title'=>'文章和分类页',	'type'=>'fieldset',	'fields'=>[
				'individual'	=> ['type'=>'select', 	'options'=>[0=>'自动获取摘要和关键字',1=>'单独设置 SEO TDK']],
				'auto'			=> ['type'=>'view', 	'show_if'=>['key'=>'individual', 'value'=>'0'],	'value'=>$auto_view],
				'list_table'	=> ['type'=>'select',	'show_if'=>['key'=>'individual', 'value'=>'1'],	'value'=>1,	'options'=>['1'=>'编辑和列表页都可设置','0'=>'仅可在编辑页设置','only'=>'仅可在列表页设置']]
			]],
			'unique'	=> ['title'=>'确保唯一设置',	'type'=>'checkbox',	'description'=>$unique],
			'robots'	=> ['title'=>'robots.txt']+$robots_field,
			'sitemap'	=> ['title'=>'Sitemap',		'type'=>'select',	'options'=>[0=>['label'=>'使用 WPJAM 生成的','description'=>$wpjam_sitemap], 'wp'=>['label'=>'使用 WordPress 内置的','description'=>$wp_sitemap]]]
		];

		return $fields;
	}

	public static function get_menu_page(){
		return [
			'tab_slug'		=> 'seo',
			'plugin_page'	=> 'wpjam-seo',
			'title'			=> 'SEO设置',
			'order'			=> 20,
			'function'		=> 'option',
			'summary'		=> __FILE__,
		];
	}

	public static function get_admin_load(){
		if(self::get_setting('individual')){
			return [
				'base'	=> ['post','edit', 'edit-tags', 'term'], 
				'model'	=> self::class
			];
		}
	}

	public static function get_default_robots(){
		$site_url	= parse_url(site_url());
		$path		= !empty($site_url['path'])  ? $site_url['path'] : '';

		return "User-agent: *
		Disallow: /wp-admin/
		Disallow: /wp-includes/
		Disallow: /cgi-bin/
		Disallow: $path/wp-content/plugins/
		Disallow: $path/wp-content/themes/
		Disallow: $path/wp-content/cache/
		Disallow: $path/author/
		Disallow: $path/trackback/
		Disallow: $path/feed/
		Disallow: $path/comments/
		Disallow: $path/search/";
	}

	public static function redirect($action){
		$sitemap	= '';

		if(!$action){
			$last_mod	= str_replace(' ', 'T', get_lastpostmodified('GMT')).'+00:00';
			$sitemap	.= "\t<url>\n";
			$sitemap	.="\t\t<loc>".home_url()."</loc>\n";
			$sitemap	.="\t\t<lastmod>".$last_mod."</lastmod>\n";
			$sitemap	.="\t\t<changefreq>daily</changefreq>\n";
			$sitemap	.="\t\t<priority>1.0</priority>\n";
			$sitemap	.="\t</url>\n";

			$taxonomies = [];
			foreach (get_taxonomies(['public' => true]) as $taxonomy => $value) {
				if($taxonomy != 'post_format'){
					$taxonomies[]	= $taxonomy;
				}
			}

			$terms	= get_terms(['taxonomy'=>$taxonomies]);

			foreach ($terms as $term) {
				$priority		= ($term->taxonomy == 'category')?0.6:0.4;
				$sitemap	.="\t<url>\n";
				$sitemap	.="\t\t<loc>".get_term_link($term)."</loc>\n";
				$sitemap	.="\t\t<lastmod>".$last_mod."</lastmod>\n";
				$sitemap	.="\t\t<changefreq>daily</changefreq>\n";
				$sitemap	.="\t\t<priority>".$priority."</priority>\n";
				$sitemap	.="\t</url>\n";
			}
		}elseif(is_numeric($action)){
			$post_types = [];

			foreach (get_post_types(['public' => true]) as $post_type => $value) {
				if($post_type != 'page' && $post_type != 'attachment'){
					$post_types[] = $post_type;
				}
			}

			$posts	= wpjam_get_posts(['posts_per_page'=>1000, 'paged'=>$action, 'post_type'=>$post_types]);

			if($posts){
				foreach($posts as $post){
					$permalink	= get_permalink($post->ID); //$siteurl.$post->post_name.'/';
					$last_mod	= str_replace(' ', 'T', $post->post_modified_gmt).'+00:00';
					$sitemap	.="\t<url>\n";
					$sitemap	.="\t\t<loc>".$permalink."</loc>\n";
					$sitemap	.="\t\t<lastmod>".$last_mod."</lastmod>\n";
					$sitemap	.="\t\t<changefreq>weekly</changefreq>\n";
					$sitemap	.="\t\t<priority>0.8</priority>\n";
					$sitemap	.="\t</url>\n";
				}
			}
		}else{
			$sitemap = apply_filters('wpjam_'.$action.'_sitemap', '');
		}

		header ("Content-Type:text/xml");

		$renderer	= new WP_Sitemaps_Renderer();

		echo '<?xml version="1.0" encoding="UTF-8"?>
		<?xml-stylesheet type="text/xsl" href="'.$renderer->get_sitemap_stylesheet_url().'"?>
		<!-- generated-on="'.date('d. F Y').'" -->
		<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$sitemap."\n".'</urlset>'."\n";

		exit;
	}

	public static function get_rewrite_rule(){
		return [
			['sitemap\.xml?$',  'index.php?module=sitemap', 'top'],
			['sitemap-(.*?)\.xml?$',  'index.php?module=sitemap&action=$matches[1]', 'top'],
		];
	}

	public static function get_meta_fields(){
		return [
			'seo_title'			=> ['title'=>'SEO标题',	'type'=>'text',		'class'=>'large-text',	'placeholder'=>'不填则使用标题'],
			'seo_description'	=> ['title'=>'SEO描述',	'type'=>'textarea'],
			'seo_keywords'		=> ['title'=>'SEO关键字','type'=>'text',		'class'=>'large-text']
		];
	}

	public static function get_meta_value($type='title', $output='html'){
		if(is_front_page()){
			if(get_query_var('paged') < 2){
				$value	= self::get_setting('home_'.$type);
			}
		}elseif(is_tag() || is_category() || is_tax()){
			if(get_query_var('paged') < 2){
				if(self::get_setting('individual')){
					$value	= get_term_meta(get_queried_object_id(), 'seo_'.$type, true);
				}

				if(empty($value) && $type == 'description'){
					$value	= term_description();
				}
			}
		}elseif(is_singular()){
			if(self::get_setting('individual')){				
				$value	= get_post_meta(get_the_ID(), 'seo_'.$type, true);
			}

			if(empty($value)){
				if($type == 'description'){
					$value	= get_the_excerpt();
				}elseif($type == 'keywords'){
					$tags	= get_the_tags();
					$value = $tags ? implode(',', wp_list_pluck($tags, 'name')) : '';
				}
			}
		}

		if(!empty($value)){
			$value	= wpjam_get_plain_text($value);

			if($type == 'title'){
				$value	= esc_textarea($value);

				return $output == 'html' ? '<title>'.$value.'</title>'."\n" : $value;
			}else{
				$value	= esc_attr($value);

				return $output == 'html' ? "<meta name='{$type}' content='{$value}' />\n" : $value;
			}

			return $value;
		}
	}

	public static function filter_document_title($title){
		return self::get_meta_value('title', '') ?: $title;
	}

	public static function filter_robots_txt($output, $public){
		return '0' == $public ? "Disallow: /\n" : self::get_setting('robots');
	}

	public static function filter_html($html){
		$title 	= self::get_meta_value('title');
		$values	= array_filter(wpjam_fill(['description', 'keywords'], [self::class, 'get_meta_value']));

		if($values){
			$html	= preg_replace('#<meta\s{1,}name=[\'"]('.implode('|', array_keys($values)).')[\'"]\s{1,}content=[\'"].*?[\'"]\s{1,}\/>#is', '', $html);
		}

		if($title || $values){
			$title	= $title ?: '\1';
			$values	= $values ? "\n".implode('', $values) : '';

			return preg_replace('#(<title>.*?<\/title>)#is', $title.$values, $html);
		}

		return $html;
	}

	public static function on_wp_head(){
		remove_action('wp_head', '_wp_render_title_tag', 1);

		echo self::get_meta_value('title') ?: _wp_render_title_tag();
		echo self::get_meta_value('description');
		echo self::get_meta_value('keywords');
	}

	public static function builtin_page_load($screen){
		if(in_array($screen->base, ['edit', 'post']) 
			&& $screen->post_type != 'attachment' 
			&& is_post_type_viewable($screen->post_type)
		){
			wpjam_register_post_option('seo', [
				'title'			=> 'SEO设置',
				'page_title'	=> 'SEO设置',
				'context'		=> 'side',
				'list_table'	=> self::get_setting('list_table', 1),
				'fields'		=> [self::class,'get_meta_fields']
			]);
		}elseif(in_array($screen->base, ['edit-tags', 'term']) 
			&& is_taxonomy_viewable($screen->taxonomy)
		){
			wpjam_register_term_option('seo', [
				'title'			=> 'SEO设置',
				'page_title'	=> 'SEO设置',
				'action'		=> 'edit',
				'submit_text'	=> '设置',
				'list_table'	=> self::get_setting('list_table', 1),
				'fields'		=> [self::class, 'get_meta_fields']
			]);
		}
	}

	public static function init(){
		if(self::get_setting('sitemap') == 0){
			wpjam_register_route('sitemap', ['model'=>self::class]);
		}
	}

	public static function add_hooks(){
		if(self::get_setting('unique')){
			add_filter('wpjam_html',	[self::class, 'filter_html']);
		}else{
			add_action('wp_head',		[self::class, 'on_wp_head'], 0);
		}

		add_filter('robots_txt',		[self::class, 'filter_robots_txt'], 10, 2);
		add_filter('document_title',	[self::class, 'filter_document_title']);
	}
}

wpjam_register_option('wpjam-seo',	['model'=>'WPJAM_SEO',	'title'=>'SEO设置',]);
