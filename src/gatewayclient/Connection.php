<?php
// +----------------------------------------------------------------------
// | ThinkPHP Gateway Client [Gateway Client For ThinkPHP]
// +----------------------------------------------------------------------
// | ThinkPHP Gateway Client
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: axguowen <axguowen@qq.com>
// +----------------------------------------------------------------------

namespace think\gatewayclient;

/**
 * 连接器类
 */
class Connection
{
    /**
     * 当前所有Gateway通讯地址
     * @var array
     */
    protected $addresses = [];

    /**
     * Gateway通讯地址最后获取时间
     * @var int
     */
    protected $getAddressesTime = 0;

    /**
     * 连接参数配置
     * @var array
     */
    protected $config = [
        // Gateway注册服务地址
        'register_address' => '',
        // 密钥, 为对应Register服务设置的密钥
        'secret_key' => '',
        // 连接超时时间，单位：秒
        'connect_timeout' => 3,
        // 与Gateway是否是长链接
        'persistent_connection' => false,
    ];

    /**
     * 架构方法
     * @access public
     * @param array $options 配置参数
     * @return void
     */
    public function __construct(array $options = [])
    {
        // 动态配置不为空
        if (!empty($options)) {
            // 合并配置
            $this->config = array_merge($this->config, $options);
        }
    }

    /**
     * 获取配置参数
     * @access public
     * @param string $name 配置名称
     * @return mixed
     */
    public function getConfig($name = '')
    {
        // 为空
        if ('' === $name) {
            // 返回全部配置
            return $this->config;
        }
        // 返回指定配置
        return isset($this->config[$name]) ? $this->config[$name] : null;
    }

    /**
     * 向所有客户端连接(或者 client_id_array 指定的客户端连接)广播消息
     * @access public
     * @param string $message 向客户端发送的消息
     * @param array|string $client_id_array 客户端 id 数组
     * @param array|string $exclude_client_id 不给这些client_id发
     * @param bool $raw 是否发送原始数据（即不调用gateway的协议的encode方法）
     * @return void
     */
    public function sendToAll($message, $client_id_array = null, $exclude_client_id = null, $raw = false)
    {
        // 构造发送数据
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd']  = Protocol::CMD_SEND_TO_ALL;
        $gateway_data['body'] = $message;
        // 如果是发送原始数据
        if ($raw) {
            $gateway_data['flag'] |= Protocol::FLAG_NOT_CALL_ENCODE;
        }
        // 存在排除的客户端ID
        if (!empty($exclude_client_id)) {
            // 字符串
            if (!is_array($exclude_client_id)) {
                $exclude_client_id = explode(',', $exclude_client_id);
            }
            // 指定了要发送的客户端ID
            if (!empty($client_id_array)) {
                $exclude_client_id = array_flip($exclude_client_id);
            }
        }
        // 指定了要发送的客户端ID
        if (!empty($client_id_array)) {
            if (!is_array($client_id_array)) {
                $client_id_array = explode(',', $client_id_array);
            }
            $data_array = [];
            // 遍历
            foreach ($client_id_array as $client_id) {
                if (isset($exclude_client_id[$client_id])) {
                    continue;
                }
                $address = Context::clientIdToAddress($client_id);
                if ($address) {
                    $key = long2ip($address['local_ip']) . ':' . $address['local_port'];
                    $data_array[$key][$address['connection_id']] = $address['connection_id'];
                }
            }
            foreach ($data_array as $addr => $connection_id_list) {
                $the_gateway_data = $gateway_data;
                $the_gateway_data['ext_data'] = json_encode(['connections' => $connection_id_list]);
                $this->sendToGateway($addr, $the_gateway_data);
            }
            // 返回
            return;
        }

        // 排除的客户端ID为空
        if (empty($exclude_client_id)) {
            // 发送到全部客户端
            return $this->sendToAllGateway($gateway_data);
        }

        $address_connection_array = static::clientIdArrayToAddressArray($exclude_client_id);

        $all_addresses = $this->getAddresses();
        // 遍历
        foreach ($all_addresses as $address) {
            $gateway_data['ext_data'] = '';
            if(isset($address_connection_array[$address])){
                $gateway_data['ext_data'] = json_encode(['exclude'=> $address_connection_array[$address]]);
            }
            $this->sendToGateway($address, $gateway_data);
        }
    }

    /**
     * 向某个client_id对应的连接发消息
     * @access public
     * @param string $client_id
     * @param string $message
     * @return array
     */
    public function sendToClient($client_id, $message)
    {
        return $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_SEND_TO_ONE, $message);
    }

    /**
     * 判断某个uid是否在线
     * @access public
     * @param string $uid
     * @return int 0|1
     */
    public function isUidOnline($uid)
    {
        return (int) $this->getClientIdByUid($uid);
    }

    /**
     * 判断多个uid是否在线
     * @access public
     * @param array $uid
     * @return array
     */
    public function isUidsOnline(array $uids)
    {
        return array_map(function ($item) {
            return (int) $item;
        }, $this->batchGetClientIdByUid($uids));
    }
    
    /**
     * 判断client_id对应的连接是否在线
     * @access public
     * @param string $client_id
     * @return int 0|1
     */
    public function isOnline($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        if (!$address_data) {
            return 0;
        }
        $address = long2ip($address_data['local_ip']) . ':' . $address_data['local_port'];
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_IS_ONLINE;
        $gateway_data['connection_id'] = $address_data['connection_id'];
        return (int) $this->sendAndRecv($address, $gateway_data);
    }

    /**
     * 获取所有在线client_id的session，client_id为 key
     * @access public
     * @param string $group
     * @return array
     */
    public function getAllClientSessions($group = '')
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_GET_ALL_CLIENT_SESSIONS;
        if (!empty($group)) {
            $gateway_data['cmd'] = Protocol::CMD_GET_CLIENT_SESSIONS_BY_GROUP;
            $gateway_data['ext_data'] = $group;
        }
        $status_data = [];
        $all_buffer_array = $this->getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $data) {
                if ($data) {
                    foreach ($data as $connection_id => $session_buffer) {
                        $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                        if ($client_id === Context::$client_id) {
                            $status_data[$client_id] = (array) $_SESSION;
                        } else {
                            $status_data[$client_id] = $session_buffer ? Context::sessionDecode($session_buffer) : [];
                        }
                    }
                }
            }
        }
        return $status_data;
    }

    /**
     * 获取某个组的所有client_id的session信息
     * @access public
     * @param string $group
     * @return array
     */
    public function getClientSessionsByGroup($group)
    {
        if (!empty($group)) {
            return $this->getAllClientSessions($group);
        }
        return [];
    }

    /**
     * 获取所有在线client_id数(getAllClientIdCount的别名)
     * @access public
     * @return int
     */
    public function getAllClientCount()
    {
        return $this->getAllClientIdCount();
    }

    /**
     * 获取所有在线client_id数
     * @access public
     * @return int
     */
    public function getAllClientIdCount()
    {
        return $this->getClientCountByGroup();
    }

    /**
     * getClientIdCountByGroup 函数的别名
     * @access public
     * @param string $group
     * @return int
     */
    public function getClientCountByGroup($group = '')
    {
        return $this->getClientIdCountByGroup($group);
    }

    /**
     * 获取某个组的在线client_id数
     * @access public
     * @param string $group
     * @return int
     */
    public function getClientIdCountByGroup($group = '')
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_GET_CLIENT_COUNT_BY_GROUP;
        $gateway_data['ext_data'] = $group;
        $total_count = 0;
        $all_buffer_array = $this->getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $count) {
                if ($count) {
                    $total_count += $count;
                }
            }
        }
        return $total_count;
    }

    /**
     * 获取某个群组在线client_id列表
     * @access public
     * @param string $group
     * @return array
     */
    public function getClientIdListByGroup($group)
    {
        if (empty($group)) {
            return [];
        }
        // 不是数组
        if(!is_array($group)){
            $group = explode(',', $group);
        }
        $data = $this->select(['uid'], ['groups' => $group]);
        $client_id_map = [];
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    $client_id_map[$client_id] = $client_id;
                }
            }
        }
        return $client_id_map;
    }

    /**
     * 获取集群所有在线client_id列表
     * @access public
     * @return array
     */
    public function getAllClientIdList()
    {
        return static::formatClientIdFromGatewayBuffer($this->select(['uid']));
    }

    /**
     * 格式化client_id
     * @access protected
     * @param $data
     * @return array
     */
    protected static function formatClientIdFromGatewayBuffer($data)
    {
        $client_id_list = [];
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    $client_id_list[$client_id] = $client_id;
                }
            }
        }
        return $client_id_list;
    }

    /**
     * 获取与 uid 绑定的 client_id 列表
     * @access public
     * @param string $uid
     * @return array
     */
    public function getClientIdByUid($uid)
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_GET_CLIENT_ID_BY_UID;
        $gateway_data['ext_data'] = $uid;
        $client_list = [];
        $all_buffer_array = $this->getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $connection_id_array) {
                if ($connection_id_array) {
                    foreach ($connection_id_array as $connection_id) {
                        $client_list[] = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    }
                }
            }
        }
        return $client_list;
    }

    /**
     * 批量获取与 uid 绑定的 client_id 列表
     * @access public
     * @param array $uids
     * @return array
     */
    public function batchGetClientIdByUid(array $uids)
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_BATCH_GET_CLIENT_ID_BY_UID;
        $gateway_data['ext_data'] = json_encode($uids);
        $client_list = [];
        $all_buffer_array = $this->getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $uid_connection_id_array) {
                if ($uid_connection_id_array) {
                    foreach ($uid_connection_id_array as $uid => $connection_ids) {
                        if (! isset($client_list[$uid])) {
                            $client_list[$uid] = [];
                        }
                        foreach ($connection_ids as $connection_id) {
                            $client_list[$uid][] = Context::addressToClientId($local_ip, $local_port, $connection_id);
                        }
                    }
                }
            }
        }
        return $client_list;
    }

    /**
     * 获取某个群组在线uid列表
     * @access public
     * @param string $group
     * @return array
     */
    public function getUidListByGroup($group)
    {
        if (empty($group)) {
            return [];
        }
        // 不是数组
        if(!is_array($group)){
            $group = explode(',', $group);
        }
        $data = $this->select(['uid'], ['groups' => $group]);
        $uid_map = [];
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (!empty($info['uid'])) {
                        $uid_map[$info['uid']] = $info['uid'];
                    }
                }
            }
        }
        return $uid_map;
    }

    /**
     * 获取某个群组在线uid数
     * @access public
     * @param string $group
     * @return int
     */
    public function getUidCountByGroup($group)
    {
        // 为空
        if(empty($group)){
            return 0;
        }
        return count($this->getUidListByGroup($group));
    }

    /**
     * 获取全局在线uid列表
     * @access public
     * @return array
     */
    public function getAllUidList()
    {
        $data = $this->select(['uid']);
        $uid_map = [];
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (!empty($info['uid'])) {
                        $uid_map[$info['uid']] = $info['uid'];
                    }
                }
            }
        }
        return $uid_map;
    }

    /**
     * 获取全局在线uid数
     * @access public
     * @return int
     */
    public function getAllUidCount()
    {
        return count($this->getAllUidList());
    }

    /**
     * 通过client_id获取uid
     * @access public
     * @param $client_id
     * @return mixed
     */
    public function getUidByClientId($client_id)
    {
        $data = $this->select(['uid'], ['client_id' => [$client_id]]);
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $info) {
                    return $info['uid'];
                }
            }
        }
    }

    /**
     * 获取所有在线的群组id
     * @access public
     * @return array
     */
    public function getAllGroupIdList()
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_GET_GROUP_ID_LIST;
        $group_id_list = [];
        $all_buffer_array = $this->getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $group_id_array) {
                if (is_array($group_id_array)) {
                    foreach ($group_id_array as $group_id) {
                        if (!isset($group_id_list[$group_id])) {
                            $group_id_list[$group_id] = $group_id;
                        }
                    }
                }
            }
        }
        return $group_id_list;
    }

    /**
     * 获取所有在线分组的uid数量，也就是每个分组的在线用户数
     * @access public
     * @return array
     */
    public function getAllGroupUidCount()
    {
        $group_uid_map = $this->getAllGroupUidList();
        $group_uid_count_map = [];
        foreach ($group_uid_map as $group_id => $uid_list) {
            $group_uid_count_map[$group_id] = count($uid_list);
        }
        return $group_uid_count_map;
    }

    /**
     * 获取所有分组uid在线列表
     * @access public
     * @return array
     */
    public function getAllGroupUidList()
    {
        $data = $this->select(['uid','groups']);
        $group_uid_map = [];
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (empty($info['uid']) || empty($info['groups'])) {
                        break;
                    }
                    $uid = $info['uid'];
                    foreach ($info['groups'] as $group_id) {
                        if(!isset($group_uid_map[$group_id])) {
                            $group_uid_map[$group_id] = [];
                        }
                        $group_uid_map[$group_id][$uid] = $uid;
                    }
                }
            }
        }
        return $group_uid_map;
    }

    /**
     * 获取所有群组在线client_id列表
     * @access public
     * @return array
     */
    public function getAllGroupClientIdList()
    {
        $data = $this->select(['groups']);
        $group_client_id_map = [];
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (empty($info['groups'])) {
                        break;
                    }
                    $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    foreach ($info['groups'] as $group_id) {
                        if(!isset($group_client_id_map[$group_id])) {
                            $group_client_id_map[$group_id] = [];
                        }
                        $group_client_id_map[$group_id][$client_id] = $client_id;
                    }
                }
            }
        }
        return $group_client_id_map;
    }

    /**
     * 获取所有群组在线client_id数量，也就是获取每个群组在线连接数
     * @access public
     * @return array
     */
    public function getAllGroupClientIdCount()
    {
        $group_client_map = $this->getAllGroupClientIdList();
        $group_client_count_map = [];
        foreach ($group_client_map as $group_id => $client_id_list) {
            $group_client_count_map[$group_id] = count($client_id_list);
        }
        return $group_client_count_map;
    }

    /**
     * 根据条件到gateway搜索数据
     * @access protected
     * @param array $fields
     * @param array $where
     * @return array
     */
    protected function select($fields = ['session', 'uid', 'groups'], $where = [])
    {
        $t = microtime(true);
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_SELECT;
        $gateway_data['ext_data'] = [
            'fields' => $fields,
            'where' => $where,
        ];
        $gateway_data_list   = [];
        // 有client_id，能计算出需要和哪些gateway通讯，只和必要的gateway通讯能降低系统负载
        if (isset($where['client_id'])) {
            $client_id_list = $where['client_id'];
            unset($gateway_data['ext_data']['where']['client_id']);
            $gateway_data['ext_data']['where']['connection_id'] = [];
            foreach ($client_id_list as $client_id) {
                $address_data = Context::clientIdToAddress($client_id);
                if (!$address_data) {
                    continue;
                }
                $address = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
                if (!isset($gateway_data_list[$address])) {
                    $gateway_data_list[$address] = $gateway_data;
                }
                $gateway_data_list[$address]['ext_data']['where']['connection_id'][$address_data['connection_id']] = $address_data['connection_id'];
            }
            foreach ($gateway_data_list as $address => $item) {
                $gateway_data_list[$address]['ext_data'] = json_encode($item['ext_data']);
            }
            // 有其它条件，则还是需要向所有gateway发送
            if (count($where) !== 1) {
                $gateway_data['ext_data'] = json_encode($gateway_data['ext_data']);
                $addresses = $this->getAddresses();
                foreach ($addresses as $address) {
                    if (!isset($gateway_data_list[$address])) {
                        $gateway_data_list[$address] = $gateway_data;
                    }
                }
            }
            return $this->getBufferFromSomeGateway($gateway_data_list);
        }
        $gateway_data['ext_data'] = json_encode($gateway_data['ext_data']);
        // 返回
        return $this->getBufferFromAllGateway($gateway_data);
    }

    /**
     * 生成验证包，用于验证此客户端的合法性
     * @access protected
     * @return string
     */
    protected function generateAuthBuffer()
    {
        // 没有设置密钥
        if(empty($this->config['secret_key'])){
            return '';
        }
        // 构造数据
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_GATEWAY_CLIENT_CONNECT;
        $gateway_data['body'] = json_encode([
            'secret_key' => $this->config['secret_key'],
        ]);
        // 返回
        return Protocol::encode($gateway_data);
    }

    /**
     * 批量向某些gateway发包，并得到返回数组
     * @access protected
     * @param array $gateway_data_array
     * @return array
     */
    protected function getBufferFromSomeGateway($gateway_data_array)
    {
        $gateway_buffer_array = [];
        $auth_buffer = $this->generateAuthBuffer();
        foreach ($gateway_data_array as $address => $gateway_data) {
                $gateway_buffer_array[$address] = $auth_buffer . Protocol::encode($gateway_data);
        }
        return $this->getBufferFromGateway($gateway_buffer_array);
    }

    /**
     * 批量向所有 gateway 发包，并得到返回数组
     * @access protected
     * @param string|array $gateway_data
     * @return array
     */
    protected function getBufferFromAllGateway($gateway_data)
    {
        $addresses = $this->getAddresses();
        $gateway_buffer_array = [];
        $gateway_buffer = $this->generateAuthBuffer() . Protocol::encode($gateway_data);
        foreach ($addresses as $address) {
            $gateway_buffer_array[$address] = $gateway_buffer;
        }

        return $this->getBufferFromGateway($gateway_buffer_array);
    }

    /**
     * 获取所有gateway内部通讯地址
     * @access protected
     * @return array
     */
    protected function getAllGatewayAddress()
    {
        return $this->getAddresses();
    }

    /**
     * 批量向gateway发送并获取数据
     * @access protected
     * @param $gateway_buffer_array
     * @return array
     */
    protected function getBufferFromGateway($gateway_buffer_array)
    {
        $client_array = $status_data = $client_address_map = $receive_buffer_array = $recv_length_array = [];
        // 批量向所有gateway进程发送请求数据
        foreach ($gateway_buffer_array as $address => $gateway_buffer) {
            $client = stream_socket_client('tcp://' . $address, $error_code, $error_message, $this->config['connect_timeout']);
            if ($client && strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer)) {
                $socket_id = (int)$client;
                $client_array[$socket_id] = $client;
                $client_address_map[$socket_id] = explode(':', $address);
                $receive_buffer_array[$socket_id] = '';
            }
        }
        // 超时5秒
        $timeout    = 5;
        $time_start = microtime(true);
        // 批量接收请求
        while (count($client_array) > 0) {
            $write = $except = [];
            $read = $client_array;
            if (@stream_select($read, $write, $except, $timeout)) {
                foreach ($read as $client) {
                    $socket_id = (int)$client;
                    $buffer = stream_socket_recvfrom($client, 65535);
                    if ($buffer !== '' && $buffer !== false) {
                        $receive_buffer_array[$socket_id] .= $buffer;
                        $receive_length = strlen($receive_buffer_array[$socket_id]);
                        if (empty($recv_length_array[$socket_id]) && $receive_length >= 4) {
                            $recv_length_array[$socket_id] = current(unpack('N', $receive_buffer_array[$socket_id]));
                        }
                        if (!empty($recv_length_array[$socket_id]) && $receive_length >= $recv_length_array[$socket_id] + 4) {
                            unset($client_array[$socket_id]);
                        }
                    } elseif (feof($client)) {
                        unset($client_array[$socket_id]);
                    }
                }
            }
            if (microtime(true) - $time_start > $timeout) {
                break;
            }
        }
        $format_buffer_array = [];
        foreach ($receive_buffer_array as $socket_id => $buffer) {
            $local_ip = ip2long($client_address_map[$socket_id][0]);
            $local_port = $client_address_map[$socket_id][1];
            $format_buffer_array[$local_ip][$local_port] = unserialize(substr($buffer, 4));
        }
        return $format_buffer_array;
    }

    /**
     * 踢掉某个客户端，并以$message通知被踢掉客户端
     * @access public
     * @param string $client_id
     * @param string $message
     * @return void
     */
    public static function closeClient($client_id, $message = null)
    {
        // 根据客户端ID获取客户端地址
        $address_data = Context::clientIdToAddress($client_id);
        if (!$address_data) {
            return false;
        }
        $address = long2ip($address_data['local_ip']) . ':' . $address_data['local_port'];
        // 返回
        return $this->kickAddress($address, $address_data['connection_id'], $message);
    }

    /**
     * 踢掉某个客户端并直接立即销毁相关连接
     * @access public
     * @param string $client_id
     * @return bool
     */
    public function destoryClient($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        if (!$address_data) {
            return false;
        }
        $address = long2ip($address_data['local_ip']) . ':' . $address_data['local_port'];
        // 返回
        return $this->destroyAddress($address, $address_data['connection_id']);
    }

    /**
     * 踢掉当前客户端并直接立即销毁相关连接
     * @access public
     * @return bool
     */
    public function destoryCurrentClient()
    {
        if (!Context::$connection_id) {
            throw new Exception('destoryCurrentClient can not be called in async context');
        }
        $address = long2ip(Context::$local_ip) . ':' . Context::$local_port;
        return $this->destroyAddress($address, Context::$connection_id);
    }

    /**
     * 将 client_id 与 uid 绑定
     * @access public
     * @param string $client_id
     * @param int|string $uid
     * @return void
     */
    public function bindUid($client_id, $uid)
    {
        $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_BIND_UID, '', $uid);
    }

    /**
     * 将 client_id 与 uid 解除绑定
     * @access public
     * @param string $client_id
     * @param int|string $uid
     * @return void
     */
    public function unbindUid($client_id, $uid)
    {
        $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_UNBIND_UID, '', $uid);
    }

    /**
     * 将 client_id 加入组
     * @access public
     * @param string $client_id
     * @param int|string $group
     * @return void
     */
    public function joinGroup($client_id, $group)
    {
        $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_JOIN_GROUP, '', $group);
    }

    /**
     * 将 client_id 移出组
     * @access public
     * @param string $client_id
     * @param int|string $group
     * @return void
     */
    public function leaveGroup($client_id, $group)
    {
        $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_LEAVE_GROUP, '', $group);
    }

    /**
     * 取消分组
     * @access public
     * @param int|string $group
     * @return void
     */
    public function ungroup($group)
    {
        if (empty($group)) {
            return false;
        }
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_UNGROUP;
        $gateway_data['ext_data'] = $group;
        return $this->sendToAllGateway($gateway_data);
    }

    /**
     * 向指定 uid 发送
     * @access public
     * @param int|string|array $uid
     * @param string $message
     * @return void
     */
    public function sendToUid($uid, $message)
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_SEND_TO_UID;
        $gateway_data['body'] = $message;

        if (!is_array($uid)) {
            $uid = explode(',', $uid);
        }

        $gateway_data['ext_data'] = json_encode($uid);

        $this->sendToAllGateway($gateway_data);
    }

    /**
     * 向 group 发送
     * @access public
     * @param int|string|array $group 组（不允许是 0 '0' false null []等为空的值）
     * @param string $message 消息
     * @param array $exclude_client_id 不给这些client_id发
     * @param bool $raw 发送原始数据（即不调用gateway的协议的encode方法）
     * @return void
     */
    public function sendToGroup($group, $message, $exclude_client_id = null, $raw = false)
    {
        if (empty($group)) {
            return false;
        }
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_SEND_TO_GROUP;
        $gateway_data['body'] = $message;
        if ($raw) {
            $gateway_data['flag'] |= Protocol::FLAG_NOT_CALL_ENCODE;
        }

        if (!is_array($group)) {
            $group = explode(',', $group);
        }

        // 分组发送，没有排除的client_id，直接发送
        $default_ext_data_buffer = json_encode(['group'=> $group, 'exclude'=> null]);
        if (empty($exclude_client_id)) {
            $gateway_data['ext_data'] = $default_ext_data_buffer;
            return $this->sendToAllGateway($gateway_data);
        }

        // 分组发送，有排除的client_id，需要将client_id转换成对应gateway进程内的connectionId
        if (!is_array($exclude_client_id)) {
            $exclude_client_id = explode(',', $exclude_client_id);
        }

        $address_connection_array = static::clientIdArrayToAddressArray($exclude_client_id);
        $addresses = $this->getAddresses();
        foreach ($addresses as $address) {
            $gateway_data['ext_data'] = $default_ext_data_buffer;
            if(isset($address_connection_array[$address])){
                $gateway_data['ext_data'] = json_encode(['group'=> $group, 'exclude'=> $address_connection_array[$address]]);
            }
            $this->sendToGateway($address, $gateway_data);
        }
    }

    /**
     * 更新 session，框架自动调用，开发者不要调用
     * @access public
     * @param string $client_id
     * @param string $session_str
     * @return bool
     */
    public function setSocketSession($client_id, $session_str)
    {
        return $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_SET_SESSION, '', $session_str);
    }

    /**
     * 设置 session，原session值会被覆盖
     * @access public
     * @param string $client_id
     * @param array $session
     * @return void
     */
    public function setSession($client_id, array $session)
    {
        if (Context::$client_id === $client_id) {
            $_SESSION = $session;
            Context::$old_session = $_SESSION;
        }
        $this->setSocketSession($client_id, Context::sessionEncode($session));
    }
    
    /**
     * 更新 session，实际上是与老的session合并
     * @access public
     * @param string $client_id
     * @param array $session
     * @return void
     */
    public function updateSession($client_id, array $session)
    {
        if (Context::$client_id === $client_id) {
            $_SESSION = array_replace_recursive((array)$_SESSION, $session);
            Context::$old_session = $_SESSION;
        }
        $this->sendCmdAndMessageToClient($client_id, Protocol::CMD_UPDATE_SESSION, '', Context::sessionEncode($session));
    }
    
    /**
     * 获取某个client_id的session
     * @access public
     * @param string $client_id
     * @return mixed false表示出错、null表示用户不存在、array表示具体的session信息 
     */
    public function getSession($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        if (!$address_data) {
            return false;
        }
        $address = long2ip($address_data['local_ip']) . ':' . $address_data['local_port'];

        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_GET_SESSION_BY_CLIENT_ID;
        $gateway_data['connection_id'] = $address_data['connection_id'];
        return $this->sendAndRecv($address, $gateway_data);
    }

    /**
     * 向某个用户网关发送命令和消息
     * @access protected
     * @param string $client_id
     * @param int $cmd
     * @param string $message
     * @param string $ext_data
     * @return bool
     */
    protected function sendCmdAndMessageToClient($client_id, $cmd, $message, $ext_data = '')
    {
        // 如果是发给当前用户则直接获取上下文中的地址
        if ($client_id === Context::$client_id || $client_id === null) {
            $address = long2ip(Context::$local_ip) . ':' . Context::$local_port;
            $connection_id = Context::$connection_id;
        } else {
            $address_data  = Context::clientIdToAddress($client_id);
            if (!$address_data) {
                return false;
            }
            $address = long2ip($address_data['local_ip']) . ':' . $address_data['local_port'];
            $connection_id = $address_data['connection_id'];
        }
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = $cmd;
        $gateway_data['connection_id'] = $connection_id;
        $gateway_data['body'] = $message;
        if (!empty($ext_data)) {
            $gateway_data['ext_data'] = $ext_data;
        }

        return $this->sendToGateway($address, $gateway_data);
    }

    /**
     * 发送数据并返回
     * @access protected
     * @param int $address
     * @param mixed $data
     * @return bool
     */
    protected function sendAndRecv($address, $data)
    {
        $buffer = $this->generateAuthBuffer() . Protocol::encode($data);
        $client = stream_socket_client('tcp://' . $address, $error_code, $error_message, $this->config['connect_timeout']);
        if (!$client) {
            throw new \Exception('can not connect to tcp://' . $address . ' ' . $errmsg);
        }
        if (strlen($buffer) === stream_socket_sendto($client, $buffer)) {
            $timeout = 5;
            // 阻塞读
            stream_set_blocking($client, 1);
            // 1秒超时
            stream_set_timeout($client, 1);
            $all_buffer = '';
            $time_start = microtime(true);
            $pack_len = 0;
            while (1) {
                $buf = stream_socket_recvfrom($client, 655350);
                if ($buf !== '' && $buf !== false) {
                    $all_buffer .= $buf;
                } else {
                    if (feof($client)) {
                        throw new \Exception('connection close tcp://' . $address);
                    } elseif (microtime(true) - $time_start > $timeout) {
                        break;
                    }
                    continue;
                }
                $recv_len = strlen($all_buffer);
                if (!$pack_len && $recv_len >= 4) {
                    $pack_len= current(unpack('N', $all_buffer));
                }
                // 回复的数据都是以\n结尾
                if (($pack_len && $recv_len >= $pack_len + 4) || microtime(true) - $time_start > $timeout) {
                    break;
                }
            }
            // 返回结果
            return unserialize(substr($all_buffer, 4));
        } else {
            throw new \Exception("sendAndRecv($address, \$bufer) fail ! Can not send data!", 502);
        }
    }

    /**
     * 发送数据到网关
     * @access protected
     * @param string $address
     * @param array $gateway_data
     * @return bool
     */
    protected function sendToGateway($address, $gateway_data)
    {
        return $this->sendBufferToGateway($address, Protocol::encode($gateway_data));
    }

    /**
     * 发送buffer数据到网关
     * @access protected
     * @param string $address
     * @param string $gateway_buffer
     * @return bool
     */
    protected function sendBufferToGateway($address, $gateway_buffer)
    {
        // 当前连接参数
        $options = $this->config;
        // 拼接鉴权数据
        $gateway_buffer = $this->generateAuthBuffer() . $gateway_buffer;
        // 连接类型
        $flag = STREAM_CLIENT_CONNECT;
        // 长连接
        if($options['persistent_connection']){
            $flag = STREAM_CLIENT_PERSISTENT | STREAM_CLIENT_CONNECT;
        }
        // 创建连接
        $client = stream_socket_client('tcp://' . $address, $error_code, $error_message, $options['connect_timeout'], $flag);
        // 返回
        return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
    }

    /**
     * 向所有 gateway 发送数据
     * @access protected
     * @param string $gateway_data
     * @return void
     */
    protected function sendToAllGateway($gateway_data)
    {
        $buffer = Protocol::encode($gateway_data);
        $all_addresses = $this->getAddresses();
        foreach ($all_addresses as $address) {
            $this->sendBufferToGateway($address, $buffer);
        }
    }

    /**
     * 踢掉某个网关的 socket
     * @access protected
     * @param string $address
     * @param int $connection_id
     * @return bool
     */
    protected function kickAddress($address, $connection_id, $message)
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_KICK;
        $gateway_data['connection_id'] = $connection_id;
        $gateway_data['body'] = $message;
        return $this->sendToGateway($address, $gateway_data);
    }

    /**
     * 销毁某个网关的 socket
     * @access protected
     * @param string $address
     * @param int $connection_id
     * @return bool
     */
    protected function destroyAddress($address, $connection_id)
    {
        $gateway_data = Protocol::$empty;
        $gateway_data['cmd'] = Protocol::CMD_DESTROY;
        $gateway_data['connection_id'] = $connection_id;
        return $this->sendToGateway($address, $gateway_data);
    }

    /**
     * 将clientid数组转换成address数组
     * @access protected
     * @param array $client_id_array
     * @return array
     */
    protected static function clientIdArrayToAddressArray(array $client_id_array)
    {
        $address_connection_array = [];
        // 遍历
        foreach ($client_id_array as $client_id) {
            $address_data = Context::clientIdToAddress($client_id);
            if ($address_data) {
                $address = long2ip($address_data['local_ip']) . ':' . $address_data['local_port'];
                $address_connection_array[$address][$address_data['connection_id']] = $address_data['connection_id'];
            }
        }
        return $address_connection_array;
    }

    /**
     * 获取全部Gateway通讯地址
     * @access protected
     * @return array
     */
    protected function getAddresses()
    {
        // 当前请求时间
        $requestTime = time();
        // 连接参数
        $options = $this->config;
        // 通讯地址存在且缓存未过期
        if (!empty($this->addresses) && $requestTime - $this->getAddressesTime < 1) {
            // 直接返回缓存的通讯地址
            return $this->addresses;
        }
        // 获取通讯地址
        $registerAddresses = (array) $options['register_address'];
        // 连接的客户端
        $client = null;
        // 遍历通讯地址
        foreach ($registerAddresses as $registerAddress) {
            set_error_handler(function(){});
            $client = stream_socket_client('tcp://' . $registerAddress, $error_code, $error_message, $options['connect_timeout']);
            restore_error_handler();
            if ($client) {
                break;
            }
        }
        if (!$client) {
            throw new \Exception('Can not connect to tcp://' . $registerAddress . ' ' . $error_message, $error_code);
        }
        // 发送连接数据
        fwrite($client, json_encode([
            'event' => 'worker_connect',
            'secret_key' => $options['secret_key'],
        ]) . "\n");
        // 设置超时
        stream_set_timeout($client, 5);
        // 获取数据
        $ret = fgets($client, 655350);
        // 获取数据失败
        if (!$ret || !$data = json_decode(trim($ret), true)) {
            throw new \Exception('getAddresses failed. tcp://' . $registerAddress . ' return ' . var_export($ret, true));
        }
        // 更新时间
        $this->getAddressesTime = $requestTime;
        // 存储通讯地址并返回
        return $this->addresses = $data['addresses'];
    }
}
