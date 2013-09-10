<?php 
/*
Plugin Name: 七牛镜像储存
Version: 1.0.0
Plugin URI: http://cuelog.com/?p=51
Description: 七牛云镜像储存，通过七牛提供的镜像储存功能自动拉取被访问的附件，提升站点访问速度； 一键转换本地附件地址和七牛镜像地址，简单易用;
Author: Cuelog
Author URI: http://cuelog.com
*/


if(is_admin()){
	define('QINIU_IS_WIN', strstr(PHP_OS, 'WIN') ? 1 : 0 );
	register_uninstall_hook( __FILE__, 'remove_qiniu' );
	add_filter ( 'plugin_action_links', 'qiniu_setting_link', 10, 2 );
	require_once("includes/rs.php");
	require_once("includes/rsf.php");
	include ('QiNiuCloud.class.php');
	new QiNiuCloud();
}

//删除插件
function remove_qiniu(){
	$exist_option = get_option('qiniu_option');
	if(isset($exist_option)){
		delete_option('qiniu_option');
	}
}
//设置按钮
function qiniu_setting_link($links, $file){
	$plugin = plugin_basename(__FILE__);
	if ( $file == $plugin ) {
		$setting_link = sprintf( '<a href="%s">%s</a>', admin_url('options-general.php').'?page=set_qiniu_option', '设置' );
		array_unshift( $links, $setting_link );
	}
	return $links;
}
?>