<?php

namespace Shortcode;

class Engine {

	const FIELD_ALIAS_SEPARATOR = '___';
	protected $add_global = true;
	
	protected $tpl_engine;
	protected $tpl;
	
	protected $spec_class;
	protected $spec_params_class;
	
	protected $specs = [];
	
	protected $data_params;
	
	protected $_data = [];	
	
	private $_reflection_class;

	protected $_has_no_results = false;

	protected $_external_data_processor = [];
	protected $_external_data_getters = [];


	public function  __construct(bool $do_init = true) {		
		if($do_init) $this->initTemplateEngine();
	}
	
	public function initTemplateEngine(): Engine {
		$this->tpl_engine = new \Mustache_Engine([
			'cache' => DIR_CACHE . 'mustashe',
		    'escape' => function($value) {
				return $value;
			},
		]); 
		$this->_reflection_class = new \ReflectionClass($this);		
		return $this;
	}
	
	public function returnData() : array {
		return $this->_data;
	}
	
	private function _get_key_alias(array &$data_params) : string {
		if(empty($data_params['key_aliases'])) return '';

		if(!is_array($data_params['key_aliases'])) $data_params['key_aliases'] = [$data_params['key_aliases'],];
		$sz = sizeof($data_params['key_aliases']);
		if(!$sz) return '';

		if(!isset($data_params['postprocess'])) $data_params['postprocess'] = [];
		
		$do_flatten = false;
		foreach($data_params['key_aliases'] as $i => $_v) {
			if(empty($this->specs[$_v])) {
				unset($data_params['key_aliases'][$i]);
				if($do_flatten === false) $do_flatten = true;
			}
		}

		if($do_flatten === true) {
			$data_params['key_aliases'] = array_values($data_params['key_aliases']);
			$sz = sizeof($data_params['key_aliases']);
			if(!$sz) return '';
		}
		
		return (isset($data_params['key_alias']) ? $data_params['key_alias'] : ($sz === 1 ? $data_params['key_aliases'][0] : ''));
	}
	
	
	/**
	 * Fetches data for the template engine using the specified data params.
	 * 
	 * @param array $input_data An array of data arrays, indexed by the data params.
	 * with indexes correspondent to $this->data_params indexes: 
	 * $input_data[$i] for $this->data_params[$i]
	 * Used if needed to use external data instead of getting it inside this function
	 * with $this->_get_data()
	 * @return Engine The calling object.
	 * @throws None
	 */
	public function getData(array &$input_data = []): Engine {		
		$this->_data = [];				
		
		foreach($this->data_params as $dp_index => $dp) {
			$key_alias = $this->_get_key_alias($dp);
			if($key_alias === '') continue;
			
			$plural = $this->specs[$dp['key_aliases'][0]]->getPlural();
			if($key_alias && isset($this->_external_data_getters[$key_alias])) {
				$_data = $this->_get_data_external($key_alias, $dp, (bool) $plural);
			} else if($key_alias && $this->_method_exists('_get_data_' . $key_alias)) {
				$_data = $this->{'_get_data_' . $key_alias}($dp, (bool) $plural);
			} else {
				if(empty($input_data[$dp_index]) || !is_array($input_data[$dp_index])) {
					$_data = $this->_get_data($dp, (bool) $plural);
				} else {
					$_key_aliases_keys = array_fill(0, sizeof($dp['key_aliases']), array_flip($dp['key_aliases']));					
					$_data = $this->_process_fields($input_data[$dp_index], $dp, $_key_aliases_keys, (bool) $plural);
				}
			}			

			if($plural) {
				$_out =& self::_array_add_keys($this->_data, explode('.', $plural));
				$_out = array_merge($_out, $_data);
			} else {
				foreach($_data as $_k => $_v) {
					$this->_data[$_k] = $_v;
				}
			}			
		}
		$this->_process_data();
		return $this;
	}

	/**
	 * Sets external data getter for the specified key alias.
	 * If it is set, it will be used in _get_data_external(), which is called in getData(). 
	 * The function / method will be passed:
	 * $data_params {@see Engine::getData $dp in getData()}
	 * $plural bool {@see Engine::getData $plural in getData()}
	 *
	 * @param string $key_alias The key alias for which the data getter will be set.
	 * @param object $obj The object with the method that will be called to get the data.
	 * @param string $method The method name in the object that will be called to get the data.
	 * If not set, the object must be a callable function.
	 * @return Engine The calling object.
	 */
	public function setExternalDataGetter(string $key_alias, $obj, string $method = null) : Engine {
		if(!$method) {
			if(!$obj || !is_callable($obj)) return $this;
			$this->_external_data_getters[$key_alias] = [
				'func' => $obj,
			];			
		} else {
			if(!is_object($obj) || !method_exists($obj, $method)) return $this;
			$this->_external_data_getters[$key_alias] = [
				'obj' => $obj,
				'method' => $method,
			];						
		}
		return 	$this;
	}	
	
	/**
	 * Sets external data processor.
	 * If it is set, it will be used in _process_data(), which is called in getData(). 
	 * The function / method will be passed:
	 * $this->_data {@see Engine::getData getData() above $this->_process_data() call} and 
	 * $data arguments (passed by ref to this function)
	 *
	 * @param object $obj The object with the method that will be called to process the data.
	 * @param string $method The method name in the object that will be called to process the data.
	 * If not set, the object must be a callable function.
	 * @param array &$data The data (optional) to be passed to the called 
	 * method / function in _process_data
	 * @return Engine The calling object.
	 */
	public function setExternalDataProcessor($obj, string $method = null, array &$data = null) : Engine{
		if(!$method) {
			if(!$obj || !is_callable($obj)) return $this;
			$this->_external_data_processor = [
				'func' => $obj,
			];			
		} else {
			if(!is_object($obj) || !method_exists($obj, $method)) return $this;
			$this->_external_data_processor = [
				'obj' => $obj,
				'method' => $method,
			];						
		}
		if($data) $this->_external_data_processor['data'] =& $data;	
		return $this;
	}
	
	/**
	 * Processes $this->data if external data processor is set 
	 * @see Engine::setExternalDataProcessor
	 * 
	 * The data processor function / method is passed:
	 * $this->_data {@see Engine::getData getData() above $this->_process_data() call} and 
	 * $this->_external_data_processor['data'] (it's a $data argument that might have been
	 * passed to setExternalDataProcessor
	 */
	protected function _process_data() {
		if(!$this->_external_data_processor) return;
		if(isset($this->_external_data_processor['data'])) {
			$data =& $this->_external_data_processor['data'];
		} else {
			$data = null;
		}
		if(isset($this->_external_data_processor['func'])) {
			$this->_data = $this->_external_data_processor['func']($this->_data, $data);
		} else if(isset($this->_external_data_processor['obj'])) {
			$this->_data = $this->_external_data_processor['obj']->{$this->_external_data_processor['method']}($this->_data, $data);
		}
	}
	

	/**
	 * $this->_get_data() version for 'global' key. Can be overridden in subclasses, 
	 * if the 'global' key exists and needs custom processing.
	 * 
	 * @param array $data_params An array of data params.
	 * @param bool $plural If true, the data will be expected to be array of arrays.
	 * @return array The fetched data.
	 */
	protected function _get_data_global(array $data_params, bool $plural = false) : array {
		
		return [];
	}
	
	protected function _process_fields(&$rr, array &$data_params, array $key_aliases_keys, bool $plural = null) : array  {
		if(!$rr) return [];
		if(is_null($plural)) $plural = !self::_array_is_assoc($rr);
		if($plural) {
			$out = [];
			foreach($rr as $r) {
				$_ar = $key_aliases_keys;
				foreach($r as $k => $v) {
					$_kAr = explode(self::FIELD_ALIAS_SEPARATOR, $k);
					if(sizeof($_kAr) < 2 || !isset($_ar[$_kAr[0]])) continue;
					$_ar[$_kAr[0]][$_kAr[1]] = $this->_postprocess_field($data_params['postprocess'], $_kAr[0], $_kAr[1], $v);
				}	
				$out[] = $_ar;
			}
		} else {
			$out = $key_aliases_keys;
			foreach($rr as $k => $v) {				
				$_kAr = explode(self::FIELD_ALIAS_SEPARATOR, $k);
				if(sizeof($_kAr) < 2 || !isset($out[$_kAr[0]])) continue;				
				$out[$_kAr[0]][$_kAr[1]] = $this->_postprocess_field($data_params['postprocess'], $_kAr[0], $_kAr[1], $v);
			}
		}	
		return $out;
	}
	
	protected function _get_data_external(string $key_alias, array $data_params, bool $plural = false) : array {
		if(!isset($this->_external_data_getters[$key_alias])) return [];
		if(isset($this->_external_data_getters[$key_alias]['func'])) return $this->_external_data_getters[$key_alias]['func']($data_params, $plural);
		if(isset($this->_external_data_getters[$key_alias]['obj'])) return $this->_external_data_getters[$key_alias]['obj']->{$this->_external_data_getters[$key_alias]['method']}($data_params, $plural);		
		return [];		
	}
	
	protected function _count_data(array $data_params) : int {
		$count_array = $this->_get_data($data_params, false, true);
		return (isset($count_array['count']) ? $count_array['count'] : 0);
	}
	
	protected function _get_data(array $data_params, bool $plural = false, bool $count_only = false) : array {
		$query_data = [];
		$fields = [];
		//$fields_ka_map = [];
		$out = [];	
		$key_aliases_keys = [];
		foreach($data_params['key_aliases'] as $ka) {
			if(empty($this->specs[$ka])) continue;
			$_qd = $this->specs[$ka]->getQuery();
			if($_qd && !empty($_qd['fields'])) {
				$query_data[$ka] = $_qd;
				$fields[] =  implode(', ', $_qd['fields']);
				$key_aliases_keys[$ka] = [];
			}
		}

		if(!$query_data) return [];		
		if(!isset($data_params['on'])) $data_params['on'] = [];
		if(!isset($data_params['on_pred'])) $data_params['on_pred'] = [];
	
		$q = "SELECT " . ($count_only ? "COUNT(*) AS _count" : implode(', ', $fields)) . " FROM ";
		
		$froms = [];
		$is_join = !empty($data_params['is_join']);
		
		$whereAr = [];
		$dp_where = [];
		if(isset($data_params['where'])) {
			if(is_string($data_params['where'])) {
				$whereAr[] = $data_params['where'];			
			} else {
				$dp_where = $data_params['where'];
			}
		}
		
		foreach($data_params['key_aliases'] as $ka) {
			if(empty($this->specs[$ka])) continue;
			$spec = $this->specs[$ka];
			$_s = "`" . $spec->getTable() . "` `" . $spec->getTableAlias() . "`";
			if(isset($dp_where[$ka])) $whereAr[] = $dp_where[$ka];

			if(!empty($data_params['on'][$ka])) {
				$skip = false;
				if(isset($data_params['on_pred'][$ka])) {
					foreach($data_params['on_pred'][$ka] as $_ka) {
						if(empty($this->specs[$_ka])) {
							$skip = true;
							break;
						}
					}
				}
				if(!$skip) {
					if($is_join) {
						$_s .= " ON (" . $data_params['on'][$ka] . ")";
					} else {
						$whereAr[] = "(" . $data_params['on'][$ka] . ")";
					}								
				}
			}
			$froms[] = $_s;
			if(!empty($query_data[$ka]['joins'])) {
				foreach($query_data[$ka]['joins'] as $_join) {
					$_s = "`" . $_join['table'] . "` `" . $_join['table_alias'] . "`";
					if($_join['on']) {
						if($is_join) {
							$_s .= " ON (" . $_join['on'] . ")";
						} else {
							$whereAr[] = "(" . $_join['on'] . ")";
						}
					}
					$froms[] = $_s;
				}
			}
		}
		if(!$froms) return [];
		$q .= implode($is_join ? " LEFT JOIN " : ", ", $froms) . " ";

		if($whereAr) $q .= " WHERE " . implode(' AND ', $whereAr);
		if(!$count_only) {
			if(!empty($data_params['order_by'])) $q .= " ORDER BY " . $data_params['order_by'];
			if(!empty($data_params['limit'])) $q .= " LIMIT " . $data_params['limit'];
		}

		$res = $this->db->query($q);
		if($count_only) return [
			'count' => ($res->num_rows && $res->row['_count'] ? (int) $res->row['_count'] : 0),
		];
		
		$res = $plural ? $res->rows : $res->row;
		
		if(!$res && $this->_has_no_results === false) $this->_has_no_results = true;
		
		return $this->_process_fields($res, $data_params, $key_aliases_keys, $plural);
	}
	
	/**
	 * Returns true if no results were found in the query during 
	 * the last $this->_get_data() call
	 *
	 * @return bool
	 */
	public function hasNoResults() : bool {
		return $this->_has_no_results;
	}
	
	protected function _postprocess_field(array &$data_params_postprocess, string $key_alias, string $field, $value) {
		if(isset($data_params_postprocess[$field]) && is_callable($data_params_postprocess[$field])) {
			$func = $data_params_postprocess[$field];
		} else {
			$func = $this->specs[$key_alias]->getPostprocess($field);
		}
		return (!is_null($func) ? $func($value, $this) : $value);
	}

	/**
	 * Sets $this->data_params for $this->getData() method.
	 * Accepted params structure:
	 * 
	 * @param array $data_params An array of arrays with keys:
	 * 'key_aliases': The only Required key. An array with one or more key aliases. 
	 * Several key aliases can be grouped together as a 
	 * single key (will be 'key_alias' value, or the first member of 'key_aliases' array)
	 * in the resulting $this->_data.
	 * 
	 * 'key_alias': Optional string. 
	 * 
	 * 'postprocess': array of ['field' => callable] to be used in 
	 * $this->_postprocess_field() (called inside $this->getData() - $this->_process_fields	
	 * ()). Callable should accept the field's value, and $this (Engine object).
	 *
	 * Other params are being used for query building in _get_data(). 
	 * @see Engine::_get_data for usage
	 * 'on': Array [$key_alias => string condition]
	 * 'on_pred': Array[$key_alias => ref to another $key_alias]
	 * 'where': String condition or Array[$key_alias => string condition]
	 * 'is_join': bool
	 * 'order_by': string
	 * 'limit': int or string (e.g. "0, 10")
	 *  
	 * If _get_data() for this key_alias is a custom method (in the form of 
	 * '_get_data_' . $key_alias), or an external data getter, 
	 * more keys can be passed and used.
	 */
	public function setDataParams(array $params): Engine {
		$this->data_params = $params;
		return $this;
	}	
	
	/**
	 * Creates required instances of Spec class and adds them to $this->specs array.
	 * Each spec is created with given parameters and validated.
	 * If spec is valid, it is added to $this->specs array.
	 * If $add_global is true, a spec with global key is added to array.
	 * 
	 * @param array $params_multi An array of arrays with spec parameters.
	 * @see SpecParam class for definition
	 * @param bool $add_global (optional) If true, overrides $this->add_global to add a 
	 * spec with 'global' key (if it doesn't exist).	 
	 * @return Engine The calling object.
	 */
	public function initSpecs(array $params_multi, bool $add_global = null): Engine {
		if(is_null($add_global)) $add_global = $this->add_global;
		if($add_global) {
			foreach($params_multi as $_params) {
				if(isset($_params['key_alias']) && $_params['key_alias'] === 'global') {
					$add_global = false;
					break;
				}
			}
		}
		if($add_global) $params_multi[] = ['key' => 'global', 'key_alias' => 'global'];
		foreach($params_multi as $params) {
			if($this->spec_params_class) {
				$spec_params = new $this->spec_params_class();
			} else {
				$spec_params = new SpecParams();
			}
			if($spec_params->init($params)) {
				$key = $spec_params->get('key');
				if(!$key) continue;
	
				$spec = $this->initSpecClass($spec_params);
				if($spec->isValid()) $this->specs[$spec->getKeyAlias()] = $spec;
			}
		}
		return $this;
	}

	public function setTemplate(string $template): Engine {
		$this->tpl = $template;
		return $this;
	}

	/**
	 * Returns an associative array of field descriptions.
	 * The keys are spec key aliases and values are arrays of field descriptions.
	 * 
	 * Descriptions for any $key_alias will be overriden if method 
	 * $this->{'_get_field_descriptions_' . $key_alias} exists.
	 *
	 * @return array
	 */
	public function getFieldDescriptions() : array {
		$out = [];
		foreach($this->specs as $k => $spec) {
			$_fields = $spec->getDescriptions();
			if($this->_method_exists('_get_field_descriptions_' . $k)) $_fields = $this->{'_get_field_descriptions_' . $k}($_fields);
			$plural = $spec->getPlural();
			
			if($plural) {
				$_out =& self::_array_add_keys($out, explode('.', $plural));
				$_out['__is_array'] = true;
				$_out[$k] = $_fields;
			} else {
				$out[$k] = $_fields;
			}
		}
		$this->_get_field_descriptions($out);
		return $out;
	}	
	
	/**
	 * This method is called in getFieldDescriptions to override or postprocess the 
	 * result of field descriptions.
	 * It is intended to be used in the extended classes to modify the field descriptions.
	 *
	 * @param array &$out The field descriptions array to be modified.
	 * @return void
	 */
	protected function _get_field_descriptions(array &$out) {

	}

	public function render(): string {
		return $this->tpl_engine->render($this->tpl, $this->_data);
	}
	
	public function reset(): Engine {		
		$this->tpl = null;	
		$this->specs = [];	
		$this->data_params = null;	
		$this->_data = [];	
		$this->_has_no_results = false;
		$this->_external_data_processor = [];
		$this->_external_data_getters = [];	
		return $this;	
	}
	
	/**
	 * Extracts fields that are being used in the template.
	 * It is used internally by {@see Engine::extractFieldsFromTemplate}.
	 * 
	 * @param string &$tpl The template string from which to extract the fields.
	 * @return array An array of extracted fields.
	 */
	protected function _extract_fields(string &$tpl = ''): array {
		if(!$tpl) $tpl =& $this->tpl;
		$_fields = $this->tpl_engine->getTokenizer()->scan($tpl);
		$_fields = array_map(function($_f) {
			return $_f['name'];
		}, 	array_filter($_fields, function($_f) {
				return isset($_f['index']);
			})
		);
		$_fields = array_map(function($_f) {
			$_ar = preg_split("/[^\w\.]+/", $_f);
			return $_ar[0];
		}, array_values(array_unique($_fields))
		);	
		return $_fields;
	}

	/**
	 * Checks which fields in the given template or from the given array that are 
	 * NOT present in the specs.
	 * The method is used internally to filter out non-present fields.
	 * 
	 * @param string $tpl The template string from which to extract the fields.
	 * @param array $fields Optional array of fields, used if $tpl is empty.
	 * @return array An array of fields that are NOT present in the specs.
	 */
	public function checkFieldsFromTemplate(string &$tpl = '', array $fields = []): array {
		if(strlen($tpl)) {
			$fields = $this->_extract_fields($tpl);
		} else if(sizeof($fields) === 0) {
			return [];
		}
		$out = [];
		$ar = [];
		$all_plurals = array_map(function($_spec) { return $_spec->getPlural();}, $this->specs);
		
		foreach($fields as $f) {
			if(in_array($f, $all_plurals) || $f === 'separator' || isset($this->specs[$f])) continue;
			$_ar = explode('.', $f);
			if(sizeof($_ar) === 2 && isset($this->specs[$_ar[0]])) {
				if(!isset($ar[$_ar[0]])) $ar[$_ar[0]] = [];
				$ar[$_ar[0]][] = $_ar[1];
			} else {
				$out[] = $f;
			}
		}
		foreach(array_keys($this->specs) as $key) {			
			if(isset($ar[$key])) {
				$_not_present = $this->specs[$key]->checkFields($ar[$key]);
				if($_not_present) {
					$_not_present = array_map(function($_f) use($key) { 
						return $key . '.' . $_f;
					}, $_not_present);
					$out = array_merge($out, $_not_present);
				}
			}			
		}
		return $out;
	}

	/**
	 * Remove fields from specs that are not being used in the template.
	 * 
	 * @param string &$tpl The template string from which to extract the fields.
	 * @param array $fields Optional array of additional fields that must be kept 
	 event if they are not present in $tpl (or $tpl is empty)
	 * @param bool $do_check Optional. If set to true, all fields that are not present in the specs will be removed from $fields with {@see Engine::checkFieldsFromTemplate}
	 * @return Engine The current Engine object, for chaining.
	 */
	public function extractFieldsFromTemplate(string &$tpl = '', array $fields = [], bool $do_check = true): Engine {
		$sz_fields = sizeof($fields);
		if(strlen($tpl)) {
			if($sz_fields === 0) {
				$fields = $this->_extract_fields($tpl);
			} else {
				$fields = array_merge($fields, $this->_extract_fields($tpl));
				$fields = array_values(array_unique($fields));
			}
		} else if($sz_fields === 0) {
			return $this;
		}
		if($do_check) {
			$_tpl = '';
			$not_present = $this->checkFieldsFromTemplate($_tpl, $fields);
			if($not_present) $fields = array_diff($fields, $not_present);
		}
		$ar = [];
		foreach($fields as $f) {
			$_ar = explode('.', $f);
			if(isset($this->specs[$_ar[0]])) {
				if(!isset($ar[$_ar[0]])) $ar[$_ar[0]] = [];
				$ar[$_ar[0]][] = $_ar[1];
			}
		}
		foreach(array_keys($this->specs) as $key) {
			if(!isset($ar[$key])) {
				unset($this->specs[$key]);
			} else {
				$this->specs[$key]->setIncludeOnly($ar[$key]);
			}			
		}
		return $this;
	}

	/**
	 * Override the fields in specs.	 
	 *
	 * @param array $fields An array of field names to be used.
	 * @return Engine The current Engine object, for chaining.
	 */
	public function setFields(array $fields): Engine {		
		$tpl = '';
		return $this->extractFieldsFromTemplate($tpl, $fields);
	}	
	
	/**
	 * Override the classes used for Spec and SpecParams.
	 * 
	 * @param string $spec_class_name (optional) The name of the class to use for
	 * Specs. If empty, the default class will be used.
	 * @param string $spec_params_class_name (optional) The name of the class to use
	 * for SpecParams. If empty, the default class will be used.
	 * @return Engine The current Engine object, for chaining.
	 */
	public function overrideSpecClasses(string $spec_class_name = '', string $spec_params_class_name = ''): Engine {
		if($spec_class_name) $this->spec_class = $spec_class_name;
		if($spec_params_class_name) $this->spec_params_class = $spec_params_class_name;
		return $this;
	}
	
	protected function initSpecClass(SpecParams $params) : Spec {
		if($this->spec_class) return new $this->spec_class($params, $this->config);
		return new Spec($params, $this->config);		
	}

	protected function _method_exists(string $method) : bool {
		return $this->_reflection_class->hasMethod($method);
	}

	protected static function _array_is_assoc(array &$ar): bool {
		return array_keys($ar) !== range(0, sizeof($ar) - 1);
	}

	protected static function &_array_add_keys(array &$target_ar, array $keys) {
		$current =& $target_ar;
		$check = true;
		foreach ($keys as $key) {
			if(!$check) {
				$current[$key] = [];
			} else {
				if (!isset($current[$key])) {
					$current[$key] = [];
					$check = false;
				} elseif (!is_array($current[$key])) {
					return $current; 
				}
			}
			$current =& $current[$key];
		}
		return $current;
	}

}

class Spec {	

	const TABLE_ALIAS_PLACEHOLDER = 'TABLE';	
	const DIR = 'shortcode_spec';
	
	private $params;
	private $_cfg;
	private $_field_keys;
	private $_filtered_field_keys;
	
	private $key;
	private $key_alias;
	
	private $include = [];
	private $exclude = [];
	
	protected $postprocess = [];
	
	
	private $config;
	private $_is_valid = false;
	
	
	public function __construct(SpecParams $params, \Config &$config) {
		$this->config =& $config;
		$cfg = $params->get('cfg_override');
		$this->init($params, ($cfg ? $cfg : []));
	}
	
	/**
	 * Initialize Spec object with SpecParams object {@see SpecParams} and 
	 * config loaded from config file SpecParams::key 
	 * {@see Spec::getQuery config structure}
	 * 
	 * @param SpecParams $params
	 * @param array $cfg optional configuration to override config loaded from 
	 * config file SpecParams::key, comes from SpecParams::cfg_override
	 * @return string key_alias
	 */
	public function init(SpecParams $params, array $cfg = []) : string {
		$this->params = $params;
		$params_ar = $params->get();
		if(!$cfg && $params_ar['cfg_override']) $cfg = $params_ar['cfg_override'];
		
		if($cfg) {
			$this->_cfg = $cfg;
		} else {		
			if($this->config->load(self::DIR . '/' . $params_ar['key'], false)) $this->_cfg = $this->config->get(self::DIR, $params_ar['key']);			
		}
		
		if($params_ar['add_fields']) $this->_cfg['fields'] = array_merge($params_ar['add_fields'], $this->_cfg['fields']);
		
		$this->_is_valid = $this->_cfg ? true : false;		
		if(!$this->_is_valid) return '';
		
		$this->key = $params_ar['key'];
		$this->key_alias = $params_ar['key_alias'] ? $params_ar['key_alias'] : $this->key;
		$this->_field_keys = array_keys($this->_cfg['fields']);
		
		foreach(['table', 'table_alias', 'joins'] as $k) {
			if(!empty($params_ar[$k])) $this->_cfg[$k] = $params_ar[$k];
		}
		
		$this->addExclude($params_ar['exclude'], false);
		$this->addInclude($params_ar['include'], false);
		$this->postprocess = $params_ar['postprocess'];
		$this->_filter_keys();		
		return $this->key_alias;		
	}
	
	public function addInclude($incl, $filter_keys = true) {
		if(!$incl) return;
		if(!is_array($incl)) $incl = [$incl, ];
		foreach($incl as $_incl) {
			if(!in_array($_incl, $this->include) && !in_array($_incl, $this->exclude)) $this->include[] = $_incl;
		}		
		if($filter_keys) $this->_filter_keys();
		return $this;
	}
	
	public function setIncludeOnly(array $incl) {
		$this->include = $incl;
		$this->exclude = [];
		$this->_filtered_field_keys = $incl;
		return $this;
	}
	
	public function addExclude($excl, $filter_keys = true) {
		if(!$excl) return;
		if(!is_array($excl)) $excl = [$excl, ];
		$_new = [];
		if($this->exclude) {
			foreach($excl as $_excl) {
				if(!in_array($_excl, $this->exclude)) $_new[] = $_excl;			
			}			
		} else {			
			$_new = $excl;
		}
		if($_new) {
			$this->exclude = array_merge($this->exclude, $_new);
			if($this->include) $this->include = array_diff($this->include, $_new);
		}
		if($filter_keys) $this->_filter_keys();
		return $this;
	}	

	/**
	 * Filters field keys according to include and exclude lists.
	 *
	 * This method filters the field keys according to the include and exclude lists.
	 * If the key is in the include list, it is kept. If the key is in the exclude list,
	 * it is removed. If the key is not in the include list and not in the exclude list,
	 * it is kept.
	 *
	 * @return self The current instance of the object for chaining.
	 */
	protected function _filter_keys() {
		$this->_filtered_field_keys = [];
		foreach($this->_field_keys as $fk) {		
			if(($this->include && !in_array($fk, $this->include)) || (!$this->include && $this->exclude && in_array($fk, $this->exclude))) continue;
			$this->_filtered_field_keys[] = $fk;
		}
		return $this;
	}
	
	/**
	 * Returns an associative array of field descriptions.
	 * Used in {@see Engine::getDescriptions}
	 *
	 * @return array
	 */
	public function getDescriptions() : array {
		$out = [];
		foreach($this->_cfg['fields'] as $k => $f) {
			if(in_array($k, $this->_filtered_field_keys) && empty($f['hidden'])) $out[$k] = !empty($f['description']) ? $f['description'] : '';
		}
		return $out;
	}
	
	/**
	 * Constructs an array of parameters to be used to build the SQL query 
	 * {@see Engine::getData}
	 * The returned array contains the following keys:
	 * - 'fields' => ['key' => '<TABLE_ALIAS>.field AS `key_alias`, ...'],
	 * - 'joins' => [['table' => '', 'table_alias' => '', 'on' => ''], ...],
	 * 
	 * The 'fields' key contains an associative array with keys as field names and values as SQL
	 * statements. The SQL statements are generated from the 'fields' configuration array and
	 * the 'table_alias' and 'key_alias' properties of the specification.
	 * 
	 * The 'joins' key contains an array of arrays with keys 'table', 'table_alias', and 'on'.
	 * The 'table' key contains the name of the table to join, the 'table_alias' key contains
	 * the alias to use for the joined table, and the 'on' key contains the SQL ON statement
	 * for the join.
	 * 
	 * If the specification does not have a table, has no filtered field keys, or is marked as
	 * 'nosql', an empty array is returned.
	 *
	 * @return array
	 */
	public function getQuery() : array {
	/*
	Config Structure (loaded from file or overridden by SpecParams::cfg_override):

	'nosql' => bool, 
	'table' => '',
	'table_alias' => '',
	'joins' => [
		['table' => '', 'table_alias' => '', 'on' => ''], ...
	],
	'fields' => [
		'key' => [
			'alias_of' => '', //e.g. if key is not a real db field			
			'description' => '', //used in getDescriptions
			'sql' => '<TABLE>.field',
			'table_alias' => '',
			'nosql' => bool,
			'hidden' => bool, //if true, the field is not included in getDescriptions
			'postprocess' => function($v) {return $v;}
		],
	]
	*/	
		if(!sizeof($this->_filtered_field_keys) || !empty($this->_cfg['nosql']) || !$this->_cfg['table'] || !$this->_cfg['table_alias']) return [];
		$out = [
			'fields' => [],
			'joins' => [],
		];
		$used_aliases = [];
		$in_field_sep = Engine::FIELD_ALIAS_SEPARATOR;
		$vars = $this->params->vars;
		
		foreach($this->_cfg['fields'] as $k => $f) {
			if(!in_array($k, $this->_filtered_field_keys) || !empty($f['nosql'])) continue;
			if(!empty($f['table_alias'])) $used_aliases[] = $f['table_alias'];
			$_table_alias = !empty($f['table_alias']) ? $f['table_alias'] : $this->_cfg['table_alias'];
			$vars[self::TABLE_ALIAS_PLACEHOLDER] = $_table_alias;
			$_has_sql = !empty($f['sql']);
			if($_has_sql) {
				$_f = self::_simple_tpl($f['sql'], $vars, ['<', '>'], false);
			} else {
				$_f = $_table_alias . '.' . (!empty($f['alias_of']) ? $f['alias_of'] : $k);
			}
			//if($_has_sql || !empty($f['alias_of'])) 
			$_f .= " AS `" . $this->key_alias . $in_field_sep . $k . "`";
			$out['fields'][$k] = $_f;
		}	
		if(sizeof($used_aliases) && !empty($this->_cfg['joins'])) {
			$vars[self::TABLE_ALIAS_PLACEHOLDER] = $this->_cfg['table_alias'];
			foreach($this->_cfg['joins'] as $_join) {
				if(!empty($_join['table']) && isset($_join['table_alias']) && in_array($_join['table_alias'], $used_aliases)) {
					if(isset($_join['on'])) {				
						$_join['on'] = "(" . self::_simple_tpl($_join['on'], $vars, ['<', '>'], false) . ")";
					} else {
						$_join['on'] = '';
					}					
					$out['joins'][] = $_join;
				}
			}
		}
		return $out;
	}

	public function isValid() : bool {
		return $this->_is_valid;
	}
	
	public function checkFields(array $fields) : array {
		return array_diff($fields, $this->_field_keys);
	}	
	
	public function getTableAlias(): string {
		return (!empty($this->_cfg['table_alias']) ? $this->_cfg['table_alias'] : '');
	}

	public function getTable(): string {
		return (!empty($this->_cfg['table']) ? $this->_cfg['table'] : '');
	}	
	
	public function getKeyAlias() : string {
		return $this->key_alias;
	}	
	
	public function getKey() : string {
		return $this->key;
	}	
	
	public function getParams() : SpecParams {
		return $this->params;
	}
	
	public function getPlural() : string {
		return $this->params->get('plural');		
	}
	
	public function getPostprocess(string $field)  {
		if(isset($this->postprocess[$field]) && is_callable($this->postprocess[$field])) return $this->postprocess[$field];
		if(isset($this->_cfg['fields'][$field]['postprocess']) && is_callable($this->_cfg['fields'][$field]['postprocess'])) return $this->_cfg['fields'][$field]['postprocess'];
		return null;
	}
	
	protected static function _simple_tpl(string $str, array $data, array $fixes = ['[[', ']]'], bool $replace_fixes = true) : string {
		return str_replace(
			array_merge(
				array_map(function ($_str) use ($fixes) {
						return $fixes[0] . $_str . $fixes[1];
					}, 
					array_keys($data)
				), ($replace_fixes ? $fixes : [])
			), 
			array_merge(
				array_values($data), ($replace_fixes ? ['', ''] : [])
			), 
			$str
		);	
	}
	
}

class SpecParams {
	
	private $_need_array = ['include', 'exclude', 'vars', 'joins', 'add_fields', 'postprocess', 'cfg_override', ];
	
	public $key = ''; //Spec config file name, also used as key_alias if key_alias is empty 
	public $key_alias = '';
	public $table = ''; //overrides table from loaded config
	public $plural = ''; //name of template field if data is supposed to be an array
	public $table_alias = ''; //overrides table alias from loaded config
	public $vars = []; //assoc. array of [key => value] to replace <key> in loaded config fields 'sql' and joins 'on'
	public $joins = []; //overrides joins from loaded config completely, use the same format as joins in loaded config
	public $exclude = []; //array of fields to exclude
	public $include = []; //array of fields to include, if not empty, only these fields will be used
	public $add_fields = []; //add more fields to loaded config fields, [field => [field data]]
	public $postprocess = []; //assoc. array of [field => callable], overrides field postprocess from loaded config
	public $cfg_override = []; //do not load config from file, use this array instead
	
	private $_default_array;
	private $_array;	
	private $_is_valid = false;
	
	public function __construct() {
		$this->_default_array = $this->_get_ret();	
	}
	
	public function init($params) : bool {
		if(is_string($params)) $params = ['key' => $params, ];
		foreach($this->_default_array as $k => $v) {
			if(!isset($params[$k])) {
				$this->$k = $v;
			} else {
				$need_array = in_array($k, $this->_need_array);
				if(is_string($params[$k])) {
					if($need_array) $params[$k] = [$params[$k],];
					$this->$k = $params[$k];
				} else if($need_array && is_array($params[$k])) {
					$this->$k = $params[$k];
				}
			}
		}
		$this->_array = $this->_get_ret();	
		$this->_is_valid = (bool) $this->key;
		return $this->_is_valid;
	}
	
	protected function _get_ret() : array {
		return get_object_vars($this);
	}
	
	public function get(string $key = '') {
		if($this->_array) {
			$ar =& $this->_array; 
		} else {
			$ar =& $this->_default_array;
		}
		
		if($key) return (isset($ar[$key]) ? $ar[$key] : null);
		return $ar;
	}
	
	public function isValid() : bool {
		return $this->_is_valid;
	}	
}

class Exception extends \Exception {
	
}

/*
		require 'Mustache/Autoloader.php';
		Mustache_Autoloader::register();		
		$this->load->library('shortcode');
		
		$e = new \Shortcode\Engine();
		
		$tpl = 'sdfsdf {{# a.field_1 }} {{{b.z}}} {{ bb.c }} dfgdfg {{b.x}} !kjh{ {{/a.field_1}} dflgkmdfg<br />
		test1: a: {{ test1.a  }}, d: {{ test1.d }}, e: {{ test1.e }}
		';		
		
		$e->initSpecs([
			'a',
			[
				'key' => 'b',
				'exclude' => ['x', 'y', 'z']
			],
			[
				'key' => 'b',
				'plural' => 'b.bs',
				'key_alias' => 'bb',
				'include' => ['field_b', 'c',],
				'exclude' => ['c',],
			],
			[
				'key' => 'b',
				'plural' => 'b.bs.bb.bbbs',
				'key_alias' => 'bbb',
			],
			[
				'key' => 'test1', 
				'cfg_override' => [
					'nosql' => true,
					'fields' => [
						'a' => [], 'b' => [], 'c' => [],
					],
				],
				'add_fields' => [
					'd' => [], 'e' => [],
				],
			],	
			['key' => 'customer', 'plural' => 'customers',],
			['key' => 'customer_group', 'plural' => 'customers',]			
		])		
		->setTemplate($tpl)
		->setExternalDataGetter('test1', function() {
			return ['a' => 11, 'b' => 12, 'c' => 13, 'd' => 14, 'e' => 15];
		})		
		->setExternalDataProcessor(function($in_data, $data) {
			$out = [];
			foreach($in_data as $k => $v) {
				$out[$k] = $v * $data['mult'];
			}
			return $out;
		}, null, $processor_data) //$processor_data set externally
		->extractFieldsFromTemplate()
		->setDataParams([
			['key_aliases' => ['customer', 'customer_group'], 'where' => 'c.customer_id < 5', 'on' => ['customer_group' => 'cgd.customer_group_id = c.customer_group_id']],

		])
		->getData();	

		$e->returnData();
		$e->render();

*/
