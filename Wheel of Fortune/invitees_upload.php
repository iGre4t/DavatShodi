<?php
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
  echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
  exit;
}

$csv = (string)($input['csv'] ?? '');
$mapping = $input['mapping'] ?? null;
if ($csv === '' || !is_array($mapping)) {
  echo json_encode(['status' => 'error', 'message' => 'Missing data.']);
  exit;
}

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'WF Event';
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}

$filePath = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapPath = $baseDir . DIRECTORY_SEPARATOR . 'WF Mapped.json';

if (file_put_contents($filePath, $csv) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save file.']);
  exit;
}

$mapPayload = [
  'workId' => (int)($mapping['workId'] ?? -1),
  'firstName' => (int)($mapping['firstName'] ?? -1),
  'lastName' => (int)($mapping['lastName'] ?? -1),
  'nationalId' => (int)($mapping['nationalId'] ?? -1),
  'phoneNumber' => (int)($mapping['phoneNumber'] ?? -1)
];

if (file_put_contents($mapPath, json_encode($mapPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
  echo json_encode(['status' => 'error', 'message' => 'Failed to save mapping.']);
  exit;
}

echo json_encode(['status' => 'ok']);
