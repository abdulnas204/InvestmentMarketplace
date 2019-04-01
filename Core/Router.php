<?php

namespace Core;

use Helpers\{
	Helper, Validator
};
use Traits\Instance;

class Router {
    use Instance;
	private $defaultParams = 'Investment/show',
	        $errorParams   = 'Errors/show',
	        $controller,
	        $action,
	        $params,
	        $additional = [];

	private static $_instance = null;

	final public function startRoute() {
		if (!$this->action) {
			$this->setUri($this->defaultParams)->route();
		}
		else if (!$this->route()) {
			$this->setUri($this->errorParams)->route();
		}
	}

//	final public function setAdditional(array $additional = []) : self {
//		$this->additional = $additional;
//		return $this;
//	}

	final private function getRequestUri() : string {
		return substr($_SERVER['REQUEST_URI'], strlen(DIR));
	}

	final public function setUri(string $uri = null) : self {
		$uri 				= $uri ?: $this->getRequestUri();
		$uri				= Validator::replace(Validator::URI, $uri);
		$uri                = explode('/', strtolower(trim($uri,'/')));
		$this->controller   = count($uri) ? ucfirst(array_shift($uri)) : '';
		$this->action       = count($uri) ? array_shift($uri) : null;
		$this->params       = count($uri) ? $uri : [];
		return $this;
	}

	final private function route() : bool {
		$controllerClass = 'Controllers\\'.$this->controller;

		if(!file_exists(real_path($controllerClass).'.php')) { return false; }
		$controller = new $controllerClass();

		if (!is_callable([$controller, $this->action])) { return false; }

		$this->params = array_filter(
							array_map(function(array $a){
								return $a[1]??false ? [$a[0] => $a[1]] : null;
							}, array_chunk($this->params, 2))
						, function($v) { return $v != null; });
		$this->params = $this->params ? array_unique(call_user_func_array('array_merge', $this->params)) : [];
//		if ($this->additional) $this->params = array_merge($this->params, $this->additional);
        call_user_func_array([$controller, $this->action], [$this->params]);
		return Helper::result();
	}

    private function isCallable($controller, string $action) : bool {
        $methods = (new \ReflectionObject($controller))->getMethods(\ReflectionMethod::IS_PUBLIC);
        if ($method = (array_column($methods, 'name', 'name')[$action] ?? null)) {
            return mb_strpos($method, '_') !== 0;
        }
        return false;
    }

    public function getCurrentPageUrl() : string {
	    return $this->controller . '/' . $this->action;
    }
}
