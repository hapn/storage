<?php

/**
*   @copyright 		Copyright (C) Jiehun.com.cn 2014 All rights reserved.
*   @file			PartialController.php
*   @author			ronnie<dengxiaolong@jiehun.com.cn>
*   @date			2014-12-17
*   @version		1.0
*   @description 
*/

class _Private_Controller extends PageController
{
	/**
	 * 分页控制器
	 */
	function pager_partial_action()
	{
		$req = $this->request;
		$outputs = $this->response->outputs;
		
		$pageTag = '${page}';
		// 获取url规则
		// 如果明确指定了网址路径
		if ( !$this->get('urlRule') ) {
			$urlRule = isset($req->userData['origin_url']) ? $req->userData['origin_url'] : $req->url;
			
			foreach($req->inputs as $key => $value) {
				if ($key != '_pn') {
					if (is_array($value)) {
						foreach($value as $v) {
							$args[] = $key.'[]='.$v;
						}
					} else {
						$args[] = $key.'='.$value;
					}
				}
			}
			if (!empty($args)) {
				$urlRule .= '?'.implode($args, '&');
			}
			
		} else {
			$urlRule = $this->get('urlRule');
		}
		if (strpos($urlRule, $pageTag) === false) {
			$startUrl = $urlRule;
			if (strpos($urlRule, '?') === false) {
				$urlRule = $urlRule.'?_pn='.$pageTag;
			} else {
				$urlRule = $urlRule.'&_pn='.$pageTag;
			}
		} else {
			$startUrl = str_replace($pageTag, '', $urlRule);
			$startUrl = rtrim($startUrl, '?');
		}
	
		// 获取页数
		if (isset($outputs['page'])) {
			$page = $outputs['page'];
		} else {
			$page = isset($req->inputs['_pn']) ? intval($req->inputs['_pn']) - 1 : 0;
		}
		$page = min(max(0, $page), 10000);
		$page = $page + 1;
	
		// 获取每页显示的条目数
		if (isset($outputs['pageSize'])) {
			$pageSize = intval($outputs['pageSize']);
		} else {
			$pageSize = isset($req->inputs['_sz']) ? intval($req->inputs['_sz']) : 20;
		}
		$pageSize = min(max(0,  $pageSize ), 1000);
	
		// 获取总数
		$total = isset($outputs['total']) ? intval($outputs['total']) : 0;
		$pageRange = $this->get('pageRange', 6);
		if ($pageRange <= 0) {
			$pageRange = 5;
		}
	
		$showTotal 	= isset($outputs['showTotal']) ? $outputs['showTotal'] : false;
		$showGo 	= isset($outputs['showGo']) ? $outputs['showGo'] :false;
		$maxPageNum = isset($outputs['maxPageNum']) ? $outputs['maxPageNum'] : 50;
		$pageNum = ceil($total / $pageSize);
		if ($maxPageNum > 0 && $pageNum > $maxPageNum) {
			$pageNum = $maxPageNum;
		}
	
		$ret = array();
		$ret[] = '<nav>';
		$ret[] = '<ul class="pagination">';
		if ($showTotal) {
			$ret[] = '<li>共'.$total.'项</li>';
		}
		if ($pageNum  > 1) {
			if ($pageNum > $pageRange) {
				if ($page > 1){
					$ret[] = '<li><a href="'.$startUrl.'">首页</a></li>';
					if ($page > 2){
						$ret[] = '<li><a href="'.str_replace($pageTag, $page - 1, $urlRule).'">上一页</a></li>';
					}else{
						$ret[] = '<li><a href="'.$startUrl.'">上一页</a></li>';
					}
				}else{
					$ret[] = '<li><span>首页</span></li>';
					$ret[] = '<li><span>上一页</span></li>';
				}
			}
	
			$half = ceil($pageRange / 2);
			if ($page - $half < 1) {
				$startP = 1;
				$endP = min($startP + $pageRange, $pageNum);
			} else if ($page + $half > $pageNum) {
				$endP = $pageNum;
				$startP = max($pageNum - $pageRange, 1);
			} else {
				$startP = $page - $half;
				$endP = $pageRange + $startP;
			}
				
				
			if($startP > 1){
				$ret[] = '<li>...</li>';
			}
			$forend = ($page > $endP) ? $endP : $page;
			for($p = $startP; $p < $forend; $p++) { //设置当前pi之前的页码
				if ($p == 1) {
					$ret[] = '<li><a href="'.$startUrl.'">'.$p.'</a></li>';
				} else {
					$ret[] = '<li><a href="'.str_replace($pageTag, $p, $urlRule).'">'.$p.'</a></li>';
				}
			}
			$ret[] = '<li class="active"><a>'.$page.'</a></li>';
	
			for($p = $page+1; $p <= $endP; $p++){		//设置当前pi之后的页码
				$ret[] = '<li><a href="'.str_replace($pageTag, $p, $urlRule).'">'.$p.'</a></li>';
			}
	
			if($endP < $pageNum){
				$ret[] = '<li>...</li>';
			}
	
			if ($pageNum > $pageRange) {
				if ($page < $pageNum){
					$ret[] = '<li><a href="'.str_replace($pageTag, $page+1, $urlRule).'">下一页</a></li>';
					$ret[] = '<li><a href="'.str_replace($pageTag, $pageNum, $urlRule).'">尾页</a></li>';
				}else{
					$ret[] = '<li><span>下一页</span></li>';
					$ret[] = '<li><span>尾页</span></li>';
				}
			}
	
			if ($showGo && $pageNum > $pageRange) {
				$pos = strpos($urlRule, '?');
				if ($pos === false) {
					$action = $urlRule;
					$params = array();
				} else {
					$action = substr($urlRule, 0, $pos);
					parse_str(substr($urlRule, $pos + 1), $params);
				}
	
	
				$ret[] = '<li><form method="get" action="'.$action.'" class="pagerForm">';
				foreach ($params as $key=>$value) {
					$ret[] = '<input type="hidden" name="'.$key.'" value="'.$value.'"/>';
				}
				$ret[] = '第<input type="text" size="2" class="pagergonum text-b" value="'.$page.'" />页<input type="submit" class="_jpgo e-btn-go" value="Go"/>';
				$ret[] = '</form></li>';
			}
		}
		$ret[] = '</ul></nav>';
		if ($pageNum  > 1) {
			if ($showGo && $pageNum > $pageRange) {
				$ret [] = '<script>hapj(function(H,$){hapj({_tag:["input"],pgo:function(E){E.on("click", function(){var _parent = E.parent()[0];var pagerNum = $(_parent).find("input[type=text]").val() == 0 ? 1 : $(_parent).find("input[type=text]").val();var ac = $(_parent).attr("action");if(pagerNum>0){$(_parent).attr("action",ac.replace("'.$pageTag.'",pagerNum));}else{$(_parent).attr("action",ac.replace("_p'.$pageTag.'",""));}});}});})</script>';
			}
		}
		$this->response->setRaw(implode('', $ret));
	}

	/**
	 * 错误页
	 */
	function error_forward_action()
	{
		$this->setView('tpl/error.phtml');
	}
	
	/**
	 * 找不到页面
	 */
	function notfound_forward_action()
	{
		$this->setView('tpl/notfound.phtml');
	}
	
	/**
	 * 错误页
	 */
	function power_forward_action()
	{
		$this->setView('tpl/nopower.phtml');
	}
}