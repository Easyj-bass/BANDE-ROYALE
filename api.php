<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/config.php';

// Parse input
$method = $_SERVER['REQUEST_METHOD'];
$isJson = (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $input  = [];
} elseif ($isJson) {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
} else {
    $action = $_POST['action'] ?? '';
    $input  = $_POST;
}

try {
    initDB();
    $pdo = getDB();

    switch ($action) {

        /* ── Ping ── */
        case 'ping':
            echo json_encode(['ok' => true, 'db' => DB_NAME]);
            break;

        /* ══════════════════════════════════
           FINANCE
        ══════════════════════════════════ */

        case 'get_finance':
            $txRows = $pdo->query(
                "SELECT id, type, description, DATE_FORMAT(date,'%Y-%m-%d') AS date, montant, event_id
                 FROM transactions ORDER BY date DESC, id DESC"
            )->fetchAll();

            $detRows = $pdo->query(
                "SELECT id, transaction_id, nom, montant, motif FROM transaction_details ORDER BY id ASC"
            )->fetchAll();

            $detMap = [];
            foreach ($detRows as $d) {
                $tid = (int)$d['transaction_id'];
                $detMap[$tid][] = [
                    'id'      => (int)$d['id'],
                    'nom'     => $d['nom'],
                    'montant' => (float)$d['montant'],
                    'motif'   => $d['motif'],
                ];
            }

            $cred = []; $deb = [];
            foreach ($txRows as $t) {
                $tid  = (int)$t['id'];
                $dets = $detMap[$tid] ?? null;
                $entry = [
                    'id'       => $tid,
                    'desc'     => $t['description'],
                    'date'     => $t['date'],
                    'amt'      => (float)$t['montant'],
                    'details'  => ($dets && count($dets) > 0) ? $dets : null,
                    'event_id' => $t['event_id'] ? (int)$t['event_id'] : null,
                ];
                if ($t['type'] === 'cred') $cred[] = $entry;
                else                       $deb[]  = $entry;
            }
            echo json_encode(['cred' => $cred, 'deb' => $deb]);
            break;

        case 'add_transaction':
            $type    = $input['type']    ?? '';
            $desc    = trim($input['desc'] ?? '');
            $date    = $input['date']    ?? date('Y-m-d');
            $amt     = (float)($input['amt'] ?? 0);
            $details = $input['details'] ?? [];

            if (!$desc)                          { echo json_encode(['error' => 'Description manquante']); break; }
            if (!in_array($type, ['cred','deb'])) { echo json_encode(['error' => 'Type invalide']);        break; }

            $s = $pdo->prepare("INSERT INTO transactions (type, description, date, montant) VALUES (?,?,?,?)");
            $s->execute([$type, $desc, $date, $amt]);
            $txId = (int)$pdo->lastInsertId();

            if (!empty($details)) {
                $s2 = $pdo->prepare("INSERT INTO transaction_details (transaction_id, nom, montant, motif) VALUES (?,?,?,?)");
                foreach ($details as $d) {
                    $s2->execute([$txId, $d['nom'] ?? '', (float)($d['montant'] ?? 0), $d['motif'] ?? '—']);
                }
            }
            echo json_encode(['ok' => true, 'id' => $txId]);
            break;

        case 'edit_transaction':
            $id      = (int)($input['id'] ?? 0);
            $desc    = trim($input['desc'] ?? '');
            $date    = $input['date']    ?? date('Y-m-d');
            $amt     = (float)($input['amt'] ?? 0);
            $details = $input['details'] ?? [];

            if (!$id) { echo json_encode(['error' => 'ID manquant']); break; }

            $pdo->prepare("UPDATE transactions SET description=?, date=?, montant=? WHERE id=?")
                ->execute([$desc, $date, $amt, $id]);

            $pdo->prepare("DELETE FROM transaction_details WHERE transaction_id=?")->execute([$id]);

            if (!empty($details)) {
                $s2 = $pdo->prepare("INSERT INTO transaction_details (transaction_id, nom, montant, motif) VALUES (?,?,?,?)");
                foreach ($details as $d) {
                    $s2->execute([$id, $d['nom'] ?? '', (float)($d['montant'] ?? 0), $d['motif'] ?? '—']);
                }
            }
            echo json_encode(['ok' => true]);
            break;

        case 'delete_transaction':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['error' => 'ID manquant']); break; }
            $pdo->prepare("DELETE FROM transactions WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        /* ══════════════════════════════════
           PROGRAMME / ÉVÉNEMENTS
        ══════════════════════════════════ */

        case 'get_events':
            $evRows = $pdo->query(
                "SELECT id, nom, type, DATE_FORMAT(date,'%Y-%m-%d') AS date, lieu, description, image_path, created_at
                 FROM events ORDER BY date DESC, id DESC"
            )->fetchAll();

            $muRows = $pdo->query(
                "SELECT id, event_id, nom, instrument FROM event_musicians ORDER BY id ASC"
            )->fetchAll();

            $muMap = [];
            foreach ($muRows as $m) {
                $eid = (int)$m['event_id'];
                $muMap[$eid][] = [
                    'id'         => (int)$m['id'],
                    'nom'        => $m['nom'],
                    'instrument' => $m['instrument'],
                ];
            }

            $result = [];
            foreach ($evRows as $e) {
                $eid = (int)$e['id'];
                $result[] = [
                    'id'          => $eid,
                    'nom'         => $e['nom'],
                    'type'        => $e['type'],
                    'date'        => $e['date'],
                    'lieu'        => $e['lieu'],
                    'description' => $e['description'],
                    'image_path'  => $e['image_path'],
                    'musicians'   => $muMap[$eid] ?? [],
                ];
            }
            echo json_encode($result);
            break;

        case 'save_event':
            $nom         = trim($input['nom']         ?? '');
            $type        = $input['type']              ?? 'autre';
            $date        = $input['date']              ?? date('Y-m-d');
            $lieu        = trim($input['lieu']         ?? '');
            $description = trim($input['description']  ?? '');
            $image_path  = trim($input['image_path']   ?? '');
            $musicians   = $input['musicians']          ?? [];

            if (!$nom) { echo json_encode(['error' => "Nom de l'événement manquant"]); break; }

            // Insert event
            $pdo->prepare("INSERT INTO events (nom, type, date, lieu, description, image_path) VALUES (?,?,?,?,?,?)")
                ->execute([$nom, $type, $date, $lieu, $description, $image_path]);
            $eventId = (int)$pdo->lastInsertId();

            // Insert musicians
            $musicianNames = [];
            if (!empty($musicians)) {
                $sm = $pdo->prepare("INSERT INTO event_musicians (event_id, nom, instrument) VALUES (?,?,?)");
                foreach ($musicians as $m) {
                    $mNom  = trim($m['nom']        ?? '');
                    $mInst = trim($m['instrument'] ?? '');
                    if ($mNom) {
                        $sm->execute([$eventId, $mNom, $mInst]);
                        $musicianNames[] = $mNom;
                    }
                }
            }

            // Auto-create finance credit entry
            $typeLabels = [
                'concert'   => 'Concert',
                'cabaret'   => 'Cabaret',
                'acoustique'=> 'Acoustique',
                'mariage'   => 'Mariage',
                'funerail'  => 'Funérailles',
                'autre'     => 'Événement',
            ];
            $typeLabel = $typeLabels[$type] ?? ucfirst($type);
            $txDesc = $nom . ' — ' . $typeLabel;

            $pdo->prepare("INSERT INTO transactions (type, description, date, montant, event_id) VALUES ('cred',?,?,0,?)")
                ->execute([$txDesc, $date, $eventId]);
            $txId = (int)$pdo->lastInsertId();

            // One detail line per musician (amount = 0, to be filled later)
            if (!empty($musicianNames)) {
                $sd = $pdo->prepare("INSERT INTO transaction_details (transaction_id, nom, montant, motif) VALUES (?,?,0,'Cotisation 500 FCFA à verser')");
                foreach ($musicianNames as $mNom) {
                    $sd->execute([$txId, $mNom]);
                }
            }

            echo json_encode(['ok' => true, 'event_id' => $eventId, 'finance_id' => $txId]);
            break;

        case 'edit_event':
            $id          = (int)($input['id']          ?? 0);
            $nom         = trim($input['nom']          ?? '');
            $type        = $input['type']               ?? 'autre';
            $date        = $input['date']               ?? date('Y-m-d');
            $lieu        = trim($input['lieu']          ?? '');
            $description = trim($input['description']   ?? '');
            $image_path  = trim($input['image_path']    ?? '');
            $musicians   = $input['musicians']           ?? [];

            if (!$id || !$nom) { echo json_encode(['error' => 'Données manquantes']); break; }

            $pdo->prepare("UPDATE events SET nom=?, type=?, date=?, lieu=?, description=?, image_path=? WHERE id=?")
                ->execute([$nom, $type, $date, $lieu, $description, $image_path, $id]);

            // Replace musicians
            $pdo->prepare("DELETE FROM event_musicians WHERE event_id=?")->execute([$id]);
            $musicianNames = [];
            if (!empty($musicians)) {
                $sm = $pdo->prepare("INSERT INTO event_musicians (event_id, nom, instrument) VALUES (?,?,?)");
                foreach ($musicians as $m) {
                    $mNom  = trim($m['nom']        ?? '');
                    $mInst = trim($m['instrument'] ?? '');
                    if ($mNom) {
                        $sm->execute([$id, $mNom, $mInst]);
                        $musicianNames[] = $mNom;
                    }
                }
            }

            // Sync linked finance entry
            $tx = $pdo->prepare("SELECT id FROM transactions WHERE event_id=? AND type='cred' LIMIT 1");
            $tx->execute([$id]);
            $txRow = $tx->fetch();
            if ($txRow) {
                $txId    = (int)$txRow['id'];
                $typeLabels = ['concert'=>'Concert','cabaret'=>'Cabaret','acoustique'=>'Acoustique','mariage'=>'Mariage','funerail'=>'Funérailles','autre'=>'Événement'];
                $typeLabel  = $typeLabels[$type] ?? ucfirst($type);
                $pdo->prepare("UPDATE transactions SET description=?, date=? WHERE id=?")
                    ->execute([$nom.' — '.$typeLabel, $date, $txId]);
                $pdo->prepare("DELETE FROM transaction_details WHERE transaction_id=?")->execute([$txId]);
                if (!empty($musicianNames)) {
                    $sd = $pdo->prepare("INSERT INTO transaction_details (transaction_id, nom, montant, motif) VALUES (?,?,0,'Cotisation 500 FCFA à verser')");
                    foreach ($musicianNames as $mNom) { $sd->execute([$txId, $mNom]); }
                }
            }

            echo json_encode(['ok' => true]);
            break;

        case 'delete_event':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['error' => 'ID manquant']); break; }
            // Detach finance entries (don't delete them)
            $pdo->prepare("UPDATE transactions SET event_id=NULL WHERE event_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        /* ── Upload image ── */
        case 'upload_image':
            if (empty($_FILES['image'])) { echo json_encode(['error' => 'Aucun fichier reçu']); break; }

            $uploadsDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

            $file    = $_FILES['image'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];

            if (!in_array($ext, $allowed))    { echo json_encode(['error' => 'Format non autorisé (jpg/png/gif/webp)']); break; }
            if ($file['size'] > 5*1024*1024)  { echo json_encode(['error' => 'Fichier trop lourd (max 5 Mo)']);           break; }

            $filename = 'evt_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadsDir . $filename)) {
                echo json_encode(['error' => "Échec de l'enregistrement"]);
                break;
            }
            echo json_encode(['ok' => true, 'path' => 'uploads/' . $filename]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue : ' . $action]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Base de données : ' . $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
