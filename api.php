<?php
// ============================================================
// NexLead CRM — PHP REST API
// Handles all CRUD operations for leads
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

$db     = Database::connect();
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim($_GET['path'] ?? '', '/');
$parts  = explode('/', $path);
$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;

// ---- Route dispatcher ----------------------------------------
switch ($resource) {
    case 'leads':
        switch ($method) {
            case 'GET':    $id ? getOneLead($db, $id) : getAllLeads($db);    break;
            case 'POST':   createLead($db);                                   break;
            case 'PUT':    $id ? updateLead($db, $id) : badRequest();         break;
            case 'DELETE': $id ? deleteLead($db, $id) : badRequest();         break;
            default:       methodNotAllowed();
        }
        break;

    case 'stats':
        getStats($db);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
}

// ==============================================================
// LEADS — GET ALL
// ==============================================================
function getAllLeads(PDO $db): void {
    $status = $_GET['status'] ?? '';
    $search = $_GET['q']      ?? '';

    $sql    = 'SELECT l.*, u.name AS assigned_name
               FROM leads l
               LEFT JOIN users u ON l.assigned_to = u.id
               WHERE 1=1';
    $params = [];

    if ($status) {
        $sql      .= ' AND l.status = :status';
        $params[':status'] = $status;
    }

    if ($search) {
        $sql      .= ' AND (l.name LIKE :q OR l.company LIKE :q OR l.email LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY l.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $leads, 'count' => count($leads)]);
}

// ==============================================================
// LEADS — GET ONE
// ==============================================================
function getOneLead(PDO $db, int $id): void {
    $stmt = $db->prepare(
        'SELECT l.*, u.name AS assigned_name
         FROM leads l
         LEFT JOIN users u ON l.assigned_to = u.id
         WHERE l.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $lead = $stmt->fetch();

    if (!$lead) {
        http_response_code(404);
        echo json_encode(['error' => 'Lead not found']);
        return;
    }

    echo json_encode(['success' => true, 'data' => $lead]);
}

// ==============================================================
// LEADS — CREATE
// ==============================================================
function createLead(PDO $db): void {
    $body = json_decode(file_get_contents('php://input'), true);

    $required = ['name', 'email', 'company'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            http_response_code(422);
            echo json_encode(['error' => "Field '{$field}' is required"]);
            return;
        }
    }

    $validStatuses = ['new','qualified','proposal','negotiation','won','lost'];
    $status = in_array($body['status'] ?? '', $validStatuses) ? $body['status'] : 'new';

    $stmt = $db->prepare(
        'INSERT INTO leads (name, email, company, phone, value, status, assigned_to, notes, source)
         VALUES (:name, :email, :company, :phone, :value, :status, :assigned_to, :notes, :source)'
    );

    $stmt->execute([
        ':name'        => htmlspecialchars(trim($body['name'])),
        ':email'       => filter_var(trim($body['email']), FILTER_SANITIZE_EMAIL),
        ':company'     => htmlspecialchars(trim($body['company'])),
        ':phone'       => $body['phone']       ?? null,
        ':value'       => (float)($body['value'] ?? 0),
        ':status'      => $status,
        ':assigned_to' => !empty($body['assigned_to']) ? (int)$body['assigned_to'] : null,
        ':notes'       => $body['notes']       ?? null,
        ':source'      => $body['source']      ?? null,
    ]);

    $newId = (int)$db->lastInsertId();
    getOneLead($db, $newId);
}

// ==============================================================
// LEADS — UPDATE
// ==============================================================
function updateLead(PDO $db, int $id): void {
    $body = json_decode(file_get_contents('php://input'), true);

    // Build dynamic SET clause from provided fields
    $allowed = ['name','email','company','phone','value','status','assigned_to','notes','source'];
    $sets    = [];
    $params  = [':id' => $id];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $sets[]           = "{$field} = :{$field}";
            $params[":{$field}"] = $body[$field];
        }
    }

    if (empty($sets)) {
        http_response_code(422);
        echo json_encode(['error' => 'No valid fields provided']);
        return;
    }

    $sql = 'UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Lead not found or no changes made']);
        return;
    }

    getOneLead($db, $id);
}

// ==============================================================
// LEADS — DELETE
// ==============================================================
function deleteLead(PDO $db, int $id): void {
    $stmt = $db->prepare('DELETE FROM leads WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Lead not found']);
        return;
    }

    echo json_encode(['success' => true, 'message' => "Lead {$id} deleted"]);
}

// ==============================================================
// STATS — dashboard KPIs
// ==============================================================
function getStats(PDO $db): void {
    $total  = $db->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    $won    = $db->query("SELECT COUNT(*) FROM leads WHERE status = 'won'")->fetchColumn();
    $pipeline = $db->query(
        "SELECT COALESCE(SUM(value),0) FROM leads WHERE status NOT IN ('won','lost')"
    )->fetchColumn();
    $winRate = $total > 0 ? round(($won / $total) * 100, 1) : 0;

    $byStatus = $db->query(
        'SELECT status, COUNT(*) AS count, COALESCE(SUM(value),0) AS total_value
         FROM leads GROUP BY status'
    )->fetchAll();

    echo json_encode([
        'success'   => true,
        'data'      => [
            'total_leads'    => (int)$total,
            'deals_won'      => (int)$won,
            'pipeline_value' => (float)$pipeline,
            'win_rate'       => $winRate,
            'by_status'      => $byStatus,
        ]
    ]);
}

// ==============================================================
// Helpers
// ==============================================================
function badRequest(): void {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
}

function methodNotAllowed(): void {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
