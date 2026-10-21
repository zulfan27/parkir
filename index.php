<?php

require_once __DIR__ . "/includes/bootstrap.php";
if (current_user()) {
    redirect(role_home());
}
redirect("login.php");
