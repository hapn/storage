<?php

/**
 * 
 * @copyright 		Copyright (C) Jiehun.com.cn 2015 All rights reserved.
 * @filesource		storage.php
 * @author			ronnie<dengxiaolong@hunbasha.com>
 * @since			2015年4月8日 下午6:38:04
 * @version		    1.0
 * @desc 			
 */
require_once LIB_ROOT . '/cache/Memcached.php';

final class StorageExport
{

	const NODE_CACHE_KEY = 'storage.nodes.list';
	
	// 一个节点存储的块数量 2^24
	const BLOCK_NUM_PER_NODE = 16777216;
	
	// 最小的存储trunk,单位:bit 2^9 一个扇区的大小
	// const MIN_TRUNK_SIZE = 512;
	
	// 每个目录中放多少个块 2^6
	const BLOCK_NUM_PER_DIR = 1024;
	
	// 同时写入块的数量 2^4
	const BLOCK_NUM_MEANWHILE = 8;
	
	// 每个块写入的图片数量 2^8 平均每张图片300k，一个block大约75M
	const IMAGE_NUM_PER_BLOCK = 256;
	
	// 块的初始化大小：64M
	const BLOCK_INIT_SIZE = 67108864;
	// const BLOCK_INIT_SIZE = 4194304;
	// 块大小不够后增加的大小：2M
	const BLOCK_INCR_SIZE = 2097152;
	
	// // 每次递增的trunk数量，(TRUNK_NUM_PER_INCR * MIN_TRUNK_SIZE)/1024/1024 = 256M
	// 2^19
	// const TRUNK_NUM_PER_INCR = 524288;
	
	// 已删除状态的图片
	const FLAG_STATUS_DELETE = 0x40;
	// 正常状态的图片
	const FLAG_STATUS_NORMAL = 0x00;
	// 普通图片
	const FLAG_TYPE_NORMAL = 0x00;
	// 关联图片
	const FLAG_TYPE_REFERENCE = 0x80;
	
	// 普通图片的meta信息长度 单位：字节
	const META_LEN_NORMAL = 34;
	// 关联图片的meta信息总长度 单位：字节
	const META_LEN_REFERENCE = 47;

	private static $nodes;

	private static $node;

	const DB_NAME = 'storage';

	const NODE_TABLE_NAME = 'st_node';

	const BLOCK_TABLE_NAME = 'st_block';

	const NODE_SQL = <<<SQL
CREATE TABLE IF NOT EXISTS `st_node`(
	`node_id` 			smallint 	unsigned 	not null 				 	comment '节点ID，必须唯一，手工指定',
	`node_name` 		varchar(15) 			not null 					comment '名称',
	`node_url` 			varchar(63) 			not null 					comment '对应的网址',
	`node_db` 			varchar(63) 			not null 					comment '对应的数据库',
	`image_url`			varchar(63) 			not null 					comment '图片主机名',
	`image_dir` 		varchar(63) 			not null 					comment '图片的根目录',
	`max_incr_id`		bigint 		unsigned	not null 	default '0'		comment '当前节点最大的递增ID',
	`max_block_id`		int  				 	not null 	default '-1'	comment '当前节点最大的块ID，据此来决定是否新建block',
	`node_desc` 		varchar(255) 										comment '描述',
	primary key(`node_id`)
) engine innodb charset utf8
SQL;

	const BLOCK_SQL = <<<SQL
CREATE TABLE IF NOT EXISTS `st_block`(
	`node_id`		smallint 	unsigned 	not null 					comment '节点ID',
	`rnode_id` 		mediumint	unsigned 	not null					comment '被复制的节点的id',
	`block_id` 		mediumint 	unsigned 	not null 					comment '块的id',
	`block_md5`		bigint    				not null 					comment '块的md5值',
	`incr_id`		bigint 		unsigned 	not null 	default 0		comment '最大的自增ID',
	`used_size`		bigint		unsigned	not null					comment '使用的大小',
	`assigned_size`	bigint   	unsigned 	not null 					comment '分配的大小，当used_size>assigned_size，必须增加块的大小',
	`update_time` 	int 		unsigned  	not null 					comment '最后更新时间',
	primary key(`node_id`, `rnode_id`, `block_id`)
) engine innodb charset utf8
SQL;

	const IMAGE_SQL = <<<SQL
CREATE TABLE IF NOT EXISTS `{IMAGE_TABLE}`(
	`incr_id`		bigint 		unsigned 	not null 	comment '自增ID',
	`img_id` 		binary(17)				not null 	comment '图片ID，最多存储10995亿张图片',
	`rimg_id`		binary(17)				not null	comment '图片关联ID，为0表示是原始图片',
	`img_md5` 		bigint 					not null 	comment '图片的md5值，用来校验',
	`img_length` 	mediumint 	unsigned 	not null 	comment '图片尺寸，最多16M',
	`img_flag` 		tinyint 	unsigned	not null  	default '1' 	comment '图片标识，0x1 1 正常 0 删除 0x2 ',
	`create_time` 	int 		unsigned 	not null 	comment '创建时间',
	PRIMARY KEY (`incr_id`),
  	KEY `idx_md5` (`img_md5`)
) engine innodb charset utf8;
SQL;

	/**
	 * 获取节点列表
	 * @return array
	 */
	function getNodes ( )
	{
		if ( self::$nodes ) {
			return self::$nodes;
		}
		
		$cache = Memcached::load();
		$nodes = $cache->get(self::NODE_CACHE_KEY);
		if ( $nodes === NULL ) {
			$db = Db::get(self::DB_NAME);
			try {
				$nodes = $db->table(self::NODE_TABLE_NAME)
					->field(
						array( 
							'node_id',
							'node_name',
							'node_url',
							'node_db',
							'image_url',
							'image_dir',
							'max_block_id',
							'node_desc'
						))
					->where('1=1')
					->get();
				
				foreach ( $nodes as &$node ) {
					if ( $node['image_url'] ) {
						$info = parse_url($node['image_url']);
						if ( ! empty($info['host']) ) {
							$node['image_host'] = $info['host'];
							if ( ! empty($info['port']) ) {
								$node['image_host'] .= ':' . $info['port'];
							}
						}
					}
					if ( ! $node['image_host'] ) {
						$node['image_host'] = $node['node_url'];
					}
				}
			} catch ( Exception $ex ) {
				$msg = $ex->getMessage();
				if ( $msg == "db.QueryError Table 'storage.st_node' doesn't exist" ) {
					$nodes = array();
				}
			}
			$nodes = array_column($nodes, NULL, 'node_id');
			$cache->set(self::NODE_CACHE_KEY, $nodes, 10 * 60);
			self::$nodes = $nodes;
		}
		return $nodes;
	}

	/**
	 * 获取节点的最大图片id
	 */
	function getIncrIds ( )
	{
		$rows = Db::get(self::DB_NAME)->table(self::NODE_TABLE_NAME)
			->field(array( 
			'node_id',
			'max_block_id',
			'max_incr_id'
		))
			->where('1=1')
			->get();
		$ret = array();
		foreach ( $rows as $row ) {
			$ret[$row['node_id']] = array( 
				$row['max_incr_id'],
				$row['max_block_id']
			);
		}
		return $ret;
	}

	/**
	 * 查找当前节点
	 */
	function getCurrentNode ( )
	{
		if ( self::$node ) {
			return self::$node;
		}
		$nodes = $this->getNodes();
		if ( Conf::get('node_id') && isset($nodes[Conf::get('node_id')]) ) {
			return (self::$node = $nodes[Conf::get('node_id')]);
		}
		return false;
	}

	/**
	 * 获取指定节点
	 * @param int $nodeId
	 * @return Ambigous <>|boolean
	 */
	function getNode ( $nodeId )
	{
		$nodes = $this->getNodes();
		if ( isset($nodes[$nodeId]) ) {
			return $nodes[$nodeId];
		}
		return false;
	}

	/**
	 * 添加节点
	 * @param int $nodeId 节点ID
	 * @param string $name 名称
	 * @param string $url 访问url
	 * @param string $db 数据库位置
	 * @param string $imageDir 图片跟目录
	 * @param string $imageUrl 图片访问地址
	 * @param string $nodeDesc 节点描述
	 */
	function addNode ( $nodeId, $name, $url, $nodeDb, $imageDir, $imageUrl, $nodeDesc = '' )
	{
		if ( ! $name || ! $url || ! $nodeDb || ! $imageDir || ! $imageUrl ) {
			throw new Exception('node.u_args');
		}
		
		// 检查目录是否存在
		if ( ! is_readable($imageDir) ) {
			throw new Exception('storage.imageDirNotFound dir=' . $imageDir);
		}
		
		// 自动创建hs_node和hs_block表
		$db = Db::get(self::DB_NAME);
		$db->queryBySql(array( 
			array( 
				self::NODE_SQL
			),
			array( 
				self::BLOCK_SQL
			)
		));
		
		// 插入节点
		$ret = $db->table(self::NODE_TABLE_NAME)
			->saveBody(
				array( 
					'node_id' => intval($nodeId),
					'node_name' => $name,
					'node_url' => $url,
					'node_db' => $nodeDb,
					'image_url' => $imageUrl,
					'image_dir' => $imageDir,
					'node_desc' => $nodeDesc
				))
			->insert();
		
		$this->destroyNodesCache();
	}

	/**
	 * 更新节点
	 * @param int $nodeId
	 * @param array $info
	 */
	function updateNode ( $nodeId, array $info )
	{
		$body = array();
		foreach ( array( 
			'node_name',
			'node_db',
			'image_url'
		) as $key ) {
			if ( isset($info[$key]) ) {
				$body[$key] = $info[$key];
			}
		}
		if ( ! $body ) {
			return;
		}
		
		$db = Db::get(self::DB_NAME);
		$db->table(self::NODE_TABLE_NAME)
			->where(array( 
			'node_id' => intval($nodeId)
		))
			->saveBody($body)
			->update();
		
		$this->destroyNodesCache();
		
		// 开启后台同步进程
		// TODO 检查备份节点同步的进度，进而作出备份的策略
	}

	private function destroyNodesCache ( )
	{
		Memcached::load()->delete(self::NODE_CACHE_KEY);
	}

	private $imageExts = array( 
		1 => 'gif',
		2 => 'jpg',
		3 => 'png',
		4 => 'swf',
		5 => 'psd'
	);

	/**
	 * 获取图片的trunk数量
	 * @param 图片内容长度 $blobLen
	 * @param string $exist
	 * 
	 * @tutorial
	 * 图片存储格式：
	 1字节标识位
	 0x1：类型 0 正常图片 1 参考图片
	 0x2：状态 0 正常 1 删除
	 
	 17字节图片ID
	 4字节创建时间
	 8字节md5长整形数字
	 
	 共26个字节
	 
	 如果类型为0：
	 4字节
	 图片长度
	 共（30*8）240bit+图片大小
	 
	 
	 如果类型为1：
	 17字节的参考图片id
	 共39*8 = 312bit
	 */
	private function getImageSize ( $blobLen, $exist = FALSE )
	{
		if ( $exist ) {
			return self::META_LEN_REFERENCE;
		}
		
		return self::META_LEN_NORMAL + $blobLen;
	}

	/**
	 * 保存图片
	 * @param string $blob 图片的内容
	 * @param int $uid 创建用户的ID
	 * @param array
	 * <code>array(
	 *  id: '', // 十六进制的图片id，包括图片所在的表ID，原始id，以及宽度，高度，类型等信息
	 *  table_id: '', // 所在表的ID
	 *  pic_id: '', //十进制的图片id
	 *  width: '',  // 宽度
	 *  height: '', // 高度
	 *  type: '', // 类型
	 * )</code>
	 */
	function save ( $blob )
	{
		$info = @getimagesizefromstring($blob);
		if ( ! $info ) {
			return false;
		}
		list( $width, $height, $type ) = $info;
		if ( ! isset($this->imageExts[$type]) ) {
			throw new \Exception('image.u_illegalType type=' . $type);
		}
		
		$node = $this->getCurrentNode();
		if ( ! $node ) {
			return false;
		}
		$nodeId = intval($node['node_id']);
		
		$db = Db::get('node' . $nodeId);
		$cdb = Db::get(self::DB_NAME);
		$md5 = Db::create_sign64($blob);
		
		// 检查最近的一个目录里边有没有重复的图片
		$maxBlockId = $node['max_block_id'];
		$blockDirId = $this->getBlockDirId($maxBlockId);
		
		$foundImageId = NULL;
		if ( $maxBlockId >= 0 ) {
			// 查找相邻两个表里是否有相同的图片
			$foundDirIds = array( 
				$blockDirId
			);
			if ( $blockDirId >= 1 ) {
				array_push($foundDirIds, $blockDirId - 1);
			}
			foreach ( $foundDirIds as $dirId ) {
				$tbName = $this->getImageTable($nodeId, $dirId);
				$row = $db->table($tbName)
					->field(array( 
					'img_id',
					'img_length'
				))
					->where(array( 
					'img_md5' => $md5,
					'rimg_id' => 0
				))
					->getOne();
				if ( $row ) {
					$foundImageId = $row['img_id'];
					break;
				}
			}
		}
		
		$blobLen = strlen($blob);
		
		if ( $foundImageId !== NULL ) {
			$copyLength = $this->getImageSize($blobLen, true);
			$newId = $this->newImageId($node, $copyLength);
			$path = $this->getBlockFile($node, $newId['block_id']);
			$imgIds = $this->packId($nodeId, $newId['incr_id'], $newId['block_id'], $newId['file_id'], $width, $height, 
					$type);
			
			$row['incr_id'] = $newId['incr_id'];
			$rimg_id = $row['img_id'];
			$row['rimg_id'] = array( 
				'exp' => '0x' . bin2hex($rimg_id)
			);
			$row['img_id'] = array( 
				'exp' => "0x{$imgIds['16']}"
			);
			$row['create_time'] = time();
			$row['img_flag'] = 1;
			$row['img_length'] = $blobLen;
			$row['img_md5'] = $md5;
			
			$tbName = $this->getImageTable($nodeId, $this->getBlockDirId($newId['block_id']));
			$db->table($tbName)
				->saveBody($row)
				->insert();
			
			$row['img_id'] = $imgIds['2'];
			$row['rimg_id'] = $rimg_id;
			$row['file_id'] = $newId['file_id'];
			$row['node_id'] = $newId['node_id'];
			$row['block_id'] = $newId['block_id'];
			
			$posInfo = $this->writeImage(NULL, $path, $row);
			
			$row['img_ext'] = $this->imageExts[$type];
			$row['img_id'] = $imgIds['64'];
			$row['img_hexid'] = $imgIds['16'];
			$row['rimg_id'] = bin2hex($row['rimg_id']);
			$row['url'] = $this->getUrl($node['image_url'], $row['img_id'], $row['img_ext']);
			$row['width'] = $width;
			$row['height'] = $height;
			
			Logger::trace('save_image:' . $row['img_id']);
			
			return $row;
		}
		
		$copyLength = $this->getImageSize($blobLen);
		$newId = $this->newImageId($node, $copyLength);
		$path = $this->getBlockFile($node, $newId['block_id']);
		$imgIds = $this->packId($nodeId, $newId['incr_id'], $newId['block_id'], $newId['file_id'], $width, $height, 
				$type);
		
		// 如果没有过相同的图片，则新建
		$row = array( 
			'incr_id' => $newId['incr_id'],
			'img_id' => array( 
				'exp' => "0x" . $imgIds['16']
			),
			'rimg_id' => array( 
				'exp' => '0b0'
			),
			'img_length' => $blobLen,
			'create_time' => time(),
			'img_flag' => 1,
			'img_md5' => $md5
		);
		$tbName = $this->getImageTable($nodeId, $this->getBlockDirId($newId['block_id']));
		$db->table($tbName)
			->saveBody($row)
			->insert();
		$row['incr_id'] = $newId['incr_id'];
		$row['img_id'] = $imgIds['2'];
		$row['rimg_id'] = 0;
		$row['file_id'] = $newId['file_id'];
		$row['node_id'] = $newId['node_id'];
		$row['block_id'] = $newId['block_id'];
		
		$posInfo = $this->writeImage($blob, $path, $row);
		
		$row['img_id'] = $imgIds['64'];
		$row['img_hexid'] = $imgIds['16'];
		$row['img_ext'] = $this->imageExts[$type];
		$row['width'] = $width;
		$row['height'] = $height;
		$row['url'] = $this->getUrl($node['image_url'], $row['img_id'], $row['img_ext']);
		
		Logger::trace('save_image:' . $row['img_id']);
		return $row;
	}

	/**
	 * 写图片
	 * @param string $blob
	 * @param string $path
	 * @return array
	 * <code>array(
	 *  img_id,
	 *  block_id,
	 *  create_time,
	 * )</code>
	 */
	function writeImage ( $blob, $path, $info )
	{
		$fh = fopen($path, 'r+');
		fseek($fh, $info['file_id'], SEEK_SET);
		$imageType = $info['rimg_id'] !== 0 ? 1 : 0;
		
		$output = '';
		
		// 写入1字节类型和状态
		if ( $imageType != 0 ) {
			$output .= pack('C', $imageType << 7);
		} else {
			$output .= pack('C', 0);
		}
		
		// 写入17字节图片id
		$output .= $info['img_id'];
		
		// 写入4个字节的创建时间
		if ( ! isset($info['create_time']) ) {
			$info['create_time'] = time();
		}
		$output .= pack('N', intval($info['create_time']));
		
		// 写入8个字节的md5校验码
		$output .= pack('N2', $info['img_md5'] >> 32, $info['img_md5'] & 0xFFFFFFFF);
		
		if ( ! $imageType ) {
			// 写入4字节长度信息
			$output .= pack('N', $info['img_length']);
			$output .= $blob;
		} else {
			// 写入17字节参考id
			$output .= $info['rimg_id'];
		}
		fwrite($fh, $output);
		fclose($fh);
		unset($blob);
		
		$bNodes = $this->getBackupNodes($info['node_id'], $info['block_id']);
		
		if ( $bNodes ) {
			require_once LIB_ROOT . 'curl/Curl.php';
			$curl = new Curl();
			foreach ( $bNodes as $bNodeId => $bNode ) {
				$url = sprintf('%s/image/_backup', $bNode['node_url']);
				// 备份图片
				$writeData = array( 
					'img_id' => self::_2to64($info['img_id']),
					'from' => $info['file_id'],
					'blob' => $output
				);
				$curl->post($url, $writeData, array(), FALSE);
				
				Logger::trace('backup to:' . $url);
			}
		}
	}

	/**
	 * 生成一个新的图片ID
	 * @param array $node 
	 * @param int $imageSize 图片占用的尺寸
	 * @return array
	 * <code>array(
	 * 	'image_id', // 图片ID
	 *  'node_id',// 节点ID
	 *  'block_id',// 块ID
	 *  'block_col',  // 块的第几列
	 *  'block_row', // 块的第几行
	 * )</code>
	 */
	public function newImageId ( $node, $imageSize )
	{
		$nodeId = intval($node['node_id']);
		
		$db = Db::get(self::DB_NAME);
		$db->table(self::NODE_TABLE_NAME)
			->saveBody(array( 
			'max_incr_id' => array( 
				'exp' => 'LAST_INSERT_ID(max_incr_id+1)'
			)
		))
			->where(array( 
			'node_id' => intval($nodeId)
		))
			->update();
		$row = $db->queryBySql('SELECT LAST_INSERT_ID() as ID');
		$lastId = intval($row[0]['ID']);
		
		Logger::trace('new_incr_id:' . $lastId);
		$maxId = self::BLOCK_NUM_PER_NODE * self::IMAGE_NUM_PER_BLOCK;
		if ( $lastId >= $maxId ) {
			throw new Exception('node.imageIdOutofRange max:' . $maxId);
		}
		
		// 计算ID应该在落在哪个块上
		// 最小ID为1
		// 处在第几列
		$blockCol = ($lastId - 1) % self::BLOCK_NUM_MEANWHILE;
		
		// 处在第几行
		$blockRow = ceil($lastId / (self::IMAGE_NUM_PER_BLOCK * self::BLOCK_NUM_MEANWHILE)) - 1;
		
		// 块的ID
		$blockId = $blockRow * self::BLOCK_NUM_MEANWHILE + $blockCol;
		
		// 图片应该放在哪个目录ID下
		$dirId = $this->getBlockDirId($blockId);
		
		// 检查图片表的创建情况
		if ( $node['max_block_id'] == - 1 ) {
			$oDirId = - 1;
		} else {
			$oDirId = $this->getBlockDirId($node['max_block_id']);
		}
		if ( $oDirId < $dirId ) {
			$iDb = Db::get('node' . $node['node_id']);
			for ( $i = $oDirId + 1; $i <= $dirId; $i ++ ) {
				$sql = $this->getImageSql($nodeId, $i);
				$iDb->queryBySql($sql);
			}
		}
		
		// 开启事务，防止并发
		$db->beginTx();
		$cause = array( 
			'node_id' => intval($nodeId),
			'rnode_id' => 0,
			'block_id' => $blockId
		);
		$rows = $db->table(self::BLOCK_TABLE_NAME)
			->field(array( 
			'used_size',
			'assigned_size'
		))
			->where($cause)
			->getForUpdate();
		
		$path = $this->getBlockFile($node, $blockId);
		if ( ! $rows ) {
			$usedSize = 0;
			$assignedSize = 0;
			
			$dir = dirname($path);
			if ( ! is_dir($dir) ) {
				mkdir($dir, 0755, true);
			}
			
			touch($path);
			$fh = fopen($path, 'r+');
			ftruncate($fh, self::BLOCK_INIT_SIZE);
			fclose($fh);
			
			$assignedSize = self::BLOCK_INIT_SIZE;
		} else {
			$usedSize = intval($rows[0]['used_size']);
			$assignedSize = intval($rows[0]['assigned_size']);
		}
		
		$nUsedSize = $usedSize + $imageSize;
		// 如果加入图片后，会让trunk的个数不够，则增加个数
		if ( $nUsedSize >= $assignedSize ) {
			do {
				$assignedSize += self::BLOCK_INCR_SIZE;
			} while ( $nUsedSize >= $assignedSize );
			
			$fh = fopen($path, 'r+');
			ftruncate($fh, $assignedSize);
			fclose($fh);
		}
		
		$cause['assigned_size'] = $assignedSize;
		$cause['block_md5'] = 0;
		$cause['used_size'] = $nUsedSize;
		$cause['update_time'] = time();
		$db->table(self::BLOCK_TABLE_NAME)
			->saveBody($cause)
			->insertOrUpdate();
		
		$clearCache = FALSE;
		if ( $blockId > $node['max_block_id'] ) {
			$db->table(self::NODE_TABLE_NAME)
				->saveBody(array( 
				'max_block_id' => $blockId
			))
				->where(array( 
				'node_id' => $nodeId
			))
				->update();
			$clearCache = TRUE;
		}
		$db->commit();
		
		// 清理缓存
		if ( $clearCache ) {
			$this->destroyNodesCache();
		}
		
		return array( 
			'incr_id' => $lastId,
			'node_id' => $node['node_id'],
			'block_id' => $blockId,
			'file_id' => $usedSize,
			'new_file_id' => $nUsedSize
		);
	}

	/**
	 * 获取图片内容
	 * @param string $id
	 * @return
	 */
	function get ( $imgId, $onlyBlob = false )
	{
		$info = $this->unpackId($imgId);
		$nodeId = intval($info['node_id']);
		$blockId = intval($info['block_id']);
		
		$nodes = $this->getNodes();
		if ( ! isset($nodes[$nodeId]) ) {
			throw new Exception('image.nodeNotFound node_id:' . $nodeId);
		}
		$node = $nodes[$nodeId];
		$cnode = $this->getCurrentNode();
		Logger::trace('node_id:' . $info['node_id'] . ';img_id:' . $imgId);
		
		// 如果不是获取备份的图片，直接读取
		if ( $node['node_id'] == $cnode['node_id'] ) {
			$path = $this->getBlockFile($node, $blockId);
			if ( ! is_file($path) ) {
				return false;
			}
			
			$start = microtime(true);
			$blob = $this->rawReadImage($imgId, $path, $info['file_id']);
			Logger::trace('readImage cost:' . sprintf('%.3f', (microtime(true) - $start) * 1000));
			return $this->_packBlob($info, $blob, $onlyBlob);
		}
		$copyNode = $cnode;
		$copyNode['node_id'] = $node['node_id'];
		$path = $this->getBlockFile($copyNode, $blockId);
		if ( ! is_readable($path) ) {
			$blob = $this->rawGetImage($imgId, $info['ext'], $node);
			return $this->_packBlob($info, $blob, $onlyBlob);
		}
		$blob = $this->rawReadImage($imgId, $path, $info['file_id']);
		return $this->_packBlob($info, $blob, $onlyBlob);
	}

	/**
	 * 每一个块的复制节点都是一定的
	 * @param int $nodeId
	 * @param int $blockId
	 * @return array
	 */
	private function getBackupNodes ( $nodeId, $blockId )
	{
		$nodes = $this->getNodes();
		$nodeIds = array_values(array_diff(array_keys($nodes), array( 
			$nodeId
		)));
		if ( ! $nodeIds ) {
			return array();
		}
		$ret = array();
		$num = count($nodeIds);
		if ( $num == 1 ) {
			return array( 
				$nodeIds[0] => $nodes[$nodeIds[0]]
			);
		}
		$first = $blockId % $num;
		$second = ($first + 1) % $num;
		if ( $num > 1 && $num < 5 ) {
			return array( 
				$nodeIds[$first] => $nodes[$nodeIds[$first]],
				$nodeIds[$second] => $nodes[$nodeIds[$second]]
			);
		}
		
		$third = ($second + 1) % $num;
		return array( 
			$nodeIds[$first] => $nodes[$nodeIds[$first]],
			$nodeIds[$second] => $nodes[$nodeIds[$second]],
			$nodeIds[$third] => $nodes[$nodeIds[$third]]
		);
	}

	private function _packBlob ( $info, $blob, $onlyBlob )
	{
		if ( ! $blob ) {
			return false;
		} else {
			if ( $onlyBlob ) {
				return $blob;
			}
			$info['blob'] = $blob;
			return $info;
		}
	}

	private function rawGetImage ( $imgId, $ext, $node )
	{
		$url = sprintf('%s/image/%s.%s', $node['node_url'], $imgId, $ext);
		require_once LIB_ROOT . 'curl/Curl.php';
		$curl = new Curl();
		$ret = $curl->get($url, array( 
			CURLOPT_CONNECTTIMEOUT => 2
		));
		Logger::trace('fetch image img_id=' . $imgId . ';url=' . $url);
		if ( $ret->code == 200 ) {
			return $ret->content;
		}
		return false;
	}

	private function rawReadImage ( $imgId, $path, $fileId )
	{
		$fh = fopen($path, 'r');
		if ( $fileId > 0 ) {
			fseek($fh, $fileId);
		}
		$flag = unpack('C', fread($fh, 1));
		if ( ! $flag ) {
			return false;
		}
		$flag = $flag[1];
		
		$delete = ($flag & self::FLAG_STATUS_DELETE) == self::FLAG_STATUS_DELETE;
		if ( $delete ) {
			return false;
		}
		
		// 读取图片id
		$_id = self::_2to64(fread($fh, 17));
		if ( $_id != $imgId ) {
			return false;
		}
		// 创建时间
		fseek($fh, 4, SEEK_CUR);
		// md5
		fseek($fh, 8, SEEK_CUR);
		
		$itype = $flag & self::FLAG_TYPE_REFERENCE;
		if ( $itype == self::FLAG_TYPE_REFERENCE ) {
			$refImageId = self::_2to64(fread($fh, 17));
			fclose($fh);
			if ( $refImageId == $imgId ) {
				return false;
			}
			return $this->get($refImageId, true);
		} else {
			$len = unpack('N', fread($fh, 4));
			$blob = fread($fh, $len[1]);
			fclose($fh);
			return $blob;
		}
	}

	/**
	 * 备份图片
	 * @param array $info
	 * @param array $node
	 */
	private function restoreImage ( $info, $node )
	{
		$rnodeIds = array();
		if ( $repliNodes ) {
			$rnodeIds = array_unique(array_map('intval', explode(',', $repliNodes)));
		}
		if ( ! $rnodeIds ) {
			return;
		}
		$nodes = $this->getNodes();
		$urls = array();
		foreach ( $rnodeIds as $rnodeId ) {
			if ( ! isset($nodes[$rnodeId]) ) {
				continue;
			}
			$urls[] = $nodes[$rnodeId]['node_url'];
		}
		$info['urls'] = $urls;
		// 调用同步程序
		$phpPath = Conf::get('php.path', 'php');
		$cmd = sprintf('cd %s/bin/ && ' . $phpPath . ' ./restore.php -d \'%s\' &', WEB_ROOT, json_encode($info));
		Logger::trace('restore cmd:' . $cmd);
		
		$start = microtime(true);
		
		pclose(popen($cmd, 'r'));
		
		Logger::trace('restore image cost:' . (microtime(true) - $start));
	}

	/**
	 * 初始化块
	 * @param array $node
	 * @param int $blockId
	 */
	private function getBlockFile ( $node, $blockId )
	{
		$blockDirId = $this->getBlockDirId($blockId);
		return sprintf('%s/node_%d/dir_%d/block_%d.data', rtrim($node['image_dir'], '/'), $node['node_id'], $blockDirId, 
				$blockId);
	}

	private function getBlockDirId ( $blockId )
	{
		return intval($blockId / self::BLOCK_NUM_PER_DIR);
	}

	/**
	 * 
	 * @param int $nodeId
	 * @param int $blockDirId
	 * @return string
	 */
	private function getImageTable ( $nodeId, $blockDirId )
	{
		return 'st_image_' . $nodeId . '_' . $blockDirId;
	}

	/**
	 * 获取图片列表
	 * @param int $nodeId
	 * @param int $blockDirId
	 * @return string
	 */
	private function getImageSql ( $nodeId, $blockDirId )
	{
		$tbName = $this->getImageTable($nodeId, $blockDirId);
		return str_replace('{IMAGE_TABLE}', $tbName, self::IMAGE_SQL);
	}

	private static $base64 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_!';

	private static $map64;

	/**
	 * 封装图片ID
	 * @param int $nodeId 节点ID
	 * @param int $incrId 自增ID
	 * @param int $blockId 块ID
	 * @param int $fileId 文件ID
	 * @param int $width 宽
	 * @param int $height 高
	 * @param int $type 类型
	 * @return string
	 */
	private function packId ( $nodeId, $incrId, $blockId, $fileId, $width, $height, $type )
	{
		$id2 = pack('nNncN2', $nodeId, $incrId, $blockId >> 8, $blockId & 0xFF, $fileId, 
				($width << 18) | ($height << 4) | $type);
		
		$id64 = $this->_2to64($id2);
		
		return array( 
			'2' => $id2,
			'16' => bin2hex($id2),
			'64' => $id64
		);
	}

	/**
	 * 将2进制转化为64进制
	 */
	private function _2to64 ( $id2 )
	{
		// 用来补全64位所需空位
		$id64 = '';
		$nums = array();
		$chars = array();
		for ($i = 0; $i < 17; $i++) {
			$chars[$i] = hexdec(bin2hex($id2[$i]));
		}
		for ( $i = 0; $i < 17; $i += 3 ) {
			$nums[] = $chars[$i] >> 2;
			$nums[] = ( ($chars[$i] & 0x3) << 4 ) | ($chars[$i+1] >> 4);
			if ($i < 15) {
				$nums[] = ( ($chars[$i + 1] & 0xF) << 2 ) | ($chars[$i+2] >> 6);
				$nums[] = $chars[$i + 2] & 0x3F;
			} else {
				$nums[] = ($chars[$i + 1] & 0xF) << 2;
			}
		}
		$base64 = self::$base64;
/*
		return implode('', array_map(function  ( $i ) use( $base64 ) {
			return $base64[$i];
		}, $nums));
*/	}

	/**
	 * 解压图片ID
	 * @param string $id 十六进制
	 * @param array
	 * <code>array(
	 *  'node_id',
	 *  'img_id',
	 *  'width',
	 *  'height',
	 *  'type',
	 *  'ext',
	 * )</code>
	 */
	function unpackId ( $id64 )
	{
		if ( strlen($id64) != 23 ) {
			return false;
		}
		
		if ( ! self::$map64 ) {
			$bmap = array();
			for ( $i = 0; $i < 64; $i ++ ) {
				$bmap[$i] = self::$base64[$i];
			}
			self::$map64 = $bmap = array_flip($bmap);
		} else {
			$bmap = self::$map64;
		}
		
		$id16 = '';
		$bins = array();
		for ( $i = 0; $i < 23; $i += 4 ) {
			$bins[] = ($bmap[$id64[$i]] << 2) | ($bmap[$id64[$i + 1]] >> 4);
			$bins[] = (($bmap[$id64[$i + 1]] & 0xF) << 4) | ($bmap[$id64[$i + 2]] >> 2);
			if ($i < 20) {
				$bins[] = (($bmap[$id64[$i + 2]] & 0x3) << 6) | $bmap[$id64[$i + 3]];
			}
		}
		$type = $bins[16] & 0xF;
		if ( ! isset($this->imageExts[$type]) ) {
			return false;
		}
		
		$nodeId = ($bins[0]) << 8 | $bins[1];
		$incrId = ($bins[2] << 24) | ($bins[3] << 16) | ($bins[4] << 8) | $bins[5];
		$blockId = ($bins[6] << 16) | ($bins[7] << 8) | $bins[8];
		$fileId = ($bins[9] << 24) | ($bins[10] << 16) | ($bins[11] << 8) | $bins[12];
		$width = ($bins[13] << 6) | ($bins[14] >> 2);
		$height = (($bins[14] & 0x3) << 12) | ($bins[15] << 4) | ($bins[16] >> 4);
		
		return array( 
			'node_id' => $nodeId,
			'incr_id' => $incrId,
			'block_id' => $blockId,
			'file_id' => $fileId,
			'width' => $width,
			'height' => $height,
			'type' => $type,
			'ext' => $this->imageExts[$type]
		);
	}

	/**
	 * 获取指定节点的图片表
	 * @param int $nodeId
	 * @return array
	 */
	function getTables ( $nodeId = 0 )
	{
		if ( $nodeId > 0 ) {
			$nodes = $this->getNodes();
			if ( ! isset($nodes[$nodeId]) ) {
				return array();
			}
		} else {
			$node = $this->getCurrentNode();
			$nodeId = $node['node_id'];
		}
		$db = Db::get('node' . $nodeId);
		$rows = $db->queryBySql("show tables like 'st_image_{$nodeId}_%'");
		if ( $rows ) {
			$rows = array_map('array_shift', $rows);
		}
		return $rows;
	}

	/**
	 * 获取图片
	 * @param int $blockDirId
	 * @param int $num
	 * @param int $nodeId
	 * @param int $maxId 最大图片ID
	 * @param array
	 */
	function getImageLists ( $blockDirId, $num, $nodeId = 0, $maxId = 0 )
	{
		if ( $nodeId > 0 ) {
			$node = $this->getNode($nodeId);
			if ( ! $node ) {
				return array( 
					'max_id' => 0,
					'list' => array()
				);
			}
		} else {
			$node = $this->getCurrentNode();
			$nodeId = $node['node_id'];
		}
		$db = Db::get('node' . $nodeId);
		$tbName = $this->getImageTable($nodeId, $blockDirId);
		
		$db->table($tbName)
			->where('1=1');
		if ( $maxId > 0 ) {
			$db->where('incr_id<' . $maxId);
		}
		$rows = $db->order('incr_id', FALSE)
			->limit(0, $num)
			->get();
		if ( $rows ) {
			foreach ( $rows as &$row ) {
				$hexId = self::_2to64($row['img_id']);
				$row['id'] = $hexId;
				$info = $this->unpackId($hexId);
				$row['node_id'] = $info['node_id'];
				$row['block_id'] = $info['block_id'];
				$row['file_id'] = $info['file_id'];
				$row['img_ext'] = $info['ext'];
				$row['img_width'] = $info['width'];
				$row['img_height'] = $info['height'];
				$row['img_url'] = $this->getUrl($node['image_url'], $hexId, $info['ext']);
			}
			$maxId = $rows[count($rows) - 1]['incr_id'];
		} else {
			$maxId = 0;
		}
		return array( 
			'max_id' => $maxId,
			'list' => $rows
		);
	}

	private function getUrl ( $host, $id, $ext )
	{
		return preg_replace(array( 
			'#\{id\}#',
			'#\{ext\}#'
		), array( 
			$id,
			$ext
		), $host);
	}

	/**
	 * 备份
	 * @param int $imgId
	 * @param string $blob
	 * @param int $from
	 * @param int $createTime
	 * @param string $md5
	 */
	function doBackup ( $imgId, $blob, $from )
	{
		$info = $this->unpackId($imgId);
		if ( ! $info ) {
			// TODO 错误处理
			return;
		}
		$node = $this->getNode($info['node_id']);
		if ( ! $node ) {
			throw new Exception('storage.nodeNotFound node_id=' . $node['node_id']);
		}
		
		$cnode = $this->getCurrentNode();
		if ( $node['node_id'] == $cnode['node_id'] ) {
			trigger_error('not need to backup', E_USER_WARNING);
			return;
		}
		
		$copyNode = $cnode;
		$copyNode['node_id'] = $node['node_id'];
		$path = $this->getBlockFile($copyNode, $info['block_id']);
		Logger::trace('backup:' . $path);
		Logger::trace('backup.data:' . strlen($blob));
		
		$fh = null;
		if ( ! is_file($path) ) {
			$dir = dirname($path);
			if ( ! is_dir($dir) ) {
				mkdir($dir, 0755, true);
			}
			touch($path);
			$fh = fopen($path, 'r+');
			ftruncate($fh, self::BLOCK_INIT_SIZE);
		} else {
			clearstatcache();
			$size = filesize($path);
			$nSize = $from + strlen($blob);
			
			if ( $nSize > $size ) {
				do {
					$size += self::BLOCK_INCR_SIZE;
				} while ( $nSize >= $size );
				
				$fh = fopen($path, 'r+');
				ftruncate($fh, $size);
			}
		}
		if ( ! $fh ) {
			$fh = fopen($path, 'r+');
		}
		fseek($fh, $from);
		fwrite($fh, $blob);
		fclose($fh);
		
		Logger::trace('backup image:' . $imgId);
	}
}
