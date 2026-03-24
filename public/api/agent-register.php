<?php
// =============================================================================
//  Server Registration Endpoint
//  POST /api/agent-register.php
//  Body: reg_token, name, hostname, ip, auto_block
//  Returns: JSON { success, token }
// =============================================================================
require_once __DIR__ . '/../../app/db/auth_db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$regToken  = trim($_POST['reg_token']  ?? '');
$name      = trim($_POST['name']       ?? '');
$hostname  = trim($_POST['hostname']   ?? gethostname());
$ip        = trim($_POST['ip']         ?? '');
$autoBlock = !empty($_POST['auto_block']) && $_POST['auto_block'] !== 'false';

if (empty($regToken) || empty($name)) {
    http_response_code(400);
    echo json_encode(['error' => 'reg_token und name erforderlich']);
    exit;
}

if (!validateAndConsumeRegToken($regToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Ungültiger oder abgelaufener Registrierungs-Token']);
    exit;
}

$serverToken = registerServer($name, $hostname, $ip, $autoBlock);

echo json_encode([
    'success' => true,
    'token'   => $serverToken,
    'name'    => $name,
]);
