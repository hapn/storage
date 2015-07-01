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

class Guanli_Node_Controller extends PageController
{

	const PAGE_SIZE = 20;

	/**
	 * 管理节点
	 */
	function index_action ( )
	{
		$api = new StorageExport();
		$nodes = $api->getNodes();
		$curnode = $api->getCurrentNode();
		
		$this->set('title', '节点列表')
			->set('cnode', $curnode)
			->set('nodes', $nodes)
			->setView('tpl/node.phtml');
		// include VIEW_ROOT.'/node.php';
	}

	function incrids_action ( )
	{
		$api = new StorageExport();
		$ids = $api->getIncrIds();
		$this->set('ids', $ids);
		
		if ( $this->get('callback') ) {
			$this->response->setCallback($this->get('callback'));
		}
	}

	function table_action ( )
	{
		$api = new StorageExport();
		$node = $api->getCurrentNode();
		$nodeId = $node['node_id'];
		
		$rows = $api->getTables($nodeId);
		$tables = array();
		foreach ( $rows as $table ) {
			$pos = strripos($table, '_');
			if ( $pos !== false ) {
				$tid = substr($table, $pos + 1);
				$tables[$tid] = array( 
					'name' => $table,
					'id' => $tid
				);
			}
		}
		$table = false;
		$tableId = 0;
		$maxId = 0;
		if ( ! empty($tables) ) {
			// 表id
			$tableId = $this->get('table_id', 0);
			if ( $tableId ) {
				if ( ! isset($tables[$tableId]) ) {
					throw new Exception('hapn.u_notfound');
				} else {
					$table = $tables[$tableId];
				}
			}
			if ( ! $tableId ) {
				$ts = array_values($tables);
				$tableId = $ts[0]['id'];
				$table = $tables[$tableId];
			}
		}
		$this->set('tables', $tables)
			->sets(
				array( 
					'tbName' => 'hs_image_' . $nodeId . '_' . $tableId,
					'title' => '数据表的图片列表',
					'max_id' => $maxId,
					'node' => $node,
					'table_id' => $tableId,
					'table' => $table
				))
			->setView('tpl/images.phtml');
	}

	private function getImages ( $nodeId, $tableId, $pageSize )
	{
		$api = new StorageExport();
		$maxId = $this->get('max_id', 0);
		return $api->getImageLists($tableId, $pageSize, $nodeId, $maxId);
	}

	/**
	 * 显示图片列表
	 * @param int $tableId
	 */
	function image_action ( $tableId )
	{
		$api = new StorageExport();
		$node = $api->getCurrentNode();
		$nodeId = $node['node_id'];
		$images = $this->getImages($nodeId, $tableId, self::PAGE_SIZE);
		
		$npageUrl = '';
		if ( count($images['list']) == self::PAGE_SIZE ) {
			$npageUrl = '/guanli/node/image/' . $nodeId . '/' . $tableId . '/?max_id=' . $images['max_id'];
		}
		
		$data = array( 
			'np_url' => $npageUrl,
			'images' => $images
		);
		
		if ( $this->request->isAjax ) {
			$this->request->of = 'json';
			
			$data['showTr'] = true;
			$this->set('html', $this->response->buildView('/guanli/node/tpl/image_list.phtml', $data, FALSE));
			$this->set('np_url', $npageUrl);
		} else {
			$this->sets($data);
			$this->setView('tpl/image_list.phtml');
		}
	}

	function add_action ( )
	{
		$this->set('title', '添加节点')
			->setView('tpl/node_add.phtml');
	}

	function edit_action ( $nodeId )
	{
		$api = new StorageExport();
		$node = $api->getNode($nodeId);
		if ( ! $node ) {
			throw new Exception('jh.u_notfound');
		}
		
		$this->set('node', $node)
			->set('title', '编辑节点')
			->setView('tpl/node_edit.phtml');
	}

	function post_edit_action ( )
	{
		$data = $_POST['data'];
		$api = new StorageExport();
		$api->updateNode(intval($data['node_id']), $data);
		
		header('Location: /guanli/node/');
	}

	function post_add_action ( )
	{
		$data = $_POST['data'];
		(new StorageExport())->addNode($data['node_id'], $data['node_name'], $data['node_url'], $data['node_db'], 
				$data['image_dir'], $data['image_url']);
		
		header('Location: /guanli/node/');
	}

	/**
	 * 块的情况
	 */
	function block_action ( )
	{
		$node = (new StorageExport())->getCurrentNode();
		$imageDir = $node['image_dir'];
		$this->set('node', $node);
		$this->set('imageDir', $imageDir);
		
		$dh = opendir($imageDir);
		$dirs = array();
		$priDir = false;
		while ( $file = readdir($dh) ) {
			if ( $file[0] == '.' ) {
				continue;
			}
			$path = $imageDir . '/' . $file;
			if ( is_dir($path) && preg_match('#^node_(\d+)$#', $file, $ms)) {
				
				$dir = array( 
					'name' => $file,
					'node_id' => $ms[1],
					'is_primary' => $ms[1] == $node['node_id'],
					'url' => '/guanli/node/load_dir/?path='.str_replace($imageDir, '', $path),
				);
				if ($dir['is_primary']) {
					$priDir = $dir;
				} else {
					$dirs[$dir['node_id']] = $dir;
				}
			}
		}
		closedir($dh);
		
		ksort($dirs, SORT_NUMERIC);
		array_unshift($dirs, $priDir);
		
		$this->set('dirs', $dirs);
		
		$this->setView('tpl/block.phtml');
	}
	
	function load_dir_action()
	{
		if ($this->request->isAjax) {
			$this->request->of = 'json';
		}
		$path = $this->get('path');
		$node = (new StorageExport())->getCurrentNode();
		$imageDir = rtrim($node['image_dir'], '/');
		$absPath = $imageDir.$path;
		
		if (!is_readable($absPath) || !is_dir($absPath)) {
			throw new Exception('hapn.u_args');
		}
		
		$dh = opendir($absPath);
		$dirs = array();
		while( $file = readdir($dh) ) {
			if ($file[0] == '.') {
				continue;
			}
			$p = realpath($absPath.'/'.$file);
			if (is_dir($p) && preg_match('#^(?:node|dir)_(\d+)$#', $file, $ms)) {
				$dirs[$ms[1]] = array(
					'name' => $file,
					'url'  => '/guanli/node/load_block/?path='.str_replace($imageDir, '', $p),
				);
			}
		}
		ksort($dirs, SORT_NUMERIC);
		
		$this->set('dirs', $dirs);
	}
	
	function load_block_action()
	{
		$path = $this->get('path');
		$node = (new StorageExport())->getCurrentNode();
		$imageDir = rtrim($node['image_dir'], '/');
		$absPath = $imageDir.$path;
	
		if (!is_readable($absPath) || !is_dir($absPath)) {
			throw new Exception('hapn.u_args');
		}
	
		$dh = opendir($absPath);
		$files = array();
		while( $file = readdir($dh) ) {
			if ($file[0] == '.') {
				continue;
			}
			$p = realpath($absPath.'/'.$file);
			if (is_file($p) && preg_match('#^block_(\d+)\.data$#', $file, $ms)) {
				$files[$ms[1]] = array(
					'name' => $file,
					'path' => str_replace($imageDir, '', $p),
					'size' => filesize($p),
				);
			}
		}
		ksort($files, SORT_NUMERIC);

		$this->set('path', $path);
		$this->set('blocks', $files);
		$this->setView('tpl/blocks.phtml');
	}
}
 
