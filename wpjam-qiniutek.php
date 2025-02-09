<?php
/*
Plugin Name: WPJAM 七牛镜像存储 Dev
Description: 使用七牛云存储实现 WordPress 博客静态文件 CDN 加速！
Plugin URI: http://blog.wpjam.com/project/wpjam-qiniutek/
Author: Denis
Author URI: http://blog.wpjam.com/
Version: 1.3.3
*/

define('WPJAM_QINIUTEK_PLUGIN_URL', plugins_url('', __FILE__));
define('WPJAM_QINIUTEK_PLUGIN_DIR', WP_PLUGIN_DIR.'/'. dirname(plugin_basename(__FILE__)));

if(!function_exists('wpjam_option_page')){
	include(WPJAM_QINIUTEK_PLUGIN_DIR.'/include/wpjam-setting-api.php');
}

if(!function_exists('get_term_meta')){
	include(WPJAM_QINIUTEK_PLUGIN_DIR.'/include/simple-term-meta.php');
	register_activation_hook( __FILE__,'simple_term_meta_install');
}

if(is_admin()){
	include(WPJAM_QINIUTEK_PLUGIN_DIR.'/admin/options.php');
}

include(WPJAM_QINIUTEK_PLUGIN_DIR.'/admin/default-options.php');

if(wpjam_qiniutek_get_setting('advanced')){
	include(WPJAM_QINIUTEK_PLUGIN_DIR.'/term-thumbnail.php');
}
include(WPJAM_QINIUTEK_PLUGIN_DIR.'/wpjam-thumbnail.php');
include(WPJAM_QINIUTEK_PLUGIN_DIR.'/wpjam-posts.php');

function wpjam_qiniutek_get_setting($setting_name){
	$option = wpjam_get_option('wpjam-qiniutek');
	return wpjam_get_setting($option, $setting_name);
}

//定义在七牛绑定的域名。
if(wpjam_qiniutek_get_setting('host')){
	define('CDN_HOST',wpjam_qiniutek_get_setting('host'));
}else{
	define('CDN_HOST',home_url());
}
if(wpjam_qiniutek_get_setting('local')){
	define('LOCAL_HOST',wpjam_qiniutek_get_setting('local'));
}else{
	define('LOCAL_HOST',home_url());
}

add_action('wp_loaded', 'wpjam_qiniutek_ob_cache');

if(!is_admin()){
	add_action('wp_enqueue_scripts', 'wpjam_qiniutek_enqueue_scripts', 1 );

	if(wpjam_qiniutek_get_setting('remote') && get_option('permalink_structure')){
		//add_filter('the_content', 		'wpjam_qiniutek_content',1);
		add_filter('the_content', 		'wpjam_qiniutek_content_md',1);
		add_filter('query_vars', 		'wpjam_qiniutek_query_vars');
		add_action('template_redirect',	'wpjam_qiniutek_template_redirect', 5);
	}

	add_filter('script_loader_src',		'wpjam_qiniutek_loader_src',10,2);
	add_filter('style_loader_src',		'wpjam_qiniutek_loader_src',10,2);
}

if(get_option('permalink_structure')){
	add_action('generate_rewrite_rules',	'wpjam_qiniutek_generate_rewrite_rules');
}

function wpjam_qiniutek_ob_cache(){
	ob_start('wpjam_qiniutek_cdn_replace');
}

function wpjam_qiniutek_cdn_replace($html){
	if(wpjam_qiniutek_get_setting('useso')){
		$html 	= str_replace(array('//ajax.googleapis.com','//fonts.googleapis.com'), array('//ajax.useso.com','//fonts.useso.com'), $html);
	}
	if(is_admin())	return $html;

	$cdn_exts	= wpjam_qiniutek_get_setting('exts');
	$cdn_dirs	= str_replace('-','\-',wpjam_qiniutek_get_setting('dirs'));

	// $html = preg_replace(
	// 	'/<img src="(.*?)icon_(.*?)\\.gif" alt="(.*?)" class="wp-smiley" \/>/', 
	// 	'<span class="wp-smiley emoji emoji-$2" title="$3">$3</span>', 
	// 	$html
	// );

	$html = apply_filters('wpjam_html_replace',$html);

	if($cdn_dirs){
		$regex	=  '/'.str_replace('/','\/',LOCAL_HOST).'\/(('.$cdn_dirs.')\/[^\s\?\\\'\"\;\>\<]{1,}.('.$cdn_exts.'))([\"\\\'\s\?]{1})/';
		$html =  preg_replace($regex, CDN_HOST.'/$1$4', $html);
	}else{
		$regex	= '/'.str_replace('/','\/',LOCAL_HOST).'\/([^\s\?\\\'\"\;\>\<]{1,}.('.$cdn_exts.'))([\"\\\'\s\?]{1})/';
		$html =  preg_replace($regex, CDN_HOST.'/$1$3', $html);
	}
	return $html;
}

function wpjam_qiniutek_content($content){
	return preg_replace_callback('|<img.*?src=[\'"](.*?)[\'"].*?>|i', 'wpjam_qiniutek_replace_remote_image', do_shortcode($content));
}
function wpjam_qiniutek_content_md($content){
	return preg_replace_callback('/((http|https):\/\/)+(\w+\.)+(\w+)[\w\/\.\-]*(jpg|jpeg|gif|png)/i', 'wpjam_qiniutek_replace_remote_image_md', do_shortcode($content));
}

function wpjam_qiniutek_replace_remote_image($matches){
	$qiniu_image_url = $image_url = $matches[1];

	if(empty($image_url)) return;

	$pre = apply_filters('pre_qiniu_remote', false, $image_url);

	if($pre == false && strpos($image_url,LOCAL_HOST) === false && strpos($image_url,CDN_HOST) === false ){
		$img_type = strtolower(pathinfo($image_url, PATHINFO_EXTENSION));

		if($img_type != 'gif'){
			$img_type = ($img_type == 'png')?$img_type:'jpg';

			$md5 = md5($image_url);
			$qiniu_image_url = CDN_HOST.'/qiniu/'.get_the_ID().'/image/'.$md5.'.'.$img_type;
		}
	}

	$width = (int)wpjam_qiniutek_get_setting('width');

	if($width){
		if(preg_match('|<img.*?width=[\'"](.*?)[\'"].*?>|i',$matches[0],$width_matches)){
			$width = $width_matches[1];
		}

		$height = 0;

		if(preg_match('|<img.*?height=[\'"](.*?)[\'"].*?>|i',$matches[0],$height_matches)){
			$height = $height_matches[1];
		}

		if($width || $height){
			$qiniu_image_url = wpjam_get_qiniu_thumbnail($qiniu_image_url, $width, $height, 0);
		}
	}

	$pre = apply_filters('pre_qiniu_watermark', false, $image_url);

	if($pre == false ){
		$qiniu_image_url = wpjam_get_qiniu_watermark($qiniu_image_url);
	}

	return str_replace($image_url, $qiniu_image_url, $matches[0]);
}
function wpjam_qiniutek_replace_remote_image_md($matches){
	$qiniu_image_url = $image_url = $matches[0];

	if(empty($image_url)) return;

	$pre = apply_filters('pre_qiniu_remote', false, $image_url);

	if($pre == false && strpos($image_url,LOCAL_HOST) === false && strpos($image_url,CDN_HOST) === false ){
		$img_type = strtolower(pathinfo($image_url, PATHINFO_EXTENSION));

		if($img_type != 'gif'){
			$img_type = ($img_type == 'png')?$img_type:'jpg';

			$md5 = md5($image_url);
			$qiniu_image_url = CDN_HOST.'/qiniu/'.get_the_ID().'/image/'.$md5.'.'.$img_type;
		}
	}

	$pre = apply_filters('pre_qiniu_watermark', false, $image_url);

	if($pre == false ){
		$qiniu_image_url = wpjam_get_qiniu_watermark($qiniu_image_url);
	}

	return str_replace($image_url, $qiniu_image_url, $matches[0]);//从$matches[0](img元素字符串)里搜索$image_url并替换为$qiniu_image_url，之后返回img元素字符串
}

add_filter('pre_qiniu_remote','wpjam_pre_qiniu_remote',10,2);
function wpjam_pre_qiniu_remote($false, $image_url){
	$exceptions	= explode("\n", wpjam_qiniutek_get_setting('exceptions'));

	if($exceptions){
		foreach ($exceptions as $exception) {
			if(trim($exception) && strpos($image_url, trim($exception)) !== false ){
				return true;
			}
		}
	}

	return $false;		
}

function wpjam_qiniutek_generate_rewrite_rules($wp_rewrite){
    $new_rules['qiniu/([^/]+)/image/([^/]+)\.([^/]+)?$']	= 'index.php?p=$matches[1]&qiniu_image=$matches[2]&qiniu_image_type=$matches[3]';
    $wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
}

function wpjam_qiniutek_query_vars($public_query_vars) {
    $public_query_vars[] = 'qiniu_image';
    $public_query_vars[] = 'qiniu_image_type';
    return $public_query_vars;
}

function wpjam_qiniutek_template_redirect(){
    $qiniu_image 		= get_query_var('qiniu_image');
    $qiniu_image_type 	= get_query_var('qiniu_image_type');

    if($qiniu_image && $qiniu_image_type){
    	include(WPJAM_QINIUTEK_PLUGIN_DIR.'/template/image.php');
    	exit;
	}
}

function wpjam_qiniutek_enqueue_scripts() {

	if(wpjam_qiniutek_get_setting('jquery')){
		wp_deregister_script( 'jquery' );
	    wp_register_script( 'jquery', 'http://cdn.staticfile.org/jquery/2.1.1/jquery.min.js', array(), '2.1.0' );
	}else{
		wp_deregister_script( 'jquery-core' );
	    wp_register_script( 'jquery-core', 'http://cdn.staticfile.org/jquery/1.11.1/jquery.min.js', array(), '1.10.2' );

		wp_deregister_script( 'jquery-migrate' );
	    wp_register_script( 'jquery-migrate', 'http://cdn.staticfile.org/jquery-migrate/1.2.1/jquery-migrate.min.js', array(), '1.2.1' );
	}
}

function wpjam_qiniutek_loader_src($src, $handle){
	if(get_option('timestamp')){
		$src = remove_query_arg(array('ver'), $src);
		$src = add_query_arg('ver',get_option('timestamp'),$src);
	}
	return $src;		
}