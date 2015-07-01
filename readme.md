图片系统概述
------------------

1. 架构：mysql+php+文件系统
2. 原理：

* mysql存储基本的meta信息。
* 文件系统存储图片。
* 将小图片汇聚成大图片，通过图片文件即可完成数据的恢复。
* 利用mysql主从，实现meta信息的备份，进而实现分布式的查询。
* 每个节点都可以写入和读取图片，去除中心化。写入图片后，自动通知其他节点同步数据。


图片存储原理
------------

* 图片系统由若干个节点组成，每个节点的图片存储了若干个图片块。
* 节点接收到图片上传请求后，计算出图片的md5值，检查是否已经上传过，上传过则创建一个参考图片请求，否则创建一个普通图片请求。
* 每个节点同时设立多个并行写入的块（默认8个），根据获取的图片自增ID，依次将图片分布到并行块中，从而避免对同一个文件的并行操作。
* 每个块初始大小64M，存储256张图片，当超过这个数目的图片数目后，将新建一个块，继续写入。
* 由于同一个文件夹的文件数目过多时，检索较慢，因而设定每1024个块汇入一个文件夹。
* 每个图片保存的时候，会根据节点数多少，将对应的块自动备份到其他节点上。

一个节点的基本目录结构如下

*	root_dir
	*	node1           // 主节点
		*	dir_0			
			* block_0.data	// 图片块
			* block_1.data
			* ...
		*	dir_1
			* block_1024.data
			* block_1025.data
			* ...
	*	node2           // 用来存储其他节点的备份目录
		*	dir_0
			* block_0.data	
	* ...

图片ID结构
------------

### 基本结构

* 2字节的node_id
* 4字节的自增id（一个节点最多42亿张图片，平均100k的话，约400T数据）
* 3字节的块id（允许放0.16亿个块，假设每个块64M的话，可存储1024T数据）
* 4字节的文件id（块里边文件的位置，最多允许一个块4G）
*	4字节的meta信息
	* 14bit的宽
	* 14bit的高
	* 4bit的类型

总共`17`个字节，用64位字符表示，最多需要17*8/6=22.6 ≈ `23`个字符

### 64位字符

> 0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_!

### 图片存储结构

#### 通用存储结构

 前面26个字节是相同的
 
*	1字节标识位
	* 0x1：类型 0 正常图片 1 参考图片
	* 0x2：状态 0 正常 1 删除
	 
* 17字节图片ID
* 4字节创建时间
* 8字节md5长整形数字
	 
后面的存储结构按类型分为两种：

1. 正常图片

* 4字节 图片长度
* 图片内容

共占用30个字节+图片字节数

2. 参考图片

* 17字节的参考图片id

共占用39个字节

服务器搭建方法
--------------

### nginx配置

```nginx
server {
    server_name  node4.demo.com;
    listen      8001
    root        ~/sites/storage/runroot/;

    location / { 
        try_files $uri $uri/ /index.php?$query_string;
    }   
    location ~ .*\.php$ {
        fastcgi_param  STORAGE_NODE_ID  4;  # 此处设置好节点的节点ID，或者在配置文件直接指定
        fastcgi_pass   127.0.0.1:9001;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME     $document_root$fastcgi_script_name;
        fastcgi_param  HTTP_HOST        $host;
        include        fastcgi_params;
    }   
}
```

```shell
git clone https://github.com/hapn/storage.git ~/sites/storage
cd ~/sites/storage
mkdir log 
chmod 777 log
cp conf/hapn.conf.php.default conf/hapn.conf.php
```


### 图片存储目录


创建图片存储目录

```shell
mkdir ~/image/data -p
chmod 777 ~/image/data
```

访问 [http://node4.demo.com/guanli/node](http://node4.demo.com/guanli/node)，将节点添加进去，即可开始上传图片。

添加节点时，需要将图片根目录指定为 ~/image/data
