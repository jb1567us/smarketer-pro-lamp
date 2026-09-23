<?php
/**
 * Lead Import API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'No file uploaded']));
}

$file = $_FILES['file']['tmp_name'];
$handle = fopen($file, "r");

if ($handle === FALSE) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Could not open file']));
}

$campaign_id = isset($_POST['campaign_id']) && $_POST['campaign_id'] !== '' ? (int)$_POST['campaign_id'] : null;

// Skip header
$header = fgetcsv($handle);
$imported = 0;
$errors = 0;

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT IGNORE INTO leads (company_name, contact_name, email, website, campaign_id, source) VALUES (?, ?, ?, ?, ?, ?)");

    while (($row = fgetcsv($handle)) !== FALSE) {
        // Basic assumption: col 0=Company, 1=Contact, 2=Email, 3=Website
        if (count($row) >= 3) {
            $stmt->execute([
                $row[0],
                $row[1] ?? '',
                $row[2],
                $row[3] ?? '',
                $campaign_id,
                'CSV Import'
            ]);
            $imported++;
        }
    }
    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'data' => ['count' => $imported],
        'count' => $imported,
        'meta' => ['timestamp' => date('c')]
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

fclose($handle);
?>
