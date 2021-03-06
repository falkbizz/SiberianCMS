<?php
abstract class Core_Model_Default_Abstract
{
    protected $_db_table;
    protected $_is_cachable = true;
    protected $_action_view = "find";
    protected static $_application;
    protected static $_session = array();
    protected static $_base_url;

    protected $_data = array();
    protected $_orig_data = array();

    protected $_specific_import_data = array();
    protected $_mandatory_columns = array();

    public function __construct($data = array()) {
        foreach($data as $key => $value) {
            if(!is_numeric($key)) {
                $this->setData($key, $value);
            }
        }
        return $this;
    }

    public function __call($method, $args)
    {
        $accessor = substr($method, 0, 3);
        $magicKeys = array('set', 'get', 'uns', 'has');

        if(substr($method, 0, 12) == 'getFormatted') {
            $key = Core_Model_Lib_String::camelize(substr($method,12));
            $data = $this->getData($key);

            if(preg_match('/^\s*([0-9]+(\.[0-9]+)?)\s*$/', $data)) {
                return $this->formatPrice($data, !empty($args[0]) ? $args[0] : null);
            }
//            elseif(preg_match('/(\d){2,4}\-(\d){2}\-(\d){2} (\d{2}:\d{2}:\d{2})/', $data)) {
            elseif(preg_match('/(\d){2,4}\-(\d){2}\-(\d){2}/', $data)) {
                return $this->formatDate($data, !empty($args[0]) ? $args[0] : null);
            }
        }
        if(in_array($accessor, $magicKeys)) {
            if(substr($method, 0, 7) == 'getOrig') {
                $key = Core_Model_Lib_String::camelize(substr($method,7));
                $method = $accessor.'OrigData';
            } else {
                $key = Core_Model_Lib_String::camelize(substr($method,3));
                $method = $accessor.'Data';
            }

            $value = isset($args[0]) ? $args[0] : null;
            return call_user_func(array($this, $method), $key, $value);
        }

        throw new Exception("Invalid method ".get_class($this)."::".$method."(".print_r($args,1).")");
    }

    public function getSession($type = null) {

        if(is_null($type)) $type = SESSION_TYPE;

        if(isset(self::$_session[$type])) {
            return self::$_session[$type];
        } else {
            $session = new Core_Model_Session($type);
            self::setSession($session, $type);
            return $session;
        }
    }

    public static function setSession($session, $type = 'front') {
        self::$_session[$type] = $session;
    }

    public function getTable() {
        if(!is_null($this->_db_table)) {
            if(is_string($this->_db_table))
                return new $this->_db_table(array('modelClass' => get_class($this)));
            else
                return $this->_db_table;
        }

        return null;
    }

    public function getFields() {
        return $this->getTable()->getFields();
    }

    public function hasTable() {
        return !is_null($this->_db_table);
    }

    public function find($id, $field = null) {
        if(!$this->hasTable()) return null;

        if(is_array($id)) {
            $row = $this->getTable()->findByArray($id);
        }
        elseif(is_null($field))
            $row = $this->getTable()->findById($id);
        else
            $row = $this->getTable()->findByField($id, $field);

        $this->_prepareDatas($row);

        return $this;
    }

    /**
     * Utility method for objects
     *
     * @param array $key_values
     * @return mixed
     */
    public function fetchElement($key_values = array()) {
        $db = $this->getTable();
        
        if(empty($key_values)) {
            $key_values = array();
            foreach($this->getData() as $key => $value) {
                $key_values[$key] = $value;
            }
        }
        
        $select = $db->select();
        foreach($key_values as $key => $value) {
            $select->where("`{$key}` = ?", $value); # key are protected with ``
        }

        $result = $db->fetchRow($select);

        return $result;
    }

    /**
     * @param array $key_values
     * @return bool
     */
    public function elementExists($key_values = array()) {
        return (boolean) $this->fetchElement($key_values);
    }

    /**
     * Utility saver for module data
     *
     * @param array $keys
     * @return bool
     */
    public function insertOrUpdate($keys = array(), $insert_once = false) {
        # Save element/data
        $saved_data = $this->getData();
        $saved_element = $this;

        $search_keys = array();

        if(empty($keys)) { # When empty, compare every data
            $search_keys = $saved_data;

            $exists = $this->elementExists($search_keys);
        } else {
            foreach($keys as $key) {
                $search_keys[$key] = $this->getData($key);
            }

            $exists = $this->elementExists($search_keys);
        }

        # Insert Only case
        if($insert_once && $exists) {
            $fetched_element = $saved_element->fetchElement($search_keys);

            return $this->find($fetched_element->getPrimaryKey());
        }

        if($exists) { # So fetch the element
            $fetched_element = $saved_element->fetchElement($search_keys);

            $this->find($fetched_element->getPrimaryKey());
        }
        
        # Re-apply data
        $this->setData($saved_data);
        $this->save();

        return $this;
    }

    /**
     * @param array $keys
     * @return $this
     */
    public function insertOnce($keys = array()) {
        return $this->insertOrUpdate($keys, true);
    }

    public function findLast($params = array()) {
        if(!$this->hasTable()) return null;

        $row = $this->getTable()->findLastByArray($params);

        $this->_prepareDatas($row);

        return $this;
    }


    public function addData($key, $value=null)
    {
        if(is_array($key)) {
            $values = $key;
            foreach($values as $key => $value) {
                $this->setData($key, $value);
            }
        }
        else {
            $this->_data[$key] = $value;
        }
        return $this;
    }

    public function setData($key, $value=null) {
        if(is_array($key)) {
            if(isset($this->_data['id'])) {
                $key['id'] = $this->_data['id'];
            }
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }
        return $this;
    }

    public function unsData($key=null)
    {
        if (is_null($key)) {
            $this->_data = array();
        } else {
            unset($this->_data[$key]);
        }
        return $this;
    }

    public function getData($key='')
    {
        if ($key==='') {
            return $this->_data;
        }
        elseif(isset($this->_data[$key]) AND !is_null($this->_data[$key])) {
            return is_string($this->_data[$key]) ? stripslashes($this->_data[$key]) : $this->_data[$key];
        }
        else {
            return null;
        }
    }

    public function hasData($key) {
        return isset($this->_data[$key]);
    }

    public function setOrigData($data) {
        $this->_orig_data = $data;
        return $this;
    }

    public function getOrigData($key = "") {

        if ($key === "") {
            return $this->_orig_data;
        }
        elseif(isset($this->_orig_data[$key]) AND !is_null($this->_orig_data[$key])) {
            return is_string($this->_orig_data[$key]) ? stripslashes($this->_orig_data[$key]) : $this->_orig_data[$key];
        }
        else {
            return null;
        }
    }

    public function isActive() {
        if($this->hasData("is_active")) return $this->getData("is_active");
        return true;
    }

    public function isCachable() {
        return $this->_is_cachable;
    }

    public function getActionView() {
        return $this->_action_view;
    }

    public function isEmpty() {
        return empty($this->_data);
    }

    public function getApplication() {
        return self::$_application;
    }

    public function prepareFeature($option_value) {
        return $this;
    }

    public function deleteFeature($option_value) {
        return $this;
    }

    public function getFeaturePaths($option_value) {

        if(!$this->isCachable()) return array();

        $action_view = $this->getActionView();

        $path = $option_value->getPath(null);
        $paths = array();

        if(stripos($path, "list") !== false) {

            $paths[] = $option_value->getPath("findall", array('value_id' => $option_value->getId()), false);

            if($uri = $option_value->getMobileViewUri($action_view)) {
                $uri_parameters = $option_value->getMobileViewUriParameter();
                $params = array();

                if ($uri_parameters) {
                    $uri_parameters = "value_id," . $uri_parameters;
                    $uri_parameters = explode(",", $uri_parameters);

                    foreach ($uri_parameters as $uri_parameter) {
                        if (stripos($uri_parameter, "/") !== false) {
                            $data = explode("/", $uri_parameter);
                            $params[$data[0]] = $data[1];
                        } else if ($data = $this->getData($uri_parameter)) {
                            $params[$uri_parameter] = $data;
                        }
                    }

                }

                $paths[] = $option_value->getPath($uri, $params, false);

            }

        } else if(stripos($path, "view") !== false) {
            $uri = $option_value->getMobileViewUri($action_view) ? $option_value->getMobileViewUri($action_view) : $action_view;

            $paths[] = $option_value->getPath($uri, array("value_id" => $option_value->getId()), false);
        }

        return $paths;

    }

    public function getTemplatePaths($page, $option_layouts, $suffix, $path) {
        $paths = array();
        $baseUrl = $this->getApplication()->getUrl(null, array(), null, $this->getApplication()->getKey());

        $module_name = current(explode("_", $this->getModel()));
        if(!empty($module_name)) {
            $module_name = strtolower($module_name);
            Core_Model_Translator::addModule($module_name);
        }

        $layout = str_replace(array($baseUrl, "/"), array("", "_"), $page->getUrl("template").$suffix);

        $params = array();
        if(in_array($page->getOptionId(), $option_layouts)) {
            $params["value_id"] = $page->getId();
        }

        $layout_id = str_replace($baseUrl, "", $path.$page->getUrl("template", $params));

        $paths[] = array(
            "layout" => $layout,
            "layout_id" => $layout_id
        );

        if($page->getMobileViewUri("template")) {

            $layout = str_replace(array($baseUrl, "/"), array("", "_"), $page->getMobileViewUri("template").$suffix);

            $params = array();
            if(in_array($page->getOptionId(), $option_layouts)) {
                $params["value_id"] = $page->getId();
            }
            $layout_id = str_replace($baseUrl, "", $path.$page->getMobileViewUri("template", $params));

            $paths[] = array(
                "layout" => $layout,
                "layout_id" => $layout_id
            );

        }

        return $paths;
    }

    public function setId($id) {
        if($this->hasTable()) {
            $this->setData($this->getTable()->getPrimaryKey(), $id)
                ->setData('id', $id)
            ;
        } else {
            $this->addData('id', $id);
        }

        return $this;
    }

    /**
     * @param array $values
     * @param null $order
     * @param array $params
     * @return Push_Model_Message[]
     */
    public function findAll($values = array(), $order = null, $params = array()) {
        return $this->getTable()->findAll($values, $order, $params);
    }

    public function countAll($values = array()) {
        return $this->getTable()->countAll($values);
    }

    public function save() {
        if($this->_canSave()) {

            if($this->getData('is_deleted') == 1) {
                $this->delete();
            }
            else {
                $row = $this->_createRow();
                $row->save();

                $this->addData($row->getData())->setId($row->getId());
                $this->setOrigData($this->getData());

            }
        }

        return $this;
    }

    public function reload() {
        $id = $this->getId();
        $this->unsData();
        if($id) {
            $this->find($id);
        }

        return $this;
    }

    public function delete() {
        if($row = $this->_createRow() AND $row->getId()) {
            $row->delete();
            $this->unsData();
        }
        return $this;
    }

    public function isProduction() {
        return APPLICATION_ENV == 'production';
    }

    public function _($text) {
        $args = func_get_args();
        return Core_Model_Translator::translate($text, $args);
    }

    public function getUrl($url = '', array $params = array(), $locale = null) {
        return Core_Model_Url::create($url, $params, $locale);
    }

    public function getPath($uri = '', array $params = array(), $locale = null) {
        return Core_Model_Url::createPath($uri, $params);
    }

    public function getCurrentUrl($withParams = true, $locale = null) {
        return Core_Model_Url::current($withParams, $locale);
    }

    public static function setBaseUrl($url) {
        self::$_base_url = $url;
    }

    public function getBaseUrl() {
        return self::$_base_url;
    }

    public function toJSON() {

        $datas = $this->getData();
        if(isset($datas['password'])) unset($datas['password']);
        if(isset($datas['created_at'])) unset($datas['created_at']);
        if(isset($datas['updated_at'])) unset($datas['updated_at']);

        return Zend_Json::encode($datas);
    }

    protected function _canSave() {
        if($this->getTable()) {
            return true;
        }
        return false;
    }

    protected function _createRow() {
        $row = $this->getTable()->createRow(); //new $this->_row(array('table' => new $this->_db_table()));
        $row->setData($this->getData());
        return $row;
    }

    public function __toString() {
        return $this->getData();
    }

    public function formatDate($date = null, $format = 'y-MM-dd') {
        $date = new Zend_Date($date, 'y-MM-dd HH:mm:ss');
        return $date->toString($format);
    }

    public function formatPrice($price, $currency = null) {
        $price = preg_replace(array('/(,)/', '/[^0-9.-]/'), array('.', ''), $price);

        if($currency) $currency = new Zend_Currency($currency);
        else $currency = Core_Model_Language::getCurrentCurrency();

        return $currency->toCurrency($price);
    }

    public static function _formatPrice($price, $currency = null) {
        $self = new static();
        return $self->formatPrice($price, $currency);
    }

    public function getMediaUrl($params = null) {
        return $this->getBaseUrl() . '/images/'.$params;
    }

    protected function _prepareDatas($row) {

        $this->uns();

        if($row) {
            $this->setData($row->getData())
                ->setOrigData($row->getData())
                ->setId($row->getId())
            ;
        }
    }

    public function createDummyContents($option_value, $design, $category) {

        $dummy_content_xml = $this->_getDummyXml($design, $category);

        foreach ($dummy_content_xml->children() as $content) {
            $this->unsData();

            $this->addData((array) $content)
                ->setValueId($option_value->getId())
                ->save()
            ;
        }

    }

    public function getSpecificImportData() {
        return $this->_specific_import_data;
    }

    public function getMandatoryColumns() {
        return $this->_mandatory_columns;
    }

    public function finalizeImport($got_heading, $data = null, $line, $full_data = null) {
        return true;
    }

    public function getExportData($parent = null) {
        return array();
    }

    protected function _getDummyXml($design, $category) {

        $option_model_name = current(explode("_", get_class($this)));

        $dummy_xml = Core_Model_Directory::getBasePathToModule($option_model_name, "data/dummy_" . $category->getCode() . ".xml");

        if(!is_file($dummy_xml))
            throw new Exception($this->_('#113: An error occurred while saving'));

        $dummy_content_xml = simplexml_load_file($dummy_xml);

        if(!$dummy_content_xml->{$design->getCode()})
            throw new Exception($this->_('#114: An error occurred while saving'));

        return $dummy_content_xml->{$design->getCode()};
    }

}
