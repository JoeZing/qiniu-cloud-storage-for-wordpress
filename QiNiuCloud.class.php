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
		$this->upload_dir = wp_upload_dir ();
		$this->option_init ();
		add_action ( 'admin_menu', array ( &$this, 'option_menu' ) );
		add_action ( 'admin_notices', array( &$this, 'check_plugin_connection' ) );
		add_action ( 'wp_ajax_nopriv_qiniu_ajax', array ( &$this, 'qiniu_ajax' ) );
		add_action ( 'wp_ajax_qiniu_ajax', array ( &$this, 'qiniu_ajax' ) );
		add_filter ( 'wp_handle_upload', array( &$this, 'upload_completed' ) );
		add_filter ( 'wp_get_attachment_url', array ( &$this, 'replace_url' ) );
		add_filter ( 'wp_generate_attachment_metadata', array ( &$this, 'uplaod_to_qiniu' ), 99 );
		add_filter ( 'wp_update_attachment_metadata', array ( &$this, 'uplaod_to_qiniu' ) );
		add_filter ( 'wp_delete_file', array ( &$this, 'delete_file_from_qiniu' ) );
		Qiniu_SetKeys ( $this->option ['access_key'], $this->option ['secret_key'] );
		$this->SDK = new Qiniu_MacHttpClient ( null );
		$putPolicy = new Qiniu_RS_PutPolicy ( $this->option ['bucket_name'] );
		$this->upToken = $putPolicy->Token ( null );	
	}
	
	/**
	 * 初始化插件参数
	 */
	private function option_init() {
		$default_option ['is_delete'] = 'N';
		$this->option = get_option ( 'qiniu_option', $default_option );
	}
	
	/**
	 * 获取SDK错误信息
	 *
	 * @return Ambigous <boolean, unknown>
	 */
	private function get_errors_from_sdk($ret, $err = null) {
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
				$error ['error'] = $api_error [$err->Code]. '，错误代码：'.$err->Code;
			} else {
				$error ['error'] = '未知错误：错误代码：' . $err->Code . ', 错误信息：' . $err->Err;
			}
			return $error;
		}
		if (isset ( $ret )) {
			return true;
		}
	}
	
	/**
	 * 显示错误信息
	 */
	private function show_msg($state = false) {
		$state = $state === false ? 'error' : 'updated';
		if( !is_null( $this->msg ) ){
			echo "<div class='{$state}'><p>{$this->msg}</p></div>";
		}
	}
	
	/**
	 * 获取文件后缀
	 *
	 * @param unknown $file        	
	 * @return boolean
	 */
	private function is_img($file = null) {
		if(!is_null($file)){
			$allow_suffix = array (	'jpg',	'jpeg',	'png',	'gif' );
			$suffix =  strtolower ( trim ( strrchr ( $file, '.' ), '.' ) );
			if (in_array ( $suffix, $allow_suffix )) {
				return true;
			}
		}else{
			$suffix = substr($this->mime, 0, strpos($this->mime, '/'));
			if($suffix == 'image'){
				return true;
			}
		}
		return false;
	}
	
	/**
	 * 获取文件集合
	 *
	 * @param wp uploas 路径 $path
	 * @return Ambigous <multitype:, multitype:string >
	 */
	private function get_file_list($path) {
		$result_list = array ();
		if (is_dir ( $path )) {
			if ($handle = opendir ( $path )) {
				while ( false !== ($file = readdir ( $handle )) ) {
					if ($file != "." && $file != "..") {
						$file_path = $path . '/' . $file;
						if (is_dir ( $file_path )) {
							$res = $this->get_file_list ( $file_path );
							$result_list = array_merge ( $res, $result_list );
						} else {
							$result_list [] = iconv('', 'UTF-8', $file_path);
						}
					}
				}
				closedir ( $handle );
			}
		}
		return $result_list;
	}
	
	/**
	 * 获取binding url
	 * @param string $str
	 * @return string
	 */
	private function get_binding_url($str = null){
		$str = is_null($str) ? '' : '/' . ltrim($str,'/');
		return 'http://'.$this->option['binding_url'].$str;
	}
	
	/**
	 * 解决上传/下载文件包括中文名问题
	 */
	private function iconv2cn($str, $cn = false){
		if( ! QINIU_IS_WIN ){
			return $str;
		}
		return $cn === true ? iconv('GBK', 'UTF-8', $str) : iconv('UTF-8', 'GBK', $str);
	}
	
	/**
	* 安装插件后检查参数设置
	* @return boolean
	*/
	public function check_plugin_connection() {
		global $hook_suffix;
		if ($hook_suffix != 'settings_page_set_qiniu_option') {
			if ( empty ( $this->option ['binding_url'] )
			|| empty ( $this->option ['bucket_name'] )
			|| empty ( $this->option ['access_key'] )
			|| empty ( $this->option ['secret_key'] ) )
			{
				echo "<div class='error'><p>七牛插件缺少相关参数，<a href='/wp-admin/options-general.php?page=set_qiniu_option'>点击这里进行设置</a></p></div>";
				return false;
			} else {
				$res = $this->connection_test ( true );
				if ($res !== true) {
					echo "<div class='error'><p>{$res['error']}</p></div>";
				}
			}
		}
	}
	
	/**
	 * 上传文件前检查是否成功链接到七牛
	 *
	 * @return Ambigous <boolean, unknown>
	 */
	public function connection_test($file) {
		$this->mime = $file ['type'];
		list ( $ret, $err ) = Qiniu_RS_Stat ( $this->SDK, $this->option ['bucket_name'], 'qiniu_test.jpg' );
		$res = $this->get_errors_from_sdk ( $ret, $err );
		if( $res !== true ){
			global $hook_suffix;
			$btn = $hook_suffix != 'settings_page_set_qiniu_option' ? '<a href="/wp-admin/options-general.php?page=set_qiniu_option">点击修改</a>' : null;
			return array('error' => '连接七牛云储存失败，请检查Asscss key 或 Secret Key是否正确，以及空间中是否存在检测文件【 qiniu_test.jpg 】 '.$btn);
		}
		return $file;
	}
	
	/**
	 * 添加参数设置页面
	 */
	public function option_menu() {
		add_options_page ( '七牛云储存设置', '七牛云储存设置', 'administrator', 'set_qiniu_option', array ( $this, 'display_option_page' 	) );
	}
	
	/**
	 * 替换文件的url地址
	 *
	 * @param 上传成功后的文件访问路径 $url        	
	 * @return string
	 */
	public function replace_url($url) {
		return str_replace ( $this->upload_dir ['baseurl'], $this->get_binding_url(), $url );
	}
	
	/**
	 * 新增或编辑图片后，上传到七牛
	 *
	 * @param 文件参数 $metadata        	
	 * @return array
	 */
	public function uplaod_to_qiniu($metadata) {
		if (! empty ( $metadata ) && $this->is_img ($metadata ['file'])) {
			$files [] = substr ( $metadata ['file'], strripos ( $metadata ['file'], '/' ) + 1 );
			if (! empty ( $metadata ['sizes'] ['thumbnail'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['thumbnail'] ['file'];
			}
			if (! empty ( $metadata ['sizes'] ['medium'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['medium'] ['file'];
			}
			if (! empty ( $metadata ['sizes'] ['large'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['large'] ['file'];
			}
			if (! empty ( $metadata ['sizes'] ['post-thumbnail'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['post-thumbnail'] ['file'];
			}
			set_time_limit ( 300 );
			foreach ( $files as $fs ) {
				$file_path = $this->upload_dir ['path'] . '/' . $fs;
				if (file_exists ( $file_path )) {
					$err = null;
					$file_key_name = ltrim ( $this->upload_dir ['subdir'] . '/' . $fs, '/' );
					list ( $ret, $err ) = Qiniu_PutFile ( $this->upToken, $file_key_name, $file_path, null );
					$res = $this->get_errors_from_sdk ( $ret, $err );
					if ($res !== true) {
						return $res;
					}
					if ($this->option ['is_delete'] == 'Y') {
						unlink ( $file_path );
					}
				}
			}
		}
		return $metadata;
	}
	
	/**
	 * 这里只对非图片的文件做上传处理，因为 uplaod_to_qiniu 方法无法获取非图片文件的meta信息
	 * @param unknown $file
	 * @return Ambigous <Ambigous, boolean, string>|unknown
	 */
	public function upload_completed($file) {
		if (! $this->is_img ()) {
			$key_name = str_replace ( $this->upload_dir ['baseurl'] . '/', '', $file ['url'] );
			$file_path = $this->upload_dir ['basedir'] . '/' . $key_name;
			list ( $ret, $err ) = Qiniu_PutFile ( $this->upToken, $key_name, $file_path, null );
			$res = $this->get_errors_from_sdk ( $ret, $err );
			if ($res !== true) {
				return $res;
			}
			if ($this->option ['is_delete'] == 'Y') {
				unlink ( $file_path );
			}
		}
		return $file;
	}
	
	
	/**
	 * 获取七牛空间中的所有文件地址
	 *
	 * @param string $path        	
	 * @return multitype:string
	 */
	public function get_files_list() {
		list ( $iterms, $markerOut, $err ) = Qiniu_RSF_ListPrefix ( $this->SDK, $this->option ['bucket_name'] );
		$files = array ();
		if (! empty ( $iterms )) {
			foreach ( $iterms as $k => $ls ) {
				$files [] = $this->get_binding_url($ls ['key']);
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
		$key = ltrim ( (str_replace ( $this->upload_dir ['basedir'], '', $file )), '/' );
		Qiniu_RS_Delete ( $this->SDK, $this->option ['bucket_name'], $key );
		return $file;
	}
	
	
	/**
	 * 本地-七牛上传/下载ajax操作
	 */
	public function qiniu_ajax() {
		if (isset ( $_GET ['do'] )) {
			if ($_GET ['do'] == 'get_local_list') {
				$list = $this->get_file_list ( $this->upload_dir ['basedir'] );
				$count = count ( $list );
				$img_baseurl = array ();
				if ($count > 0) {
					foreach ( $list as $img ) {
						$img_baseurl [] = str_replace ( $this->upload_dir ['basedir'], $this->upload_dir ['baseurl'], $img );
					}
				}
				$res = array (
						'count' => $count,
						'url' => $img_baseurl 
				);
				die ( json_encode ( $res ) );
			} elseif ($_GET ['do'] == 'upload') {
				if (isset ( $_GET ['file_path'] )) {
					set_time_limit ( 200 );
					$file_path = $this->iconv2cn( str_replace( $this->upload_dir['baseurl'], $this->upload_dir['basedir'], $_GET['file_path'] ) );
					if (file_exists ( $file_path )) {
						$key_name = $this->iconv2cn( str_replace ( $this->upload_dir ['basedir'] . '/', '', $file_path ), true );
						list ( $ret, $err ) = Qiniu_PutFile ( $this->upToken, $key_name, $file_path, null );
						$res = $this->get_errors_from_sdk ( $ret, $err );
						if ($res !== true) {
							die ( '【Error】 >> ' . $this->upload_dir ['baseurl'] .'/' . $key_name . ' 原因：' . $res ['error'] );
						}
					}
					die ( '上传成功 >> '. $this->get_binding_url( $key_name ) );
				}
			} elseif ($_GET ['do'] == 'get_files_list') {
				$list = $this->get_files_list ();
				$count = count ( $list );
				$res = array (
						'count' => $count,
						'url' => $list 
				);
				die ( json_encode ( $res ) );
			} elseif ($_GET ['do'] == 'download') {
				if (isset ( $_GET ['file_path'] )) {
					$file = str_replace ( $this->get_binding_url(), '', $_GET ['file_path'] );
					$local = str_replace ( $this->get_binding_url(), $this->upload_dir ['basedir'], $_GET ['file_path'] );
					$local_url = $this->upload_dir ['baseurl'] . $file;
					if (file_exists ( $this->iconv2cn($local) )) {
						$msg = '【取消下载，文件已经存在】：' . $local_url;
					} else {
						$fs = file_get_contents ( $_GET ['file_path'] );
						$fp = fopen ( $this->iconv2cn( $local ), 'wb' );
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
		if (isset ( $_POST ['submit'] )) {
			if (! empty ( $_POST ['action'] )) {
				if (empty ( $this->option ['binding_url'] ) || empty ( $this->option ['bucket_name'] )) {
					$this->msg = '取消操作，你还没有设置七牛绑定的域名或空间名';
					$this->show_msg ();
				} else {
					global $wpdb;
					$qiniu_url = $this->option ['binding_url'];
					$local_url = str_replace ( 'http://', '', $this->upload_dir['baseurl'] );
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
				$this->option ['binding_url'] = str_replace ( 'http://', '', trim ( trim ( $_POST ['binding_url'] ), '/'  ) );
				// 空间名
				$this->option ['bucket_name'] = trim ( $_POST ['bucket_name'] );
				// AK
				if(!empty($_POST ['access_key'])){
					$this->option ['access_key'] = trim ( $_POST ['access_key'] );
				}
				// SK
				if(!empty($_POST ['secret_key'])){
					$this->option ['secret_key'] = trim ( $_POST ['secret_key'] );
				}
				// 是否上传后删除本地文件
				$this->option ['is_delete'] = $_POST ['is_delete'] == 'Y' ? 'Y' : 'N';
				$res = update_option ( 'qiniu_option', $this->option );
				$this->msg = $res == false ? '没有做任何修改' : '设置成功';
				$this->show_msg(true);
			}
		}
		$connection_test = $this->connection_test ( true );

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
				<td><input name="access_key" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $connection_test !== true ? $this->option['access_key'] : null; ?>" /> <span class="description">连接成功后此项将隐藏</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">Secret_Key:</th>
				<td><input name="secret_key" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $connection_test !== true ? $this->option['secret_key'] : null ?>" /> <span class="description">连接成功后此项将隐藏</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">上传后是否删除本地附件:</th>
				<td>
					<p>
						<label><input type="radio" name="is_delete" value="Y" <?php echo $this->option['is_delete'] == 'Y' ? 'checked="checked"' : null; ?> /> 是 </label> &nbsp; 
						<label><input type="radio" name="is_delete" value="N" <?php echo $this->option['is_delete'] == 'N' ? 'checked="checked"' : null; ?> /> 否</label>
					</p>
					<p class="description">强烈建议此选项为<b>否</b>，可以在紧要关头时转换地址后关闭插件，直接恢复本地附件的访问</p>
				</td>
			</tr>
		</table>
		<?php if( $connection_test !== true ){?><p><strong style="color:red; font-size: 14px"><?php echo $connection_test['error']?></strong></p><?php }?>
		<p class="submit">
			<input type="submit" class="button-primary" name="submit" value="保存设置" />
		</p>
	</form>
	<?php if($connection_test === true) { ?> 
	<hr />
	<?php screen_icon(); ?>
	<h2>将站点所有附件上传到七牛云空间并转换文章中的附件URL为七牛的URL</h2>
	<p>PS1: 此操不会删除本地服务器上的附件</p>
	<p>PS2: 如果又拍云中有同名的附件，将会被覆盖</p>
	<p>PS3: 传输过程中请不要关闭页面，如果附件很多，等待所有附件上传完成</p>
	<p>PS4: 上传完成后请点击下面按钮转换url地址</p>
	<p><input type="button" class="button-primary" id="upload_check" value="检查本地服务器文件列表" /></p>
	<p id="loading" style="display:none;"></p>
	<div id="upload_action" style="display:none;">
		<p><span style="color: red;">服务器目录下共计文件：<strong id="image_count">0</strong> 张</span>&nbsp;&nbsp;<input type="button" disabled="disabled" class="button-primary" id="upload_btn" value="开始上传" /></p>
		<p id="upload_state" style="display:none;"><span style="color: red;">正在上传第：<strong id="now_number">1</strong> 张</span></p>
		<p id="upload_error" style="display:none;"><span style="color: red;">上传失败：<strong id="error_number">0</strong> 张</span></p>
		<p id="upload_result" style="display:none;color: red;padding-left:10px"></p>
		<div>
			<textarea id="upload_reslut_list" style="width: 100%; height: 300px;" readonly="readonly" disabled="disabled" ></textarea>
		</div>
	</div>
	<br />
	<form name="qiniu_form" method="post" action="<?php echo admin_url('options-general.php?page=set_qiniu_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="将本地URL转为七牛URL" />
		<input type="hidden" name="action" value="to_qiniu" />
	</form>
	<br />
	<hr />
	<?php screen_icon(); ?>
	<h2>恢复本地访问，下载七牛中所有文件并将文章中的附件url恢复为本地服务器的访问地址</h2>
	<p>PS1: 如果本地服务器中有同名的附件，将会被覆盖</p>
	<p>PS2: 传输过程中请不要关闭页面，如果附件很多，等待所有附件下载完成</p>
	<p>PS3: 下载完成后请点击下面按钮转换url地址</p>
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
		<input type="submit" class="button-primary" name="submit" value="恢复为本地URL" />
		<input type="hidden" name="action" value="to_local" />
	</form>
<?php }?>
	
	<script type="text/javascript">
	jQuery(function($){
		var list_data = null;
		var error_list = '';
		var textarea = $('#upload_reslut_list');

		$('#upload_check').click(function(){
			$('#upload_action,#upload_error,#upload_result,#upload_state').hide();
			textarea.val(null);
			var upload_check = $(this);
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'qiniu_ajax', 'do': 'get_local_list'},
				timeout: 30000,
				error: function(){
					alert('获取文件列表失败，可能是服务器超时了');
				},
				beforeSend: function(){
					upload_check.attr('disabled','disabled');
					$('#loading').fadeIn('fast').html('<img src="<?php echo plugins_url( 'loading.gif' , __FILE__ ); ?>" /> 加载中...');
				},
				success: function(data){
					upload_check.removeAttr('disabled');
					if(data && data.count > 0){
						$('#loading').hide();
						$('#upload_action').fadeIn('fast');
						$('#upload_btn').removeAttr('disabled');
						$('#image_count').text(data.count);
						var textarea_val;
						list_data = data;
						for(var i in data.url){
							textarea_val = textarea.val();
							textarea.val(data.url[i] + "\r\n" + textarea_val);
						}
					}else{
						$('#loading').html('空间中没有文件');
					}
				}
			});
		});

		$('#upload_btn').click(function(){
			if(list_data.count == 0){
				alert('空间中没有文件');
				return false;
			}
			var btn = $(this);
			var upload_state = $('#upload_state');
			$('#download_error').hide();
			$('#upload_result').hide();
			upload_state.slideDown('fast');
			btn.attr('disabled','disabled').val('上传过程中请勿关闭页面...');
			textarea.val('');
			var now_number = 0, error_number = 0;
			for(var i in list_data.url){
				$.ajax({
					url: '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'TEXT',
					data: {'action': 'qiniu_ajax', 'do': 'upload', 'file_path': list_data.url[i]},
					error: function(){
						textarea.val('【Error】 上传失败，请使用FTP上传 >> '+list_data.url[i]);
					},
					success: function(data){
						$('#now_number').text(now_number + 1);
						if(data.indexOf('Error') > 0){
							error_number ++;
							error_list =  data + "\r\n" +error_list;
							$('#upload_error').slideDown('fast');
							$('#error_number').text(error_number);
						}
						textarea_val = textarea.val();
						textarea.val(data + "\r\n" + textarea_val);
						now_number ++;
					},
					complete: function(){
						if(now_number == list_data.count){
							btn.removeAttr('disabled').val('开始上传');
							$('#upload_state').hide();
							if(error_number == 0){
								$('#upload_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;"  /> 所有附件上传成功！').fadeIn('fast');
							}else{
								textarea.val(error_list);
								error_list = '';
							}
						}
					}
				});
			}
		});

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