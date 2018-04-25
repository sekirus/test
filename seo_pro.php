<?php
class ControllerCommonSeoPro extends Controller {
	private $cache_data = null;

	// при создании объекта, считываем кэш в переменную. Из файла cache.seo_pro.
	// Если файлика нет, то берем (в переменную + создаем файл кэша cache.seo_pro) из базы url_alias пару keyword => query. 
	public function __construct($registry) {
		parent::__construct($registry);
		$this->cache_data = $this->cache->get('seo_pro');  // читаем из файла кэша cache.seo_pro
		if (!$this->cache_data) {  // если кэша нет, то создаем его
			$query = $this->db->query("SELECT LOWER(`keyword`) as 'keyword', `query` FROM " . DB_PREFIX . "url_alias");
			$this->cache_data = array();
			foreach ($query->rows as $row) {
				$this->cache_data['keywords'][$row['keyword']] = $row['query'];
				$this->cache_data['queries'][$row['query']] = $row['keyword'];
			}
			$this->cache->set('seo_pro', $this->cache_data);
		}
	}

	public function index() {  // SEO -> URL 
		// Добавляем функцию rewrite к классу URL
		if ($this->config->get('config_seo_url')) {  // опция активна
			$this->url->addRewrite($this);
		} else {
			return;
		}

		if (!isset($this->request->get['_route_'])) {  // у нас урл вида index.php
			$this->validate();
			return;
		}
	// у нас SEO урл  // тогда разбор УРЛа
		$route = $this->request->get['_route_'];  // $route - SEO url men/fashion/extraslim/  
		unset($this->request->get['_route_']);
		$parts = explode('/', trim(utf8_strtolower($route), '/')); // теперь у нас массив SEO частей в $parts // $route больше не используется

		if (reset($parts) == 'go') {		// короткие ссылки
			if (isset($parts[1])) {
				$short_keyword = $this->db->escape(trim($parts[1]));
				$query = $this->db->query("SELECT `url_short_id`, `query` FROM `".DB_PREFIX."url_short` WHERE keyword = '$short_keyword'");
				
				if ($query->num_rows && !empty($query->rows[0]['query'])) {  // redirect
					$short_query = $query->rows[0]['query'];
//					$short_query = ((substr($short_query, 0, 1) != "/") ? '/' : '').$short_query;
					$this->db->query("UPDATE ".DB_PREFIX."url_short SET viewed = (viewed + 1) WHERE url_short_id = '".(int)$query->rows[0]['url_short_id']."'");
					$this->response->redirect(HTTP_SERVER.$short_query);
				}
			}
		}

		if (reset($parts) == 'order') {		// сразу определяем страница под заказ или в наличие. и убираем эту часть СЕО УРЛа
				unset($parts[key($parts)]);
				$parts = array_values($parts);
				$this->request->get['preorder'] = "1";
		}
		if (reset($parts) == 'product') {		// если ссылка на продукт, то удаляем слово product, так как определять будем по названию модели
				unset($parts[key($parts)]);
				$parts = array_values($parts);
		}		

		switch (reset($parts)) {
//	   		case 'link':
	   		case 'blog':
	   				$rows[] = array('keyword' => 'blog', 'query' => (isset($this->cache_data['keywords']['blog'])) ? $this->cache_data['keywords']['blog'] : 'module/blog');
					$part_type = '';
					$part_day = '';
					$part_page = '';
					$part_sort = '';
					$tags = array();
					$tags_db = array();
					for ($i = count($rows); $i < sizeof($parts); $i++){  // Перебор всех значений после слова "blog"
						if 	(empty($part_type)) {
							$part_count = 0;
							switch ($parts[$i]) {
								case 'tag':
									$part_type = $parts[$i];
									$query = $this->db->query("SELECT `tag_id`, `alias` FROM `".DB_PREFIX."blog_tags`");
									if ($query->num_rows) {
										foreach ($query->rows as $result) {
											$tags_db[$result['tag_id']] = $result['alias'];
										}		 			
									}
									break;

								case 'day':
									$part_type = $parts[$i];
									break;

								case 'page':
									$part_type = $parts[$i];
									break;

								case 'sort':
									$part_type = $parts[$i];
									break;
								default:
									if ((int) $parts[$i] > 3000) {
										$rows[] = array('keyword' => $parts[$i], 'query' => 'blog_id='.(int)$parts[$i]);
									} else {
										$query = $this->db->query("SELECT `blog_id` FROM `".DB_PREFIX."blog_alias` WHERE `alias` = '".$this->db->escape($parts[$i])."' LIMIT 1");
										if ($query->num_rows) $rows[] = array('keyword' => $parts[$i], 'query' => 'blog_id='.(int)$query->rows[0]['blog_id']);
									}
									
							}
						} else {
							switch ($part_type) {
								case 'tag':
									if (in_array($parts[$i], array('day', 'page', 'sort'))) {
										$part_type = '';
										$i--;									
									}

									foreach ($tags_db as $tag_id=>$tag_alias) {
										if (strcasecmp($parts[$i], $tag_alias) == 0) {
											if (!in_array($tag_id, $tags)) $tags[] = $tag_id;
											break;
										}
									}
									break;

								case 'day':
									if (!is_numeric($parts[$i])) {
										$part_type = '';
										$i--;
										break;
									}
								
									$part_day .= (($part_day == '')?'':'-').(int)$parts[$i];
									$part_count++;
									if ($part_count == 3) {
										$part_type = '';
										break;									
									}
									break;

								case 'page':
									if (!is_numeric($parts[$i])) {
										$part_type = '';
										$i--;
										break;
									}
									
									$part_page = $parts[$i];
									$part_type = '';
									break;

								case 'sort':
									if (in_array($parts[$i], array('name', 'view', 'comment'))) {
										$part_sort = $parts[$i];
									} else {
											$i--;									
									}
									
									$part_type = '';
									break;
							}							
						}
					}

					if (!empty($tags)) {
						$rows[] = array('keyword' => 'tag', 'query' => 'tag_id='.implode(",", $tags));
					}
					if (!empty($part_day)) {
						$rows[] = array('keyword' => 'day', 'query' => 'day='.$part_day);
					}
					if (!empty($part_page)) {
						$rows[] = array('keyword' => 'page', 'query' => 'page_number='.$part_page);
					}
					if (!empty($part_sort)) {
						$rows[] = array('keyword' => 'sort', 'query' => 'sort_type='.$part_sort);
					}															

					for ($i = count($rows); $i < sizeof($parts); $i++){
						$rows[] = array('keyword' => '', 'query' => '');
					}	   		
	   		
	   		
				break;
				
	   		default:
				
				$last_part = explode('.', array_pop($parts));	// хз для чего. избавляемся от расширения типа ".html"
				if (!empty($last_part[1])) $ext = $last_part[1];
				array_push($parts, $last_part[0]);
				
//				list($last_part, $ext) = explode('.', array_pop($parts));	// хз для чего. избавляемся от расширения типа ".html"


				// для работы категорий с одинаковыми названиями, но разной вложенностью проверям каждый раз в базе все
				$rows = array();
				$parent_id = 0;
				foreach ($parts as $keyword) {
					$sql_query ="SELECT c.category_id, ua.keyword, ua.query, c.parent_id FROM ss_url_alias ua ".
								"LEFT JOIN ss_category c ON (RIGHT(ua.query, LENGTH(ua.query)-LOCATE('=',ua.query)) = c.category_id) ".
								"WHERE	 ua.keyword  = '".$this->db->escape($keyword)."' and ".
										"ua.query  like 'category_id%' and ".
										"c.status    = 1 and ".
										"c.parent_id = ".$parent_id;

					$query = $this->db->query($sql_query);

					if ($query->num_rows == 1) { // это категория
						$parent_id = (int) $query->row['category_id'];
						$rows[] = array('keyword' => $keyword, 'query' => $query->row['query']);
					} else {  // все остальное кроме категорий
						if (isset($this->cache_data['keywords'][$keyword])) {
							$rows[] = array('keyword' => $keyword, 'query' => $this->cache_data['keywords'][$keyword]);
						} else { // ручной разбор слов
						}
					}
				}
    	}					

		if (count($rows) == sizeof($parts)) {
			$queries = array();
			foreach ($rows as $row) {
				if ($row['keyword'] == "") continue;
				$queries[utf8_strtolower($row['keyword'])] = $row['query'];
			}

			reset($parts);
			foreach ($parts as $part) {
				if (!isset($queries[$part])) continue;
					
				$url = explode('=', $queries[$part], 2);
					
				if ($url[0] == 'category_id') {
					if (!isset($this->request->get['path'])) {
						$this->request->get['path'] = $url[1];
					} else {
						$this->request->get['path'] .= '_' . $url[1];
					}
				} elseif (count($url) > 1) {
					$this->request->get[$url[0]] = $url[1];
				}
			}
		} else {
			$this->request->get['route'] = 'error/not_found';
		}

	// прописываем route
		if (isset($this->request->get['product_id'])) { // продукты
			$this->request->get['route'] = 'product/product';
			if (!isset($this->request->get['path'])) {
				$path = $this->getPathByProduct($this->request->get['product_id']);
				if ($path) $this->request->get['path'] = $path;
			}
			if (!empty($ext) && $ext == 'quick') $this->request->get['quick'] = 1;
		} elseif (isset($this->request->get['path'])) 			{ 	$this->request->get['route'] = 'product/category';
		} elseif (isset($this->request->get['manufacturer_id'])){ 	$this->request->get['route'] = 'product/manufacturer/info';
		} elseif (isset($this->request->get['information_id'])) {	$this->request->get['route'] = 'information/information';
		} else {
			if (isset($queries[$parts[0]])) $this->request->get['route'] = $queries[$parts[0]];
		}

		$this->validate();

		if (isset($this->request->get['route'])) {
			return $this->forward($this->request->get['route']);
		}
	}

	public function rewrite($link) {  // URL -> SEO
		if (!$this->config->get('config_seo_url')) return $link; // если не включен SEO URL то ничего не делаем с URL и выходим 

		$component = parse_url(str_replace('&amp;', '&', $link)); // разбиваем урл на компоненты scheme, host, path, query

		$data = array();
		parse_str($component['query'], $data);  // разбиваем параметры на ключ=>значение

		$route = $data['route'];
		unset($data['route']);

		switch ($route) {
			case 'product/product':
				if (isset($data['preorder'])) {
					$preorder = true;
					unset($data['preorder']);
				}
				if (isset($data['quick'])) {
					$quickview = true;
				}
				if (isset($data['product_id'])) {
					$isproduct = true;
					$tmp = $data;
					$data = array();
//					if ($this->config->get('config_seo_url_include_path')) {
//						$data['path'] = $this->getPathByProduct($tmp['product_id']);
//						if (!$data['path']) return $link;
//					}
					$data['product_id'] = $tmp['product_id'];
					if (isset($tmp['tracking'])) {
						$data['tracking'] = $tmp['tracking'];
					}
				}
				break;

			case 'product/category':
				if (isset($data['preorder'])) {
					$preorder = true;					
					unset($data['preorder']);
				}
				if (isset($data['path'])) {
					
					if ($data['path'] == 0) {
						$isallcategory = true;
						unset($data['path']);
					} else  {
						$category = explode('_', $data['path']);
						$category = end($category);
						$data['path'] = $this->getPathByCategory($category);
						if (!$data['path']) return $link;
					}
				}
				break;

			case 'product/product/review':
			case 'information/information/info':
				return $link;
				break;

			default:
				break;
		}

		if ($component['scheme'] == 'https') {
			$link = $this->config->get('config_ssl');
		} else {
			$link = $this->config->get('config_url');
		}

		$link .= 'index.php?route=' . $route;

		if (count($data)) {
			$link .= '&amp;' . urldecode(http_build_query($data, '', '&amp;'));
		}

		$queries = array();
		$values = array();
		$queries_category = array();

		foreach ($data as $key => $value) {  // перечисляем параметры запроса query (ключ => значение)
			switch ($key) {
				case 'product_id':
				case 'manufacturer_id':
				case 'category_id':
				case 'information_id':
					$queries[] = $key . '=' . $value;
					unset($data[$key]);
					$postfix = 1;
					break;
				
				case 'styleid':  // доработать //
					$queries[] = $route;
					$queries[] = $key . '=' . $value;				
					unset($data[$key]);				
					break;
// ------------------ БЛОГ -------------------					
				case 'blog_id':  // блог //
					$query = $this->db->query("SELECT `alias` FROM `".DB_PREFIX."blog_alias` WHERE `blog_id` = '".(int)$value."' LIMIT 1");
					if ($query->num_rows) {
						$values[] = $query->rows[0]['alias'];
					} else {
						$values[] = $value;						
					}
					unset($data[$key]);
					break;

				case 'tag_id':  // блог //
					$values[] = 'tag';	
					$tags = implode(",", array_map('intval', explode(",", $value)));
					$query = $this->db->query("SELECT alias FROM `".DB_PREFIX."blog_tags` WHERE tag_id IN (".$tags.")");
					if ($query->num_rows) {
						foreach ($query->rows as $result) {
							$values[] = $result['alias'];
						}
					}
					unset($data[$key]);
					break;
				
				case 'day':
					$days = array_map('intval', explode("-", $value));
					if ( (0 < count($days)) && (count($days) < 4) ) {
						if ( isset($days[0]) && ( (1900 < $days[0]) && ($days[0] < 2999) ) ) {
							$values[] = 'day';
							$values[] = $days[0];
							if ( isset($days[1]) && ( (0 < $days[1]) && ($days[1] < 13) ) ) {
								$values[] = $days[1];
								if ( isset($days[2]) && ( (0 < $days[2]) && ($days[2] < 32) ) ) {
									$values[] = $days[2];
								}
							}
						}
					}
					unset($data[$key]);
					break;
					
				case 'page_number':
					$values[] = 'page';
					$values[] = (int) $value;
					unset($data[$key]);
					break;
					
				case 'sort_type':
					if (in_array($value, array('name', 'view', 'comment'))) {
						$values[] = 'sort';
						$values[] = $value;
					}
					unset($data[$key]);
					break;
// -------------------------------------------
					
				case 'path':
					$categories = explode('_', $value);
					$queries_category = array();
					foreach ($categories as $category) {
						$queries_category[] = 'category_id=' . $category;
						//$queries[] = 'category_id=' . $category;
					}
					unset($data[$key]);
					break;

				case 'new':
					if (!empty($value))	$queries[] = 'new='. (int) $value;
					unset($data[$key]);
					break;
					
				default:
					break;
			}
		}

		$queries = array_merge($queries_category, $queries);

		if(empty($queries)) {
			$queries[] = $route;
		}

		$rows = array();
		foreach($queries as $query) {
			if(isset($this->cache_data['queries'][$query])) {
				$rows[] = array('query' => $query, 'keyword' => $this->cache_data['queries'][$query]);
			}
		}
		
	// начинаем формировать SEO url
		$seo_url = '';
		if (isset($preorder))  $seo_url = 'order';
		if (isset($isallcategory)) $seo_url .= (empty($seo_url)) ? 'products' : '/products';
		if (isset($isproduct)) $seo_url .= (empty($seo_url)) ? 'product' : '/product';

		if(count($rows) == count($queries)) {
			$aliases = array();
			foreach($rows as $row) {
				$aliases[$row['query']] = $row['keyword'];
			}

			foreach($queries as $query) {
				$seo_url .= '/' . rawurlencode($aliases[$query]);
			}
		}

		if ($seo_url == '') return $link;

		$seo_url = trim($seo_url, '/');

		if ($component['scheme'] == 'https') {
			$seo_url = $this->config->get('config_ssl') . $seo_url;
		} else {
			$seo_url = $this->config->get('config_url') . $seo_url;
		}

		if (isset($postfix)) {
			if (!empty($quickview)) $seo_url .= '.quick';
			else $seo_url .= trim($this->config->get('config_seo_url_postfix'));
		} else {
			$seo_url .= '/';
		}

		if(substr($seo_url, -2) == '//') {
			$seo_url = substr($seo_url, 0, -1);
		}

		if (count($values)) {
			$seo_url .= '' . urldecode(implode("/", $values))."/";
		}
		
		if (count($data)) {
			$seo_url .= '?' . urldecode(http_build_query($data, '', '&amp;'));
		}

		return $seo_url;
	}

	private function getPathByProduct($product_id) {
		$product_id = (int)$product_id;
		if ($product_id < 1) return false;

		static $path = null;
		if (!is_array($path)) {
			$path = $this->cache->get('product.seopath');
			if (!is_array($path)) $path = array();
		}

		if (!isset($path[$product_id])) {
			$query = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . $product_id . "' ORDER BY main_category DESC LIMIT 1");

			$path[$product_id] = $this->getPathByCategory($query->num_rows ? (int)$query->row['category_id'] : 0);

			$this->cache->set('product.seopath', $path);
		}

		return $path[$product_id];
	}

	private function getPathByCategory($category_id) {
		$category_id = (int)$category_id;

		if ($category_id < 1) return false;

		static $path = null;
		if (!is_array($path)) {
			$path = $this->cache->get('category.seopath');
			if (!is_array($path)) $path = array();
		}

		if (!isset($path[$category_id])) {
			$max_level = 10;

			$sql = "SELECT CONCAT_WS('_'";
			for ($i = $max_level-1; $i >= 0; --$i) {
				$sql .= ",t$i.category_id";
			}
			$sql .= ") AS path FROM " . DB_PREFIX . "category t0";
			for ($i = 1; $i < $max_level; ++$i) {
				$sql .= " LEFT JOIN " . DB_PREFIX . "category t$i ON (t$i.category_id = t" . ($i-1) . ".parent_id)";
			}
			$sql .= " WHERE t0.category_id = '" . $category_id . "'";

			$query = $this->db->query($sql);

			$path[$category_id] = $query->num_rows ? $query->row['path'] : false;

			$this->cache->set('category.seopath', $path);
		}

		return $path[$category_id];
	}

	private function validate() {
		if (isset($this->request->get['route']) && $this->request->get['route'] == 'error/not_found') {
			return;
		}
		if(empty($this->request->get['route'])) {
			$this->request->get['route'] = 'common/home';
		}

		if (isset($this->request->server['HTTP_X_REQUESTED_WITH']) && strtolower($this->request->server['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
			return;
		}
		if (isset($this->request->server['HTTPS']) && (($this->request->server['HTTPS'] == 'on') || ($this->request->server['HTTPS'] == '1'))) {
			$config_ssl = substr($this->config->get('config_ssl'), 0, $this->strpos_offset('/', $this->config->get('config_ssl'), 3) + 1);
			$url = str_replace('&amp;', '&', $config_ssl . ltrim($this->request->server['REQUEST_URI'], '/'));
			$seo = str_replace('&amp;', '&', $this->url->link($this->request->get['route'], $this->getQueryString(array('route')), 'SSL'));
		} else {
			$config_url = substr($this->config->get('config_url'), 0, $this->strpos_offset('/', $this->config->get('config_url'), 3) + 1); // http://server/
			$url = str_replace('&amp;', '&', $config_url . ltrim($this->request->server['REQUEST_URI'], '/')); //http://server/index.php?route=xxxx/yyyy&aaa=bbb
			$seo = str_replace('&amp;', '&', $this->url->link($this->request->get['route'], $this->getQueryString(array('route')), 'NONSSL'));
		}
		
		if (rawurldecode($url) != rawurldecode($seo)) {
			header($this->request->server['SERVER_PROTOCOL'] . ' 301 Moved Permanently');

			$this->response->redirect($seo);
		}
	}

	private function strpos_offset($needle, $haystack, $occurrence) {
		// explode the haystack
		$arr = explode($needle, $haystack);
		// check the needle is not out of bounds
		switch($occurrence) {
			case $occurrence == 0:
				return false;
			case $occurrence > max(array_keys($arr)):
				return false;
			default:
				return strlen(implode($needle, array_slice($arr, 0, $occurrence)));
		}
	}

	private function getQueryString($exclude = array()) {
		if (!is_array($exclude)) {
			$exclude = array();
			}

		return urldecode(http_build_query(array_diff_key($this->request->get, array_flip($exclude))));
	}



























































	private function get_seo_keyword($query) {
		if (isset($this->cache_data['queries'][$query])) {
			return strtolower($this->cache_data['queries']["product_id=$product_id"]);
		} else {
			return false;
		}
	}


	private function url_productproduct_to_seo($component) {
		parse_str($component['query'], $data);  // разбиваем параметры на ключ=>значение

		$preorder    = isset($data['preorder'])   ? true : false;
		$quickview   = isset($data['quick'])      ? true : null;
		$product_id  = isset($data['product_id']) ? (int)$data['product_id'] : null;
		if (($product_seo = $this->get_seo_keyword("product_id=$product_id")) === FALSE) return false;
		
		unset($data['route']);
		unset($data['preorder']);
		unset($data['product_id']);
		unset($data['quick']);
		
	// начинаем формировать SEO url
		$seo_url  = ($component['scheme'] == 'https') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$seo_url .= ($preorder) ? 'order/' : '';
		$seo_url .= 			  'product/';
		$seo_url .= 			  $product_seo;
		$seo_url .= !empty($quickview)  ? '.quick'
										: trim($this->config->get('config_seo_url_postfix')); // .html
		$seo_url .= count($data) ? '?'.urldecode(http_build_query($data, '', '&amp;')) : '';

		return $seo_url;
	}
	
	private function url_productcategory_to_seo($component) {
		parse_str($component['query'], $data);  // разбиваем параметры на ключ=>значение

		$preorder = isset($data['preorder']) ? true          : false;
		$path     = isset($data['path'])     ? $data['path'] : '';
		$new      = isset($data['new'])      ? (int)$data['new']  : 0;
		
		unset($data['route']);
		unset($data['preorder']);
		unset($data['path']);
		
		$seo_url  = ($component['scheme'] == 'https') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$seo_url .= ($preorder) ? 'order/' : '';
		$seo_url .= ($path == 0) ? 'products/' : '';

		$category = explode('_', $path);
		foreach ($category as $category_id) {
			if (($category_seo = $this->get_seo_keyword("category_id=$category_id")) === FALSE) return false;				
			$seo_url .= $category_seo."/";
		}
	
	// обработка NEW
		if (($new_seo = $this->get_seo_keyword("new=$new")) !== FALSE) {
			$seo_url .= $new_seo."/";
			unset($data['new']);			
		}
			
	// значения по умолчанию
		if ($new == 0) unset($data['new']);

		$seo_url .= count($data) ? '?'.urldecode(http_build_query($data, '', '&amp;')) : '';
	
		return $seo_url;
	}	


	private function url_moduleblog_to_seo($component) {
		parse_str($component['query'], $data);  // разбиваем параметры на ключ=>значение

		$blog_id	 = isset($data['blog_id']) 	   ? $data['blog_id'] 	  : null;
		$tag_id  	 = isset($data['tag_id']) 	   ? $data['tag_id'] 	  : null;
		$day  		 = isset($data['day']) 		   ? $data['day']		  : null;
		$page_number = isset($data['page_number']) ? $data['page_number'] : null;
		$sort_type   = isset($data['sort_type'])   ? $data['sort_type']   : null;
		
	// начинаем формировать SEO url
		$seo_url  = ($component['scheme'] == 'https') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$seo_url .= 'blog/';
		
	// конкретный блог
		if (isset($blog_id)) {
			// узнаем есть ли алиас. или пишем просто число
		}
		
	// список блогов


	}

	public function rewrite2($link) {  // URL -> SEO
		if (!$this->config->get('config_seo_url')) return $link; // если не включен SEO URL то ничего не делаем с URL и выходим 

		$component = parse_url(str_replace('&amp;', '&', $link)); // разбиваем урл на компоненты scheme, host, path, query

		$data = array();
		parse_str($component['query'], $data);  // разбиваем параметры на ключ=>значение

		$route = $data['route'];
		unset($data['route']);


	// определение маршрута по ROUTE
		switch ($route) {
			case 'product/product':
				return (($seo = $this->url_productproduct_to_seo($component)) !== FALSE) ? $seo : $link ;
				
			
			case 'product/category':
				return (($seo = $this->url_productcategory_to_seo($component)) !== FALSE) ? $seo : $link ;

			case 'module/blog':
				$this->url_moduleblog_to_seo($component);
				break;
			
			case 'product/product/review':
			case 'information/information/info':
				return $link;
		}

		if ($component['scheme'] == 'https') {
			$link = $this->config->get('config_ssl');
		} else {
			$link = $this->config->get('config_url');
		}

		$link .= 'index.php?route=' . $route;

		if (count($data)) {
			$link .= '&amp;' . urldecode(http_build_query($data, '', '&amp;'));
		}

		$queries = array();
		$values = array();
		$queries_category = array();

		foreach ($data as $key => $value) {  // перечисляем параметры запроса query (ключ => значение)
			switch ($key) {
				case 'product_id':
				case 'manufacturer_id':
				case 'category_id':
				case 'information_id':
					$queries[] = $key . '=' . $value;
					unset($data[$key]);
					$postfix = 1;
					break;
				
				case 'styleid':  // доработать //
					$queries[] = $route;
					$queries[] = $key . '=' . $value;				
					unset($data[$key]);				
					break;
// ------------------ БЛОГ -------------------					
				case 'blog_id':  // блог //
					$query = $this->db->query("SELECT `alias` FROM `".DB_PREFIX."blog_alias` WHERE `blog_id` = '".(int)$value."' LIMIT 1");
					if ($query->num_rows) {
						$values[] = $query->rows[0]['alias'];
					} else {
						$values[] = $value;						
					}
					unset($data[$key]);
					break;

				case 'tag_id':  // блог //
					$values[] = 'tag';	
					$tags = implode(",", array_map('intval', explode(",", $value)));
					$query = $this->db->query("SELECT alias FROM `".DB_PREFIX."blog_tags` WHERE tag_id IN (".$tags.")");
					if ($query->num_rows) {
						foreach ($query->rows as $result) {
							$values[] = $result['alias'];
						}
					}
					unset($data[$key]);
					break;
				
				case 'day':
					$days = array_map('intval', explode("-", $value));
					if ( (0 < count($days)) && (count($days) < 4) ) {
						if ( isset($days[0]) && ( (1900 < $days[0]) && ($days[0] < 2999) ) ) {
							$values[] = 'day';
							$values[] = $days[0];
							if ( isset($days[1]) && ( (0 < $days[1]) && ($days[1] < 13) ) ) {
								$values[] = $days[1];
								if ( isset($days[2]) && ( (0 < $days[2]) && ($days[2] < 32) ) ) {
									$values[] = $days[2];
								}
							}
						}
					}
					unset($data[$key]);
					break;
					
				case 'page_number':
					$values[] = 'page';
					$values[] = (int) $value;
					unset($data[$key]);
					break;
					
				case 'sort_type':
					if (in_array($value, array('name', 'view', 'comment'))) {
						$values[] = 'sort';
						$values[] = $value;
					}
					unset($data[$key]);
					break;
// -------------------------------------------
					
				case 'path':
					$categories = explode('_', $value);
					$queries_category = array();
					foreach ($categories as $category) {
						$queries_category[] = 'category_id=' . $category;
						//$queries[] = 'category_id=' . $category;
					}
					unset($data[$key]);
					break;

				case 'new':
					if (!empty($value))	$queries[] = 'new='. (int) $value;
					unset($data[$key]);
					break;
					
				default:
					break;
			}
		}

		$queries = array_merge($queries_category, $queries);

		if(empty($queries)) {
			$queries[] = $route;
		}

		$rows = array();
		foreach($queries as $query) {
			if(isset($this->cache_data['queries'][$query])) {
				$rows[] = array('query' => $query, 'keyword' => $this->cache_data['queries'][$query]);
			}
		}
		
	// начинаем формировать SEO url
		$seo_url = '';
		if (isset($preorder))  $seo_url = 'order';
		if (isset($isallcategory)) $seo_url .= (empty($seo_url)) ? 'products' : '/products';
		if (isset($isproduct)) $seo_url .= (empty($seo_url)) ? 'product' : '/product';

		if(count($rows) == count($queries)) {
			$aliases = array();
			foreach($rows as $row) {
				$aliases[$row['query']] = $row['keyword'];
			}

			foreach($queries as $query) {
				$seo_url .= '/' . rawurlencode($aliases[$query]);
			}
		}

		if ($seo_url == '') return $link;

		$seo_url = trim($seo_url, '/');

		if ($component['scheme'] == 'https') {
			$seo_url = $this->config->get('config_ssl') . $seo_url;
		} else {
			$seo_url = $this->config->get('config_url') . $seo_url;
		}

		if (isset($postfix)) {
			if (!empty($quickview)) $seo_url .= '.quick';
			else $seo_url .= trim($this->config->get('config_seo_url_postfix'));
		} else {
			$seo_url .= '/';
		}

		if(substr($seo_url, -2) == '//') {
			$seo_url = substr($seo_url, 0, -1);
		}

		if (count($values)) {
			$seo_url .= '' . urldecode(implode("/", $values))."/";
		}
		
		if (count($data)) {
			$seo_url .= '?' . urldecode(http_build_query($data, '', '&amp;'));
		}

		return $seo_url;
	}



	
 }	
?>