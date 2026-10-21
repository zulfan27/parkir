<?php

require_once __DIR__ . "/includes/bootstrap.php";
if (current_user()) {
    log_activity($db, (int) current_user()["id_user"], "Logout aplikasi");
}
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 42000,
        $p["path"],
        $p["domain"],
        $p["secure"],
        $p["httponly"],
    );
}
session_destroy();
redirect("login.php");
