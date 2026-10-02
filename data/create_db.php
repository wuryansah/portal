<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', '');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
echo "MySQL connected: " . $mysqli->server_info . "\n";
$mysqli->query("CREATE DATABASE IF NOT EXISTS mall_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database mall_portal ready\n";
$mysqli->close();