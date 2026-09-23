<?php
require_once __DIR__ . '/../includes/autoload.php';

use App\DorkLibrary;
use App\ExtractionEngine;

echo "--- Testing DorkLibrary ---\n";
$dorks = DorkLibrary::generateDorks("HVAC Repair", "Austin, TX");
echo "Social Intent Dork: " . $dorks['social_intent'] . "\n";

$expansions = DorkLibrary::expandLocation("Austin, TX");
echo "Location Expansions: " . implode(", ", $expansions) . "\n\n";

echo "--- Testing ExtractionEngine ---\n";
$htmlSample = "Contact us at info [at] example [dot] com or personal.email@gmail.com. Find us on facebook.com/testbiz";
$emails = ExtractionEngine::extractEmails($htmlSample);
echo "Extracted Emails: " . implode(", ", $emails) . "\n";

$socials = ExtractionEngine::extractSocials($htmlSample);
echo "Extracted Socials: " . json_encode($socials) . "\n";

if (in_array('personal.email@gmail.com', $emails) && isset($socials['facebook'])) {
    echo "\n✅ VERIFICATION SUCCESSFUL: Extraction logic is functional.\n";
} else {
    echo "\n❌ VERIFICATION FAILED: Results do not match expected output.\n";
}
