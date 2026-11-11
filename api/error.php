<?php
header('Content-Type: application/json');
$code = (int) ($_GET['code'] ?? 500);
$messages = [
    400 => 'Bad Request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    422 => 'Unprocessable Entity',
    429 => 'Too Many Requests',
    500 => 'Internal Server Error',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
];
http_response_code($code);
echo json_encode(['error' => $messages[$code] ?? 'Error', 'status' => $code]);
