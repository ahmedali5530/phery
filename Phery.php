<?php
/**
 * The MIT License (MIT)
 *
 * Copyright © 2010-2012 Paulo Cesar, http://phery-php-ajax.net/
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the “Software”), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge,
 * publish, distribute, sublicense, and/or sell copies of the Software,
 * and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 * @link       http://phery-php-ajax.net/
 * @author     Paulo Cesar
 * @version    2.1.0
 * @license    http://opensource.org/licenses/MIT MIT License
 */

/**
 * Main class for Phery
 *
 * @package    Phery
 */
class Phery implements ArrayAccess {

	/**
	 * Exception on callback() function
	 * @see Phery::callback()
	 */
	const ERROR_CALLBACK = 0;
	/**
	 * Exception on process() function
	 * @see Phery::process()
	 */
	const ERROR_PROCESS = 1;
	/**
	 * Exception on set() function
	 * @see Phery::set()
	 */
	const ERROR_SET = 2;
	/**
	 * Exception when the CSRF is invalid
	 * @see Phery::process()
	 */
	const ERROR_CSRF = 4;
	/**
	 * Exception on static functions
	 * @see Phery::link_to()
	 * @see Phery::select_for()
	 * @see Phery::form_for()
	 */
	const ERROR_TO = 3;

	/**
	 * The functions registered
	 * @var array
	 */
	protected $functions = array();
	/**
	 * The callbacks registered
	 * @var array
	 */
	protected $callbacks = array();
	/**
	 * The callback data to be passed to callbacks and responses
	 * @var array
	 */
	protected $data = array();
	/**
	 * Static instance for singleton
	 * @var Phery
	 * @static
	 */
	protected static $instance = null;
	/**
	 * Will call the functions defined in this variable even
	 * if it wasn't sent by AJAX, use it wisely. (good for SEO though?)
	 * @var array
	 */
	protected $respond_to_post = array();
	/**
	 * Hold the answers for answer_for function
	 * @see Phery::answer_for()
	 * @var array
	 */
	protected $answers = array();
	/**
	 * Render view function
	 * @var array
	 */
	protected $views = array();
	/**
	 * Config
	 * <pre>
	 * 'exit_allowed' (boolean)
	 * 'no_stripslashes' (boolean)
	 * 'exceptions' (boolean)
	 * 'respond_to_post' (array)
	 * 'compress' (boolean)
	 * 'csrf' (boolean)
	 * </pre>
	 * @var array
	 * @see Phery::config()
	 */
	protected $config = array();

	/**
	 * Construct the new Phery instance
	 * @param array $config Config array
	 */
	public function __construct(array $config = array())
	{
		$this->callbacks = array(
			'before' => array(),
			'after' => array()
		);

		$config = array_merge_recursive(
			array(
				'exit_allowed' => true,
				'no_stripslashes' => false,
				'exceptions' => false,
				'respond_to_post' => array(),
				'compress' => false,
				'csrf' => false,
				'error_reporting' => false
			), $config
		);

		$this->config($config);
	}

	/**
	 * Set callbacks for before and after filters.
	 * Callbacks are useful for example, if you have 2 or more AJAX functions, and you need to perform
	 * the same data manipulation, like removing an 'id' from the $_POST['args'], or to check for potential
	 * CSRF or SQL injection attempts on all the functions, clean data or perform START TRANSACTION for database, etc
	 *
	 * @param array $callbacks
	 * <pre>
	 * array(
	 *
	 *     // Set a function to be called BEFORE
	 *     // processing the request, if it's an
	 *     // AJAX to be processed request, can be
	 *     // an array of callbacks
	 *
	 *     'before' => array|function,
	 *
	 *     // Set a function to be called AFTER
	 *     // processing the request, if it's an AJAX
	 *     // processed request, can be an array of
	 *     // callbacks
	 *
	 *     'after' => array|function
	 * );
	 * </pre>
	 * The callback function should be
	 * <pre>
	 *
	 * // $additional_args is passed using the callback_data() function, in this case, a before callback
	 *
	 * function before_callback($ajax_data, $internal_data){
	 *   // Do stuff
	 *   $_POST['args']['id'] = $additional_args['id'];
	 *   return true;
	 * }
	 *
	 * // after callback would be to save the data perhaps? Just to keep the code D.R.Y.
	 *
	 * function after_callback($ajax_data, $internal_data, $PheryResponse){
	 *   $this->database->save();
	 *   $PheryResponse->merge(PheryResponse::factory('#loading')->fadeOut());
	 *   return true;
	 * }
	 * </pre>
	 * Returning false on the callback will make the process() phase to RETURN, but won't exit.
	 * You may manually exit on the after callback if desired
	 * Any data that should be modified will be inside $_POST['args'] (can be accessed freely on 'before',
	 * will be passed to the AJAX function)
	 *
	 * @return Phery
	 */
	public function callback(array $callbacks)
	{
		if (isset($callbacks['before']))
		{
			if (is_array($callbacks['before']) && !is_callable($callbacks['before']))
			{
				foreach ($callbacks['before'] as $func)
				{
					if (is_callable($func))
					{
						$this->callbacks['before'][] = $func;
					}
					else
					{
						self::exception($this, "The provided before callback function isn't callable", self::ERROR_CALLBACK);
					}
				}
			}
			else
			{
				if (is_callable($callbacks['before']))
				{
					$this->callbacks['before'][] = $callbacks['before'];
				}
				else
				{
					self::exception($this, "The provided before callback function isn't callable", self::ERROR_CALLBACK);
				}
			}
		}

		if (isset($callbacks['after']))
		{
			if (is_array($callbacks['after']) && !is_callable($callbacks['after']))
			{
				foreach ($callbacks['after'] as $func)
				{
					if (is_callable($func))
					{
						$this->callbacks['after'][] = $func;
					}
					else
					{
						self::exception($this, "The provided after callback function isn't callable", self::ERROR_CALLBACK);
					}
				}
			}
			else
			{
				if (is_callable($callbacks['after']))
				{
					$this->callbacks['after'][] = $callbacks['after'];
				}
				else
				{
					self::exception($this, "The provided after callback function isn't callable", self::ERROR_CALLBACK);
				}
			}
		}

		return $this;
	}

	/**
	 * Throw an exception if enabled
	 *
	 * @param Phery   $phery Instance
	 * @param string  $exception
	 * @param integer $code
	 *
	 * @throws PheryException
	 * @return boolean
	 */
	protected static function exception($phery, $exception, $code)
	{
		if ($phery instanceof Phery && $phery->config['exceptions'] === true)
		{
			throw new PheryException($exception, $code);
		}

		return false;
	}



	/**
	 * Set any data to pass to the callbacks
	 *
	 * @param mixed ... Parameters, can be anything
	 *
	 * @return Phery
	 */
	public function data()
	{
		foreach (func_get_args() as $arg)
		{
			if (is_array($arg))
			{
				$this->data = array_merge_recursive($arg, $this->data);
			}
			else
			{
				$this->data[] = $arg;
			}
		}

		return $this;
	}

	/**
	 * Encode PHP code to put inside data-args, usually for updating the data there
	 *
	 * @param array  $data     Any data that can be converted using json_encode
	 * @param string $encoding Encoding for the arguments
	 *
	 * @return string Return json_encode'd and htmlentities'd string
	 */
	public static function args(array $data, $encoding = 'UTF-8')
	{
		return htmlentities(json_encode($data), ENT_COMPAT, $encoding, false);
	}

	/**
	 * Output the meta HTML with the token.
	 * This method needs to use sessions through session_start
	 *
	 * @param bool $check Check if the current token is valid
	 * @return string|bool
	 */
	public function csrf($check = false)
	{
		if ($this->config['csrf'] !== true)
		{
			return !empty($check) ? true : '';
		}

		if ($check === false)
		{
			$token = sha1(uniqid(microtime(true), true));

			$_SESSION['phery'] = array(
				'csrf' => $token
			);

			$token = base64_encode($token);

			return "<meta id=\"csrf-token\" content=\"{$token}\" />\n";
		}
		else
		{
			if (empty($_SESSION['phery']['csrf']))
			{
				return false;
			}

			return $_SESSION['phery']['csrf'] === base64_decode($check, true);
		}
	}

	/**
	 * Check if the current call is an ajax call
	 *
	 * @param bool $is_phery Check if is an ajax call and a phery specific call
	 *
	 * @static
	 * @return bool
	 */
	public static function is_ajax($is_phery = false)
	{
		switch ($is_phery)
		{
			case true:
				return (bool)(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
				strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0 &&
				strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' &&
				!empty($_SERVER['HTTP_X_PHERY']));
			case false:
				return (bool)(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
				strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0);
		}
		return false;
	}

	/**
	 * Strip slashes recursive
	 *
	 * @param $variable
	 * @return array|string
	 */
	private function stripslashes_recursive($variable)
	{
		if (is_string($variable))
		{
			return stripslashes($variable);
		}

		if (is_array($variable))
		{
			foreach ($variable as $i => $value)
			{
				$variable[$i] = $this->stripslashes_recursive($value);
			}
		}

		return $variable;
	}

	/**
	 * Return the data associatated with a processed POST call
	 *
	 * @param string $alias   The name of the alias for the process function
	 * @param mixed  $default Any data that should be returned if there's no answer, defaults to null
	 *
	 * @return mixed Return $default if no data available, defaults to NULL
	 */
	public function answer_for($alias, $default = null)
	{
		if (isset($this->answers[$alias]) && !empty($this->answers[$alias]))
		{
			return $this->answers[$alias];
		}

		return $default;
	}

	/**
	 * Default error handler
	 *
	 * @param int $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param int $errline
	 */
	public static function error_handler($errno, $errstr, $errfile, $errline)
	{
		ob_end_clean();

		$response = PheryResponse::factory()->exception($errstr, array(
			'code' => $errno,
			'file' => $errfile,
			'line' => $errline
		));

		self::respond($response);
		self::shutdown_handler(false, false, true);
	}

	/**
	 * Default shutdown handler
	 *
	 * @param bool $compressed
	 * @param bool $errors
	 * @param bool $handled
	 */
	public static function shutdown_handler($compressed = false, $errors = false, $handled = false)
	{
		if ($handled)
		{
			while (ob_get_level() > 0)
			{
				ob_end_flush();
			}
		}

		if ($errors === true && ($error = error_get_last()) && !$handled)
		{
			self::error_handler($error["type"], $error["message"], $error["file"], $error["line"]);
		}

		if (!$handled || $compressed)
		{
			while (ob_get_level() > 0)
			{
				ob_end_flush();
			}
		}

		exit;
	}

	/**
	 * Helper function to properly output the headers for a PheryResponse in case you need
	 * to manually return it (like when following a redirect)
	 *
	 * @param string|PheryResponse $response The response or a string
	 * @param boolean              $compress Either to compress the response or not
	 * @param string               $name     This parameter can be ignored
	 *
	 * @return string
	 */
	public static function respond($response, $compress = false, $name = '')
	{
		if ($response instanceof PheryResponse)
		{
			if (!headers_sent())
			{
				header('Cache-Control: no-cache, must-revalidate');
				header('Expires: 0');
				header('Content-Type: application/json');
				header('Connection: close');
			}
		}

		if ($response === null)
		{
			self::error_handler(E_NOTICE, 'Response was void'.($name ? ' for ' . $name : ''), '', 0);
		}
		else
		{
			$response = "{$response}";
		}

		if (
			$compress &&
			strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') &&
			strlen($response) > 80
		)
		{
			ob_start('ob_gzhandler');
			echo $response;
		}
		else
		{
			echo $response;
		}
		return $response;
	}

	/**
	 * Set the callback for view portions, as defined in Phery.view()
	 *
	 * @param array $views Array consisting of array('#id_of_view' => callback)
	 *                     The callback is like a normal phery callback, but the second parameter
	 *                     receives different data. But it MUST always return a PheryResponse with
	 *                     render_view(). You can do any manipulation like you would in regular
	 *                     callbacks. If you want to manipulate the DOM AFTER it was rendered, do it
	 *                     javascript side, using the afterHtml callback when setting up the views.
	 * <pre>
	 * Phery::instance()->views(array(
	 *     '#container' => function($data, $params){
	 *          return
	 *              PheryResponse::factory()
	 *              ->render_view('html', array('extra data like titles, menus, etc'));
	 *      }
	 * ));
	 * </pre>
	 *
	 * @return Phery
	 */
	public function views(array $views)
	{
		foreach ($views as $container => $callback)
		{
			if (is_callable($callback))
			{
				if ($container[0] !== '#')
				{
					$container = '#' . $container;
				}
				$this->views[$container] = $callback;
			}
		}

		return $this;
	}

	/**
	 * Initialize stuff before calling the AJAX function
	 *
	 * @return void
	 */
	protected function before_user_func()
	{
		if ($this->config['error_reporting'] !== false)
		{
			set_error_handler('Phery::error_handler', $this->config['error_reporting']);
		}

		register_shutdown_function('Phery::shutdown_handler', $this->config['compress'], $this->config['error_reporting'] !== false);

		if (empty($_POST['phery']['csrf']))
		{
			$_POST['phery']['csrf'] = '';
		}

		if ($this->csrf($_POST['phery']['csrf']) === false)
		{
			self::exception($this, 'Invalid CSRF token', self::ERROR_CSRF);
		}
	}

	/**
	 * Process the requests if any
	 *
	 * @param boolean $respond_to_post
	 * @param boolean $last_call
	 *
	 * @return boolean
	 */
	private function process_data($respond_to_post, $last_call)
	{
		$response = null;
		$view = false;

		if (empty($_POST['phery']))
		{
			return self::exception($this, 'Non-Phery AJAX request', self::ERROR_PROCESS);
		}

		if (!empty($_GET['_']))
		{
			$this->data['requested'] = (int)$_GET['_'];
			unset($_GET['_']);
		}

		if (!empty($_GET['_try_count']))
		{
			$this->data['retries'] = (int)$_GET['_try_count'];
			unset($_GET['_try_count']);
		}

		$args = array();
		$remote = false;

		if (!empty($_POST['phery']['remote']))
		{
			$remote = $_POST['phery']['remote'];
		}

		if (!empty($_POST['phery']['submit_id']))
		{
			$this->data['submit_id'] = "#{$_POST['phery']['submit_id']}";
		}

		if ($remote !== false)
		{
			$this->data['remote'] = $remote;

			if ($respond_to_post === true)
			{
				if ($this->config['no_stripslashes'] === false)
				{
					$args = $this->stripslashes_recursive($_POST);
				}
				else
				{
					$args = $_POST;
				}

				unset($args['phery']['remote']);
			}
		}

		if (!empty($_POST['args']))
		{
			if ($this->config['no_stripslashes'] === false)
			{
				$args = $this->stripslashes_recursive($_POST['args']);
			}
			else
			{
				$args = $_POST['args'];
			}

			if ($last_call === true || $respond_to_post === true)
			{
				unset($_POST['args']);
			}
		}

		foreach ($_POST['phery'] as $name => $post)
		{
			if (!isset($this->data[$name]))
			{
				$this->data[$name] = $post;
			}
		}

		if (count($this->callbacks['before']))
		{
			foreach ($this->callbacks['before'] as $func)
			{
				if (($args = call_user_func($func, $args, $this->data, $this)) === false)
				{
					return false;
				}
			}
		}

		if (!empty($_POST['phery']['view']))
		{
			$this->data['view'] = $_POST['phery']['view'];
		}

		if ($remote !== false)
		{
			if (isset($this->functions[$remote]))
			{
				if (isset($_POST['phery']['remote']))
				{
					unset($_POST['phery']['remote']);
				}

				$this->before_user_func();

				$response = call_user_func($this->functions[$remote], $args, $this->data, $this);

				foreach ($this->callbacks['after'] as $func)
				{
					if (call_user_func($func, $args, $this->data, $response, $this) === false)
					{
						return false;
					}
				}

				$_POST['phery']['remote'] = $remote;

				if ($respond_to_post === false)
				{
					self::respond($response, $this->config['compress'], 'function "'. $remote . '"');
				}
				else
				{
					$this->answers[$remote] = $response;
				}
			}
			else
			{
				if ($last_call)
				{
					self::exception($this, 'The function provided "' . ($remote) . '" isn\'t set', self::ERROR_PROCESS);
				}
			}
		}
		else
		{
			if (!empty($this->data['view']) && isset($this->views[$this->data['view']]))
			{
				$view = $this->data['view'];

				$this->before_user_func();

				$response = call_user_func($this->views[$this->data['view']], $args, $this->data, $this);

				foreach ($this->callbacks['after'] as $func)
				{
					if (call_user_func($func, $args, $this->data, $response, $this) === false)
					{
						return false;
					}
				}

				self::respond($response, $this->config['compress'], 'view "'. $this->data['view'] . '"');
			}
			else
			{
				if ($last_call)
				{
					self::exception($this, 'The function provided "' . ($remote) . '" isn\'t set', self::ERROR_PROCESS);
				}
			}
		}

		if ($respond_to_post === false)
		{
			if ($response === null && $last_call & !$view)
			{
				self::respond(PheryResponse::factory());
			}

			if ($this->config['exit_allowed'] === true)
			{
				if ($last_call || $response !== null)
				{
					exit;
				}
			}
		}

		return true;
	}

	/**
	 * Process the AJAX requests if any
	 *
	 * @param bool $last_call Set this to false if any other further calls
	 *                        to process() will happen, otherwise it will exit
	 *
	 * @throws PheryException
	 * @return void
	 */
	public function process($last_call = true)
	{
		if (self::is_ajax(true))
		{
			// AJAX call
			$this->process_data(false, $last_call);
		}
		elseif (
			count($this->respond_to_post) &&
			strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' &&
			isset($_POST['phery']) && isset($_POST['phery']['remote']) &&
			in_array($_POST['phery']['remote'], $this->respond_to_post)
		)
		{
			// Regular processing, respond to post, pass the $_POST variable to the function anyway
			$this->process_data(true, false);
		}
	}

	/**
	 * Config the current instance of Phery
	 *
	 * @param string|array $config Associative array containing the following options
	 * <pre>
	 * array(
	 *     // Defaults to true, stop further script execution
	 *     'exit_allowed' => true|false,
	 *
	 *     // Don't apply stripslashes on the args
	 *     'no_stripslashes' => true|false,
	 *
	 *     // Throw exceptions on errors
	 *     'exceptions' => true|false,
	 *
	 *     // Set the functions that will be called even if is a
	 *     // POST but not an AJAX call
	 *     'respond_to_post' => array('function-alias-1','function-alias-2'),
	 *
	 *     // Enable/disable GZIP/DEFLATE compression, depending on the browser support.
	 *     // Don't enable it if you are using Apache DEFLATE/GZIP, or zlib.output_compression
	 *     // Most of the time, compression will hide exceptions, because it will output plain
	 *     // text while the content-type is gzip
	 *     'compress' => true|false,
	 *
	 *     // Error reporting temporarily using error_reporting(). 'false' disables
	 *     // the error_reporting and wont try to catch any error.
	 *     // Anything else than false will throw a PheryResponse->exception() with
	 *     // the message
	 *     'error_reporting' => false|E_ALL|E_DEPRECATED|...
	 *
	 * );
	 * </pre>
	 * If you pass a string, it will return the current config for the key specified
	 * Anything else, will output the current config as associative array
	 *
	 * @return Phery|string|array
	 */
	public function config($config = null)
	{
		if (!empty($config))
		{
			if (is_array($config))
			{
				if (isset($config['exit_allowed']))
				{
					$this->config['exit_allowed'] = (bool)$config['exit_allowed'];
				}

				if (isset($config['no_stripslashes']))
				{
					$this->config['no_stripslashes'] = (bool)$config['no_stripslashes'];
				}

				if (isset($config['exceptions']))
				{
					$this->config['exceptions'] = (bool)$config['exceptions'];
				}

				if (isset($config['compress']))
				{
					if (!ini_get('zlib.output_compression'))
					{
						$this->config['compress'] = (bool)$config['compress'];
					}
				}

				if (isset($config['csrf']))
				{
					$this->config['csrf'] = (bool)$config['csrf'];

					if ($this->config['csrf'])
					{
						if (session_id() == '')
						{
							session_start();
						}
					}
				}

				if (isset($config['error_reporting']))
				{
					if ($config['error_reporting'] !== false)
					{
						$this->config['error_reporting'] = (int)$config['error_reporting'];
					}
					else
					{
						$this->config['error_reporting'] = false;
					}
				}

				if (isset($config['respond_to_post']) && is_array($config['respond_to_post']))
				{
					if (count($config['respond_to_post']))
					{
						$this->respond_to_post = array_merge_recursive(
							$this->respond_to_post, $config['respond_to_post']
						);
					}
					else
					{
						$this->respond_to_post = array();
					}
				}

				return $this;
			}
			elseif (is_string($config) && isset($this->config[$config]))
			{
				return $this->config[$config];
			}
		}

		return $this->config;
	}

	/**
	 * Generates just one instance. Useful to use in many included files. Chainable
	 *
	 * @param array $config Associative config array
	 *
	 * @see Phery::__construct()
	 * @see Phery::config()
	 * @static
	 * @return Phery
	 */
	public static function instance(array $config = array())
	{
		if (!(self::$instance instanceof Phery))
		{
			self::$instance = new Phery($config);
		}
		else if ($config)
		{
			self::$instance->config($config);
		}

		return self::$instance;
	}

	/**
	 * Sets the functions to respond to the ajax call.
	 * For security reasons, these functions should not be reacheable through POST/GET requests.
	 * These will be set only for AJAX requests as it will only be called in case of an ajax request,
	 * to save resources.
	 * The answer/process function, should have the following structure:
	 * <pre>
	 * function func($ajax_data, $callback_data){
	 *   $r = new PheryResponse; // or PheryResponse::factory();
	 *
	 *   // Sometimes the $callback_data will have an item called 'submit_id',
	 *   // is the ID of the calling DOM element.
	 *   // if (isset($callback_data['submit_id'])) {  }
	 *
	 *   $r->jquery('#id')->animate(...);
	 *      return $r;
	 * }
	 * </pre>
	 *
	 * @param array $functions An array of functions to register to the instance.
	 *
	 * @return Phery
	 */
	public function set(array $functions)
	{
		if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST' && !isset($_SERVER['HTTP_X_PHERY']))
		{
			return $this;
		}

		if (isset($functions) && is_array($functions))
		{
			foreach ($functions as $name => $func)
			{
				if (is_callable($func))
				{
					if (isset($this->functions[$name]))
					{
						self::exception($this, 'The function "' . $name . '" already exists', self::ERROR_SET);
					}
					$this->functions[$name] = $func;
				}
				else
				{
					self::exception($this, 'Provided function "' . $name . '" isnt a valid function or method', self::ERROR_SET);
				}
			}
		}
		else
		{
			self::exception($this, 'Call to "set" must be provided an array', self::ERROR_SET);
		}

		return $this;
	}

	/**
	 * Create a new instance of Phery that can be chained, without the need of assigning it to a variable
	 *
	 * @param array $config Associative config array
	 *
	 * @see Phery::config()
	 * @static
	 * @return Phery
	 */
	public static function factory(array $config = array())
	{
		return new Phery($config);
	}

	/**
	 * Common check for all static factories
	 *
	 * @param array $attributes
	 * @param bool $include_method
	 *
	 * @return string
	 */
	protected static function common_check(&$attributes, $include_method = true)
	{
		if (isset($attributes['args']))
		{
			$attributes['data-args.phery'] = json_encode($attributes['args']);
			unset($attributes['args']);
		}

		if (isset($attributes['confirm']))
		{
			$attributes['data-confirm'] = $attributes['confirm'];
			unset($attributes['confirm']);
		}

		if (isset($attributes['target']))
		{
			$attributes['data-target.phery'] = $attributes['target'];
			unset($attributes['target']);
		}

		if (isset($attributes['related']))
		{
			$attributes['data-related.phery'] = $attributes['related'];
			unset($attributes['related']);
		}

		if ($include_method)
		{
			if (isset($attributes['method']))
			{
				$attributes['data-method.phery'] = $attributes['method'];
				unset($attributes['method']);
			}
		}

		$encoding = 'UTF-8';
		if (isset($attributes['encoding']))
		{
			$encoding = $attributes['encoding'];
			unset($attributes['encoding']);
		}

		return $encoding;
	}

	/**
	 * Helper function that generates an ajax link, defaults to "A" tag
	 *
	 * @param string $content    The content of the link. This is ignored for self closing tags, img, input, iframe
	 * @param string $function   The PHP function assigned name on Phery::set()
	 * @param array  $attributes Extra attributes that can be passed to the link, like class, style, etc
	 * <pre>
	 * array(
	 *     // Display confirmation on click
	 *     'confirm' => 'Are you sure?',
	 *
	 *     // The tag for the item, defaults to a. If the tag is set to img, the 'src' must be set in attributes parameter
	 *     'tag' => 'a',
	 *
	 *     // Define another URI for the AJAX call, this defines the HREF of A
	 *     'href' => '/path/to/url',
	 *
	 *     // Extra arguments to pass to the AJAX function, will be stored
	 *     // in the data-args attribute as a JSON notation
	 *     'args' => array(1, "a"),
	 *
	 *     // Set the "href" attribute for non-anchor (a) AJAX tags (like buttons or spans).
	 *     // Works for A links too, but it won't function without javascript, through data-target
	 *     'target' => '/default/ajax/controller',
	 *
	 *     // Define the data-type for the communication
	 *     'data-type' => 'json',
	 *
	 *     // Set the encoding of the data, defaults to UTF-8
	 *     'encoding' => 'UTF-8',
	 *
	 *     // Set the method (for restful responses)
	 *     'method' => 'PUT'
	 * );
	 * </pre>
	 * @param Phery  $phery      Pass the current instance of phery, so it can check if the
	 *                           functions are defined, and throw exceptions
	 *
	 * @static
	 * @return string The mounted HTML tag
	 */
	public static function link_to($content, $function, array $attributes = array(), Phery $phery = null)
	{
		if (!$function)
		{
			self::exception($phery, 'The "function" argument must be provided to "link_to"', self::ERROR_TO);

			return '';
		}

		if ($phery && !isset($phery->functions[$function]))
		{
			self::exception($phery, 'The function "' . $function . '" provided in "link_to" hasnt been set', self::ERROR_TO);
		}

		$tag = 'a';
		if (isset($attributes['tag']))
		{
			$tag = $attributes['tag'];
			unset($attributes['tag']);
		}

		$encoding = self::common_check($attributes);

		$attributes['data-remote'] = $function;

		$ret = array();
		$ret[] = "<{$tag}";
		foreach ($attributes as $attribute => $value)
		{
			$ret[] = "{$attribute}=\"" . htmlentities($value, ENT_COMPAT, $encoding, false) . "\"";
		}

		if (!in_array(strtolower($tag), array('img', 'input', 'iframe', 'hr', 'area', 'embed', 'keygen')))
		{
			$ret[] = ">{$content}</{$tag}>";
		}
		else
		{
			$ret[] = "/>";
		}

		return join(' ', $ret);
	}

	/**
	 * Create a <form> tag with ajax enabled. Must be closed manually with </form>
	 *
	 * @param string $action   where to go, can be empty
	 * @param string $function Registered function name
	 * @param array  $attributes
	 * <pre>
	 * array(
	 *     //Confirmation dialog
	 *     'confirm' => 'Are you sure?',
	 *
	 *     // Type of call, defaults to JSON (to use PheryResponse)
	 *     'data-type' => 'json',
	 *
	 *     // 'all' submits all elements on the form, even empty ones
	 *     // 'disabled' enables submitting disabled elements
	 *     'submit' => array('all' => true, 'disabled' => true),
	 *
	 *     // Set the encoding of the data, defaults to UTF-8
	 *     'encoding' => 'UTF-8',
	 * );
	 * </pre>
	 * @param Phery  $phery    Pass the current instance of phery, so it can check if the functions are defined, and throw exceptions
	 *
	 * @static
	 * @return string The mounted <form> HTML tag
	 */
	public static function form_for($action, $function, array $attributes = array(), Phery $phery = null)
	{
		if (!$function)
		{
			self::exception($phery, 'The "function" argument must be provided to "form_for"', self::ERROR_TO);

			return '';
		}

		if ($phery && !isset($phery->functions[$function]))
		{
			self::exception($phery, 'The function "' . $function . '" provided in "form_for" hasnt been set', self::ERROR_TO);
		}

		$encoding = self::common_check($attributes, false);

		if (isset($attributes['submit']))
		{
			$attributes['data-submit.phery'] = json_encode($attributes['submit']);
			unset($attributes['submit']);
		}

		$ret = array();
		$ret[] = '<form method="POST" action="' . $action . '" data-remote="' . $function . '"';
		foreach ($attributes as $attribute => $value)
		{
			$ret[] = "{$attribute}=\"" . htmlentities($value, ENT_COMPAT, $encoding, false) . "\"";
		}
		$ret[] = '><input type="hidden" name="phery[remote]" value="' . $function . '"/>';

		return join(' ', $ret);
	}

	/**
	 * Create a <select> element with ajax enabled "onchange" event.
	 *
	 * @param string $function Registered function name
	 * @param array  $items    Options for the select, 'value' => 'text' representation
	 * @param array  $attributes
	 * <pre>
	 * array(
	 *     // Confirmation dialog
	 *     'confirm' => 'Are you sure?',
	 *
	 *     // Type of call, defaults to JSON (to use PheryResponse)
	 *     'data-type' => 'json',
	 *
	 *     // The URL where it should call, translates to data-target
	 *     'target' => '/path/to/php',
	 *
	 *     // Extra arguments to pass to the AJAX function, will be stored
	 *     // in the args attribute as a JSON notation, translates to data-args
	 *     'args' => array(1, "a"),
	 *
	 *     // Set the encoding of the data, defaults to UTF-8
	 *     'encoding' => 'UTF-8',
	 *
	 *     // The current selected value, or array(1,2) for multiple
	 *     'selected' => 1
	 *
	 *     // Set the method (for restful responses)
	 *     'method' => 'PUT'
	 * );
	 * </pre>
	 * @param Phery  $phery    Pass the current instance of phery, so it can check if the functions are defined, and throw exceptions
	 *
	 * @static
	 * @return string The mounted <select> with <option>s inside
	 */
	public static function select_for($function, array $items, array $attributes = array(), Phery $phery = null)
	{
		if (!$function)
		{
			self::exception($phery, 'The "function" argument must be provided to "select_for"', self::ERROR_TO);

			return '';
		}

		if ($phery && !isset($phery->functions[$function]))
		{
			self::exception($phery, 'The function "' . $function . '" provided in "select_for" hasnt been set', self::ERROR_TO);
		}

		$encoding = self::common_check($attributes);

		$selected = array();
		if (isset($attributes['selected']))
		{
			if (is_array($attributes['selected']))
			{
				// multiple select
				$selected = $attributes['selected'];
			}
			else
			{
				// single select
				$selected = array($attributes['selected']);
			}
			unset($attributes['selected']);
		}

		if (isset($attributes['multiple']))
		{
			$attributes['multiple'] = 'multiple';
		}

		$ret = array();
		$ret[] = '<select data-remote="' . $function . '"';
		foreach ($attributes as $attribute => $value)
		{
			$ret[] = "{$attribute}=\"" . htmlentities($value, ENT_COMPAT, $encoding, false) . "\"";
		}
		$ret[] = '>';

		foreach ($items as $value => $text)
		{
			$_value = 'value="' . htmlentities($value, ENT_COMPAT, $encoding, false) . '"';
			if (in_array($value, $selected))
			{
				$_value .= ' selected="selected"';
			}
			$ret[] = "<option " . ($_value) . ">{$text}</option>\n";
		}
		$ret[] = '</select>';

		return join(' ', $ret);
	}

	/**
	 * OffsetExists
	 *
	 * @param mixed $offset
	 *
	 * @return bool
	 */
	public function offsetExists($offset)
	{
		return isset($this->data[$offset]);
	}

	/**
	 * OffsetUnset
	 *
	 * @param mixed $offset
	 */
	public function offsetUnset($offset)
	{
		if (isset($this->data[$offset]))
		{
			unset($this->data[$offset]);
		}
	}

	/**
	 * OffsetGet
	 *
	 * @param mixed $offset
	 *
	 * @return mixed|null
	 */
	public function offsetGet($offset)
	{
		if (isset($this->data[$offset]))
		{
			return $this->data[$offset];
		}

		return null;
	}

	/**
	 * offsetSet
	 *
	 * @param mixed $offset
	 * @param mixed $value
	 */
	public function offsetSet($offset, $value)
	{
		$this->data[$offset] = $value;
	}

	/**
	 * Set shared data
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set($name, $value)
	{
		$this->data[$name] = $value;
	}

	/**
	 * Get shared data
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get($name)
	{
		if (isset($this->data[$name]))
		{
			return $this->data[$name];
		}

		return null;
	}

	/**
	 * Utility function taken from MYSQL.
	 * To not raise any E_NOTICES (if enabled in your error reporting), call it with @ before
	 * the variables. Eg.: Phery::coalesce(@$var1, @$var['asdf']);
	 *
	 * @param ...
	 *
	 * @return mixed
	 */
	public static function coalesce()
	{
		$args = func_get_args();
		foreach ($args as &$arg)
		{
			if (isset($arg) && !empty($arg))
			{
				return $arg;
			}
		}

		return null;
	}
}

/**
 * Standard response for the json parser
 * @package    Phery
 *
 * @method PheryResponse ajax() ajax($url, $settings = null) Perform an asynchronous HTTP (Ajax) request.
 * @method PheryResponse ajaxSetup() ajaxSetup($obj) Set default values for future Ajax requests.
 * @method PheryResponse post() post($url, $success = null) Load data from the server using a HTTP POST request.
 * @method PheryResponse get() get($url, $success = null) Load data from the server using a HTTP GET request.
 * @method PheryResponse getJSON() getJSON($url, $success = null) Load JSON-encoded data from the server using a GET HTTP request.
 * @method PheryResponse getScript() getScript($url, $success = null) Load a JavaScript file from the server using a GET HTTP request, then execute it.
 * @method PheryResponse detach() detach() Detach a DOM element retaining the events attached to it
 * @method PheryResponse prependTo() prependTo($target) Prepend DOM element to target
 * @method PheryResponse appendTo() appendTo($target) Append DOM element to target
 * @method PheryResponse replaceWith() replaceWith($newContent) The content to insert. May be an HTML string, DOM element, or jQuery object.
 * @method PheryResponse css() css($propertyName, $value = null) propertyName: A CSS property name. value: A value to set for the property.
 * @method PheryResponse toggle() toggle($speed) Toggle an object visible or hidden, can be animated with 'fast', 'slow', 'normal'
 * @method PheryResponse hide() hide($speed = 0) Hide an object, can be animated with 'fast', 'slow', 'normal'
 * @method PheryResponse show() show($speed = 0) Show an object, can be animated with 'fast', 'slow', 'normal'
 * @method PheryResponse toggleClass() toggleClass($className) Add/Remove a class from an element
 * @method PheryResponse data() data($name, $data) Add data to element
 * @method PheryResponse addClass() addClass($className) Add a class from an element
 * @method PheryResponse removeClass() removeClass($className) Remove a class from an element
 * @method PheryResponse animate() animate($prop, $dur, $easing = null, $cb = null) Perform a custom animation of a set of CSS properties.
 * @method PheryResponse trigger() trigger($eventName, $args = array()) Trigger an event
 * @method PheryResponse triggerHandler() triggerHandler($eventType, $extraParameters = array()) Execute all handlers attached to an element for an event.
 * @method PheryResponse fadeIn() fadeIn($prop, $dur, $easing, $cb) Animate an element
 * @method PheryResponse filter() filter($selector) Reduce the set of matched elements to those that match the selector or pass the function's test.
 * @method PheryResponse fadeTo() fadeTo($dur, $opacity) Animate an element
 * @method PheryResponse fadeOut() fadeOut($prop, $dur, $easing, $cb) Animate an element
 * @method PheryResponse slideUp() slideUp($dur, $cb) Hide with slide up animation
 * @method PheryResponse slideDown() slideDown($dur, $cb) Show with slide down animation
 * @method PheryResponse slideToggle() slideToggle($dur, $cb) Toggle show/hide the element, using slide animation
 * @method PheryResponse unbind() unbind($name) Unbind an event from an element
 * @method PheryResponse undelegate() undelegate() Remove a handler from the event for all elements which match the current selector, now or in the future, based upon a specific set of root elements.
 * @method PheryResponse stop() stop() Stop animation on elements
 * @method PheryResponse die() die($name) Unbind an event from an element set by live()
 * @method PheryResponse val() val($content) Set the value of an element
 * @method PheryResponse removeData() removeData($name) Remove element data added with data()
 * @method PheryResponse removeAttr() removeAttr($name) Remove an attribute from an element
 * @method PheryResponse scrollTop() scrollTop($val) Set the scroll from the top
 * @method PheryResponse scrollLeft() scrollLeft($val) Set the scroll from the left
 * @method PheryResponse height() height($val) Set the height from the left
 * @method PheryResponse width() width($val) Set the width from the left
 * @method PheryResponse slice() slice($start, $end) Reduce the set of matched elements to a subset specified by a range of indices.
 * @method PheryResponse not() not($val) Remove elements from the set of matched elements.
 * @method PheryResponse eq() eq($selector) Reduce the set of matched elements to the one at the specified index.
 * @method PheryResponse offset() offset($coordinates) Set the current coordinates of every element in the set of matched elements, relative to the document.
 * @method PheryResponse map() map($callback) Pass each element in the current matched set through a function, producing a new jQuery object containing the return values.
 * @method PheryResponse children() children($selector) Get the children of each element in the set of matched elements, optionally filtered by a selector.
 * @method PheryResponse closest() closest($selector) Get the first ancestor element that matches the selector, beginning at the current element and progressing up through the DOM tree.
 * @method PheryResponse find() find($selector) Get the descendants of each element in the current set of matched elements, filtered by a selector, jQuery object, or element.
 * @method PheryResponse next() next($selector = null) Get the immediately following sibling of each element in the set of matched elements, optionally filtered by a selector.
 * @method PheryResponse nextAll() nextAll($selector) Get all following siblings of each element in the set of matched elements, optionally filtered by a selector.
 * @method PheryResponse nextUntil() nextUntil($selector) Get all following siblings of each element up to  but not including the element matched by the selector.
 * @method PheryResponse parentsUntil() parentsUntil($selector) Get the ancestors of each element in the current set of matched elements, up to but not including the element matched by the selector.
 * @method PheryResponse offsetParent() offsetParent() Get the closest ancestor element that is positioned.
 * @method PheryResponse parent() parent($selector = null) Get the parent of each element in the current set of matched elements, optionally filtered by a selector.
 * @method PheryResponse parents() parents($selector) Get the ancestors of each element in the current set of matched elements, optionally filtered by a selector.
 * @method PheryResponse prev() prev($selector = null) Get the immediately preceding sibling of each element in the set of matched elements, optionally filtered by a selector.
 * @method PheryResponse prevAll() prevAll($selector) Get all preceding siblings of each element in the set of matched elements, optionally filtered by a selector.
 * @method PheryResponse prevUntil() prevUntil($selector) Get the ancestors of each element in the current set of matched elements, optionally filtered by a selector.
 * @method PheryResponse siblings() siblings($selector) Get the siblings of each element in the set of matched elements, optionally filtered by a selector.
 * @method PheryResponse add() add($selector) Add elements to the set of matched elements.
 * @method PheryResponse andSelf() andSelf() Add the previous set of elements on the stack to the current set.
 * @method PheryResponse contents() contents() Get the children of each element in the set of matched elements, including text nodes.
 * @method PheryResponse end() end() End the most recent filtering operation in the current chain and return the set of matched elements to its previous state.
 * @method PheryResponse after() after($content) Insert content, specified by the parameter, after each element in the set of matched elements.
 * @method PheryResponse before() before($content) Insert content, specified by the parameter, before each element in the set of matched elements.
 * @method PheryResponse insertAfter() insertAfter($target) Insert every element in the set of matched elements after the target.
 * @method PheryResponse insertbefore() insertBefore($target) Insert every element in the set of matched elements before the target.
 * @method PheryResponse unwrap() unwrap() Remove the parents of the set of matched elements from the DOM, leaving the matched elements in their place.
 * @method PheryResponse wrap() wrap($wrappingElement) Wrap an HTML structure around each element in the set of matched elements.
 * @method PheryResponse wrapAll() wrapAll($wrappingElement) Wrap an HTML structure around all elements in the set of matched elements.
 * @method PheryResponse wrapInner() wrapInner($wrappingElement) Wrap an HTML structure around the content of each element in the set of matched elements.
 * @method PheryResponse delegate() delegate($selector, $eventType, $handler) Attach a handler to one or more events for all elements that match the selector, now or in the future, based on a specific set of root elements.
 * @method PheryResponse live() live($eventType, $handler) Attach a handler to the event for all elements which match the current selector, now or in the future.
 * @method PheryResponse one() one($eventType, $handler) Attach a handler to an event for the elements. The handler is executed at most once per element.
 * @method PheryResponse bind() bind($eventType, $handler) Attach a handler to an event for the elements.
 * @method PheryResponse each() each($function) Iterate over a jQ object, executing a function for each matched element.
 */
class PheryResponse extends ArrayObject {

	/**
	 * All responses that were created in the run, access them through their name
	 * @var PheryResponse[]
	 */
	protected static $responses = array();
	/**
	 * Common data available to all responses
	 * @var array
	 */
	protected static $global = array();
	/**
	 * Last jQuery selector defined
	 * @var string
	 */
	protected $last_selector = null;
	/**
	 * Array containing answer data
	 * @var array
	 */
	protected $data = array();
	/**
	 * Array containing merged data
	 * @var array
	 */
	protected $merged = array();
	/**
	 * Name of the current response
	 * @var string
	 */
	protected $name = null;

	/**
	 * Construct a new response
	 *
	 * @param string $selector Create the object already selecting the DOM element
	 * @param array $constructor Only available if you are creating an element, like $('<p/>')
	 */
	public function __construct($selector = null, array $constructor = array())
	{
		parent::__construct(array(), self::ARRAY_AS_PROPS);
		$this->jquery($selector, $constructor);
		$this->set_response_name(uniqid("", true));
	}

	/**
	 * Renew the CSRF token on a given Phery instance
	 * Resets any selectors that were being chained before
	 *
	 * @param Phery $instance Instance of Phery
	 * @return PheryResponse
	 */
	public function renew_csrf(Phery $instance)
	{
		if ($instance->config('csrf') === true)
		{
			$this->jquery('head meta#csrf-token')->replaceWith($instance->csrf());
		}

		return $this;
	}

	/**
	 * Set the name of this response
	 *
	 * @param string $name Name of current response
	 *
	 * @return PheryResponse
	 */
	public function set_response_name($name)
	{
		if (!empty($this->name))
		{
			unset(self::$responses[$this->name]);
		}
		$this->name = $name;
		self::$responses[$this->name] = $this;

		return $this;
	}

	/**
	 * Get the name of this response
	 *
	 * @return null|string
	 */
	public function get_response_name()
	{
		return $this->name;
	}

	/**
	 * Set a global value that can be accessed through $pheryresponse['value']
	 *
	 * @param array|string
	 * @param mixed $value [Optional]
	 */
	public static function set_global($name, $value = null)
	{
		if (isset($name) && is_array($name))
		{
			foreach ($name as $n => $v)
			{
				self::$global[$n] = $v;
			}
		}
		else
		{
			self::$global[$name] = $value;
		}
	}

	/**
	 * Unset a global variable
	 *
	 * @param string $name Variable name
	 */
	public static function unset_global($name)
	{
		unset(self::$global[$name]);
	}

	/**
	 * Will check for globals and local values
	 *
	 * @param string|int $index
	 *
	 * @return mixed
	 */
	public function offsetExists($index)
	{
		if (isset(self::$global[$index]))
		{
			return true;
		}

		return parent::offsetExists($index);
	}

	/**
	 * Set local variables, will be available only in this instance
	 *
	 * @param string|int|null $index
	 * @param mixed           $newval
	 *
	 * @return void
	 */
	public function offsetSet($index, $newval)
	{
		if ($index === null)
		{
			$this[] = $newval;
		}
		else
		{
			parent::offsetSet($index, $newval);
		}
	}

	/**
	 * Return null if no value
	 *
	 * @param mixed $index
	 *
	 * @return mixed|null
	 */
	public function offsetGet($index)
	{
		if (parent::offsetExists($index))
		{
			return parent::offsetGet($index);
		}
		if (isset(self::$global[$index]))
		{
			return self::$global[$index];
		}

		return null;
	}

	/**
	 * Get a response by name
	 *
	 * @param $name
	 *
	 * @return PheryResponse|null
	 */
	public static function get_response($name)
	{
		if (isset(self::$responses[$name]) && self::$responses[$name] instanceof PheryResponse)
		{
			return self::$responses[$name];
		}

		return null;
	}

	/**
	 * Get merged response data as a new PheryResponse.
	 * This method works like a constructor if the previous response was destroyed
	 *
	 * @param string $name Name of the merged response
	 * @return PheryResponse|null
	 */
	public function get_merged($name)
	{
		if (isset($this->merged[$name]))
		{
			if (isset(self::$responses[$name]))
			{
				return self::$responses[$name];
			}
			$response = new PheryResponse;
			$response->data = $this->merged[$name];
			return $response;
		}
		return null;
	}

	/**
	 * Same as phery.remote()
	 *
	 * @param string  $remote Function
	 * @param array   $args   Arguments to pass to the
	 * @param array   $attr   Here you may set like data-method, data-target, data-type
	 * @param boolean $directCall
	 *
	 * @return PheryResponse
	 */
	public function phery_remote($remote, $args = array(), $attr = array(), $directCall = true)
	{
		$this->last_selector = '-';

		return $this->cmd(0xff, array(
			$remote,
			$args,
			$attr,
			$directCall
		));
	}

	/**
	 * Accesses the phery() methods of the calling element, same as doing $('selector').phery()
	 *
	 * @param string|array $func Name of the phery function on the element or an array of functions
	 * <pre>
	 * 'append_args', 'set_args', 'remote', 'exception', 'remove'
	 * </pre>
	 * @param ... Any data to pass to the element
	 *
	 * @return PheryResponse
	 */
	public function phery($func)
	{
		$this->last_selector = null;

		if (!is_array($func)){
			$args = func_get_args();
			array_shift($args);

			return $this->cmd(10, array(
				$func,
				$args
			));
		}

		return $this->cmd(10, array(
			$func,
		));
	}

	/**
	 * Set a global variable, that can be accessed directly through window object,
	 * can set properties inside objects if you pass an array as the variable.
	 * If it doesn't exist it will be created
	 *
	 * <pre>
	 * // window.customer_info = {'name': 'John','surname': 'Doe', 'age': 39}
	 * PheryResponse::factory()->set_var('customer_info', array('name' => 'John', 'surname' => 'Doe', 'age' => 39));
	 * </pre>
	 *
	 * <pre>
	 * // window.customer_info.name = 'John'
	 * PheryResponse::factory()->set_var(array('customer_info','name'), 'John');
	 * </pre>
	 *
	 * @param string|array $variable Global variable name
	 * @param mixed        $data     Any data
	 * @return PheryResponse
	 */
	public function set_var($variable, $data)
	{
		$this->last_selector = null;

		if (!empty($data) && is_array($data))
		{
			foreach ($data as $name => $d)
			{
				$data[$name] = self::typecast($d, true, true);
			}
		}
		else
		{
			$data = self::typecast($data, true, true);
		}

		return $this->cmd(9, array(
			!is_array($variable) ? array($variable) : $variable,
			array($data)
		));
	}

	/**
	 * Delete a global variable, that can be accessed directly through window, can unset object properties,
	 * if you pass an array
	 *
	 * <pre>
	 * PheryResponse::factory()->unset('customer_info');
	 * </pre>
	 *
	 * <pre>
	 * PheryResponse::factory()->unset(array('customer_info','name')); // translates to delete customer_info['name']
	 * </pre>
	 *
	 * @param string|array $variable Global variable name
	 * @return PheryResponse
	 */
	public function unset_var($variable)
	{
		$this->last_selector = null;

		return $this->cmd(9, array(
			!is_array($variable) ? array($variable) : $variable,
		));
	}

	/**
	 * Create a new PheryResponse instance for chaining, fast and effective for one line returns
	 * <pre>
	 * function answer($data)
	 * {
	 *  return
	 *         PheryResponse::factory('a#link-'.$data['rel'])
	 *         ->attr('href', '#')
	 *         ->alert('done');
	 * }
	 * </pre>
	 *
	 * @param string $selector optional
	 * @param array $constructor Same as $('<p/>', {})
	 *
	 * @static
	 * @return PheryResponse
	 */
	public static function factory($selector = null, array $constructor = array())
	{
		return new PheryResponse($selector, $constructor);
	}

	/**
	 * Remove a batch of calls for a selector. Won't remove for merged responses.
	 * Passing an integer, will remove commands, like dump_vars, call, etc
	 *
	 * @param string|int $selector
	 *
	 * @return PheryResponse
	 */
	public function remove_selector($selector)
	{
		if ((is_string($selector) || is_int($selector)) && isset($this->data[$selector]))
		{
			unset($this->data[$selector]);
		}

		return $this;
	}

	/**
	 * Access the current calling DOM element without the need for IDs, names, etc
	 *
	 * @return PheryResponse
	 */
	public function this()
	{
		$this->last_selector = '~';

		return $this;
	}

	/**
	 * Merge another response to this one.
	 * Selectors with the same name will be added in order, for example:
	 * <pre>
	 * function process()
	 * {
	 *      $response = PheryResponse::factory('a.links')->remove();
	 *      // $response will execute before
	 *      // there will be no more "a.links" in the DOM, so the addClass() will fail silently
	 *      // to invert the order, merge $response to $response2
	 *      $response2 = PheryResponse::factory('a.links')->addClass('red');
	 *      return $response->merge($response2);
	 * }
	 * </pre>
	 *
	 * @param PheryResponse|string $phery Another PheryResponse object or a name of response
	 *
	 * @return PheryResponse
	 */
	public function merge($phery)
	{
		if (is_string($phery))
		{
			if (isset(self::$responses[$phery]))
			{
				$this->merged[self::$responses[$phery]->name] = self::$responses[$phery]->data;
			}
		}
		elseif ($phery instanceof PheryResponse)
		{
			$this->merged[$phery->name] = $phery->data;
		}

		return $this;
	}

	/**
	 * Remove a previously merged response
	 *
	 * @param PheryResponse|string $phery
	 *
	 * @return PheryResponse
	 */
	public function unmerge($phery)
	{
		if (is_string($phery))
		{
			if (isset(self::$responses[$phery]))
			{
				unset($this->merged[self::$responses[$phery]->name]);
			}
		}
		elseif ($phery instanceof PheryResponse)
		{
			unset($this->merged[$phery->name]);
		}

		return $this;
	}

	/**
	 * Pretty print to console.log
	 *
	 * @param ... Any var
	 *
	 * @return PheryResponse
	 */
	public function print_vars()
	{
		$this->last_selector = null;

		$args = array();
		foreach (func_get_args() as $name => $arg)
		{
			if (is_object($arg))
			{
				$arg = get_object_vars($arg);
			}
			$args[$name] = array(print_r($arg, true));
		}

		return $this->cmd(6, $args);
	}

	/**
	 * Dump var to console.log
	 *
	 * @param ... Any var
	 *
	 * @return PheryResponse
	 */
	public function dump_vars()
	{
		$this->last_selector = null;
		$args = array();
		foreach (func_get_args() as $index => $func)
		{

			if (is_object($func))
			{
				$args[$index] = array(get_object_vars($func));
			}
			else
			{
				$args[$index] = array($func);
			}
		}

		return $this->cmd(6, $args);
	}

	/**
	 * Sets the selector, so you can chain many calls to it. Passing # works like jQuery.func
	 *
	 * @param string $selector Sets the current selector for subsequent chaining
	 * <pre>
	 * PheryResponse::factory()
	 * ->jquery('.slides')
	 * ->fadeTo(0,0)
	 * ->css(array('top' => '10px', 'left' => '90px'));
	 * </pre>
	 *
	 * <pre>
	 * PheryResponse::factory()->jquery()->getJSON();
	 * </pre>
	 * @param array $constructor Only available if you are creating a new element, like $('<p/>', {})
	 *
	 * @return PheryResponse
	 */
	public function jquery($selector = '#', array $constructor = array())
	{
		$this->last_selector = $selector;

		if (count($constructor) && isset($selector) && is_string($selector) && substr($selector, 0, 1) === '<')
		{
			foreach ($constructor as $name => $value)
			{
				$this->$name($value);
			}
		}
		return $this;
	}

	/**
	 * Shortcut/alias for jquery($selector) Passing null works like jQuery.func
	 *
	 * @param string $selector Sets the current selector for subsequent chaining
	 * @param array $constructor Only available if you are creating a new element, like $('<p/>', {})
	 *
	 * @return PheryResponse
	 */
	public function j($selector = '#', array $constructor = array())
	{
		return $this->jquery($selector, $constructor);
	}

	/**
	 * Show an alert box
	 *
	 * @param string $msg Message to be displayed
	 *
	 * @return PheryResponse
	 */
	public function alert($msg)
	{
		if (is_array($msg))
		{
			$msg = join("\n", $msg);
		}

		$this->last_selector = null;

		return $this->cmd(1, array(
			self::typecast($msg, true)
		));
	}

	/**
	 * Pass JSON to the browser
	 *
	 * @param mixed $obj Data to be encoded to json (usually an array or a JsonSerializable)
	 *
	 * @return PheryResponse
	 */
	public function json($obj)
	{
		$this->last_selector = null;

		return $this->cmd(4, array(
			json_encode($obj)
		));
	}

	/**
	 * Remove the current jQuery selector
	 *
	 * @param string|boolean $selector Set a selector
	 *
	 * @return PheryResponse
	 */
	public function remove($selector = null)
	{
		return $this->cmd('remove', array(), $selector);
	}

	/**
	 * Add a command to the response
	 *
	 * @param int|string|array $cmd      Integer for command, see phery.js for more info
	 * @param array            $args     Array to pass to the response
	 * @param string           $selector Insert the jquery selector
	 *
	 * @return PheryResponse
	 */
	public function cmd($cmd, array $args = array(), $selector = null)
	{
		$selector = Phery::coalesce($selector, $this->last_selector);

		if ($selector === null)
		{
			$this->data[] = array(
				'c' => $cmd,
				'a' => $args
			);
		}
		else
		{
			if (!isset($this->data[$selector]))
			{
				$this->data[$selector] = array();
			}
			$this->data[$selector][] = array(
				'c' => $cmd,
				'a' => $args
			);
		}

		return $this;
	}

	/**
	 * Set the attribute of a jQuery selector
	 * Example:
	 * <pre>
	 * PheryResponse::factory()
	 * ->attr('href', 'http://url.com', 'a#link-' . $args['id']);
	 * </pre>
	 *
	 * @param string $attr     HTML attribute of the item
	 * @param string $data     Value
	 * @param string $selector [optional] Provide the jQuery selector directly
	 *
	 * @return PheryResponse
	 */
	public function attr($attr, $data, $selector = null)
	{
		return $this->cmd('attr', array(
			$attr,
			$data
		), $selector);
	}

	/**
	 * Trigger the phery:exception event on the calling element
	 * with additional data
	 *
	 * @param string $msg  Message to pass to the exception
	 * @param mixed  $data Any data to pass, can be anything
	 *
	 * @return PheryResponse
	 */
	public function exception($msg, $data = null)
	{
		$this->last_selector = null;

		return $this->cmd(7, array(
			$msg,
			$data
		));
	}

	/**
	 * Call a javascript function.
	 * Warning: calling this function will reset the selector jQuery selector previously stated
	 *
	 * @param string|array $func_name Function name. If you pass a string, it will be accessed on window.func.
	 *                                If you pass an array, it will access a member of an object, like array('object', 'property', 'function')
	 * @param              mixed      ... Any additional arguments to pass to the function
	 *
	 * @return PheryResponse
	 */
	public function call($func_name)
	{
		$args = func_get_args();
		array_shift($args);
		$this->last_selector = null;

		return $this->cmd(2, array(
			!is_array($func_name) ? array($func_name) : $func_name,
			$args
		));
	}

	/**
	 * Call 'apply' on a javascript function.
	 * Warning: calling this function will reset the selector jQuery selector previously stated
	 *
	 * @param string|array $func_name Function name
	 * @param array  $args      Any additional arguments to pass to the function
	 *
	 * @return PheryResponse
	 */
	public function apply($func_name, array $args = array())
	{
		$this->last_selector = null;

		return $this->cmd(2, array(
			!is_array($func_name) ? array($func_name) : $func_name,
			$args
		));
	}

	/**
	 * Clear the selected attribute.
	 * Alias for attr('attribute', '')
	 * @see PheryResponse::attr()
	 *
	 * @param string $attr     Name of the DOM attribute to clear, such as 'innerHTML', 'style', 'href', etc not the jQuery counterparts
	 * @param string $selector [optional] Provide the jQuery selector directly
	 *
	 * @return PheryResponse
	 */
	public function clear($attr, $selector = null)
	{
		return $this->attr($attr, '', $selector);
	}

	/**
	 * Set the HTML content of an element.
	 * Automatically typecasted to string, so classes that
	 * respond to __toString() will be converted automatically
	 *
	 * @param string $content
	 * @param string $selector [optional] Provide the jQuery selector directly
	 *
	 * @return PheryResponse
	 */
	public function html($content, $selector = null)
	{
		if (is_array($content))
		{
			$content = join("\n", $content);
		}

		return $this->cmd('html', array(
			self::typecast($content, true, true)
		), $selector);
	}

	/**
	 * Set the text of an element.
	 * Automatically typecasted to string, so classes that
	 * respond to __toString() will be converted automatically
	 *
	 * @param string $content
	 * @param string $selector [optional] Provide the jQuery selector directly
	 *
	 * @return PheryResponse
	 */
	public function text($content, $selector = null)
	{
		if (is_array($content))
		{
			$content = join("\n", $content);
		}

		return $this->cmd('text', array(
			self::typecast($content, true, true)
		), $selector);
	}

	/**
	 * Compile a script and call it on-the-fly.
	 * There is a closure on the executed function, so
	 * to reach out global variables, you need to use window.variable
	 * Warning: calling this function will reset the selector jQuery selector previously set
	 *
	 * @param string|array $script Script content. If provided an array, it will be joined with \n
	 * <pre>
	 * PheryResponse::factory()
	 * ->script(array("if (confirm('Are you really sure?')) $('*').remove()"));
	 * </pre>
	 *
	 * @return PheryResponse
	 */
	public function script($script)
	{
		$this->last_selector = null;

		if (is_array($script))
		{
			$script = join("\n", $script);
		}

		return $this->cmd(3, array(
			$script
		));
	}

	/**
	 * Access a global object path
	 *
	 * @param string|string[] $namespace     For accessing objects, like $.namespace.function() or
	 *                                    document.href. if you want to access a global variable,
	 *                                    use array('object','property'). You may use a mix of getter/setter
	 *                                    to apply a global value to a variable
	 *
	 * <pre>
	 * PheryResponse::factory()->set_var(array('obj','newproperty'),
	 *      PheryResponse::factory()->path(array('other_obj','enabled'))
	 * );
	 * </pre>
	 *
	 * @return PheryResponse
	 */
	public function path($namespace)
	{
		$this->last_selector = '+';

		return $this->cmd(!is_array($namespace) ? array($namespace) : $namespace);
	}
	/**
	 * Render a view to the container previously specified
	 *
	 * @param string $html HTML to be replaced in the container
	 * @param array  $data Array of data to pass to the before/after functions set on Phery.view
	 *
	 * @see Phery.view() on JS
	 * @return PheryResponse
	 */
	public function render_view($html, $data = array())
	{
		$this->last_selector = null;

		if (is_array($html))
		{
			$html = join("\n", $html);
		}

		return $this->cmd(5, array(
			self::typecast($html, true, true),
			$data
		));
	}

	/**
	 * Creates a redirect
	 *
	 * @param string        $url      Complete url with http:// (according to W3 http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.30)
	 * @param bool|string   $view     Internal means that phery will cancel the
	 *                                current DOM manipulation and commands and will issue another
	 *                                phery.remote to the location in url, useful if your PHP code is
	 *                                issuing redirects but you are using AJAX views.
	 *                                Passing false will issue a browser redirect
	 *
	 * @return PheryResponse
	 */
	public function redirect($url, $view = false)
	{
		if ($view === false && !preg_match('#https?\://#i', $url))
		{
			$_url = (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'];

			if (!empty($url[0]) && ($url[0] === '/' || $url[0] === '?'))
			{
				$_url .= str_replace('?'.$_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
			}
			elseif ($url[0] !== '/')
			{
				$_url .= '/';
			}
			$_url .= $url;
		}
		else
		{
			$_url = $url;
		}

		$this->last_selector = null;

		if ($view !== false)
		{
			return $this->reset_response()->cmd(8, array(
				$_url,
				$view
			));
		}
		else
		{
			return $this->cmd(8, array(
				$_url,
				false
			));
		}
	}

	/**
	 * Prepend string/HTML to target(s)
	 *
	 * @param string $content  Content to be prepended to the selected element
	 * @param string $selector [optional] Optional jquery selector string
	 *
	 * @return PheryResponse
	 */
	public function prepend($content, $selector = null)
	{
		if (is_array($content))
		{
			$content = join("\n", $content);
		}

		return $this->cmd('prepend', array(
			self::typecast($content, true, true)
		), $selector);
	}

	/**
	 * Clear all the selectors and commands in the current response.
	 * @return PheryResponse
	 */
	public function reset_response()
	{
		$this->data = array();
		$this->last_selector = null;
		$this->merged = array();
		return $this;
	}

	/**
	 * Append string/HTML to target(s)
	 *
	 * @param string $content  Content to be appended to the selected element
	 * @param string $selector [optional] Optional jquery selector string
	 *
	 * @return PheryResponse
	 */
	public function append($content, $selector = null)
	{
		if (is_array($content))
		{
			$content = join("\n", $content);
		}

		return $this->cmd('append', array(
			self::typecast($content, true, true)
		), $selector);
	}

	/**
	 * Magically map to any additional jQuery function.
	 * To reach this magically called functions, the jquery() selector must be called prior
	 * to any jquery specific call
	 *
	 * @param $name
	 * @param $arguments
	 *
	 * @see PheryResponse::jquery()
	 * @see PheryResponse::j()
	 * @return PheryResponse
	 */
	public function __call($name, $arguments)
	{
		if ($this->last_selector)
		{
			if (count($arguments))
			{
				foreach ($arguments as $_name => $argument)
				{
					$arguments[$_name] = self::typecast($argument, true, true);
				}

				$this->cmd($name, array(
					$arguments
				));
			}
			else
			{
				$this->cmd($name);
			}

		}

		return $this;
	}

	/**
	 * Convert, to a maximum depth, nested responses, and typecast int properly
	 *
	 * @param mixed $argument The value
	 * @param bool $toString Call class __toString() if possible, and typecast int correctly
	 * @param bool $nested Should it look for nested arrays and classes?
	 * @param int $depth Max depth
	 * @return mixed
	 */
	protected static function typecast($argument, $toString = true, $nested = false, $depth = 4)
	{
		if ($nested)
		{
			$depth--;
			if ($argument instanceof PheryResponse)
			{
				$argument = array('PR' => $argument->process_merged());
			}
			elseif ($argument instanceof PheryFunction)
			{
				$argument = array('PF' => $argument->compile());
			}
			elseif ($depth > 0 && is_array($argument))
			{
				foreach ($argument as $name => $arg) {
					$argument[$name] = self::typecast($arg, $toString, $nested, $depth);
				}
			}
		}

		if ($toString && !empty($argument))
		{
			if (is_string($argument) && ctype_digit($argument))
			{
				$argument = (int)$argument;
			}
			elseif (is_object($argument))
			{
				$rc = new ReflectionClass(get_class($argument));
				if ($rc->hasMethod('__toString'))
				{
					$argument = "{$argument}";
				}
				else
				{
					$argument = json_decode(json_encode($argument), true);
				}
			}
		}

		return $argument;
	}

	/**
	 * Process merged responses
	 * @return array
	 */
	protected function process_merged()
	{
		$data = $this->data;

		if (empty($data) && $this->last_selector !== null)
		{
			$data[$this->last_selector] = array();
		}

		foreach ($this->merged as $r)
		{
			$data = array_merge_recursive($data, $r);
		}

		return $data;
	}

	/**
	 * Return the JSON encoded data
	 * @return string
	 */
	public function render()
	{
		return json_encode((object)$this->process_merged());
	}

	/**
	 * Return the JSON encoded data
	 * if the object is typecasted as a string
	 * @return string
	 */
	public function __toString()
	{
		return $this->render();
	}

	/**
	 * Initialize the instance from a serialized state
	 *
	 * @param string $serialized
	 * @throws PheryException
	 * @return PheryResponse
	 */
	public function unserialize($serialized)
	{
		$obj = json_decode($serialized, true);
		if ($obj && is_array($obj) && json_last_error() === JSON_ERROR_NONE)
		{
			$this->exchangeArray($obj['this']);
			$this->data = (array)$obj['data'];
			$this->set_response_name((string)$obj['name']);
			$this->merged = (array)$obj['merged'];
		}
		else
		{
			throw new PheryException('Invalid data passed to unserialize');
		}
		return $this;
	}

	/**
	 * Serialize the response in JSON
	 * @return string|bool
	 */
	public function serialize()
	{
		return json_encode(array(
			'data' => $this->data,
			'this' => $this->getArrayCopy(),
			'name' => $this->name,
			'merged' => $this->merged,
		));
	}
}

/**
 * Create an anonymous function for use on Javascript callbacks
 * @package    Phery
 */
class PheryFunction {

	/**
	 * Parameters that will be replaced inside the response
	 * @var array
	 */
	protected $parameters = array();
	/**
	 * The function string itself
	 * @var array
	 */
	protected $value = null;

	/**
	 * Sets new raw parameter to be passed, that will be eval'ed.
	 *
	 * $raw = new PheryFunction('function($val){ return $val; }');
	 *
	 * @param   string|array  $value      Raw function string. If you pass an array,
	 *                                    it will be joined with a line break
	 * @param   array         $parameters You can pass parameters that will be replaced
	 *                                    in the $value when compiling
	 */
	public function __construct($value, $parameters = array())
	{
		if (!empty($value))
		{
			// Set the expression string
			if (is_array($value))
			{
				$this->value = join("\n", $value);
			}
			elseif (is_string($value))
			{
				$this->value = $value;
			}
			$this->parameters = $parameters;
		}
	}

	/**
	 * Bind a variable to a parameter.
	 *
	 * @param   string  $param  parameter key to replace
	 * @param   mixed   $var    variable to use
	 * @return  PheryFunction
	 */
	public function bind($param, & $var)
	{
		$this->parameters[$param] =& $var;

		return $this;
	}

	/**
	 * Set the value of a parameter.
	 *
	 * @param   string  $param  parameter key to replace
	 * @param   mixed   $value  value to use
	 * @return  PheryFunction
	 */
	public function param($param, $value)
	{
		$this->parameters[$param] = $value;

		return $this;
	}

	/**
	 * Add multiple parameter values.
	 *
	 * @param   array   $params list of parameter values
	 * @return  PheryFunction
	 */
	public function parameters(array $params)
	{
		$this->parameters = $params + $this->parameters;

		return $this;
	}

	/**
	 * Get the value as a string.
	 *
	 * @return  string
	 */
	public function value()
	{
		return (string) $this->value;
	}

	/**
	 * Return the value of the expression as a string.
	 *
	 *     echo $expression;
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return $this->value();
	}

	/**
	 * Compile function and return it. Replaces any parameters with
	 * their given values.
	 *
	 * @return  string
	 */
	public function compile()
	{
		$value = $this->value();

		if ( ! empty($this->parameters))
		{
			$params = $this->parameters;
			$value = strtr($value, $params);
		}

		return $value;
	}

	/**
	 * Static instantation for PheryFunction
	 *
	 * @param string|array $value
	 * @param array        $parameters
	 *
	 * @return PheryFunction
	 */
	public static function factory($value, $parameters = array())
	{
		return new PheryFunction($value, $parameters);
	}
}

/**
 * Exception class for Phery specific exceptions
 * @package    Phery
 */
class PheryException extends Exception {

}