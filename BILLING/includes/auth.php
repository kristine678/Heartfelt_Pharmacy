<?php
require_once __DIR__ . '/../config/db.php';

if (basename($_SERVER['PHP_SELF']) !== 'login.php' && !isset($_SESSION['user_id'])) {
    redirect(staffLoginUrl());
}
