<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

new \yii\console\Application([
    'id'         => 'test',
    'basePath'   => __DIR__,
    'components' => [
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn'   => 'sqlite::memory:',
        ],
    ],
]);

\Yii::$app->db->createCommand(
    'CREATE TABLE post (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT NOT NULL DEFAULT \'\',
        deleted_at INTEGER DEFAULT NULL
    )'
)->execute();

\Yii::$app->db->createCommand(
    'CREATE TABLE article (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT NOT NULL DEFAULT \'\',
        is_deleted INTEGER NOT NULL DEFAULT 0
    )'
)->execute();

\Yii::$app->db->createCommand(
    'CREATE TABLE comment (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id    INTEGER NOT NULL,
        body       TEXT NOT NULL DEFAULT \'\',
        deleted_at INTEGER DEFAULT NULL
    )'
)->execute();