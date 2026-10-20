<?php
header('Content-Type: application/json');

http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'Customer sign-up is currently disabled.'
]);
