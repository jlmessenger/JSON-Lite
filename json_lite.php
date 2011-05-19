<?php
/**
 * JSON Lite
 *
 * A small and fast PHP JSON library
 *
 * @package   JSON-Lite
 * @version   1.0
 * @author    Jeremy Messenger <jlmessengertech+github@gmail.com>
 * @copyright 2011 Jeremy Messenger
 * @license   GPL
 * @link      http://jlmessenger.com
 */

/**
 * Class that provides JSON creation and parsing along with JSON-RPC helpers
 * @package   JSON-Lite
 */
class json_lite
{
	/**
	 * Creates a JSON string in simple JSON RPC format
	 * @param string $method Remote method to execute
	 * @param array $params Array of parameters for the method
	 * @return string A JSON-RPC string
	 */
	function encode_json_rpc($method, $params)
	{
		return json_lite::to_json(array(
				'method' => $method,
				'params' => $params
			));
	}
	
	/**
	 * Execute a JSON-PRC call on the matching method in the supplied class
	 * @param string $json A JSON-RPC string
	 * @param object $class A class with methods available to for RPC execution
	 * @param string $method_prefix A prefix to add to each RPC method (to limit what can be executed)
	 * @return mixed|null The result of the executed method
	 */
	function exec_json_rpc($json, $class, $method_prefix = '', &$rpc_values)
	{
		$rpc_values = json_lite::from_json($json);
		
		if (is_array($rpc_values)
			&& array_key_exists('method', $rpc_values)
			&& array_key_exists('params', $rpc_values)
			&& is_array($rpc_values['params']))
		{
			$method = $method_prefix.$rpc_values['method'];
			if (method_exists($class, $method))
			{
				return call_user_func_array(array($class, $method), $rpc_values['params']);
			}
		}
		return NULL;
	}
	
	/**
	 * Convert a PHP array or value into a JSON string
	 * @param mixed $value PHP array or value
	 * @return string A JSON string
	 */
	function to_json($value)
	{
		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}
		elseif (is_null($value))
		{
			return 'null';
		}
		elseif (is_numeric($value))
		{
			return (string)$value;
		}
		elseif (is_array($value))
		{
			if (count($value) == 0 || array_keys($value) === range(0, count($value)-1))
			{
				// array is 0 based sequential
				$items = array();
				foreach ($value as $val)
				{
					$items[] = json_lite::to_json($val);
				}
				return '['.implode(',', $items).']';
			}
			else
			{
				// array is associative
				$items = array();
				foreach ($value as $key => $val)
				{
					$items[] = json_lite::_json_str($key).':'.json_lite::to_json($val);
				}
				return '{'.implode(',', $items).'}';
			}
		}
		else
		{
			return json_lite::_json_str($value);
		}
	}
	
	/**
	 * Convert PHP string to a valid JSON string
	 * Additionally encodes , [ ] { } characters in hex format
	 */
	function _json_str($value)
	{
		$value = (string)$value;
		$json = '"';
		for ($i = 0; $i < strlen($value); $i++)
		{
			$o = ord($value{$i});
			switch ($o)
			{
				case 8: $json .= '\\b'; break; // backspace
				case 9: $json .= '\\t'; break; // tab
				case 10: $json .= '\\n'; break; // linefeed
				case 12: $json .= '\\f'; break; // formfeed
				case 13: $json .= '\\r'; break; // carriage return
				case 34: // double-quote
				case 47: // forward-slash
				case 92: // back-slash
					$json .= '\\'.$value{$i}; break;
				case 44: $json .= '\\u002c'; break; // comma
				case 91: $json .= '\\u005b'; break; // open-bracket
				case 93: $json .= '\\u005d'; break; // close-bracket
				case 123: $json .= '\\u007b'; break; // open-curly-bracket
				case 125: $json .= '\\u007d'; break; // close-curly-bracket
				default:
					if ($o < 32 || $o > 126)
					{
						$json .= '\\u'.str_pad(dechex($o), 4, '0', STR_PAD_LEFT);
					}
					else
					{
						$json .= $value{$i};
					}
			}
		}
		$json .= '"';
		return $json;
	}
	
	/**
	 * Convert a JSON string into native PHP arrays/values
	 * @param string $json A JSON string (created by to_json)
	 * @return mixed PHP array or value
	 */
	function from_json(&$json, $try_php_json = TRUE)
	{
		if ($try_php_json && extension_loaded('json'))
		{
			return json_decode($json, TRUE);
		}
		else
		{
			return json_lite_node::parse_json($json);
		}
	}
}

/**
 * Class for recursively parsing JSON nodes
 * @package   JSON-Lite
 */
class json_lite_node
{
	/**
	 * Position of start boundary character within JSON string for this node
	 * @var integer
	 */
	var $start_str_pos;
	/**
	 * Position of end boundary character within JSON string for this node
	 * @var integer
	 */
	var $end_str_pos;
	/**
	 * If this JSON node is { } then TRUE, if [ ] then FALSE
	 * @var boolean
	 */
	var $is_assoc;
	/**
	 * Final parsed value of this node
	 * @var array
	 */
	var $value = array();
	/**
	 * Array of sub-nodes within this node
	 * @var array
	 */
	var $sub_nodes = array();
	
	/**
	 * Use json_lite_node::parse_json()
	 * Parse a JSON string into php array
	 * @param string $json JSON string
	 * @return array PHP representation of JSON data
	 */
	function parse_json(&$json)
	{
		// find all { } [ ] JSON array/object boundaries
		preg_match_all('/[\[\]\{\}]/', $json, $boundaries, PREG_OFFSET_CAPTURE);
		
		// recursivly populate the nodes
		$b_index = 0;
		$nodes = new json_lite_node($json, $boundaries[0], $b_index);
		return $nodes->value;
	}
	
	/**
	 * Don't use directly, Use json_lite_node::parse_json()
	 * Creates a JSON node
	 * @param string $json JSON string
	 * @param array $boundaries Array of { } [ ] boundary points from json_lite_node::parse_json
	 * @param integer $b_index The starting index of the boundaries array to process
	 */
	function json_lite_node(&$json, &$boundaries, &$b_index)
	{
		$this->start_str_pos = $boundaries[$b_index][1];
		$this->is_assoc = $boundaries[$b_index][0] == '{';
		
		for ($i = $b_index + 1; $i < count($boundaries); $i++)
		{
			switch ($boundaries[$i][0])
			{
				case '{':
				case '[':
					// begin processing of a nested-node
					// (note: $i is passed by reference so that subnodes
					//        are skipped upon returning from the sub-node)
					$this->sub_nodes[] = new json_lite_node($json, $boundaries, $i);
					break;
				case '}':
					if ($this->is_assoc)
					{
						// complete processing of the current associative node
						$b_index = $i; // ending b_index is updated for reference by the parent
						$this->close_node($json, $boundaries, $i);
						break 2;
					}
				case ']':
					if (!$this->is_assoc)
					{
						// complete processing of the current sequential node
						$b_index = $i; // ending b_index is updated for reference by the parent
						$this->close_node($json, $boundaries, $i);
						break 2;
					}
			}
		}
	}
	
	/**
	 * Closing the node populates the final data points
	 * including the nodes value array
	 * @param string $json JSON string
	 * @param array $boundaries Array of { } [ ] boundary points from json_lite_node::parse_json
	 * @param integer $b_index The ending index of the boundaries array being closed
	 */
	function close_node(&$json, &$boundaries, $b_index)
	{
		$this->end_b_index = $b_index;
		$this->end_str_pos = $boundaries[$b_index][1];
		
		if (count($this->sub_nodes))
		{
			// deal with sub-node values
			// (note: each subnode is removed and replaced with a string in the format '&#'
			//        which is then pupulated with that sub-nodes value later)
			$content = substr($json, $this->start_str_pos + 1, $this->sub_nodes[0]->start_str_pos - $this->start_str_pos - 1);
			for ($j = 0; $j < count($this->sub_nodes) - 1; $j++)
			{
				$content .= "&$j";
				$content .= substr($json, $this->sub_nodes[$j]->end_str_pos + 1, $this->sub_nodes[$j+1]->start_str_pos - $this->sub_nodes[$j]->end_str_pos - 1);
			}
			$content .= "&$j";
			$content .= substr($json, $this->sub_nodes[$j]->end_str_pos + 1, $this->end_str_pos - $this->sub_nodes[$j]->end_str_pos - 1);
		}
		else
		{
			// no sub-nodes, parse entire string as usual
			$content = substr($json, $this->start_str_pos + 1, $this->end_str_pos - $this->start_str_pos - 1);
		}
		
		// items in JSON are separated by commas
		$segments = explode(',', $content);
		if (count($segments) == 1 && trim($segments[0]) == '')
		{
			return; // empty array
		}
		
		if ($this->is_assoc)
		{
			// associative array nodes have keys: value format
			foreach ($segments as $segment)
			{
				list($key, $val) = explode(':', $segment, 2);
				$key = $this->_node_str_value(trim($key));
				$this->value[$key] = $this->_node_value(trim($val));
			}
		}
		else
		{
			// sequential array node are just lists of values
			foreach ($segments as $segment)
			{
				$this->value[] = $this->_node_value(trim($segment));
			}
		}
	}
	
	/**
	 * Convert node value to native PHP type
	 * @param string $json JSON number, string, boolean, null, or sub-node reference
	 * @return mixed PHP value
	 */
	function _node_value($json)
	{
		// fix simple types
		switch ($json)
		{
			case 'true': return TRUE;
			case 'false': return FALSE;
			case 'null': return NULL;
		}
		if (is_numeric($json))
		{
			// rely on php type juggling for numbers
			return 0 + $json;
		}
		switch ($json{0})
		{
			case '"':
				// parse string types
				return $this->_node_str_value($json);
			case '&':
				// parse sub-node references
				$sub_key = substr($json, 1);
				if (array_key_exists($sub_key, $this->sub_nodes))
					return $this->sub_nodes[$sub_key]->value;
		}
		return ''; // unknown defaults to empty string
	}
	
	/**
	 * Convert string node value to native PHP string
	 * @param string $json JSON string
	 * @return string PHP string
	 */
	function _node_str_value($json)
	{
		$str = '';
		$bs = FALSE; // backslashed state
		for ($i = 1; $i < strlen($json) - 1; $i++)
		{
			if ($bs)
			{
				// in backslash mode
				switch ($json{$i})
				{
					case 'b': $str .= chr(8); break; // backspace
					case 't': $str .= chr(9); break; // tab
					case 'n': $str .= chr(10); break; // linefeed
					case 'f': $str .= chr(12); break; // formfeed
					case 'r': $str .= chr(13); break; // carriage return
					case '"': // double-quote
					case '/': // forward-slash
					case '\\': // back-slash
						$str .= $json{$i}; break;
					case 'u': // hex mode
						$h = substr($json, $i+1, 4);
						$i += 4;
						$str .= chr(hexdec($h));
						break;
					default:
						// unknown escape char
						// return literal
						$str .= $json{$i};
				}
				$bs = FALSE;
			}
			elseif ($json{$i} == '\\')
			{
				$bs = TRUE; // put in backslash mode
			}
			else
			{
				$str .= $json{$i};
			}
		}
		return utf8_encode($str);
	}
}
