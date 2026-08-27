<?php
file_put_contents(__DIR__ . '/notify_count.txt', '1', FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
