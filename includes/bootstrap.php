<?php

declare(strict_types=1);
session_start();
$configFile = __DIR__ . "/../config/config.php";
$config = file_exists($configFile)
    ? require $configFile
    : require __DIR__ . "/../config/config.example.php";
date_default_timezone_set($config["timezone"] ?? "Asia/Jakarta");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(
        $config["db_host"],
        $config["db_user"],
        $config["db_pass"],
        $config["db_name"],
    );
    $db->set_charset("utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    exit(
        "Koneksi database gagal. Periksa MySQL dan config/config.php. Detail: " .
            htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8")
    );
}
require_once __DIR__ . "/functions.php";
