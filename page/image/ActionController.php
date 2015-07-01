<?php

/**
 * 
 * @copyright 		Copyright (C) Jiehun.com.cn 2015 All rights reserved.
 * @filesource		ActionController.php
 * @author			ronnie<dengxiaolong@hunbasha.com>
 * @since			2015/3/31
 * @version		    1.0
 * @desc 			
 */
require_once API_ROOT . 'StorageExport.php';
class Image_Controller extends PageController
{

	/**
	 * 显示图片
	 * @param string $imgId
	 */
	function index_action ( $imgId )
	{
		$api = new StorageExport();
		$start = microtime(true);
		$info = $api->get($imgId);
		$cost = sprintf('%.3f', (microtime(true) - $start) * 1000);
		Logger::trace('getImage size:'.sprintf('%.3fk', strlen($info['blob'])/1024).' cost:'.$cost);
		if ( ! $info ) {
			throw LesspException::notfound();
		}
		$this->response->setHeader(
				sprintf('Pinfo: %d:n%d,b%d,t%d,w%d,h%d,c%.3f', Conf::get('node_id'), $info['node_id'], $info['block_id'], $info['file_id'], 
						$info['width'], $info['height'], $cost));
		$this->response->setRaw($info['blob']);
	}

	/**
	 * 上传图片
	 * @throws Exception
	 */
	function post_upload_action ( )
	{
		$redirect = false;
		if ( empty($_FILES['image']['tmp_name']) ) {
			if ( ! isset($_POST['image']) ) {
				throw new Exception('hapn.u_notfound');
			}
			$content = $_POST['image'];
		} else {
			$content = file_get_contents($_FILES['image']['tmp_name']);
			$redirect = true;
		}
		if ( ! getimagesizefromstring($content) ) {
			throw new Exception('image.u_fileIllegal');
		}
		
		$start = microtime(true);
		
		
		$imgApi = new StorageExport();
		$info = $imgApi->save($content);
		ksort($info);
		
		Logger::trace(sprintf('upload cost:%.3fms', (microtime(true) - $start) * 1000));
		
		if ( $redirect ) {
			$this->response->redirect('/image/upload?img=' . $info['img_id'] . '.' . $info['img_ext']);
		} else {
			$this->request->of = 'json';
			$this->response->setRaw(json_encode($info));
		}
	}

	function upload_action ( )
	{
		$this->set('title', '上传图片')
			->set('img', $this->get('img'))
			->setView('tpl/upload.phtml');
	}
	
	/**
	 * 备份图片
	 */
	function post_backup_action()
	{
		$datas = $this->gets('img_id', 'from', 'blob');
	
		$api = new StorageExport();
		$api->doBackup($datas['img_id'], $datas['blob'], $datas['from']);
	}
}
 
