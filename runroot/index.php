<?php
/***************************************************************************
 * 
 * Copyright (c) 2010 , Inc. All Rights Reserved
 * $Id$:index.php,2010/05/07 13:49:09 
 * 
 **************************************************************************/
/**
 * @file index.php
 * @author huqingping
 * @date 2010/05/07 13:49:09
 * @version 1.0 
 * @brief 
 *  
 **/
define('WEB_ROOT', 		dirname(__DIR__));
define('FR_ROOT',		WEB_ROOT.'/Lessp/fr/');
define('RUN_ROOT',		WEB_ROOT.'/runroot/');
define('LIB_ROOT',		WEB_ROOT.'/Lessp/lib/');
define('PLUGIN_ROOT',	WEB_ROOT.'/plugin/');
define('LOG_ROOT',		WEB_ROOT.'/log/');
define('CONF_ROOT',		WEB_ROOT.'/conf/');
define('TMP_ROOT',		WEB_ROOT.'/tmp/');
define('EXLIB_ROOT',	WEB_ROOT.'/exlib/');
define('PAGE_ROOT',		WEB_ROOT.'/page/');
define('API_ROOT',		WEB_ROOT.'/api/');
define('TOOL_ROOT',		WEB_ROOT.'/bin/');

if (isset($argv)) {
	require_once FR_ROOT.'app/ToolApp.php';
	$obj = new ToolApp();
	$obj->run();
} else {
	require_once FR_ROOT.'app/WebApp.php';
	$obj = new WebApp();
	$obj->run();
}

