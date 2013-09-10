<?php
/**
 * 
 * @author Cuelog
 * @link http://cuelog.com
 * @copyright 2013 Cuelog
 */
class QiNiuCloud {
	
	/**
	 *
	 * @var 提示信息
	 */
	private $msg = null;
	
	/**
	 * 
	 * @var 连接状态
	 */
	private $connection_status = true;
	
	/**
	 *
	 * @var 文件mime类型，用于判断是否非图片文件
	 */
	private $mime = null;
	
	/**
	 *
	 * @var wordpress中upload_dir函数的各项值
	 */
	private $upload_dir = array ();
	
	/**
	 *
	 * @var 七牛SDK实例
	 */
	private $SDK = null;
	
	/**
	 *
	 * @var 文件签名
	 */
	private $upToken = null;
	
	/**
	 *
	 * @var 插件设置参数
	 */
	private $option = array ();
	
	
	public function __construct() {
		add_action ( 'admin_notices', array (&$this,'check_plugin_connection' ) );
		add_action ( 'admin_menu', array (&$this, 'option_menu' ) );
		add_action ( 'wp_ajax_nopriv_qiniu_ajax', array ( &$this, 'qiniu_ajax' ) );
		add_action ( 'wp_ajax_qiniu_ajax', array ( &$this,'qiniu_ajax' ) );
		add_filter ( 'wp_get_attachment_url', array (&$this,'replace_url' ) );
		add_filter ( 'wp_delete_file', array (	&$this,	'delete_file_from_qiniu' ) );
	}
	

	
	private function option_init(){
		if($this->option == null){
			$this->upload_dir = wp_upload_dir ();
			$this->option = get_option ( 'qiniu_option' );
		}
	}
	
	private function sdk_init (){
		if($this->SDK == null){
			$this->option_init();
			Qiniu_SetKeys ( $this->option ['access_key'], $this->option ['secret_key'] );
			$this->SDK = new Qiniu_MacHttpClient ( null );
			$putPolicy = new Qiniu_RS_PutPolicy ( $this->option ['bucket_name'] );
			$this->upToken = $putPolicy->Token ( null );
		}
	}
	
	
	/**
	 * 获取SDK错误信息
	 *
	 * @return Ambigous <boolean, unknown>
	 */
	private function get_errors_from_sdk($ret, $err = null) {
		if (isset ( $ret )) {
			return true;
		}
		if (is_object ( $err )) {
			$api_error = array (
					'400' => '请求参数错误',
					'401' => '认证授权失败，可能是密钥信息不对或者数字签名错误',
					'405' => '请求方式错误，非预期的请求方式',
					'599' => '服务端操作失败',
					'608' => '文件内容被修改',
					'612' => '指定的文件不存在或已经被删除',
					'614' => '文件已存在',
					'630' => 'Bucket 数量已达顶限，创建失败',
					'631' => '指定的 Bucket 不存在',
					'701' => '上传数据块校验出错'
			);
				
			if (isset ( $api_error [$err->Code] )) {
				$error ['error'] = $api_error [$err->Code] . '，错误代码：' . $err->Code;
			} else {
				$error ['error'] = '未知错误：错误代码：' . $err->Code . ', 错误信息：' . $err->Err;
			}
			return $error;
		}
		return false;
	}
	
	/**
	 * 显示错误信息
	 */
	private function show_msg($state = false) {
		$state = $state === false ? 'error' : 'updated';
		if (! is_null ( $this->msg )) {
			echo "<div class='{$state}'><p>{$this->msg}</p></div>";
		}
	}
	
	/**
	 * 获取binding url
	 *
	 * @param string $str        	
	 * @return string
	 */
	private function get_binding_url($str = null) {
		$this->option_init();
		$str = is_null ( $str ) ? '' : '/' . ltrim ( $str, '/' );
		return 'http://' . $this->option ['binding_url'] . $str;
	}
	
	/**
	 * 解决上传/下载文件包括中文名问题
	 */
	private function iconv2cn($str, $cn = false) {
		if (! QINIU_IS_WIN) {
			return $str;
		}
		return $cn === true ? iconv ( 'GBK', 'UTF-8', $str ) : iconv ( 'UTF-8', 'GBK', $str );
	}
	
	/**
	 * 安装插件后检查参数设置
	 *
	 * @return boolean
	 */
	public function check_plugin_connection() {
		global $hook_suffix;
		if ($hook_suffix == 'plugins.php' || $hook_suffix == 'settings_page_set_qiniu_option') {
			$this->sdk_init();
			list ( $ret, $err ) = Qiniu_RS_Stat ( $this->SDK, $this->option ['bucket_name'], 'qiniu_test.jpg' );
			$res = $this->get_errors_from_sdk ( $ret, $err );
			if ($res !== true) {
				$this->connection_status = false;
				echo "<div class='error'><p>连接七牛云储存失败，请<a href='/wp-admin/options-general.php?page=set_qiniu_option'>检查</a>Asscss key 或 Secret Key是否正确，以及七牛空间中是否存在检测文件【 qiniu_test.jpg 】 </p></div>";
			}
		}
	}

	/**
	 * 添加参数设置页面
	 */
	public function option_menu() {
		add_options_page ( '七牛云储存设置', '七牛云储存设置', 'administrator', 'set_qiniu_option', array (
				$this,
				'display_option_page' 
		) );
	}
	
	/**
	 * 替换文件的url地址
	 *
	 * @param 上传成功后的文件访问路径 $url        	
	 * @return string
	 */
	public function replace_url($url) {
		return str_replace ( home_url (), $this->get_binding_url (), $url );
	}
	
	/**
	 * 获取七牛空间中的所有文件地址
	 *
	 * @param string $path        	
	 * @return multitype:string
	 */
	public function get_files_list() {
		$this->sdk_init();
		list ( $iterms, $markerOut, $err ) = Qiniu_RSF_ListPrefix ( $this->SDK, $this->option ['bucket_name'] );
		$files = array ();
		if (! empty ( $iterms )) {
			foreach ( $iterms as $k => $ls ) {
				$files [] = $this->get_binding_url ( $ls ['key'] );
			}
		}
		return $files;
	}
	
	/**
	 * 删除空间中的文件
	 *
	 * @param 删除的文件 $file        	
	 * @return string
	 */
	public function delete_file_from_qiniu($file) {
		$this->sdk_init();
		$key = str_replace ( $this->upload_dir ['basedir'] . '/', '', $file );
		$key = str_replace ( home_url () . '/', '', $this->upload_dir ['baseurl'] ) . '/' . $key;
		Qiniu_RS_Delete ( $this->SDK, $this->option ['bucket_name'], $key );
		return $file;
	}
	
	/**
	 * 文件下载
	 */
	public function qiniu_ajax() {
		$this->option_init();
		if (isset ( $_GET ['do'] )) {
			if ($_GET ['do'] == 'get_files_list') {
				$list = $this->get_files_list ();
				$count = count ( $list );
				$res = array (
						'count' => $count,
						'url' => $list 
				);
				die ( json_encode ( $res ) );
			} elseif ($_GET ['do'] == 'download') {
				if (! empty ( $_GET ['file_path'] )) {
					// 兼容之前的版本
					$binding_url = $this->replace_url ( $this->upload_dir ['baseurl'] );
					$file = str_replace ( $binding_url, '', $_GET ['file_path'] );
					$file = str_replace ( $this->get_binding_url (), '', $file );
					$local = $this->upload_dir ['basedir'] . $file;
					$local_url = $this->upload_dir ['baseurl'] . $file;
					if (file_exists ( $this->iconv2cn ( $local ) )) {
						$msg = '【取消下载，文件已经存在】：' . $local_url;
					} else {
						$file_dir = $this->upload_dir ['basedir'] . substr ( $file, 0, strrpos ( $file, '/' ) );
						if (! is_dir ( $file_dir )) {
							if( ! mkdir ( $file_dir, 0755, true ) ) {
								die ( '【Error】 >> 创建目录失败，请确定是否有足够的权限：' . $file_dir );
							}
						}
						$fs = file_get_contents ( $_GET ['file_path'] );
						$fp = fopen ( $this->iconv2cn ( $local ), 'wb' );
						$res = fwrite ( $fp, $fs );
						$msg = $res === false ? '【Error】 >> 下载失败：' . $_GET ['file_path'] : '下载成功 >> ' . $local_url;
						fclose ( $fp );
					}
					die ( $msg );
				}
			}
		}
	}
	
	/**
	 * 参数设置页面
	 */
	public function display_option_page() {
		$this->option_init();
		if (isset ( $_POST ['submit'] )) {
			if (! empty ( $_POST ['action'] )) {
				if (empty ( $this->option ['binding_url'] ) || empty ( $this->option ['bucket_name'] )) {
					$this->msg = '取消操作，你还没有设置七牛绑定的域名或空间名';
					$this->show_msg ();
				} else {
					global $wpdb;
					$qiniu_url = $this->option ['binding_url'];
					$local_url = str_replace ( 'http://', '', $this->upload_dir ['baseurl'] );
					if ($_POST ['action'] == 'to_qiniu') {
						$sql = "UPDATE $wpdb->posts set `post_content` = replace( `post_content` ,'{$local_url}','{$qiniu_url}')";
					} elseif ($_POST ['action'] == 'to_local') {
						$sql = "UPDATE $wpdb->posts set `post_content` = replace( `post_content` ,'{$qiniu_url}','{$local_url}')";
					}
					$num_rows = $wpdb->query ( $sql );
					$this->msg = "共有 {$num_rows} 篇文章替换";
					$this->show_msg ( true );
				}
			} else {
				// 绑定域名
				$this->option ['binding_url'] = str_replace ( 'http://', '', trim ( trim ( $_POST ['binding_url'] ), '/' ) );
				// 空间名
				$this->option ['bucket_name'] = trim ( $_POST ['bucket_name'] );
				// AK
				if (! empty ( $_POST ['access_key'] )) {
					$this->option ['access_key'] = trim ( $_POST ['access_key'] );
				}
				// SK
				if (! empty ( $_POST ['secret_key'] )) {
					$this->option ['secret_key'] = trim ( $_POST ['secret_key'] );
				}
				$res = update_option ( 'qiniu_option', $this->option );
				$this->msg = $res == false ? '没有做任何修改' : '设置成功，刷新后查看是否连接成功';
				$this->show_msg ( true );
			}
		}
?>
<div class="wrap">
<?php screen_icon(); ?>
<h2>七牛插件设置</h2>
	<form name="qiniu_form" method="post" action="<?php echo admin_url('options-general.php?page=set_qiniu_option'); ?>">
		<table class="form-table">
			<tr valign="top">
				<th scope="row">七牛绑定的域名:</th>
				<td>
					<input name="binding_url" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['binding_url']; ?>" /> <span class="description">七牛空间提供的的默认域名或者已经绑定七牛空间的二级域名</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">空间名称:</th>
				<td><input name="bucket_name" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['bucket_name']; ?>" /> <span class="description">在七牛创建的空间名称</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">Access_Key:</th>
				<td><input name="access_key" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->connection_status !== true ? $this->option['access_key'] : null; ?>" /> <span class="description">连接成功后此项将隐藏</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">Secret_Key:</th>
				<td><input name="secret_key" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->connection_status !== true ? $this->option['secret_key'] : null ?>" /> <span class="description">连接成功后此项将隐藏</span></td>
			</tr>
		</table>
		<p class="submit">
			<input type="submit" class="button-primary" name="submit" value="保存设置" />
		</p>
	</form>
	<?php if($this->connection_status === true) { ?> 
	<hr />
	<?php screen_icon(); ?>
	<h2>使用七牛镜像访问</h2>
	<p>功能说明：此操作会将文章中的附件地址替换为七牛空间中的文件镜像地址，不需要手动上传任何本地附件，详情请看<a href="http://support.qiniu.com/entries/23961677-%E4%B8%83%E7%89%9B%E6%8F%90%E4%BE%9B%E7%9A%84%E9%9D%99%E5%83%8F%E5%AD%98%E5%82%A8%E6%98%AF%E4%BB%80%E4%B9%88" target="_blank">什么是七牛镜像储存</a></p>
	<form name="qiniu_form" method="post" action="<?php echo admin_url('options-general.php?page=set_qiniu_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="将文章中的本地附件地址转为镜像地址" />
		<input type="hidden" name="action" value="to_qiniu" />
	</form>
	<br />
	<hr />
	<?php screen_icon(); ?>
	<h2>恢复附件的本地链接</h2>
	<p>功能说明：当你需要停用本插件或恢复文件时，可以将七牛空间中的文件下载下来后，将文章内的附件镜像地址替换为本地链接</p>
	<p><input type="button" class="button-primary" id="download_check" value="查看七牛文件列表" /></p>
	<p id="downloading" style="display:none;"></p>
	<div id="download_action" style="display:none;">
		<p><span style="color: red;">七牛空间下共计文件：<strong id="download_image_count">0</strong> 张</span>&nbsp;&nbsp;<input type="button" disabled="disabled" class="button-primary" id="download_btn" value="开始下载" /></p>
		<p id="download_state" style="display:none;"><span style="color: red;">正在下载第：<strong id="download_now_number">1</strong> 张</span></p>
		<p id="download_error" style="display:none;"><span style="color: red;">下载失败：<strong id="download_error_number">0</strong> 张</span></p>
		<p id="download_result" style="display:none;color: red;padding-left:10px"></p>
		<div>
			<textarea id="download_result_list" style="width: 100%; height: 300px;" readonly="readonly" disabled="disabled" ></textarea>
		</div>
	</div>
	<br />
	<form name="qiniu_form" method="post" action="<?php echo admin_url('options-general.php?page=set_qiniu_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="将文章中的附件镜像地址恢复为本地链接" />
		<input type="hidden" name="action" value="to_local" />
	</form>
<?php }?>
	
	<script type="text/javascript">
	jQuery(function($){
		
		var error_list = '';
		var down_list = null;
		var down_textarea = $('#download_result_list');

		$('#download_check').click(function(){
			$('#download_action,#download_error,#download_result,#download_state').hide();
			down_textarea.val(null);
			var download_check = $(this);
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'qiniu_ajax', 'do': 'get_files_list'},
				timeout: 30000,
				error: function(){
					alert('获取文件列表失败，可能是服务器超时了');
				},
				beforeSend: function(){
					download_check.attr('disabled','disabled');
					$('#downloading').fadeIn('fast').html('<img src="<?php echo plugins_url( 'loading.gif' , __FILE__ ); ?>" /> 加载中...');
				},
				success: function(data){
					download_check.removeAttr('disabled');
					if(data && data.count > 0){
						$('#downloading').hide();
						$('#download_action').fadeIn('fast');
						$('#download_btn').removeAttr('disabled');
						$('#download_image_count').text(data.count);
						var textarea_val;
						down_list = data;
						for(var i in data.url){
							textarea_val = down_textarea.val();
							down_textarea.val(data.url[i] + "\r\n" + textarea_val);
						}
					}else{
						$('#downloading').html('空间中没有文件');
					}
				}
			});
		});

		$('#download_btn').click(function(){
			if(down_list.count == 0){
				alert('空间中没有文件');
				return false;
			}
			var btn = $(this);
			var download_state = $('#download_state');
			$('#download_result').hide();
			download_state.slideDown('fast');
			btn.attr('disabled','disabled').val('下载过程中请勿关闭页面...');
			down_textarea.val('');
			var download_now_number = 0, download_error_number = 0;
			for(var i in down_list.url){
				$.ajax({
					url: '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'TEXT',
					data: {'action': 'qiniu_ajax', 'do': 'download', 'file_path': down_list.url[i]},
					error: function(){
						down_textarea.val('【Error】 下载失败，请手动下载 >> '+down_list.url[i]);
					},
					success: function(data){
						$('#download_now_number').text(download_now_number + 1);
						if(data.indexOf('Error') > 0){
							download_error_number ++;
							error_list =  data + "\r\n" +error_list;
							$('#download_error').slideDown('fast');
							$('#download_error_number').text(download_error_number);
						}
						textarea_val = down_textarea.val();
						down_textarea.val(data + "\r\n" + textarea_val);
						download_now_number ++;
					},
					complete: function(){
						if(download_now_number == down_list.count){
							btn.removeAttr('disabled').val('开始下载');
							$('#download_state').hide();
							if(download_error_number == 0){
								$('#download_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;" /> 所有文件下载成功！').fadeIn('fast');
							}else{
								down_textarea.val(error_list);
								error_list = '';
							}
						}
					}
				});
			}
		});

	});

	</script>
</div>

<?php 
	}
}
?>