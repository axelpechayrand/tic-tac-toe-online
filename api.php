<?php
header('Content-Type: application/json');

// --- À REMPLIR avec tes identifiants InfinityFree (menu "MySQL Databases") ---
$DB_HOST = 'sqlXXX.infinityfree.com';
$DB_NAME = 'epiz_XXXXXXX_tictactoe';
$DB_USER = 'epiz_XXXXXXX';
$DB_PASS = 'ton_mot_de_passe';
// -----------------------------------------------------------------------------

function respond($data) { echo json_encode($data); exit; }

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    respond(['ok' => false, 'error' => 'Connexion base de données impossible.']);
}

$action = $_REQUEST['action'] ?? '';

function genCode() {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 5; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}

function roomToArray($row) {
    return [
        'id' => $row['id'],
        'board' => str_split($row['board']),
        'turn' => $row['turn'],
        'players' => ['X' => (bool)$row['player_x'], 'O' => (bool)$row['player_o']],
        'status' => $row['status'],
        'winner' => $row['winner'],
        'win_line' => $row['win_line'],
    ];
}

$LINES = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];

function checkWinner($board, $LINES) {
    foreach ($LINES as $line) {
        [$a, $b, $c] = $line;
        if ($board[$a] !== '-' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
            return ['player' => $board[$a], 'line' => implode(',', $line)];
        }
    }
    return null;
}

if ($action === 'create') {
    // petit ménage des vieux salons (plus de 24h)
    $pdo->exec("DELETE FROM rooms WHERE updated_at < NOW() - INTERVAL 1 DAY");

    do {
        $id = genCode();
        $stmt = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
        $stmt->execute([$id]);
    } while ($stmt->fetch());

    $stmt = $pdo->prepare("INSERT INTO rooms (id, board, turn, player_x, player_o, status) VALUES (?, '---------', 'X', 1, 0, 'waiting')");
    $stmt->execute([$id]);

    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    respond(['ok' => true, 'room' => roomToArray($stmt->fetch(PDO::FETCH_ASSOC))]);
}

if ($action === 'join') {
    $id = strtoupper($_REQUEST['id'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(['ok' => false, 'error' => 'Salon introuvable. Vérifie le code.']);
    if ($row['player_o']) respond(['ok' => false, 'error' => 'Ce salon est déjà complet.']);

    $status = $row['player_x'] ? 'playing' : 'waiting';
    $stmt = $pdo->prepare("UPDATE rooms SET player_o = 1, status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    respond(['ok' => true, 'symbol' => 'O', 'room' => roomToArray($stmt->fetch(PDO::FETCH_ASSOC))]);
}

if ($action === 'state') {
    $id = strtoupper($_REQUEST['id'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(['ok' => false, 'error' => 'Salon introuvable.']);
    respond(['ok' => true, 'room' => roomToArray($row)]);
}

if ($action === 'move') {
    $id = strtoupper($_REQUEST['id'] ?? '');
    $index = intval($_REQUEST['index'] ?? -1);
    $symbol = $_REQUEST['symbol'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(['ok' => false, 'error' => 'Salon introuvable.']);

    if ($row['status'] !== 'playing' || $row['turn'] !== $symbol || $index < 0 || $index > 8) {
        respond(['ok' => true, 'room' => roomToArray($row)]); // coup ignoré, on renvoie l'état actuel
    }

    $board = str_split($row['board']);
    if ($board[$index] !== '-') respond(['ok' => true, 'room' => roomToArray($row)]);

    $board[$index] = $symbol;
    $newBoard = implode('', $board);
    $result = checkWinner($board, $LINES);
    $nextTurn = $symbol === 'X' ? 'O' : 'X';

    if ($result) {
        $stmt = $pdo->prepare("UPDATE rooms SET board=?, turn=?, status='finished', winner=?, win_line=? WHERE id=?");
        $stmt->execute([$newBoard, $nextTurn, $result['player'], $result['line'], $id]);
    } elseif (!in_array('-', $board, true)) {
        $stmt = $pdo->prepare("UPDATE rooms SET board=?, turn=?, status='finished', winner='draw', win_line=NULL WHERE id=?");
        $stmt->execute([$newBoard, $nextTurn, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE rooms SET board=?, turn=? WHERE id=?");
        $stmt->execute([$newBoard, $nextTurn, $id]);
    }

    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    respond(['ok' => true, 'room' => roomToArray($stmt->fetch(PDO::FETCH_ASSOC))]);
}

if ($action === 'rematch') {
    $id = strtoupper($_REQUEST['id'] ?? '');
    $stmt = $pdo->prepare("UPDATE rooms SET board='---------', turn='X', status='playing', winner=NULL, win_line=NULL WHERE id=?");
    $stmt->execute([$id]);
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    respond(['ok' => true, 'room' => roomToArray($stmt->fetch(PDO::FETCH_ASSOC))]);
}

respond(['ok' => false, 'error' => 'Action inconnue.']);
