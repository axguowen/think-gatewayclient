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

return [
    // 默认连接本机
    'default' => 'localhost',
    // 连接配置
    'connections' => [
        // 本机连接参数
        'localhost' => [
            // Gateway注册服务地址
            'register_address' => '127.0.0.1:1236',
            // 密钥, 为对应Register服务设置的密钥
            'secret_key' => '',
            // 连接超时时间，单位：秒
            'connect_timeout' => 3,
            // 与Gateway是否是长链接
            'persistent_connection' => false,
            // 禁用服务注册地址缓存
            'addresses_cache_disable' => false,
        ],
        // 其它主机连接参数
        'other' => [
            // Gateway注册服务地址
            'register_address' => '192.168.0.89:1236',
        ],
    ],
];
