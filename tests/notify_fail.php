<?php
http_response_code(500);
header('Content-Type: application/json');
echo json_encode(['error' => 'wallet credit failed']);
