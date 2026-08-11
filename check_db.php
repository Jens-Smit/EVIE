<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=evie_db', 'root', '');

echo "=== AGENT_HISTORY DATA ===\n";
$rows = $pdo->query('SELECT * FROM agent_history')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}
echo "\n";


echo "=== USER_PROFILE DATA ===\n";
$rows = $pdo->query('SELECT * FROM user_profile')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}