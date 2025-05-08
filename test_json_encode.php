<?php
require_once 'config/db.php';

// Get problems from database
$stmt = $conn->prepare("SELECT * FROM problems LIMIT 5");
$stmt->execute();
$problems = $stmt->get_result();

echo "<h1>Testing JSON Encoding of Problem Data</h1>";

while ($problem = $problems->fetch_assoc()) {
    echo "<h2>Problem: " . htmlspecialchars($problem['title']) . " (ID: " . $problem['id'] . ")</h2>";
    
    // Original problem data
    echo "<h3>Original Problem Data (substring of first 100 chars)</h3>";
    echo "<pre>";
    foreach ($problem as $key => $value) {
        if (is_string($value) && strlen($value) > 100) {
            echo htmlspecialchars($key) . ": " . htmlspecialchars(substr($value, 0, 100)) . "...\n";
        } else {
            echo htmlspecialchars($key) . ": " . htmlspecialchars(var_export($value, true)) . "\n";
        }
    }
    echo "</pre>";
    
    // Test json_encode
    $json = json_encode($problem);
    $jsonError = json_last_error_msg();
    
    echo "<h3>JSON Encoding Result</h3>";
    if ($json === false) {
        echo "<p style='color:red'>JSON encoding error: " . $jsonError . "</p>";
    } else {
        echo "<p style='color:green'>JSON encoding successful!</p>";
        echo "<p>JSON string length: " . strlen($json) . "</p>";
        
        // Test decode back
        $decoded = json_decode($json, true);
        echo "<p>Successfully decoded back: " . ($decoded ? "Yes" : "No") . "</p>";
        
        // Check for specific encoding issues in description
        if (isset($problem['description'])) {
            $descLength = strlen($problem['description']);
            $encodedDesc = json_encode($problem['description']);
            $decodedDesc = json_decode($encodedDesc);
            
            echo "<h4>Description Field Specific Check</h4>";
            echo "<p>Original description length: " . $descLength . "</p>";
            echo "<p>Encoded description length: " . strlen($encodedDesc) . "</p>";
            echo "<p>Description encoding/decoding match: " . ($decodedDesc === $problem['description'] ? "Yes" : "No") . "</p>";
            
            if ($decodedDesc !== $problem['description']) {
                echo "<p style='color:red'>Warning: Description field has encoding issues!</p>";
                
                // Find problematic characters
                for ($i = 0; $i < $descLength; $i++) {
                    $char = $problem['description'][$i];
                    $encoded = json_encode($char);
                    $decoded = json_decode($encoded);
                    
                    if ($decoded !== $char) {
                        $ordVal = ord($char);
                        echo "<p>Problematic character at position $i: Char code: $ordVal</p>";
                        if (count($problemChars = []) < 5) { // Show max 5 problematic chars
                            $problemChars[] = $i;
                        }
                    }
                }
            }
        }
    }
    
    echo "<hr>";
}
?> 