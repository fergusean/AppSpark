<?php
/*
	AppSpark 20130209
	A lightweight PHP framework to simplify the development of web applications
	Copyright (c) 2013 Signal24, Inc.
*/

$__ASSingleton = null;

function AppSpark_GetInstance() {
	global $__ASSingleton;
	return $__ASSingleton;
}

class AppSpark {
	private $_globalObjects, $_config;
	
	function __construct() {
		global $__ASSingleton;
		$__ASSingleton = $this;
		define('APPSPARK_CLI', php_sapi_name() == 'cli');
		define('APPSPARK_APPDIR', realpath(dirname(__FILE__) . '/../'));
		$this->_ASLoadConfig();
		$this->_ASAutoLoad();
		$this->_ASRoute();
		$this->_ASComplete();
	}
	
	private function _ASLoadConfig() {
		if (!file_exists(APPSPARK_APPDIR . '/config/config.php'))
			bail('Application configuration file was not found.');
		
		$config = array();
		include(APPSPARK_APPDIR . '/config/config.php');
		$this->_config = $config;
		unset($config);
	}
	
	private function _ASAutoLoad() {
		$loader = new AppSparkLoader();
		$config = new AppSparkConfig();
		
		$this->_globalObjects = array();
		$this->_ASAddGlobalObject('load', $loader);
		$this->_ASAddGlobalObject('config', $config);
		
		if (!file_exists(APPSPARK_APPDIR . '/config/autoload.php'))
			return;
		
		$autoload = array();
		@include(APPSPARK_APPDIR . '/config/autoload.php');
		
		if (isset($autoload['libraries']))
			if (is_array($autoload['libraries']))
				foreach ($autoload['libraries'] as $library)
					$loader->library($library);
		
		if (isset($autoload['models']))
			if (is_array($autoload['models']))
				foreach ($autoload['models'] as $model)
					$loader->model($model);
		
		if (isset($autoload['helpers']))
			if (is_array($autoload['helpers']))
				foreach ($autoload['helpers'] as $helper)
					$loader->helper($helper);
	}
	
	private function _ASRoute() {
		if (APPSPARK_CLI && !$_SERVER['REQUEST_URI']) {
			global $argv;
			$requestURI = $argv[1];
		} else {
			$base = dirname($_SERVER['SCRIPT_NAME']);
			$requestURI = $_SERVER['REQUEST_URI'];
			if ($base == substr($requestURI, 0, strlen($base)))
				$requestURI = substr($requestURI, strlen($base));
		}
		$requestURI = preg_replace('/^\/|\/?\?.*$|\/$/', '', $requestURI);
		$route = false;
		
		$map = array();
		@include(APPSPARK_APPDIR . '/config/routes.php');
		foreach ($map as $mapExp => $override) {
			if ($mapExp == '')
				continue;
			$matches = array();
			if (@preg_match('/^' . str_replace('/', '\\/', $mapExp) . '$/i', $requestURI, $matches)) {
				for ($index = 1, $count = count($matches); $index < $count; $index++)
					$override = str_replace('$' . $index, $matches[$index], $override);
				$route = $override;
				break;
			}
		}
		if (!$route)
			$route = $requestURI;
		if (!$route)
			$route = $map[''];
		if (!$route)
			bail('No route was specified and no default route was configured.', 404, 'No Route');
		unset($map);
		
		$controllerDir = APPSPARK_APPDIR . '/controllers/';
		$route = explode('/', trim($route, '/'));
		while (count($route)) {
			$segment = array_shift($route);
			if (substr($segment, 0, 1) == '_')
				bail('The requested resource is restricted to internal use.', 403, 'Restricted');
			if (strlen($route[0]))
				if (file_exists($nextControllerDir = $controllerDir . $segment . '/'))
					if (file_exists($nextControllerDir . $route[0] . '/') || file_exists($nextControllerDir . $route[0] . '.php')) {
						$controllerDir = $nextControllerDir;
						continue;
					}
			if (file_exists($nextControllerDir = $controllerDir . $segment . '.php')) {
				$controllerDir = $nextControllerDir;
				break;
			}
			bail('The requested resource could not be located.', 404, 'Not Found');
		}
		
		include_once($controllerDir);
		$controllerName = substr($controllerName = basename($controllerDir), 0, strlen($controllerName) - 4);
		if (!class_exists($controllerName))
			bail('The requested resource could not be located [CE].', 404, 'Not Found');
		global $__ASLoaderAssignGlobalKey;
		$__ASLoaderAssignGlobalKey = 'controller';
		$controller = new $controllerName();
		if (!is_subclass_of($controller, 'AppSparkController'))
			bail('The requested resource does not properly extend the root controller.', 500, 'Improper Controller');
		if (!count($route))
			$route[] = 'index';
		if (substr($route[0], 0, 1) == '_')
			bail('The requested function is restricted to internal use.', 403, 'Restricted');
		if (!method_exists($controller, $methodName = array_shift($route)))
			if (method_exists($controller, '_default')) {
				array_unshift($route, $methodName);
				$methodName = '_default';
			} else
				bail('The requested resource could not be located.', 404, 'Not Found');
		if (count($route))
			call_user_func_array(array($controller, $methodName), $route);
		else
			call_user_func(array($controller, $methodName));
	}
	
	private function _ASComplete() {
		foreach ($this->_globalObjects as $obj)
			if (method_exists($obj, '__complete'))
				call_user_func(array($obj, '__complete'));
	}
	
	public function _ASGetConfig($key) {
		if (isset($this->_config[$key]))
			return $this->_config[$key];
		else
			return false; 
	}
	
	public function _ASImportGlobalObjects($target) {
		foreach ($this->_globalObjects as $key => $obj)
			$target->$key = $obj;
	}
	
	public function _ASAddGlobalObject($key, $obj) {
		foreach ($this->_globalObjects as $globalObject)
			$globalObject->$key = $obj;
		$this->_globalObjects[$key] = $obj;
		$this->$key = $obj;
	}
	
	public function _ASGetGlobalObject($key) {
		if (isset($this->_globalObjects[$key]))
			return $this->_globalObjects[$key];
		else
			return false;
	}
}

class AppSparkObject {
	function __construct() {
		global $__ASLoaderAssignGlobalKey;
		$AS = AppSpark_GetInstance();
		$AS->_ASImportGlobalObjects($this);
		$__ASLoaderAssignGlobalKey && $AS->_ASAddGlobalObject($__ASLoaderAssignGlobalKey, $this);
		$__ASLoaderAssignGlobalKey = null;
	}
}

class AppSparkController extends AppSparkObject {}
class AppSparkModel extends AppSparkObject {}

class AppSparkLoader {
	private $_AS;
	
	function __construct() {
		$this->_AS = AppSpark_GetInstance();
		$this->load = $this;
	}
	
	function library($libraryPath) {
		$libraryName = array_pop(explode('/', $libraryPath));
		if ($this->_AS->_ASGetGlobalObject($libraryName))
			return true;
		if (file_exists($libraryFile = APPSPARK_APPDIR . '/libraries/' . $libraryPath . '.php')) {
			include_once($libraryFile);
			if (!class_exists($libraryName))
				return false;
			$library = new $libraryName();
			$this->_AS->_ASAddGlobalObject($libraryName, $library);
			return true;
		} elseif (class_exists($className = 'AppSpark' . $libraryName)) {
			$library = new $className();
			$this->_AS->_ASAddGlobalObject($libraryName, $library);
			return true;
		}
		return false;
	}
	
	function model($modelPath) {
		global $__ASLoaderAssignGlobalKey;
		$modelName = array_pop(explode('/', $modelPath));
		if ($this->_AS->_ASGetGlobalObject($modelName))
			return true;
		if (file_exists($modelFile = APPSPARK_APPDIR . '/models/' . $modelPath . '.php')) {
			include_once($modelFile);
			if (!class_exists($modelName))
				return false;
			$__ASLoaderAssignGlobalKey = $modelName;
			$model = new $modelName();
			return true;
		}
		return false;
	}
	
	function helper($helperName) {
		if (file_exists($helperFile = APPSPARK_APPDIR . '/helpers/' . $helperName . '.php')) {
			include_once($helperFile);
			return true;
		}
		return false;
	}
	
	function view($viewName, $export = array()) {
		if (!file_exists($viewFile = APPSPARK_APPDIR . '/views/' . $viewName) || @is_dir($viewFile))
			if (!file_exists($viewFile .= '.php'))
				return false;
		extract($export);
		include($viewFile);
	}
}

class AppSparkConfig {
	private $_AS;
	
	function __construct() {
		$this->_AS = AppSpark_GetInstance();
	}
	
	function get($key) {
		return $this->_AS->_ASGetConfig($key);
	}
}

class AppSparkDatabase {
	function __construct($autoload = true) {
		if (!$autoload)
			return;
		if (!($db = $this->load()))
			return false;
		AppSpark_GetInstance()->_ASAddGlobalObject('db', new AppSparkDatabaseAR($db));
	}
	
	function load($param = false) {
		if (!is_array($param)) {
			@include(APPSPARK_APPDIR . '/config/database.php');
			if ($param)
				$param = $db[$param];
			elseif (is_array($db[$default_connection]))
				$param = $db[$default_connection];
		}
		if (!is_array($param))
			return 1;
		if (!($db = @mysql_connect($param['hostname'], $param['username'], $param['password'], true)))
			return 2;
		if (!@mysql_select_db($param['database'], $db))
			return 3;
		if (!@mysql_set_charset($param['charset'], $db))
			return 4;
		return $db;
	}
}

class AppSparkDatabaseAR {
	protected $_db, $_select, $_from, $_join, $_set, $_where, $_isOrWhere, $_groupBy, $_sort, $_limit, $_result, $_lastQuery;
	
	function __construct($db) {
		$this->_db = $db;
		$this->reset();
	}

	public function getConn() {
		return $this->_db;
	}
	
	public function reset() {
		if (isset($this->_sticky))
			return;
		$this->_distinct = false;
		$this->_select = '*';
		$this->_from = null;
		$this->_join = array();
		$this->_set = array();
		$this->_where = array();
		$this->_isOrWhere = false;
		$this->_groupBy = array();
		$this->_sort = array();
		$this->_limit = null;
	}
	
	public function distinct() {
		$this->_distinct = true;
		return $this;
	}
	
	public function select($select) {
		$this->_select = ($this->_select != '*' ? $this->_select . ', ' : '') . $select;
		return $this;
	}
	
	public function from($from) {
		$this->_from = $from;
		return $this;
	}
	
	public function join($table, $on, $type = false) {
		$types = array('left');
		$this->_join[] = array($table, $on, in_array(strtolower($type), $types) ? $type : false);
		return $this;
	}
	
	public function where($one, $two = false) {
		if (is_array($one) || $two !== false) {
			if (!is_array($one))
				$one = array($one => $two);
			foreach ($one as $key => $val) {
				$keyParts = explode(' ', $key, 2);
				if ($keyParts[1])
					if (in_array(strtoupper($keyParts[1]), array('=', '!=', '>=', '<=', '<', '>', '&', 'LIKE', 'NOT LIKE')))
						$operation = $keyParts[1];
				if ($operation)
					$key = $keyParts[0];
				else
					$operation = '=';
				$this->_where[] = "`" . implode('`.`', explode('.', $key)) . "` " . $operation . ' ' . $this->escape($val);
			}
		} else {
			$this->_where[] = $one;
		}
		return $this;
	}
	
	public function or_where($one, $two = null) {
		$this->_isOrWhere = true;
		return $this->where($one, $two);
	}
	
	public function where_in($column, $vals) {
		return $this->where($column . ' in (' . implode(',', $vals) . ')');
	}
	
	public function where_not_in($column, $vals) {
		return $this->where($column . ' not in (' . implode(',', $vals) . ')');
	}
	
	public function group_by($by) {
		if (is_array($by))
			$this->_groupBy = array_merge($this->_groupBy, $by);
		else
			$this->_groupBy[] = $by;
		return $this;
	} 
	
	public function order_by($one, $two = false) {
		if ($two)
			$this->_sort[] = "`" . implode('`.`', explode('.', $one)) . "` " . $two;
		else
			$this->_sort[] = $one;
		return $this;
	}
	
	public function limit($limit, $start = 0) {
		$this->_limit = $start . ', ' . $limit;
		return $this;
	}
	
	public function get($from = false) {
		if ($from)
			$this->from($from);
		$query = "SELECT " . $this->_select . " FROM " . $this->_from;
		if (count($this->_join))
			foreach ($this->_join as $join)
				$query .= " " . trim(strtoupper($join[2]) . " JOIN " . $join[0] . " ON (" . $join[1] . ")");
		if (count($this->_where))
			$query .= " WHERE (" . implode($this->_isOrWhere ? " OR " : " AND ", $this->_where) . ")";
		if ($this->_groupBy)
			$query .= " GROUP BY " . implode(', ', $this->_groupBy);
		if ($this->_sort)
			$query .= " ORDER BY " . implode(', ', $this->_sort);
		if ($this->_limit)
			$query .= " LIMIT " . $this->_limit;
		$this->query($query);
		return $this->_result ? $this : $this->_result;
	}
	
	public function get_where($from, $where) {
		$this->where($where);
		return $this->get($from);
	}
	
	public function count_all_results($from = false) {
		if ($from)
			$this->from($from);
		$query = "SELECT COUNT(*) FROM " . $this->_from;
		if (count($this->_join))
			foreach ($this->_join as $join)
				$query .= " " . trim(strtoupper($join[2]) . " JOIN " . $join[0] . " ON (" . $join[1] . ")");
		if (count($this->_where))
			$query .= " WHERE (" . implode($this->_isOrWhere ? " OR " : " AND ", $this->_where) . ")";
		if ($this->_limit)
			$query .= " LIMIT " . $this->_limit;
		$result = $this->query($query);
		if (!$this->_result)
			return false;
		$count = mysql_result($this->_result, 0, 0);
		return $count;
	}
	
	public function num_rows() {
		return mysql_num_rows($this->_result);
	}
	
	public function row($row = false) {
		$row !== false && mysql_data_seek($this->_result, $row);
		return ife(mysql_fetch_object($this->_result), new stdClass());
	}
	
	public function row_array($row = false) {
		$row !== false && mysql_data_seek($this->_result, $row);
		return ife(mysql_fetch_assoc($this->_result), array());
	}
	
	public function result() {
		$result = array();
		while ($row = mysql_fetch_object($this->_result))
			$result[] = $row;
		return $result;
	}
	
	public function result_array() {
		$result = array();
		while ($row = mysql_fetch_assoc($this->_result))
			$result[] = $row;
		return $result;
	}
	
	public function result_resource() {
		return $this->_result;
	}
	
	public function set($one, $two = false) {
		if (is_array($one))
			foreach ($one as $key => $val)
				$this->_set[] = "`" . implode('`.`', explode('.', $key)) . "`=" . (is_null($val) ? 'NULL' : $this->escape($val));
		elseif ($two !== false)
			$this->_set[] = "`" . implode('`.`', explode('.', $one)) . "`=" . (is_null($two) ? 'NULL' : $this->escape($two));
		else
			$this->_set[] = $one;
		return $this;
	}
	
	public function insert($table, $set = false, $replace = false) {
		$set && $this->set($set);
		$query = ($replace ? "REPLACE" : "INSERT") . " INTO " . $table;
		if (count($this->_set))
			$query .= " SET " . implode(",", $this->_set);
		$this->query($query);
		return $this->_result;
	}
	
	public function replace($table, $set = false) {
		return $this->insert($table, $set, true);
	}
	
	public function update($table, $set = false, $where = false) {
		$set && $this->set($set);
		$where && $this->where($where);
		$query = "UPDATE " . $table;
		if (count($this->_set))
			$query .= " SET " . implode(",", $this->_set);
		if (count($this->_where))
			$query .= " WHERE (" . implode($this->_isOrWhere ? " OR " : " AND ", $this->_where) . ")";
		$this->query($query);
		return $this->_result;
	}
	
	public function delete($from = false, $where = false) {
		if ($from)
			$this->from($from);
		if ($where)
			$this->where($where);
		$query = "DELETE FROM " . $this->_from;
		if (count($this->_where))
			$query .= " WHERE (" . implode($this->_isOrWhere ? " OR " : " AND ", $this->_where) . ")";
		$this->query($query);
		return $this->_result;
	}
	
	public function query($query) {
		$this->_result = mysql_query($query, $this->_db);
		$this->_lastQuery = $query;
		$this->reset();
		return $this;
	}
		
	public function insert_id() {
		return mysql_insert_id($this->_db);
	}
		
	public function escape($data) {
		if (is_null($data))
			return 'NULL';
		else
			return "'" . mysql_real_escape_string($data, $this->_db) . "'";
	}
	
	public function close() {
		return mysql_close($this->_db);
	}
	
	public function last_query() {
		return $this->_lastQuery;
	}
	
	public function affected_rows() {
		return mysql_affected_rows($this->_db);
	}
	
	public function sticky() {
		$this->_sticky = true;
	}
	
	public function unsticky() {
		unset($this->_sticky);
	}
}

class AppSparkSession {
	function __construct($init = true) {
		$init && $this->init();
	}
	
	function init($cookieName = false, $force = false) {
		$AS = AppSpark_GetInstance();
		if (!$cookieName)
			$cookieName = $AS->config->get('session_cookie_name');
		if (!$cookieName)
			$cookieName = 'ASSID';
		if (!$_COOKIE['ES'] && !$_COOKIE[$cookieName] && !$force)
			return;
		$cookieDomain = $AS->config->get('session_cookie_domain');
		if (!$cookieDomain)
			$cookieDomain = $_SERVER['HTTP_HOST'];
		session_set_cookie_params(0, '/', $cookieDomain);
		session_name($cookieName);
		session_start();
	}
}

class AppSparkEmail {
	protected $_from, $_from_addr, $_reply_to, $_to, $_to_addr, $_subject, $_message, $_isHTML, $_attachments, $_headers;
	
	function reset() {
		$this->_from = null;
		$this->_reply_to = null;
		$this->_to = null;
		$this->_subject = null;
		$this->_message = null;
		$this->_isHTML = false;
		$this->_attachments = null;
		$this->_headers = null;
	}
	
	function from($from, $name = false) {
		$this->_from = $name ? $name . ' <' . $from . '>' : '<' . $from . '>';
		$this->_from_addr = $from;
	}
	
	function reply_to($reply_to, $name = false) {
		$reply_to = '<' . $reply_to . '>';
		$this->_reply_to = $name ? $name . ' ' . $reply_to : $reply_to;
	}
	
	function to($to, $name = false) {
		$this->_to = $name ? $name . ' <' . $to . '>' : '<' . $to . '>';
		$this->_to_addr = $to;
	}
	
	function subject($subject) {
		$this->_subject = $subject;
	}
	
	function message($message) {
		$this->_message = $message;
	}
	
	function setHTML() {
		$this->_isHTML = true;
	}
	
	function setText() {
		$this->_isHTML = false;
	}
	
	function attach($file) {
		if (is_array($this->_attachments))
			$this->_attachments[] = $file;
		else
			$this->_attachments = array($file);
	}
	
	function header($key, $value) {
		if (!is_array($this->_headers))
			$this->_headers = array();
		$this->_headers[$key] = $value;
	}
	
	function send() {		
		$headers = array(
			'X-Sender: ' . $this->_from_addr,
			'From: ' . $this->_from,
			'To: ' . $this->_to
		);
		if (is_array($this->_headers))
			foreach ($this->_headers as $key => $value)
				$headers[] = $key . ': ' . $value;
		if ($this->_isHTML || $this->_attachments) {
			$hash = md5(uniqid());
			$headers[] = 'Content-Type: multipart/alternative; boundary="' . $hash . '"';
			$headers[] = 'Mime-Version: 1.0';
			$message = "This is a multi-part message in MIME format.\r\n\r\n";
			if ($this->_isHTML) {
				$html = $this->_message;
				$html = preg_replace('| +|', ' ', $html);
				$html = preg_replace('/\x00+/', '', $html);
				$html = str_replace(array("\r\n", "\r"), "\n", $html);
				$lines = explode("\n", $html);
				$result = '';
				foreach ($lines as $line) {
					$temp = '';
					for ($charIndex = 0, $length = strlen($line); $charIndex < $length; $charIndex++) {
						$char = substr($line, $charIndex, 1);
						$charCode = ord($char);
						if ($charCode == $length - 1)
							if ($charCode == 32 || $charCode == 9)
								$char = '=' . sprintf('%02s', dechex($charCode));
						if ($charCode == 61)
							$char = '=' . strtoupper(sprintf('%02s', dechex($charCode)));
						if (strlen($temp) + strlen($char) >= 76) {
							$result .= $temp . '=' . "\r\n";
							$temp = '';
						}
						$temp .= $char;
					}
					$result .= $temp . "\r\n";
				}
				$result = substr($result, 0, -2);
				$message .= '--' . $hash . "\r\n" .
				            'Content-Type: text/html; charset="iso-8859-1"' . "\r\n" .
				            'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n" .
				            $result . "\r\n\r\n";
			} else {
				$message = '--' . $hash . "\r\n" .
				           'Content-Type: text/plain; charset="iso-8859-1"' . "\r\n" .
				           'Content-Transfer-Encoding: 7bit' . "\r\n\r\n" .
				           word_wrap($this->_message, 76, "\r\n") . "\r\n\r\n";
			}
			if ($this->_attachments)
				foreach ($this->_attachments as $file)
					$message .= '--' . $hash . "\r\n" .
					            'Content-Type: application/octet-stream; name="' . basename($file) . '"' . "\r\n" .
					            'Content-Transfer-Encoding: base64' . "\r\n" .
					            'Content-Disposition: attachment' . "\r\n\r\n" .
					            chunk_split(base64_encode(file_get_contents($attachment))) . "\r\n\r\n";
			$messae .= '--' . $hash . '--';
		} else {
			$message = $this->_message;
		}
		$result = @mail($this->_to_addr, $this->_subject, $message, implode("\r\n", $headers), '-f ' . $this->_from_addr);
		$this->reset();
		return $result;
	}
}

function bail($msg, $code = 500, $httpMsg = 'Internal Server Error') {
	header('HTTP/1.1 ' . $code . ' ' . $httpMsg);
	echo $msg;
	exit;
}

function json($data) {
	echo str_replace(':"false"', ':false', json_encode($data));
}

function json_success($data = false) {
	!$data && ($data = array());
	$data['success'] = true;
	json($data);
}

function json_error($error, $data = false) {
	!$data && ($data = array());
	$data['success'] = false;
	$data['error'] = $error;
	json($data);
	exit;
}

function ife($true, $false) {
	return $true ? $true : $false;
}

function redirect($target) {
	header('Location: ' . $target);
	exit;
}

new AppSpark();

/* End of file */