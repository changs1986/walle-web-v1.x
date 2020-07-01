<?php
// Uncomment to enable debug mode. Recommended for development.
defined('YII_DEBUG') or define('YII_DEBUG', false);

// Uncomment to enable dev environment. Recommended for development
defined('YII_ENV') or define('YII_ENV', 'prod');

if (empty($_ENV)) {
    $_ENV = $_SERVER;
    foreach ($_ENV as $key => $value) {
        if (strpos($key, '_PASS')) {
            $_ENV[$key] = base64_decode($value);
            if ($_ENV[$key] === false) {
                $_ENV[$key] = $value;
            }
        }
    }
}

return [
    'components' => [
        'db' => [
            'dsn'       => isset($_ENV['WALLE_DB_DSN'])  ? $_ENV['WALLE_DB_DSN']  : 'mysql:host=127.0.0.1;dbname=walle',
            'username'  => isset($_ENV['WALLE_DB_USER']) ? $_ENV['WALLE_DB_USER'] : 'root',
            'password'  => isset($_ENV['WALLE_DB_PASS']) ? $_ENV['WALLE_DB_PASS'] : '',
        ],
        //使用sendmail发布
        'mail' => [
            'class' => 'yii\swiftmailer\Mailer',
            'viewPath' => 'mail',
            'transport' => [
                'class' => 'Swift_MailTransport',
            ],
            'useFileTransport' => false,
            'messageConfig' => [
                'charset' => 'UTF-8',
            ],
        ],
        'request' => [
            'cookieValidationKey' => 'PdXWDAfV5-gPJJWRar5sEN71DN0JcDRV',
        ],
        'redis' => [
            'class' => 'yii\redis\Connection',
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ],
    ],
    'language'   => isset($_ENV['WALLE_LANGUAGE']) ? $_ENV['WALLE_LANGUAGE'] : 'zh-CN', // zh-CN => 中文,  en => English
];
