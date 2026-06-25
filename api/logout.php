<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
logout();
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
