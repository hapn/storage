<?php

/**
 * 
 * @copyright 		Copyright (C) Jiehun.com.cn 2015 All rights reserved.
 * @filesource		StorageInitFilter.php
 * @author			ronnie<dengxiaolong@hunbasha.com>
 * @since			2015/3/31
 * @version		    1.0
 * @desc 			
 */
 
final class StorageInitFilter implements IFilter
{
	function execute(WebApp $app)
	{
		// 载入节点数据
		require_once API_ROOT.'StorageExport.php';
		$nodes = (new StorageExport())->getNodes();
		
		$curNodeId = Conf::get('node_id');
		if (!$curNodeId) {
			$curNodeId = isset($_SERVER['STORAGE_NODE_ID']) ? intval($_SERVER['STORAGE_NODE_ID']) : 0;
			
			if ($curNodeId > 0) {
				Conf::set('node_id', $curNodeId);
			}
		}
		if (!$curNodeId) {
			throw new Exception('node.u_args node_id not defined');
		}
		
//		if (!isset($nodes[$curNodeId])) {
//			throw new Exception('node.u_curnodeNotFound node_id='.$curNodeId);
//		}
		Logger::addBasic(array('node_id' => $curNodeId));
		
		$dbConfs = Conf::get('db.conf');
		foreach($nodes as $node) {
			// 设定好当前节点的数据库
			$cdb = $node['node_db'];
			$confs = parse_url($cdb);
			if (!isset($confs['port'])) {
				$confs['port'] = 3306;
			}
			$confs['path'] = trim($confs['path'], '/');
			
			$confs['charset'] = 'utf8';
			if (isset($confs['query'])) {
				parse_str($confs['query'], $args);
				if (isset($args['charset']) && $args['charset'] != 'utf8') {
					$confs['charset'] = $args['charset'];
				}
			}
			$key = 'node'.$node['node_id'];
			$dbConfs['db_pool'][$key] = array(
				'ip' 	=> $confs['host'],
				'user' 	=> $confs['user'],
				'pass' 	=> $confs['pass'],
				'port' 	=> $confs['port'],
				'charset' => $confs['charset']
			);
			$dbConfs['dbs'][$key] = $key;
			$dbConfs['db_alias'][$key] = $confs['path'];
		}
		Db::init($dbConfs);
		Conf::set('db.conf', $dbConfs);
	}
}
