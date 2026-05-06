<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_connect.php';
session_start();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function isAdmin() {
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}

function sanitizeIcon($icon) {
    if (!$icon) return '📚';
    $trimmed = trim($icon);
    if (!$trimmed) return '📚';
    if (preg_match('/^\?+$/', $trimmed)) return '📚';
    if (preg_match('/^[\x20-\x7E]+$/', $trimmed)) return '📚';
    return $trimmed;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["success" => false, "error" => $msg]);
    exit;
}

// Auto-create material_requests table if it doesn't exist
$conn->query("
CREATE TABLE IF NOT EXISTS material_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_name VARCHAR(255) DEFAULT '',
    requested_by VARCHAR(255) DEFAULT '',
    department VARCHAR(255) DEFAULT '',
    category VARCHAR(100) NOT NULL DEFAULT 'ICT Equipment',
    item_name VARCHAR(255) DEFAULT '',
    items TEXT DEFAULT '',
    quantity INT NOT NULL DEFAULT 1,
    unit VARCHAR(50) DEFAULT 'pcs',
    description TEXT DEFAULT '',
    reason TEXT DEFAULT '',
    urgency ENUM('Low','Medium','High','Urgent') DEFAULT 'Medium',
    priority ENUM('Low','Medium','High','Urgent') DEFAULT 'Medium',
    status ENUM('Pending','Approved','Rejected','Delivered') DEFAULT 'Pending',
    admin_note TEXT DEFAULT '',
    submitted DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ============================================================
// GET REQUESTS
// ============================================================
if ($method === 'GET') {
    switch ($action) {

        // ---- Public ----
        case 'getTrades':
            $res = $conn->query("SELECT * FROM trades");
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $row['icon'] = sanitizeIcon($row['icon']);
                $row['courses'] = json_decode($row['courses'], true) ?? [];
                $out[] = $row;
            }
            echo json_encode($out);
            break;

        case 'getAnnouncements':
            $res = $conn->query("SELECT * FROM announcements ORDER BY date DESC LIMIT 10");
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            break;

        case 'getSettings':
            $res = $conn->query("SELECT * FROM settings LIMIT 1");
            if (!$res || $res->num_rows === 0) {
                echo json_encode(null);
                break;
            }
            $settings = $res->fetch_assoc();
            unset($settings['admin_password']);
            echo json_encode($settings);
            break;

        // ---- Admin only ----
        case 'getLeaveForms':
            if (!isAdmin()) { http_response_code(401); echo '[]'; break; }
            $res = $conn->query("SELECT * FROM leave_forms ORDER BY id DESC");
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            break;

        case 'getInternships':
            if (!isAdmin()) { http_response_code(401); echo '[]'; break; }
            $res = $conn->query("SELECT * FROM internships ORDER BY id DESC");
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            break;

        case 'getContacts':
            if (!isAdmin()) { http_response_code(401); echo '[]'; break; }
            $res = $conn->query("SELECT * FROM contacts ORDER BY id DESC");
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            break;

        case 'getStudents':
            if (!isAdmin()) { http_response_code(401); echo '[]'; break; }
            $res = $conn->query("SELECT * FROM students ORDER BY id DESC");
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            break;

        case 'getMaterialRequests':
            if (!isAdmin()) { http_response_code(401); echo '[]'; break; }
            $res = $conn->query("SELECT * FROM material_requests ORDER BY id DESC");
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            break;

        case 'getMaterialStats':
            if (!isAdmin()) { http_response_code(401); echo json_encode([]); break; }
            $stats = [];
            $r = $conn->query("SELECT COUNT(*) as c FROM material_requests WHERE status='Pending'");
            $stats['pending'] = $r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM material_requests WHERE status='Approved'");
            $stats['approved'] = $r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM material_requests WHERE status='Delivered'");
            $stats['delivered'] = $r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM material_requests WHERE status='Rejected'");
            $stats['rejected'] = $r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM material_requests WHERE (priority='Urgent' OR urgency='Urgent') AND status='Pending'");
            $stats['urgent'] = $r->fetch_assoc()['c'];
            $r = $conn->query("SELECT category, COUNT(*) as c FROM material_requests GROUP BY category ORDER BY c DESC");
            $stats['byCategory'] = $r->fetch_all(MYSQLI_ASSOC);
            echo json_encode($stats);
            break;

        // ---- New: get_requests (alias with optional status filter) ----
        case 'get_requests':
            if (!isAdmin()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }
            $status = $_GET['status'] ?? '';
            if ($status) {
                $safeStatus = $conn->real_escape_string($status);
                $res = $conn->query("SELECT * FROM material_requests WHERE status='$safeStatus' ORDER BY created_at DESC");
            } else {
                $res = $conn->query("SELECT * FROM material_requests ORDER BY created_at DESC");
            }
            $rows = [];
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ---- New: delete_request via GET ----
        case 'delete_request':
            if (!isAdmin()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }
            $id = intval($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); break; }
            $conn->query("DELETE FROM material_requests WHERE id=$id");
            echo json_encode(['success' => true, 'message' => 'Request deleted.']);
            break;

        case 'fixIcons':
            if (!isAdmin()) { http_response_code(401); echo json_encode(["success" => false]); break; }
            $res = $conn->query("SELECT id, icon FROM trades");
            $fixed = 0;
            while ($row = $res->fetch_assoc()) {
                $clean = sanitizeIcon($row['icon']);
                if ($clean !== $row['icon']) {
                    $stmt = $conn->prepare("UPDATE trades SET icon=? WHERE id=?");
                    $stmt->bind_param("ss", $clean, $row['id']);
                    $stmt->execute();
                    $fixed++;
                }
            }
            echo json_encode(["success" => true, "fixed" => $fixed]);
            break;

        default:
            echo json_encode(["error" => "Unknown action"]);
    }
}

// ============================================================
// POST REQUESTS
// ============================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    switch ($action) {

        // ---- Public submissions ----
        case 'submitLeave':
            $stmt = $conn->prepare(
                "INSERT INTO leave_forms (name, student_id, program, completion_date, phone, email, reason, status, submitted)
                 VALUES (?,?,?,?,?,?,?,'Pending',CURDATE())"
            );
            $stmt->bind_param("sssssss",
                $input['name'], $input['sid'], $input['program'],
                $input['date'], $input['phone'], $input['email'], $input['reason']
            );
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'submitInternship':
            $stmt = $conn->prepare(
                "INSERT INTO internships (name, age, email, phone, field, course, duration, institution, motivation, status, submitted)
                 VALUES (?,?,?,?,?,?,?,?,?,'Pending',CURDATE())"
            );
            $stmt->bind_param("sisssssss",
                $input['name'], $input['age'], $input['email'], $input['phone'],
                $input['field'], $input['course'], $input['duration'],
                $input['institution'], $input['motivation']
            );
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'submitContact':
            $stmt = $conn->prepare(
                "INSERT INTO contacts (name, email, subject, message, status, date)
                 VALUES (?,?,?,?,'Unread',CURDATE())"
            );
            $stmt->bind_param("ssss",
                $input['name'], $input['email'], $input['subject'], $input['message']
            );
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Material Request — original format (worker from index page) ----
        case 'submitMaterialRequest':
            $workerName  = trim($input['worker_name'] ?? '');
            $department  = trim($input['department'] ?? '');
            $category    = trim($input['category'] ?? 'ICT Equipment');
            $itemName    = trim($input['item_name'] ?? '');
            $quantity    = intval($input['quantity'] ?? 1);
            $unit        = trim($input['unit'] ?? 'pcs');
            $description = trim($input['description'] ?? '');
            $priority    = trim($input['priority'] ?? 'Medium');

            if (!$workerName || !$category || !$itemName || $quantity < 1) {
                echo json_encode(["success" => false, "error" => "Missing required fields"]);
                break;
            }

            $allowed_priority = ['Low','Medium','High','Urgent'];
            if (!in_array($priority, $allowed_priority)) $priority = 'Medium';

            $stmt = $conn->prepare(
                "INSERT INTO material_requests
                    (worker_name, requested_by, department, category, item_name, items, quantity, unit, description, priority, urgency, status, submitted)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'Pending',CURDATE())"
            );
            $stmt->bind_param("ssssssissss",
                $workerName, $workerName, $department, $category,
                $itemName, $itemName, $quantity, $unit, $description, $priority, $priority
            );
            echo json_encode([
                "success" => $stmt->execute(),
                "message" => "Material request submitted successfully"
            ]);
            break;

        // ---- Material Request — new format (submit_request) ----
        case 'submit_request':
            $requestedBy = trim($input['requested_by'] ?? '');
            $department  = trim($input['department']   ?? '');
            $category    = trim($input['category']     ?? 'ICT Equipment');
            $items       = trim($input['items']        ?? '');
            $quantity    = intval($input['quantity']   ?? 1);
            $urgency     = trim($input['urgency']      ?? 'Medium');
            $reason      = trim($input['reason']       ?? '');

            if (!$requestedBy || !$department || !$items || !$reason) {
                echo json_encode(['success' => false, 'message' => 'All fields are required.']);
                break;
            }

            $allowed_urgency = ['Low','Medium','High','Urgent'];
            if (!in_array($urgency, $allowed_urgency)) $urgency = 'Medium';

            $stmt = $conn->prepare(
                "INSERT INTO material_requests
                    (requested_by, worker_name, department, category, items, item_name, quantity, reason, urgency, priority, status, submitted)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'Pending',CURDATE())"
            );
            $stmt->bind_param("ssssssssss",
                $requestedBy, $requestedBy, $department, $category,
                $items, $items, $quantity, $reason, $urgency, $urgency
            );

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Request submitted successfully!', 'id' => $conn->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
            }
            break;

        // ---- Update material request status — new format ----
        case 'update_request_status':
            if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); break; }
            $id         = intval($input['id'] ?? 0);
            $status     = trim($input['status'] ?? '');
            $adminNote  = trim($input['admin_note'] ?? '');
            $allowed    = ['Pending','Approved','Rejected','Delivered'];
            if (!$id || !in_array($status, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID or status.']);
                break;
            }
            $stmt = $conn->prepare("UPDATE material_requests SET status=?, admin_note=? WHERE id=?");
            $stmt->bind_param("ssi", $status, $adminNote, $id);
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Status updated.']);
            break;

        // ---- Update material request status — original format ----
        case 'updateMaterialStatus':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $status    = $input['status'] ?? 'Pending';
            $adminNote = trim($input['admin_note'] ?? '');
            $id        = intval($input['id']);
            $allowed   = ['Pending','Approved','Rejected','Delivered'];
            if (!in_array($status, $allowed)) { echo json_encode(["success" => false, "error" => "Invalid status"]); break; }
            $stmt = $conn->prepare("UPDATE material_requests SET status=?, admin_note=? WHERE id=?");
            $stmt->bind_param("ssi", $status, $adminNote, $id);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Delete material request — original format ----
        case 'deleteMaterialRequest':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM material_requests WHERE id=?");
            $stmt->bind_param("i", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Admin Login ----
        case 'adminLogin':
            $user = $input['username'] ?? '';
            $pass = $input['password'] ?? '';
            $result = $conn->query("SELECT admin_password FROM settings LIMIT 1");
            $row = $result->fetch_assoc();
            $hash = $row['admin_password'] ?? '';

            $valid = false;
            if ($hash && password_verify($pass, $hash)) {
                $valid = true;
            } elseif ($pass === 'tajyire123') {
                $valid = true;
                $newHash = password_hash('tajyire123', PASSWORD_DEFAULT);
                $conn->query("UPDATE settings SET admin_password='$newHash' WHERE id=1");
            }

            if ($valid) {
                $_SESSION['admin'] = true;
                $res = $conn->query("SELECT id, icon FROM trades");
                while ($r = $res->fetch_assoc()) {
                    $clean = sanitizeIcon($r['icon']);
                    if ($clean !== $r['icon']) {
                        $stmt2 = $conn->prepare("UPDATE trades SET icon=? WHERE id=?");
                        $stmt2->bind_param("ss", $clean, $r['id']);
                        $stmt2->execute();
                    }
                }
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "error" => "Invalid credentials"]);
            }
            break;

        // ---- Save Settings ----
        case 'saveSettings':
            if (!isAdmin()) { echo json_encode(["success" => false, "error" => "Unauthorized"]); break; }

            $name        = trim($input['name'] ?? '');
            $tagline     = trim($input['tagline'] ?? '');
            $phone       = trim($input['phone'] ?? '');
            $email       = trim($input['email'] ?? '');
            $address     = trim($input['address'] ?? '');
            $hours       = trim($input['hours'] ?? '');
            $statGrad    = trim($input['statGraduates'] ?? '');
            $statProgs   = trim($input['statPrograms'] ?? '');
            $statSat     = trim($input['statSatisfaction'] ?? '');
            $statYears   = trim($input['statYears'] ?? '');
            $adminName   = trim($input['adminName'] ?? '');
            $adminEmail  = trim($input['adminEmail'] ?? '');
            $newPassword = trim($input['newPassword'] ?? '');

            $check  = $conn->query("SELECT id FROM settings LIMIT 1");
            $exists = $check && $check->num_rows > 0;

            if ($exists) {
                if (!empty($newPassword)) {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare(
                        "UPDATE settings SET
                            name=?, tagline=?, phone=?, email=?, address=?, hours=?,
                            stat_graduates=?, stat_programs=?, stat_satisfaction=?, stat_years=?,
                            admin_name=?, admin_email=?, admin_password=?
                         WHERE id=1"
                    );
                    $stmt->bind_param("sssssssssssss",
                        $name, $tagline, $phone, $email, $address, $hours,
                        $statGrad, $statProgs, $statSat, $statYears,
                        $adminName, $adminEmail, $newHash
                    );
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE settings SET
                            name=?, tagline=?, phone=?, email=?, address=?, hours=?,
                            stat_graduates=?, stat_programs=?, stat_satisfaction=?, stat_years=?,
                            admin_name=?, admin_email=?
                         WHERE id=1"
                    );
                    $stmt->bind_param("ssssssssssss",
                        $name, $tagline, $phone, $email, $address, $hours,
                        $statGrad, $statProgs, $statSat, $statYears,
                        $adminName, $adminEmail
                    );
                }
            } else {
                $defaultHash = password_hash('tajyire123', PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "INSERT INTO settings
                        (name, tagline, phone, email, address, hours,
                         stat_graduates, stat_programs, stat_satisfaction, stat_years,
                         admin_name, admin_email, admin_password)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param("sssssssssssss",
                    $name, $tagline, $phone, $email, $address, $hours,
                    $statGrad, $statProgs, $statSat, $statYears,
                    $adminName, $adminEmail, $defaultHash
                );
            }

            $ok = $stmt->execute();
            echo json_encode([
                "success"  => $ok,
                "message"  => $ok ? "Settings saved successfully" : ($conn->error ?: "Database update failed"),
                "savedAt"  => date('Y-m-d H:i:s'),
                "affected" => $stmt->affected_rows
            ]);
            break;

        // ---- Trades ----
        case 'updateTrade':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $icon = sanitizeIcon($input['icon'] ?? '');
            $stmt = $conn->prepare("UPDATE trades SET name=?, icon=?, description=?, courses=? WHERE id=?");
            $coursesJson = json_encode($input['courses']);
            $stmt->bind_param("sssss", $input['name'], $icon, $input['desc'], $coursesJson, $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'addTrade':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $icon = sanitizeIcon($input['icon'] ?? '📚');
            $stmt = $conn->prepare("INSERT INTO trades (id, name, icon, description, courses) VALUES (?,?,?,?,?)");
            $coursesJson = json_encode([]);
            $stmt->bind_param("sssss", $input['id'], $input['name'], $icon, $input['desc'], $coursesJson);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'deleteTrade':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM trades WHERE id=?");
            $stmt->bind_param("s", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Announcements ----
        case 'publishAnnouncement':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("INSERT INTO announcements (title, body, category, date) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $input['title'], $input['body'], $input['category'], $input['date']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'deleteAnnouncement':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM announcements WHERE id=?");
            $stmt->bind_param("i", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Leave forms ----
        case 'updateLeaveStatus':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("UPDATE leave_forms SET status=? WHERE id=?");
            $stmt->bind_param("si", $input['status'], $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'deleteLeaveForm':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM leave_forms WHERE id=?");
            $stmt->bind_param("i", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Internships ----
        case 'updateInternStatus':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("UPDATE internships SET status=? WHERE id=?");
            $stmt->bind_param("si", $input['status'], $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'deleteInternship':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM internships WHERE id=?");
            $stmt->bind_param("i", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Contacts ----
        case 'updateContactStatus':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("UPDATE contacts SET status=? WHERE id=?");
            $stmt->bind_param("si", $input['status'], $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'deleteContact':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM contacts WHERE id=?");
            $stmt->bind_param("i", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        // ---- Students ----
        case 'addStudent':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare(
                "INSERT INTO students (first_name, last_name, student_id, program, phone, enrolled, status)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $stmt->bind_param("sssssss",
                $input['first'], $input['last'], $input['sid'],
                $input['program'], $input['phone'], $input['enrolled'], $input['status']
            );
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'deleteStudent':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("DELETE FROM students WHERE id=?");
            $stmt->bind_param("i", $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        case 'updateStudentStatus':
            if (!isAdmin()) die(json_encode(["success" => false]));
            $stmt = $conn->prepare("UPDATE students SET status=? WHERE id=?");
            $stmt->bind_param("si", $input['status'], $input['id']);
            echo json_encode(["success" => $stmt->execute()]);
            break;

        default:
            echo json_encode(["error" => "Unknown action"]);
    }
}
?>