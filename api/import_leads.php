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

// Recipient country (CASL, item 8, additive): optional POST field applied to
// every imported row, or an optional 6th CSV column per row (the column wins
// when non-empty). ISO-3166-1 alpha-2; invalid values → NULL (unknown).
// When leads.country_code does not exist yet (item-8 DDL not applied), the
// import behaves exactly as before.
$countryDefault = \App\Compliance::normalizeCountryCode($_POST['country_code'] ?? null);
$hasCountryCol = false;
try {
    $colStmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() " .
        "AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'country_code' LIMIT 1"
    );
    $colStmt->execute();
    $hasCountryCol = (bool)$colStmt->fetch();
} catch (\Throwable $e) {
    $hasCountryCol = false;
}

// Campaign attribution (item 10): leads.campaign_id is written only when the
// column exists; pre-migration imports behave exactly as before.
$hasCampaignIdCol = false;
try {
    $colStmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() " .
        "AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'campaign_id' LIMIT 1"
    );
    $colStmt->execute();
    $hasCampaignIdCol = (bool)$colStmt->fetch();
} catch (\Throwable $e) {
    $hasCampaignIdCol = false;
}

// Skip header — Phase 0 fix: the old code BLINDLY discarded the first row,
// eating a real lead whenever the CSV had no header. Now we only skip it
// when it actually looks like a header row.
$header = fgetcsv($handle);
$lineNum = 1;
$pendingRow = null;
if ($header !== false && !importLooksLikeHeader($header)) {
    $pendingRow = $header; // no header present — first row is data
    $header = null;
}
$imported = 0;
$skipped = 0;
$errors = 0;
$errorRows = []; // capped sample of rejected rows for the response
$updatedCampaign = 0;

// Re-import semantics (Phase 0 fix — was silent): duplicates used to keep
// their OLD campaign_id with no way to know or change it. Default 'keep'
// preserves the old behavior; 'update_campaign' re-assigns duplicates to
// this import's campaign. The choice is echoed in the response meta.
$onDuplicate = strtolower(trim((string)($_POST['on_duplicate'] ?? 'keep')));
if ($onDuplicate !== 'update_campaign') { $onDuplicate = 'keep'; }
$allowCampaignUpdate = ($onDuplicate === 'update_campaign') && $hasCampaignIdCol;

/**
 * Heuristic: does this row look like a header rather than lead data?
 * A valid email in the email column means DATA, not a header.
 */
function importLooksLikeHeader(array $row): bool {
    $emailCell = strtolower(trim((string)($row[2] ?? '')));
    // A real address in the email column means DATA, never a header.
    if (filter_var($emailCell, FILTER_VALIDATE_EMAIL)) return false;
    // Common header labels (anchored — a garbage value like "not-an-email"
    // must NOT match, or a real lead row would be silently skipped).
    if (preg_match('/^(e-?mail|email[ _-]?address|contact[ _-]?email)$/', $emailCell)) return true;
    $companyCell = strtolower(trim((string)($row[0] ?? '')));
    if (preg_match('/^(company|business|organi[sz]ation)([ _-]?name)?$/', $companyCell)) return true;
    return false;
}

try {
    // App\PDO is a mysqli shim without beginTransaction()/commit()/rollBack();
    // use raw SQL transaction statements instead (import_leads fix, item 10).
    $pdo->exec('START TRANSACTION');
    $extraCols = [];
    if ($hasCampaignIdCol) { $extraCols[] = 'campaign_id'; }
    if ($hasLawfulBasisCol) { $extraCols[] = 'lawful_basis'; }
    if ($hasCountryCol) { $extraCols[] = 'country_code'; }
    $placeholders = implode(', ', array_fill(0, 5 + count($extraCols), '?'));
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO leads (company_name, contact_name, email, website, source" .
        ($extraCols ? ', ' . implode(', ', $extraCols) : '') .
        ") VALUES ({$placeholders})"
    );

    // $lineNum tracks the 1-based CSV line of the row being processed
    // ($header/first-row read above consumed line 1).
    while (true) {
        if ($pendingRow !== null) {
            $row = $pendingRow;
            $pendingRow = null; // consumed — still line 1
        } else {
            $row = fgetcsv($handle);
            if ($row === FALSE) break;
            $lineNum++;
        }
        // Basic assumption: col 0=Company, 1=Contact, 2=Email, 3=Website,
        // 4=Lawful basis (optional), 5=Country ISO-2 (optional)
        if (count($row) >= 3) {
            // Phase 0 fix: validate the email instead of inserting garbage
            // (or silently dropping short rows with no accounting).
            $email = strtolower(trim((string)($row[2] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors++;
                if (count($errorRows) < 20) {
                    $errorRows[] = [
                        'row' => $lineNum,
                        'reason' => $email === '' ? 'missing email' : 'invalid email',
                        'value' => substr((string)($row[2] ?? ''), 0, 80),
                    ];
                }
                continue;
            }
            $params = [
                trim((string)$row[0]),
                trim((string)($row[1] ?? '')),
                $email,
                trim((string)($row[3] ?? '')),
                'CSV Import'
            ];
            if ($hasCampaignIdCol) { $params[] = $campaign_id; }
            if ($hasLawfulBasisCol) {
                $rowBasis = isset($row[4]) ? substr(trim((string)$row[4]), 0, 50) : '';
                $params[] = $rowBasis !== '' ? $rowBasis : $lawfulBasisDefault;
            }
            if ($hasCountryCol) {
                $rowCountry = isset($row[5]) ? \App\Compliance::normalizeCountryCode($row[5]) : null;
                $params[] = $rowCountry !== null ? $rowCountry : $countryDefault;
            }
            $stmt->execute($params);
            // INSERT IGNORE skips duplicates silently: only count real inserts.
            // mysqli affected_rows is 1 for an insert, 0 for an ignored duplicate.
            if ($stmt->rowCount() > 0) {
                $imported++;
            } else {
                $skipped++;
                // Explicit re-import semantics: optionally re-assign the
                // duplicate to this import's campaign instead of silently
                // keeping the old one.
                if ($allowCampaignUpdate && $campaign_id !== null) {
                    $upd = $pdo->prepare("UPDATE leads SET campaign_id = ? WHERE email = ?");
                    $upd->execute([$campaign_id, $email]);
                    if ($upd->rowCount() > 0) { $updatedCampaign++; }
                }
            }
        } else {
            // Row too short to be a lead — count it as an error, not silence.
            $errors++;
            if (count($errorRows) < 20) {
                $errorRows[] = ['row' => $lineNum, 'reason' => 'too few columns', 'value' => ''];
            }
        }
    }
    $pdo->exec('COMMIT');
    echo json_encode([
        'success' => true,
        'data' => [
            'count' => $imported,
            'skipped_duplicates' => $skipped,
            'errors' => $errors,
            'error_rows' => $errorRows,
            'updated_campaign' => $updatedCampaign,
        ],
        'count' => $imported,
        'meta' => ['timestamp' => date('c'), 'on_duplicate' => $onDuplicate]
    ]);
} catch (Exception $e) {
    $pdo->exec('ROLLBACK');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

fclose($handle);
?>
