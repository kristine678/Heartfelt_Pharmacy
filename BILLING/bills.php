<?php
// Bills was a duplicate of History (same paid-orders query, same rows) and has been removed.
// This stub only exists so old bookmarks, links and the /bills URL keep working: they now
// land on History, which shows everything Bills did (Order #, Status, Total, Created,
// View receipt, Email receipt, admin Delete).
require_once 'config/db.php';

if (!isLoggedIn()) {
    redirect(staffLoginUrl());
}

header('Location: history.php');
exit;
