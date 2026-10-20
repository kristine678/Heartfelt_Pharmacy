<?php
require_once 'config/db.php';

if (!isLoggedIn()) {
    header('Location: ' . staffLoginUrl());
    exit;
}

header('Location: ' . staffDashboardUrl());
exit;
