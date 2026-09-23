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

// Lawful basis / provenance (GDPR, additive): optional POST field applied to
// every imported row, or an optional 5th CSV column per row (the column wins
// when non-empty). Recommended values: consent, contract, legitimate_interest,
// legal_obligation — stored free-form (VARCHAR(50)) otherwise. When the
// leads.lawful_basis column does not exist yet (GDPR DDL not applied), the
// import behaves exactly as before.
$lawfulBasisDefault = isset($_POST['lawful_basis']) ? substr(trim((string)$_POST['lawful_basis']), 0, 50) : '';
$lawfulBasisDefault = $lawfulBasisDefault !== '' ? $lawfulBasisDefault : null;
$hasLawfulBasisCol = \App\Gdpr::columnExists($pdo, 'leads', 'lawful_basis');

// Skip header
$header = fgetcsv($handle);
$imported = 0;
$errors = 0;

try {
    $pdo->beginTransaction();
    $extraCols = [];
    if ($hasLawfulBasisCol) { $extraCols[] = 'lawful_basis'; }
    $placeholders = implode(', ', array_fill(0, 6 + count($extraCols), '?'));
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO leads (company_name, contact_name, email, website, campaign_id, source" .
        ($extraCols ? ', ' . implode(', ', $extraCols) : '') .
        ") VALUES ({$placeholders})"
    );

    while (($row = fgetcsv($handle)) !== FALSE) {
        // Basic assumption: col 0=Company, 1=Contact, 2=Email, 3=Website,
        // 4=Lawful basis (optional)
        if (count($row) >= 3) {
            $params = [
                $row[0],
                $row[1] ?? '',
                $row[2],
                $row[3] ?? '',
                $campaign_id,
                'CSV Import'
            ];
            if ($hasLawfulBasisCol) {
                $rowBasis = isset($row[4]) ? substr(trim((string)$row[4]), 0, 50) : '';
                $params[] = $rowBasis !== '' ? $rowBasis : $lawfulBasisDefault;
            }
            $stmt->execute($params);
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
