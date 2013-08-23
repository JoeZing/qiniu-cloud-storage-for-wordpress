<?php 
/*
 Plugin Name: 七牛云储存
Version: 1.0.0
Plugin URI: http://cuelog.com
Description: 七牛云储存插件， 上传多媒体附件时，自动上传到七牛云储存空间； 本地服务器与七牛云储存空间所有文件一键上传/下载; 一键转换本地文件url和七牛空间的文件url，简单易用;
Author: Cuelog
Author URI: http://cuelog.com
*/

define('QINIU_IS_WIN',strstr(PHP_OS, 'WIN') ? 1 : 0 );
register_uninstall_hook( __FILE__, 'remove_qiniu' );
add_filter ( 'plugin_action_links', 'qiniu_setting_link', 10, 2 );
require_once("includes/io.php");
require_once("includes/rs.php");
require_once("includes/fop.php");
require_once("includes/rsf.php");
include ('QiNiuCloud.class.php');
new QiNiuCloud();
//删除插件
function remove_qiniu(){
	$exist_option = get_option('qiniu_option');
	if(isset($exist_option)){
		delete_option('qiniu_option');
	}
}
//设置按钮
function qiniu_setting_link($links, $file){
	if ( is_admin() && current_user_can('manage_options') ) {
		$plugin = plugin_basename(__FILE__);
		if ( $file == $plugin ) {
			$setting_link = sprintf( '<a href="%s">%s</a>', admin_url('options-general.php').'?page=set_qiniu_option', '设置' );
			array_unshift( $links, $setting_link );
		}
	}
	return $links;
}
?>