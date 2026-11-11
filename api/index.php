<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'endpoint' => '/api/'], JSON_UNESCAPED_SLASHES);
