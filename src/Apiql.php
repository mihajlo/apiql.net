<?php
require_once 'DB.php';

use Evolution\CodeIgniterDB as CI;

class Request
{

    public function getGet($parm = false)
    {
        return isset($_GET[$parm]) ? $_GET[$parm] : null;
    }

    public function getPost($parm = false)
    {
        return isset($_POST[$parm]) ? $_POST[$parm] : $_POST;
    }

    public function getMethod()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtolower($_SERVER['REQUEST_METHOD']) : 'unknown';
    }
}

class Response
{

    public function setStatusCode($code)
    {
        http_response_code((int) $code);
    }

    public function setJSON($arr = [])
    {
        header('Content-Type: application/json');
        echo json_encode($arr, JSON_PRETTY_PRINT);
        exit();
    }
}

/**
 * Description of Endpoint
 *
 * @author mihajlo
 */
class Apiql
{

    private static $db_data = [
        'dsn' => '',
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => '',
        'dbdriver' => 'mysqli',
        'dbprefix' => '',
        'db_debug' => false,
        'char_set' => 'utf8',
        'dbcollat' => 'utf8_general_ci',
        'swap_pre' => '',
        'encrypt' => FALSE,
        'compress' => FALSE,
        'stricton' => FALSE,
        'failover' => array(),
        'save_queries' => TRUE
    ];

    public function __construct($config = [])
    {
        $this->config = (object) array_merge([
                'token' => 'xxxyyyzzz',
                'max_limit_per_page' => 100,
                'default_per_page' => 10,
                'allowed_actions' => [],
                'disabled_tables' => [],
                'disabled_columns' => [],
                'debug' => false, //true,
                'debug_info' => [
                    'primary_column' => true,
                    'fields' => true,
                    'sql' => true,
                    'request' => true,
                    'explain' => true,
                ],
                'defaultStatusMessages' => [
                    200 => 'OK',
                    401 => 'Autherntication failed!',
                    400 => 'Bad request!',
                    403 => 'Not allowed!',
                    404 => 'End-point not found!',
                    405 => 'Bad request',
                    500 => 'Internal server error!'
                ]
                ], $config);
        self::$db_data = array_merge(self::$db_data, $config);
        $this->db = & CI\DB(self::$db_data);

        $this->request = new Request();
        $this->response = new Response();
    }

    public function handleRequest()
    {
        $table = NULL;
        $id = 0;
        
        if ($this->segment(1)) {
            $table = $this->segment(1);
        }
        if ($this->segment(2)) {
            $id = $this->segment(2);
        }
        
        if(!$table){
            @header('Location:'.$this->config->base_url.'documentation/');die;
        }
        
        if(!in_array($table, array_keys($this->config->allowed_actions))){
            return $this->notFound('Endpoint `' . $table . '` not found!');
        }

        $this->defaultHeaders();
        if (!$this->isAuthenticated()) {
            return $this->notAuthenticated();
        }
        if ($this->request->getMethod() == 'post' && !$id) {
            return $this->add($table);
        } else if ($this->request->getMethod() == 'post' && $id) {
            return $this->edit($table, $id);
        } else if ($this->request->getMethod() == 'delete' && $id) {
            return $this->delete($table, $id);
        }
        
        if(!(array)@$this->config->allowed_actions[$table]){
            $this->config->allowed_actions[$table] = ['list'];
        }
        if (!in_array('list', (array)@$this->config->allowed_actions[$table])) {
            return $this->notAllowed('List action is not allowed!');
        }


        $db = $this->db;

        if (!$db->table_exists($table)) {
            return $this->notFound('Table `' . $table . '` not found!');
        }
        $db->query("SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        $realPrim = @$db->query("SHOW KEYS FROM " . $table . " WHERE Key_name = 'PRIMARY'")->row('Column_name');
        if($realPrim){
            $primary = $table . '.' . $realPrim;
        }
        else{
            $realPrim = @$db->query("SHOW COLUMNS FROM " . $table)->row('Field');
            $primary = $table . '.' . $realPrim;
        }
        $builder = $db->from($table);


        //MERGE - JOINS
        $merge = [];
        if ($this->request->getGet('merge')) {
            $merge = $this->request->getGet('merge');
            foreach ($merge as $joinDataKey => $joinDataValue) {
                $joinArr = explode('.', $joinDataKey);
                $builder->join(trim(@$joinArr[0]), $joinDataKey . ' = ' . $joinDataValue, 'INNER');
            }
        }

        //FIELDS
        $fields = [];
        if ($this->request->getGet('field')) {
            $fields = $this->request->getGet('field');
            foreach ($fields as $fieldKey => $fieldValue) {
                if ($fieldValue) {
                    $builder->select(trim($fieldKey . ' AS ' . $fieldValue));
                } else {
                    $builder->select(trim($fieldKey));
                }
            }
        }

        //GROUP
        if ($this->request->getGet('group')) {
            $groupFields = array_keys($this->request->getGet('group'));
            $builder->group_by($groupFields);
        }


        //ORDER
        if ($this->request->getGet('sort')) {
            $sortFields = $this->request->getGet('sort');
            foreach ($sortFields as $sortKey => $sortValue) {
                $builder->order_by($sortKey, $sortValue);
            }
        }


        //LIMIT, PAGE, OFFSET data
        $page = $this->request->getGet('page') ?: 1;
        $limit = $this->request->getGet('limit') ?: $this->config->default_per_page;
        if ($limit > $this->config->max_limit_per_page) {
            $limit = $this->config->max_limit_per_page;
        }
        $offset = ($page - 1) * $limit;
        $builder->limit($limit, $offset);

        //CONTAINS ID - single record
        if ($id) {
            $builder->where($primary, $id);
            $builder->limit(1);
        } else {
            if ($this->request->getGet('filter')) {
                $builder->where($this->request->getGet('filter'));
            }
            if ($this->request->getGet('search')) {
                $builder->like($this->request->getGet('search'));
            }
        }

        $sql = $builder->get_compiled_select();//exit($sql);
        $output = [];

        try {
            $dbresults = $db->query($sql);
            if ($dbresults) {
                $dbresults = $dbresults->result_array();
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
            if ($this->config->debug && $this->config->debug_info['sql']) {
                $error .= '   |   SQL: ' . $sql;
            }
            return $this->badRequest($error);
        }

        if (!$id) {
            try {
                $total_records = $db->query("SELECT COUNT(1) AS total FROM (SELECT 'cnt' AS 'cnt' FROM " . @explode('LIMIT ', @explode('FROM ', $sql)[1])[0] . ") a");
                if ($total_records) {
                    $total_records = $total_records->row('total');
                }
            } catch (\Exception $e) {
                $total_records = 99999999;
            }
            $output[$table]['page_info'] = [
                'page' => (int) $page,
                'per_page' => (int) $limit,
                'total_records' => (int) $total_records,
                'total_pages' => ceil($total_records / $limit)
            ];
        }

        if ($this->request->getGet('add')) {
            $addTables = $this->request->getGet('add');
            foreach ($dbresults != false ? $dbresults : [] as $k => $item) {
                foreach ($addTables as $addTableKey => $addTableValue) {
                    $tmpTableArr = explode('.', $addTableKey);
                    try {
                        $tableDataSet = $db->query("SELECT * FROM " . @$tmpTableArr[0] . " WHERE " . $tmpTableArr[1] . "=" . $item[@$addTableValue] . " LIMIT 1");
                        if ($tableDataSet) {
                            $tableDataSet = $tableDataSet->row();
                        }
                    } catch (\Exception $e) {
                        $error = $e->getMessage();
                        if ($this->config->debug && $this->config->debug_info['sql']) {
                            $error .= '   |   SQL: ' . $sql;
                        }
                        return $this->badRequest($error);
                    }
                    foreach ($tableDataSet as $kk => $vv) {
                        if (in_array($kk, $this->config->disabled_columns)) {
                            unset($tableDataSet->{$kk});
                        }
                    }

                    $dbresults[$k][@$tmpTableArr[0]] = $tableDataSet;
                }
            }
        }

        foreach ($dbresults != false ? $dbresults : [] as $k2 => $v2) {
            foreach ($v2 as $k3k => $v3v) {
                if (in_array($k3k, $this->config->disabled_columns)) {
                    unset($dbresults[$k2][$k3k]);
                }
            }
        }

        if ($id) {
            $dbresults = @$dbresults[0];
            if (!$dbresults) {
                $this->notFound('Record not found!');
            }
        }

        $output[$table]['data'] = $dbresults;

        if ($this->config->debug) {
            if ($this->config->debug_info['request']) {
                $output['debug']['request'] = $_REQUEST;
            }
            if ($this->config->debug_info['primary_column']) {
                $output['debug']['primary_column'] = $primary;
            }
            if ($this->config->debug_info['fields']) {
                $output['debug']['fields'] = $fields;
            }
            if ($this->config->debug_info['sql']) {
                $output['debug']['sql'] = $sql;
            }
            if ($this->config->debug_info['explain']) {
                $explainResults = $db->query("EXPLAIN " . $sql);
                if ($explainResults) {
                    $output['debug']['explain'] = $explainResults->result_array();
                }
            }
        }

        if (!is_array($output[$table]['data'])) {
            $this->badRequest($db->error()['message']);
        }

        $res = $this->apiResponse($output);


        return $res;
    }

    public function segment($num = 1)
    {
        $mainSegment = explode('index.php/', $_SERVER['PHP_SELF']);
        $segments = explode('/', $mainSegment[1]);
        return isset($segments[$num - 1]) ? $segments[$num - 1] : false;
    }
    /*
     * 
     * var insertForm = {
      "user_id":1,
      "log_type":"visit",
      "created":"2020-04-25 00:00:01",
      "log":"https://magicarts.mk/"
      };

      $.post('https://apiql.php.mk/api/user_logs?token=xxxyyyzzz',insertForm,function(resp){
      console.log(resp.user_logs.data);
      });
     * 
     */

    private function add($table = 'default')
    {
        if (!in_array('add', (array)@$this->config->allowed_actions[$table])) {
            return $this->notAllowed('Insert/add action is not allowed!');
        }
        $db = $this->db;

        if (!$db->table_exists($table)) {
            return $this->notFound('Table `' . $table . '` not found!');
        }
        $output = [];



        try {
            $builder = $db->from($table);
            $sql = $builder->set($this->request->getPost())->get_compiled_insert();
            $db->query($sql);
            $output[$table]['data'] = $db->insert_id();
            if ($this->config->debug) {
                if ($this->config->debug_info['request']) {
                    $output['debug']['request'] = $_REQUEST;
                }
                if ($this->config->debug_info['fields']) {
                    $output['debug']['insert_fields'] = $_POST;
                }
                if ($this->config->debug_info['sql']) {
                    $output['debug']['sql'] = $sql;
                }
                if ($this->config->debug_info['explain']) {
                    $explained = $db->query("EXPLAIN " . $sql);
                    if ($explained) {
                        $output['debug']['explain'] = $explained->result_array();
                    }
                }
            }
            if (!$output[$table]['data']) {
                $this->badRequest($db->error()['message']);
            }
            return $this->apiResponse($output);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            if ($this->config->debug && $this->config->debug_info['sql']) {
                $error .= '   |   SQL: ' . $db->last_query();
            }
            return $this->badRequest($error);
        }
    }

    private function edit($table = 'default', $id = 0)
    {
        if (!in_array('edit', (array)@$this->config->allowed_actions[$table])) {
            return $this->notAllowed('Edit/update action is not allowed!');
        }

        $db = $this->db;

        if (!$db->table_exists($table)) {
            return $this->notFound('Table `' . $table . '` not found!');
        }
        $primary = $table . '.' . $db->query("SHOW KEYS FROM " . $table . " WHERE Key_name = 'PRIMARY'")->row('Column_name');

        $output = [];
        $builder = $db->from($table);
        $builder->where($primary, $id);
        $sql = $builder->set($this->request->getPost())->get_compiled_update();

        try {
            $db->query($sql);
            $output[$table]['data'] = $id;
            if ($this->config->debug) {
                if ($this->config->debug_info['request']) {
                    $output['debug']['request'] = $_REQUEST;
                }
                if ($this->config->debug_info['fields']) {
                    $output['debug']['update_fields'] = $this->request->getPost();
                }
                if ($this->config->debug_info['sql']) {
                    $output['debug']['sql'] = $sql;
                }
                if ($this->config->debug_info['explain']) {
                    $output['debug']['explain'] = $db->query("EXPLAIN " . $sql);
                    if ($output['debug']['explain']) {
                        $output['debug']['explain'] = $db->query("EXPLAIN " . $sql)->result_array();
                    }
                }
            }

            if ($db->error()['message']) {
                $this->badRequest($db->error()['message']);
            }

            return $this->apiResponse($output);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            if ($this->config->debug && $this->config->debug_info['sql']) {
                $error .= '   |   SQL: ' . $db->last_query();
            }
            return $this->badRequest($error);
        }
    }

    private function delete($table = 'default', $id = 0)
    {

        if (!in_array('delete', (array)@$this->config->allowed_actions[$table])) {
            return $this->notAllowed('Delete action is not allowed!');
        }

        $db = $this->db;

        if (!$db->table_exists($table)) {
            return $this->notFound('Table `' . $table . '` not found!');
        }
        $primary = $db->query("SHOW KEYS FROM " . $table . " WHERE Key_name = 'PRIMARY'");
        if ($primary) {
            $primary = $table . '.' . $primary->row('Column_name');
        } else {
            return $this->notFound('No primary key found for table `' . $table . '`!');
        }

        $output = [];
        $sql = "DELETE FROM " . $table . " WHERE " . $primary . "=" . (int) $id;

        try {
            $db->query($sql);
            $output[$table]['data'] = $id;
            if ($this->config->debug) {
                if ($this->config->debug_info['request']) {
                    $output['debug']['request'] = $_REQUEST;
                }
                if ($this->config->debug_info['fields']) {
                    $output['debug']['update_fields'] = $this->request->getPost();
                }
                if ($this->config->debug_info['sql']) {
                    $output['debug']['sql'] = $sql;
                }
                if ($this->config->debug_info['explain']) {
                    $output['debug']['explain'] = $db->query("EXPLAIN " . $sql);
                    if ($output['debug']['explain']) {
                        $output['debug']['explain'] = $output['debug']['explain']->result_array();
                    }
                }
            }
            return $this->apiResponse($output);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            if ($this->config->debug && $this->config->debug_info['sql']) {
                $error .= '   |   SQL: ' . $db->last_query();
            }
            return $this->badRequest($error);
        }
    }

    private function defaultHeaders()
    {
        header("Access-Control-Allow-Origin: *");
        //$this->response->setHeader('Access-Control-Allow-Origin', '*');
        header("Access-Control-Allow-Headers: Access-Control-Allow-Headers, Origin,Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers");
        //$this->response->setHeader('Access-Control-Allow-Headers', 'Access-Control-Allow-Headers, Origin,Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers');
        header("Cache-Control: no-cache");
        //$this->response->setHeader('Cache-Control', 'no-cache');
        header("Cache-Control: must-revalidate");
        //$this->response->setHeader('Cache-Control', 'must-revalidate');
    }

    private function isAuthenticated()
    {
        return ($this->request->getGet('token') == $this->config->token) ?: false;
    }

    private function apiResponse($data = [])
    {
        $code = 200;
        $this->response->setStatusCode($code);
        return $this->response->setJSON(array_merge([
                'error' => false,
                'status_text' => 'OK',
                'status_code' => $code,
                'status_msg' => $this->config->defaultStatusMessages[$code],
                    ], (array) $data));
    }

    private function notAuthenticated($msg = false)
    {
        $code = 401;
        $msg = $msg ?: $this->config->defaultStatusMessages[$code];
        $this->response->setStatusCode($code);
        return $this->response->setJSON([
                'error' => true,
                'status_text' => 'error',
                'status_code' => $code,
                'status_msg' => $msg,
        ]);
    }

    private function notFound($msg = false)
    {
        $code = 404;
        $msg = $msg ?: $this->config->defaultStatusMessages[$code];
        $this->response->setStatusCode($code);
        return $this->response->setJSON([
                'error' => true,
                'status_text' => 'error',
                'status_code' => $code,
                'status_msg' => $msg,
        ]);
    }

    private function notAllowed($msg = false)
    {
        $code = 403;
        $msg = $msg ?: $this->config->defaultStatusMessages[$code];
        $this->response->setStatusCode($code);
        return $this->response->setJSON([
                'error' => true,
                'status_text' => 'error',
                'status_code' => $code,
                'status_msg' => $msg,
        ]);
    }

    private function badRequest($msg = false)
    {
        $code = 400;
        $msg = $msg ? $msg : @$this->config->defaultStatusMessages[$code];
        $this->response->setStatusCode($code);
        return $this->response->setJSON([
                'error' => true,
                'status_text' => 'error',
                'status_code' => $code,
                'status_msg' => $msg,
        ]);
    }

    private function serverError($msg = false)
    {
        $code = 500;
        $msg = $msg ?: $this->config->defaultStatusMessages[$code];
        $this->response->setStatusCode($code);
        return $this->response->setJSON([
                'error' => true,
                'status_text' => 'error',
                'status_code' => $code,
                'status_msg' => $msg,
        ]);
    }
}
