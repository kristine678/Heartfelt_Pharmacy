<?php
require_once 'config/db.php';
require_once 'includes/permissions.php';
// TASK 2: Help Center is a default page for staff; guarded like every other page.
requirePageAccess('help');

$activePage = 'help';
include 'includes/header.php';
?>
<div class="page-title">
    <h1>Help Center</h1>
</div>
<div class="form-card">
    <h3>How it works</h3>
    <ul>
        <li>Create a customer and order from the menu screen.</li>
        <li>Add menu items to build the current bill.</li>
        <li>Use Print, Fire, or Charge to move through the workflow.</li>
        <li>Review completed sales in History.</li>
    </ul>
    <h3>FAQ</h3>
    <p><strong>How do I change tax rates?</strong> Visit Settings and update the tax and service charge fields.</p>
    <p><strong>How can I print a receipt?</strong> Open the bill and select Print, or use the Print button from the order screen.</p>
</div>
<div class="form-card" style="margin-top: 16px;">
    <a href="logout.php" class="btn btn-primary">Logout</a>
</div>
<?php include 'includes/footer.php'; ?>
