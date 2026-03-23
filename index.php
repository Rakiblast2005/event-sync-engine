<?php
// ============================================================
//  REAL-TIME EVENT SYNCHRONIZATION ENGINE  |  index.php
//  Place in: C:/xampp/htdocs/eventsync/
//  Visit:    http://localhost/eventsync/
//  Admin:    admin@eventsync.com / admin123
// ============================================================
session_start();

// ── DB ───────────────────────────────────────────────────
define('DB_HOST','localhost');
define('DB_USER','root');
define('DB_PASS','');
define('DB_NAME','eventsync');

function db(){
    static $conn = null;
    if(!$conn){
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if($conn->connect_error){
            // Return error as JSON if API call
            if(isset($_GET['api'])){
                header('Content-Type: application/json');
                echo json_encode(['err'=>'DB Error: '.$conn->connect_error]);
                exit;
            }
            die('<h2 style="font-family:sans-serif;color:red;padding:40px">Database connection failed: '.$conn->connect_error.'<br><br>Make sure MySQL is running and you have imported database.sql</h2>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ── AUTO SETUP (runs once on first visit) ────────────────
function runSetup(){
    $db = db();
    // Check if admin exists
    $res = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
    if($res && $res->num_rows === 0){
        // Create admin with properly hashed password
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, 'admin')");
        $name = 'Super Admin';
        $email = 'admin@eventsync.com';
        $stmt->bind_param('sss', $name, $email, $hash);
        $stmt->execute();
        $adminId = $db->insert_id;

        // Seed sample events
        $events = [
            ['AI Tech Conference 2025','Explore frontiers of AI: LLMs, robotics, autonomous systems with world-class researchers and live demos.','Technology','2025-09-15','09:00','HICC Convention Centre, Hyderabad',2999,500,'🤖'],
            ['Full-Stack Web Dev Bootcamp','Intensive 2-day bootcamp: React, Node.js, databases, deployment. Beginner to production-ready.','Education','2025-10-05','08:30','Chennai Trade Centre, Chennai',1499,80,'💻'],
            ['Startup Networking Meetup','200+ founders, VCs, innovators. Pitch sessions, panels, 1-on-1 speed networking.','Business','2025-10-20','17:00','T-Hub, Hyderabad',499,200,'🚀'],
            ['Cloud & DevOps Summit','AWS, GCP, Azure, Kubernetes, CI/CD, SRE practices with hands-on labs and cloud credits.','Technology','2025-11-10','09:30','The Lalit, Mumbai',3499,300,'☁️'],
            ['Creative Design Thinking','UX research, prototyping, storytelling techniques used by product teams at Google and Apple.','Design','2025-11-25','10:00','IIM Ahmedabad Campus',899,60,'🎨'],
        ];
        $stmt = $db->prepare("INSERT INTO events (title,description,category,date,time,location,price,capacity,image,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach($events as $e){
            $stmt->bind_param('ssssssdisi', $e[0],$e[1],$e[2],$e[3],$e[4],$e[5],$e[6],$e[7],$e[8],$adminId);
            $stmt->execute();
        }
    }
}
runSetup();

// ── HELPERS ──────────────────────────────────────────────
function uid()      { return $_SESSION['uid']   ?? null; }
function uname()    { return $_SESSION['uname'] ?? ''; }
function urole()    { return $_SESSION['urole'] ?? ''; }
function isAdmin()  { return urole() === 'admin'; }
function isLoggedIn(){ return uid() !== null; }
function esc($s)    { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function txnId()    { return 'TXN'.strtoupper(substr(bin2hex(random_bytes(8)),0,14)); }
function sendNotif($uid, $msg){
    $s = db()->prepare("INSERT INTO notifications(user_id,message) VALUES(?,?)");
    $s->bind_param('is', $uid, $msg);
    $s->execute();
}
function alreadyRegistered($uid, $eid){
    $s = db()->prepare("SELECT registration_id FROM event_registrations WHERE user_id=? AND event_id=?");
    $s->bind_param('ii', $uid, $eid);
    $s->execute();
    return $s->get_result()->num_rows > 0;
}
function validateEmail($email){ return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }

// ── API ROUTER ────────────────────────────────────────────
if(isset($_GET['api'])){
    header('Content-Type: application/json');
    $action = trim($_GET['api']);

    // ── REGISTER ─────────────────────────────────────────
    if($action === 'register'){
        $fullname = trim($_POST['fullname'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        // Validation
        if($fullname === '')           { echo json_encode(['err'=>'Full name is required.']); exit; }
        if(strlen($fullname) < 2)     { echo json_encode(['err'=>'Name must be at least 2 characters.']); exit; }
        if($email === '')              { echo json_encode(['err'=>'Email address is required.']); exit; }
        if(!validateEmail($email))    { echo json_encode(['err'=>'Please enter a valid email address.']); exit; }
        if($password === '')           { echo json_encode(['err'=>'Password is required.']); exit; }
        if(strlen($password) < 6)     { echo json_encode(['err'=>'Password must be at least 6 characters.']); exit; }
        if(strlen($fullname) > 120)   { echo json_encode(['err'=>'Name too long (max 120 chars).']); exit; }

        // Check duplicate
        $chk = db()->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param('s', $email);
        $chk->execute();
        if($chk->get_result()->num_rows > 0){
            echo json_encode(['err'=>'This email is already registered. Please sign in.']);
            exit;
        }

        // Insert
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ins  = db()->prepare("INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, 'user')");
        $ins->bind_param('sss', $fullname, $email, $hash);
        if(!$ins->execute()){
            echo json_encode(['err'=>'Registration failed. Please try again.']);
            exit;
        }
        $newId = db()->insert_id;
        sendNotif($newId, "Welcome to EventSync, {$fullname}! 🎉 Browse and register for events.");
        echo json_encode(['ok'=>'Account created successfully! Please sign in.']);
        exit;
    }

    // ── LOGIN ─────────────────────────────────────────────
    if($action === 'login'){
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if($email === '')     { echo json_encode(['err'=>'Email address is required.']); exit; }
        if($password === '')  { echo json_encode(['err'=>'Password is required.']); exit; }
        if(!validateEmail($email)){ echo json_encode(['err'=>'Please enter a valid email address.']); exit; }

        $stmt = db()->prepare("SELECT id, fullname, password, role FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if(!$row){
            echo json_encode(['err'=>'No account found with this email address.']);
            exit;
        }
        if(!password_verify($password, $row['password'])){
            echo json_encode(['err'=>'Incorrect password. Please try again.']);
            exit;
        }

        // Set session
        $_SESSION['uid']   = (int)$row['id'];
        $_SESSION['uname'] = $row['fullname'];
        $_SESSION['urole'] = $row['role'];
        session_regenerate_id(true);

        echo json_encode([
            'ok'   => 1,
            'role' => $row['role'],
            'name' => $row['fullname'],
            'uid'  => $row['id']
        ]);
        exit;
    }

    // ── LOGOUT ───────────────────────────────────────────
    if($action === 'logout'){
        session_unset();
        session_destroy();
        echo json_encode(['ok'=>1]);
        exit;
    }

    // ── POLL EVENTS (public) ─────────────────────────────
    if($action === 'poll'){
        $q     = trim($_GET['q']    ?? '');
        $cat   = trim($_GET['cat']  ?? '');
        $price = trim($_GET['price']?? '');

        $sql = "SELECT e.*, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.event_id AND r.status='confirmed') AS reg_count FROM events e WHERE 1";
        $params = []; $types = '';

        if($q !== ''){
            $sql .= " AND (e.title LIKE ? OR e.location LIKE ?)";
            $like = "%{$q}%"; $params[] = $like; $params[] = $like; $types .= 'ss';
        }
        if($cat !== ''){ $sql .= " AND e.category = ?"; $params[] = $cat; $types .= 's'; }
        if($price === 'free'){ $sql .= " AND e.price = 0"; }
        if($price === 'paid'){ $sql .= " AND e.price > 0"; }
        $sql .= " ORDER BY e.date ASC";

        $stmt = db()->prepare($sql);
        if($types){ $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok'=>1, 'events'=>$rows]);
        exit;
    }

    // ── REQUIRE AUTH from here ────────────────────────────
    if(!isLoggedIn()){
        echo json_encode(['err'=>'Please sign in to continue.']);
        exit;
    }
    $currentUid = uid();

    // ── NOTIFICATIONS ─────────────────────────────────────
    if($action === 'notifs'){
        $stmt = db()->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 15");
        $stmt->bind_param('i', $currentUid);
        $stmt->execute();
        $rows   = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $unread = db()->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND status='unread'");
        $unread->bind_param('i', $currentUid);
        $unread->execute();
        $count = $unread->get_result()->fetch_assoc()['c'];
        echo json_encode(['ok'=>1, 'notifs'=>$rows, 'unread'=>(int)$count]);
        exit;
    }

    if($action === 'mark_read'){
        $stmt = db()->prepare("UPDATE notifications SET status='read' WHERE user_id=?");
        $stmt->bind_param('i', $currentUid);
        $stmt->execute();
        echo json_encode(['ok'=>1]);
        exit;
    }

    // ── REGISTER FOR EVENT ────────────────────────────────
    if($action === 'reg_event'){
        $eid = (int)($_POST['event_id'] ?? 0);
        if($eid < 1){ echo json_encode(['err'=>'Invalid event.']); exit; }

        $evStmt = db()->prepare("SELECT * FROM events WHERE event_id=?");
        $evStmt->bind_param('i', $eid);
        $evStmt->execute();
        $ev = $evStmt->get_result()->fetch_assoc();
        if(!$ev){ echo json_encode(['err'=>'Event not found.']); exit; }

        if(alreadyRegistered($currentUid, $eid)){
            echo json_encode(['err'=>'You are already registered for this event.']);
            exit;
        }

        $cntStmt = db()->prepare("SELECT COUNT(*) AS c FROM event_registrations WHERE event_id=? AND status='confirmed'");
        $cntStmt->bind_param('i', $eid);
        $cntStmt->execute();
        $cnt = $cntStmt->get_result()->fetch_assoc()['c'];
        if($cnt >= $ev['capacity']){ echo json_encode(['err'=>'Sorry, this event is full.']); exit; }

        $ins = db()->prepare("INSERT INTO event_registrations (user_id, event_id, status) VALUES (?, ?, 'confirmed')");
        $ins->bind_param('ii', $currentUid, $eid);
        if(!$ins->execute()){ echo json_encode(['err'=>'Registration failed. Try again.']); exit; }

        sendNotif($currentUid, "You registered for \"{$ev['title']}\" on ".date('d M Y', strtotime($ev['date'])).".");
        echo json_encode(['ok'=>1, 'price'=>$ev['price'], 'title'=>$ev['title'], 'event_id'=>$eid]);
        exit;
    }

    // ── PAYMENT ───────────────────────────────────────────
    if($action === 'payment'){
        $eid    = (int)($_POST['event_id'] ?? 0);
        $method = $_POST['method'] ?? 'credit_card';
        $amount = (float)($_POST['amount'] ?? 0);

        $allowed = ['credit_card','debit_card','upi','net_banking','paypal','wallet'];
        if(!in_array($method, $allowed)){ $method = 'credit_card'; }
        if($eid < 1 || $amount < 0){ echo json_encode(['err'=>'Invalid payment data.']); exit; }

        $txn = txnId();
        $ins  = db()->prepare("INSERT INTO payments (user_id,event_id,amount,payment_method,payment_status,transaction_id) VALUES (?,?,?,'".db()->real_escape_string($method)."','success',?)");
        // Use proper bind
        $ins2 = db()->prepare("INSERT INTO payments (user_id,event_id,amount,payment_method,payment_status,transaction_id) VALUES (?,?,?,?,?,?)");
        $status = 'success';
        $ins2->bind_param('iidsss', $currentUid, $eid, $amount, $method, $status, $txn);
        if(!$ins2->execute()){ echo json_encode(['err'=>'Payment record failed.']); exit; }

        $evR = db()->prepare("SELECT title FROM events WHERE event_id=?");
        $evR->bind_param('i', $eid);
        $evR->execute();
        $title = $evR->get_result()->fetch_assoc()['title'] ?? 'event';
        sendNotif($currentUid, "Payment of ₹".number_format($amount,2)." confirmed for \"{$title}\". TXN: {$txn}");
        echo json_encode(['ok'=>1, 'txn'=>$txn]);
        exit;
    }

    // ── USER DASHBOARD ────────────────────────────────────
    if($action === 'dash'){
        $regs = db()->prepare("SELECT e.*, er.created_at AS reg_date, er.status AS reg_status FROM event_registrations er JOIN events e ON e.event_id=er.event_id WHERE er.user_id=? ORDER BY e.date ASC");
        $regs->bind_param('i', $currentUid);
        $regs->execute();
        $regRows = $regs->get_result()->fetch_all(MYSQLI_ASSOC);

        $pays = db()->prepare("SELECT p.*, e.title FROM payments p JOIN events e ON e.event_id=p.event_id WHERE p.user_id=? ORDER BY p.created_at DESC LIMIT 20");
        $pays->bind_param('i', $currentUid);
        $pays->execute();
        $payRows = $pays->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['ok'=>1, 'regs'=>$regRows, 'pays'=>$payRows]);
        exit;
    }

    // ── ADMIN ONLY ────────────────────────────────────────
    if(!isAdmin()){
        echo json_encode(['err'=>'Access denied. Admin only.']);
        exit;
    }

    if($action === 'admin_stats'){
        $users = db()->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch_assoc()['c'];
        $evts  = db()->query("SELECT COUNT(*) AS c FROM events")->fetch_assoc()['c'];
        $regs  = db()->query("SELECT COUNT(*) AS c FROM event_registrations")->fetch_assoc()['c'];
        $rev   = db()->query("SELECT COALESCE(SUM(amount),0) AS s FROM payments WHERE payment_status='success'")->fetch_assoc()['s'];

        $recentPay = db()->query("SELECT p.*,u.fullname,e.title FROM payments p JOIN users u ON u.id=p.user_id JOIN events e ON e.event_id=p.event_id ORDER BY p.created_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
        $usersList = db()->query("SELECT u.id,u.fullname,u.email,u.role,u.created_at,(SELECT COUNT(*) FROM event_registrations r WHERE r.user_id=u.id) AS reg_count FROM users u WHERE u.role='user' ORDER BY u.created_at DESC")->fetch_all(MYSQLI_ASSOC);
        $allRegs   = db()->query("SELECT er.*,u.fullname,e.title FROM event_registrations er JOIN users u ON u.id=er.user_id JOIN events e ON e.event_id=er.event_id ORDER BY er.created_at DESC LIMIT 30")->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['ok'=>1,'users'=>(int)$users,'evts'=>(int)$evts,'regs'=>(int)$regs,'rev'=>(float)$rev,'recentPay'=>$recentPay,'usersList'=>$usersList,'allRegs'=>$allRegs]);
        exit;
    }

    if($action === 'create_event'){
        $title    = trim($_POST['title']       ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $cat      = trim($_POST['category']    ?? 'General');
        $date     = trim($_POST['date']        ?? '');
        $time     = trim($_POST['time']        ?? '');
        $loc      = trim($_POST['location']    ?? '');
        $price    = (float)($_POST['price']    ?? 0);
        $capacity = (int)($_POST['capacity']   ?? 100);
        $image    = trim($_POST['image']       ?? '📅');

        if($title === '')  { echo json_encode(['err'=>'Event title is required.']); exit; }
        if($date  === '')  { echo json_encode(['err'=>'Event date is required.']);  exit; }
        if($time  === '')  { echo json_encode(['err'=>'Event time is required.']);  exit; }
        if($capacity < 1)  { echo json_encode(['err'=>'Capacity must be at least 1.']); exit; }
        if($price < 0)     { echo json_encode(['err'=>'Price cannot be negative.']); exit; }

        $ins = db()->prepare("INSERT INTO events (title,description,category,date,time,location,price,capacity,image,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->bind_param('ssssssdiis', $title,$desc,$cat,$date,$time,$loc,$price,$capacity,$image,$currentUid);
        if(!$ins->execute()){ echo json_encode(['err'=>'Failed to create event.']); exit; }
        echo json_encode(['ok'=>'Event created successfully!']);
        exit;
    }

    if($action === 'update_event'){
        $eid      = (int)($_POST['event_id']   ?? 0);
        $title    = trim($_POST['title']       ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $cat      = trim($_POST['category']    ?? 'General');
        $date     = trim($_POST['date']        ?? '');
        $time     = trim($_POST['time']        ?? '');
        $loc      = trim($_POST['location']    ?? '');
        $price    = (float)($_POST['price']    ?? 0);
        $capacity = (int)($_POST['capacity']   ?? 100);
        $image    = trim($_POST['image']       ?? '📅');

        if($eid < 1)       { echo json_encode(['err'=>'Invalid event.']); exit; }
        if($title === '')  { echo json_encode(['err'=>'Event title is required.']); exit; }
        if($date  === '')  { echo json_encode(['err'=>'Event date is required.']);  exit; }
        if($time  === '')  { echo json_encode(['err'=>'Event time is required.']);  exit; }

        $upd = db()->prepare("UPDATE events SET title=?,description=?,category=?,date=?,time=?,location=?,price=?,capacity=?,image=? WHERE event_id=?");
        $upd->bind_param('ssssssdiisi', $title,$desc,$cat,$date,$time,$loc,$price,$capacity,$image,$eid);
        if(!$upd->execute()){ echo json_encode(['err'=>'Failed to update event.']); exit; }

        // Notify registered users
        $regUsers = db()->query("SELECT user_id FROM event_registrations WHERE event_id={$eid} AND status='confirmed'");
        while($ru = $regUsers->fetch_assoc()){
            sendNotif($ru['user_id'], "📢 Event \"{$title}\" has been updated. Check the new details!");
        }
        echo json_encode(['ok'=>'Event updated successfully!']);
        exit;
    }

    if($action === 'delete_event'){
        $eid = (int)($_POST['event_id'] ?? 0);
        if($eid < 1){ echo json_encode(['err'=>'Invalid event.']); exit; }
        $del = db()->prepare("DELETE FROM events WHERE event_id=?");
        $del->bind_param('i', $eid);
        $del->execute();
        echo json_encode(['ok'=>'Event deleted.']);
        exit;
    }

    if($action === 'delete_user'){
        $tid = (int)($_POST['target_id'] ?? 0);
        if($tid < 1){ echo json_encode(['err'=>'Invalid user.']); exit; }
        $del = db()->prepare("DELETE FROM users WHERE id=? AND role='user'");
        $del->bind_param('i', $tid);
        $del->execute();
        echo json_encode(['ok'=>'User removed.']);
        exit;
    }

    echo json_encode(['err'=>'Unknown action.']);
    exit;
}

// PHP vars for template
$isLoggedIn = isLoggedIn();
$userRole   = urole();
$userName   = uname();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>EventSync — Real-Time Event Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
/* ═══ DESIGN TOKENS ═══════════════════════════════════════ */
:root{
  --bg0:#07090F; --bg1:#0C1119; --bg2:#111827; --bg3:#1C2636;
  --teal:#00D4AA; --teal2:#00A884; --gold:#F5C518; --gold2:#D4A817;
  --coral:#FF5C5C; --violet:#7C6FF7;
  --text:#E2E8F0; --text2:#94A3B8; --text3:#4B5A6E;
  --border:rgba(255,255,255,.06); --border-t:rgba(0,212,170,.18);
  --card:rgba(255,255,255,.02); --card2:rgba(255,255,255,.05);
  --glow:0 0 36px rgba(0,212,170,.12);
  --shadow:0 20px 40px rgba(0,0,0,.5);
  --r:12px; --r2:18px;
  --sw:268px; --th:64px;
}
[data-theme="light"]{
  --bg0:#F1F5FB; --bg1:#E8EFF9; --bg2:#FFFFFF; --bg3:#F6F9FF;
  --text:#0F172A; --text2:#475569; --text3:#94A3B8;
  --card:rgba(0,0,0,.02); --card2:rgba(0,0,0,.05);
  --border:rgba(0,0,0,.07); --border-t:rgba(0,170,140,.2);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg0);color:var(--text);min-height:100vh;overflow-x:hidden}
h1,h2,h3,h4{font-family:'Syne',sans-serif}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:var(--teal);border-radius:4px}
button,input,select,textarea{font-family:'DM Sans',sans-serif}
a{color:var(--teal);text-decoration:none}
.hidden{display:none!important}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.fade{animation:fadeUp .4s cubic-bezier(.4,0,.2,1) both}
.spin{animation:spin .7s linear infinite}

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:9px;border:none;font-size:14px;font-weight:600;cursor:pointer;transition:all .18s;white-space:nowrap;position:relative;overflow:hidden}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important}
.btn:hover:not(:disabled){transform:translateY(-2px)}
.btn:active:not(:disabled){transform:translateY(0)}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal2));color:#07090F}
.btn-teal:hover:not(:disabled){box-shadow:0 8px 20px rgba(0,212,170,.38)}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#07090F}
.btn-gold:hover:not(:disabled){box-shadow:0 8px 20px rgba(245,197,24,.35)}
.btn-ghost{background:var(--card2);border:1.5px solid var(--border);color:var(--text)}
.btn-ghost:hover:not(:disabled){border-color:var(--teal);color:var(--teal)}
.btn-danger{background:rgba(255,92,92,.1);border:1.5px solid rgba(255,92,92,.25);color:var(--coral)}
.btn-danger:hover:not(:disabled){background:var(--coral);color:#fff;border-color:var(--coral)}
.btn-sm{padding:7px 14px;font-size:12px;border-radius:8px}
.btn-xs{padding:5px 10px;font-size:11px;border-radius:6px}
.btn-full{width:100%;justify-content:center}
.btn-loader{width:16px;height:16px;border:2px solid rgba(0,0,0,.2);border-top-color:rgba(0,0,0,.6);border-radius:50%;animation:spin .6s linear infinite;display:none}

/* ── FORMS ───────────────────────────────────────────────── */
.fgroup{margin-bottom:16px}
.fgroup label{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text2);margin-bottom:6px}
.finput{width:100%;padding:11px 14px;background:var(--bg3);border:1.5px solid var(--border);border-radius:9px;color:var(--text);font-size:14px;outline:none;transition:all .18s}
.finput:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(0,212,170,.1);background:var(--bg2)}
.finput::placeholder{color:var(--text3)}
.finput.error{border-color:var(--coral);box-shadow:0 0 0 3px rgba(255,92,92,.1)}
select.finput option{background:var(--bg2)}
textarea.finput{resize:vertical;min-height:84px;line-height:1.5}
.finput-icon{position:relative}
.finput-icon i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none}
.finput-icon .finput{padding-left:38px}
.finput-icon .eye-toggle{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--text3);cursor:pointer;font-size:13px;border:none;background:none;padding:4px}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field-hint{font-size:11px;color:var(--text3);margin-top:4px}
.field-error{font-size:11px;color:var(--coral);margin-top:4px;display:none}
.field-error.show{display:block}

/* ── ALERT BANNERS ───────────────────────────────────────── */
.alert{padding:11px 15px;border-radius:9px;font-size:13px;font-weight:500;margin-bottom:14px;display:none;align-items:center;gap:9px;animation:fadeIn .25s ease}
.alert.show{display:flex}
.alert-err{background:rgba(255,92,92,.1);border:1px solid rgba(255,92,92,.22);color:#ff8a8a}
.alert-ok{background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.22);color:var(--teal)}
.alert i{flex-shrink:0;font-size:14px}

/* ── BADGES ──────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:10px;font-weight:700;letter-spacing:.2px}
.badge-teal{background:rgba(0,212,170,.1);color:var(--teal);border:1px solid rgba(0,212,170,.2)}
.badge-gold{background:rgba(245,197,24,.1);color:var(--gold);border:1px solid rgba(245,197,24,.2)}
.badge-coral{background:rgba(255,92,92,.1);color:var(--coral);border:1px solid rgba(255,92,92,.2)}
.badge-violet{background:rgba(124,111,247,.1);color:var(--violet);border:1px solid rgba(124,111,247,.2)}

/* ══════════════════════════════════════════════════════════
   NAVBAR
══════════════════════════════════════════════════════════ */
.navbar{position:fixed;top:0;left:0;right:0;height:var(--th);z-index:900;display:flex;align-items:center;padding:0 36px;gap:20px;transition:all .3s}
.navbar.scrolled{background:rgba(7,9,15,.9);backdrop-filter:blur(18px);border-bottom:1px solid var(--border)}
.nav-logo{font-family:'Syne',sans-serif;font-size:19px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:9px;text-decoration:none}
.logo-dot{width:9px;height:9px;background:var(--teal);border-radius:50%;animation:pulse 2s infinite}
.nav-links{display:flex;gap:2px;flex:1;justify-content:center}
.nav-links a{padding:7px 13px;border-radius:8px;color:var(--text2);font-size:13.5px;font-weight:500;transition:.15s}
.nav-links a:hover{color:var(--teal);background:rgba(0,212,170,.06)}
.nav-actions{display:flex;gap:9px;align-items:center}
.icon-btn{width:35px;height:35px;border-radius:8px;background:var(--card2);border:1px solid var(--border);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;font-size:13px}
.icon-btn:hover{color:var(--teal);border-color:var(--teal)}

/* ══════════════════════════════════════════════════════════
   HERO
══════════════════════════════════════════════════════════ */
.hero{min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:calc(var(--th) + 60px) 36px 80px;position:relative;overflow:hidden}
.hero-bg{position:absolute;inset:0;background:radial-gradient(ellipse 80% 55% at 50% 0%,rgba(0,212,170,.07) 0%,transparent 70%),radial-gradient(ellipse 50% 35% at 85% 85%,rgba(245,197,24,.05) 0%,transparent 60%);pointer-events:none}
.hero-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:56px 56px;pointer-events:none}
.hero-content{position:relative;max-width:800px;margin:0 auto}
.hero-tag{display:inline-flex;align-items:center;gap:7px;background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.18);border-radius:99px;padding:5px 14px;font-size:11px;font-weight:700;color:var(--teal);letter-spacing:.8px;text-transform:uppercase;margin-bottom:22px}
.hero-tag .dot{width:5px;height:5px;background:var(--teal);border-radius:50%;animation:pulse 1.6s infinite}
.hero h1{font-size:clamp(2.2rem,5.5vw,4.2rem);font-weight:800;line-height:1.08;margin-bottom:20px;letter-spacing:-1.5px}
.hero h1 .hl{background:linear-gradient(135deg,var(--teal) 30%,var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:1.05rem;color:var(--text2);max-width:520px;margin:0 auto 34px;line-height:1.72}
.hero-btns{display:flex;gap:11px;justify-content:center;flex-wrap:wrap}
.hero-stats{display:flex;gap:44px;justify-content:center;margin-top:54px;padding-top:34px;border-top:1px solid var(--border)}
.stat-item .snum{font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800}
.stat-item .slbl{font-size:12px;color:var(--text2);margin-top:1px}

/* ══════════════════════════════════════════════════════════
   EVENTS SECTION
══════════════════════════════════════════════════════════ */
.pub-section{padding:80px 36px;max-width:1180px;margin:0 auto}
.sec-tag{font-size:10px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin-bottom:8px}
.sec-title{font-size:clamp(1.7rem,3.5vw,2.5rem);font-weight:800;margin-bottom:12px;letter-spacing:-.5px}
.sec-sub{font-size:.93rem;color:var(--text2);max-width:500px;line-height:1.65}
.toolbar{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin:28px 0 24px}
.srch-wrap{flex:1;min-width:200px;position:relative}
.srch-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px}
.srch-wrap .finput{padding-left:36px}
.filter-grp{display:flex;gap:6px;flex-wrap:wrap}
.fbtn{padding:7px 13px;border-radius:7px;border:1px solid var(--border);background:var(--bg2);color:var(--text2);font-size:12px;font-weight:600;cursor:pointer;transition:.15s;font-family:'DM Sans',sans-serif}
.fbtn:hover,.fbtn.on{border-color:var(--teal);color:var(--teal);background:rgba(0,212,170,.06)}
.ev-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(295px,1fr));gap:18px}
/* Event Card */
.ev-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;transition:all .28s cubic-bezier(.4,0,.2,1)}
.ev-card:hover{transform:translateY(-5px);box-shadow:0 20px 40px rgba(0,0,0,.38),var(--glow);border-color:var(--border-t)}
.ev-card-img{height:120px;display:flex;align-items:center;justify-content:center;font-size:50px;background:linear-gradient(135deg,var(--bg3),var(--bg2));position:relative;overflow:hidden}
.ev-card-img::after{content:'';position:absolute;bottom:0;left:0;right:0;height:50px;background:linear-gradient(to top,var(--bg2),transparent)}
.ev-card-img span{position:relative;z-index:1}
.ev-body{padding:16px 18px}
.ev-cat{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--teal);margin-bottom:6px}
.ev-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:10px;line-height:1.3;color:var(--text)}
.ev-meta{display:flex;flex-direction:column;gap:5px;margin-bottom:10px}
.ev-meta-row{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text2)}
.ev-meta-row i{color:var(--teal);width:13px;font-size:11px;flex-shrink:0}
.ev-progress{height:3px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:8px}
.ev-progress-fill{height:100%;background:linear-gradient(90deg,var(--teal),var(--teal2));border-radius:3px;transition:.5s}
.ev-footer{padding:12px 18px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
.ev-price{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--gold)}
.ev-price small{font-family:'DM Sans',sans-serif;font-size:10px;color:var(--text3);font-weight:400;display:block;line-height:1}
.countdown{display:flex;gap:6px;margin:8px 0}
.cd-box{text-align:center;background:var(--bg3);border-radius:6px;padding:4px 7px;min-width:38px;border:1px solid var(--border)}
.cd-n{font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--teal);line-height:1}
.cd-l{font-size:8px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
/* Empty state */
.empty{grid-column:1/-1;text-align:center;padding:52px 20px;color:var(--text3)}
.empty-icon{font-size:40px;margin-bottom:12px;opacity:.5}

/* ══════════════════════════════════════════════════════════
   FEATURES
══════════════════════════════════════════════════════════ */
.features-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;margin-top:44px}
.feat{padding:24px;border-radius:var(--r2);background:var(--bg2);border:1px solid var(--border);transition:.25s}
.feat:hover{transform:translateY(-3px);border-color:var(--border-t);box-shadow:var(--glow)}
.feat-ico{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:14px}
.ft{background:linear-gradient(135deg,rgba(0,212,170,.18),rgba(0,168,132,.08))}
.fg{background:linear-gradient(135deg,rgba(245,197,24,.18),rgba(212,168,23,.08))}
.fv{background:linear-gradient(135deg,rgba(124,111,247,.18),rgba(90,80,200,.08))}
.fc{background:linear-gradient(135deg,rgba(255,92,92,.18),rgba(210,60,60,.08))}
.feat h3{font-size:14px;font-weight:700;margin-bottom:6px}
.feat p{font-size:12px;color:var(--text2);line-height:1.6}

/* About */
.about-sec{background:var(--bg1);padding:80px 36px;margin:50px 0}
.about-inner{max-width:1180px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
.about-check{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--text2);margin-bottom:10px}
.about-check i{color:var(--teal);font-size:13px}
.about-vis{position:relative;height:300px;display:flex;align-items:center;justify-content:center;font-size:80px;animation:float 4s ease-in-out infinite}
.about-pills{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:9px}
.about-pill{background:var(--bg2);border:1px solid var(--border-t);border-radius:8px;padding:8px 13px;font-size:12px;font-weight:600;color:var(--teal);white-space:nowrap;animation:float 3s ease-in-out infinite}
.about-pill:nth-child(2){animation-delay:.35s}.about-pill:nth-child(3){animation-delay:.7s}

/* Footer */
footer{background:var(--bg1);border-top:1px solid var(--border);padding:44px 36px 24px;text-align:center;color:var(--text3);font-size:12px}
.footer-logo{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;color:var(--text);margin-bottom:6px}

/* ══════════════════════════════════════════════════════════
   MODALS
══════════════════════════════════════════════════════════ */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.overlay.open{display:flex;animation:fadeIn .2s ease}
.modal{background:var(--bg1);border:1px solid var(--border);border-radius:var(--r2);width:100%;max-width:440px;overflow:hidden;animation:fadeUp .28s cubic-bezier(.4,0,.2,1)}
.modal-lg{max-width:520px}
.modal-hdr{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-hdr h2{font-size:17px;font-weight:800}
.modal-x{width:28px;height:28px;border:none;background:var(--card2);border-radius:7px;color:var(--text2);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:.15s}
.modal-x:hover{background:var(--coral);color:#fff}
.modal-body{padding:20px 24px}
.modal-footer-btns{padding:0 24px 20px;display:flex;gap:9px;justify-content:flex-end}
.mtabs{display:flex;background:var(--bg3);border-radius:8px;padding:3px;margin-bottom:18px}
.mtab{flex:1;padding:8px;text-align:center;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;color:var(--text2);transition:.18s;user-select:none}
.mtab.on{background:var(--bg1);color:var(--teal)}

/* ── PAYMENT MODAL ─────────────────────────────────────── */
.pay-summary{background:var(--bg3);border-radius:10px;padding:13px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid var(--border)}
.pay-ev-name{font-size:13px;font-weight:600;color:var(--text)}
.pay-ev-label{font-size:10px;color:var(--text3);margin-bottom:2px}
.pay-amount{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:var(--gold);flex-shrink:0}
.pay-methods{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-bottom:14px}
.pm{padding:11px 6px;border-radius:9px;border:1.5px solid var(--border);background:var(--bg3);text-align:center;cursor:pointer;transition:.18s;user-select:none}
.pm:hover{border-color:var(--teal)}.pm.on{border-color:var(--teal);background:rgba(0,212,170,.06)}
.pm-ico{font-size:19px;margin-bottom:4px}.pm-lbl{font-size:10px;font-weight:600;color:var(--text2)}
.pay-card-fields{} .pay-upi-fields{}
.success-wrap{text-align:center;padding:36px 20px}
.success-ico{font-size:52px;margin-bottom:12px;animation:float 2s ease-in-out infinite}
.txn-code{font-family:monospace;font-size:12px;background:var(--bg3);border:1px solid var(--border-t);border-radius:7px;padding:9px 16px;color:var(--teal);display:inline-block;margin:10px 0;letter-spacing:.5px}

/* ══════════════════════════════════════════════════════════
   APP DASHBOARD LAYOUT
══════════════════════════════════════════════════════════ */
#appPage{display:none;min-height:100vh}
.sidebar{width:var(--sw);background:var(--bg1);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;transition:transform .28s cubic-bezier(.4,0,.2,1);overflow:hidden}
.sb-logo{padding:18px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px}
.sb-logo span{font-family:'Syne',sans-serif;font-size:16px;font-weight:800}
.sb-logo .dot{width:8px;height:8px;background:var(--teal);border-radius:50%;animation:pulse 2s infinite}
.sb-nav{flex:1;padding:14px 8px;overflow-y:auto}
.sb-group{margin-bottom:18px}
.sb-group-lbl{font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);padding:0 10px;margin-bottom:4px}
.sb-item{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:8px;color:var(--text2);font-size:13px;font-weight:500;cursor:pointer;transition:.15s;margin-bottom:1px}
.sb-item:hover{background:var(--card2);color:var(--text)}
.sb-item.on{background:rgba(0,212,170,.1);color:var(--teal);border:1px solid rgba(0,212,170,.14)}
.sb-item i{width:15px;text-align:center;font-size:13px;flex-shrink:0}
.sb-footer{padding:10px 8px;border-top:1px solid var(--border)}
.user-pill{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:8px;background:var(--card)}
.user-ava{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--teal),var(--teal2));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:13px;color:#07090F;flex-shrink:0}
.user-dets{flex:1;min-width:0}
.user-nm{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.user-rl{font-size:10px;color:var(--text3)}
.btn-out{width:100%;margin-top:7px;padding:8px;border-radius:7px;background:rgba(255,92,92,.07);border:1px solid rgba(255,92,92,.17);color:var(--coral);font-size:12px;font-weight:600;cursor:pointer;transition:.18s;display:flex;align-items:center;justify-content:center;gap:7px}
.btn-out:hover{background:var(--coral);color:#fff}
/* Topbar */
.topbar{position:fixed;left:var(--sw);right:0;top:0;height:var(--th);background:rgba(12,17,25,.9);backdrop-filter:blur(14px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 22px;gap:13px;z-index:100;transition:left .28s}
.topbar-ttl{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;flex:1;color:var(--text)}.topbar-ttl span{color:var(--teal)}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--teal);animation:pulse 2s infinite;flex-shrink:0}
.live-lbl{font-size:11px;color:var(--text3);white-space:nowrap}
.notif-wrap{position:relative}
.notif-btn{width:36px;height:36px;border-radius:8px;background:var(--card2);border:1px solid var(--border);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;transition:.15s}
.notif-btn:hover{border-color:var(--teal);color:var(--teal)}
.n-badge{position:absolute;top:-4px;right:-4px;background:var(--coral);color:#fff;font-size:9px;font-weight:700;min-width:16px;height:16px;border-radius:99px;display:none;align-items:center;justify-content:center;border:2px solid var(--bg1);padding:0 2px}
.notif-dd{position:absolute;right:0;top:44px;width:310px;background:var(--bg1);border:1px solid var(--border);border-radius:13px;box-shadow:var(--shadow);display:none;z-index:400;overflow:hidden}
.notif-dd.open{display:block;animation:fadeUp .18s ease}
.n-head{padding:12px 15px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:700}
.n-head button{background:none;border:none;color:var(--teal);font-size:11px;font-weight:600;cursor:pointer}
.n-list{max-height:250px;overflow-y:auto}
.n-item{padding:10px 15px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text2);display:flex;gap:8px}
.n-item:last-child{border:none}
.n-item.unread{background:rgba(0,212,170,.04);color:var(--text)}
.n-time{font-size:10px;color:var(--text3);margin-top:2px}
.hamburger{display:none;width:35px;height:35px;border-radius:7px;background:var(--card2);border:1px solid var(--border);color:var(--text2);cursor:pointer;align-items:center;justify-content:center;font-size:14px}
/* Main */
.main-cnt{margin-left:var(--sw);margin-top:var(--th);padding:22px;min-height:calc(100vh - var(--th));transition:margin-left .28s}
.pg{display:none}.pg.on{display:block;animation:fadeUp .3s cubic-bezier(.4,0,.2,1)}
/* Stats row */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:14px;margin-bottom:22px}
.st-card{padding:18px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);display:flex;align-items:center;gap:14px;transition:.22s}
.st-card:hover{transform:translateY(-2px);border-color:var(--border-t);box-shadow:var(--glow)}
.st-ico{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.st-num{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;line-height:1}
.st-lbl{font-size:11px;color:var(--text2);margin-top:2px}
/* Welcome banner */
.welcome-banner{background:linear-gradient(135deg,var(--bg2) 0%,var(--bg3) 100%);border:1px solid var(--border);border-radius:var(--r2);padding:22px 26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:14px;position:relative;overflow:hidden}
.welcome-banner::before{content:'';position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(0,212,170,.07),transparent 70%);pointer-events:none}
.welcome-banner h2{font-size:19px;font-weight:800;margin-bottom:3px}
.welcome-banner p{font-size:13px;color:var(--text2)}
.welcome-emoji{font-size:44px;animation:float 4s ease-in-out infinite;flex-shrink:0}
/* Section header */
.sec-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.sec-hdr h2{font-size:17px;font-weight:800}
/* Table */
.tbl-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;margin-bottom:18px}
.tbl-head{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:700}
table{width:100%;border-collapse:collapse}
th{padding:9px 13px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border)}
td{padding:10px 13px;font-size:12px;color:var(--text2);border-bottom:1px solid rgba(255,255,255,.025)}
tr:hover td{background:var(--card);color:var(--text)}
tr:last-child td{border-bottom:none}
/* Chart */
.chart-bars{display:flex;align-items:flex-end;gap:6px;height:100px;padding:8px 0}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
.bar-fill{width:100%;background:linear-gradient(to top,var(--teal2),var(--teal));border-radius:3px 3px 0 0;transition:.5s;min-height:3px}
.bar-lbl{font-size:8px;color:var(--text3);text-align:center;white-space:nowrap;overflow:hidden;width:100%;text-overflow:ellipsis}
.bar-val{font-size:9px;font-weight:700;color:var(--teal)}
/* Admin ev grid */
.admin-ev-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(275px,1fr));gap:16px}
/* Emoji picker */
.emoji-picker{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.ep-opt{width:34px;height:34px;border-radius:7px;border:1.5px solid var(--border);background:var(--bg3);cursor:pointer;font-size:17px;display:flex;align-items:center;justify-content:center;transition:.15s;user-select:none}
.ep-opt:hover,.ep-opt.on{border-color:var(--teal);background:rgba(0,212,170,.07)}
/* Sidebar overlay */
#sbOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:199}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════════════ */
@media(max-width:1024px){.frow{grid-template-columns:1fr}}
@media(max-width:900px){
  :root{--sw:0px}
  .sidebar{transform:translateX(-268px);width:268px}
  .sidebar.open{transform:translateX(0)}
  .topbar{left:0}.main-cnt{margin-left:0}
  .hamburger{display:flex}
  .about-inner{grid-template-columns:1fr}.about-vis{height:160px;font-size:60px}
  .nav-links{display:none}
}
@media(max-width:640px){
  .navbar{padding:0 16px}.hero{padding:calc(var(--th)+40px) 16px 60px}
  .hero-stats{gap:18px;flex-wrap:wrap}
  .pub-section,.about-sec{padding:50px 16px}
  .main-cnt{padding:12px}.stats-row{grid-template-columns:1fr 1fr}
  .pay-methods{grid-template-columns:repeat(2,1fr)}
  .frow{grid-template-columns:1fr}
  .topbar{padding:0 12px}.topbar-ttl{font-size:14px}
  .live-lbl{display:none}
  .notif-dd{width:calc(100vw - 24px);right:-80px}
  .hero-stats .stat-item .snum{font-size:1.4rem}
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     PUBLIC PAGE
══════════════════════════════════════════════════════════ -->
<div id="pubPage" <?= $isLoggedIn ? 'class="hidden"' : '' ?>>

  <nav class="navbar" id="navbar">
    <a class="nav-logo" href="#"><div class="logo-dot"></div>EventSync</a>
    <div class="nav-links">
      <a href="#ev-sec">Events</a>
      <a href="#feat-sec">Features</a>
      <a href="#about-sec">About</a>
    </div>
    <div class="nav-actions">
      <button class="icon-btn" onclick="toggleTheme()" title="Toggle theme"><i class="fa fa-moon" id="themeIco"></i></button>
      <button class="btn btn-ghost btn-sm" onclick="openAuth('login')">Sign In</button>
      <button class="btn btn-teal btn-sm" onclick="openAuth('register')"><i class="fa fa-rocket"></i> Get Started</button>
    </div>
  </nav>

  <!-- Hero -->
  <section class="hero">
    <div class="hero-bg"></div><div class="hero-grid"></div>
    <div class="hero-content">
      <div class="hero-tag fade"><div class="dot"></div>Live Event Platform</div>
      <h1 class="fade" style="animation-delay:.1s">Discover &amp; Sync<br><span class="hl">Events in Real-Time</span></h1>
      <p class="hero-sub fade" style="animation-delay:.2s">Register for events, make secure payments, and receive live updates — all in one modern platform.</p>
      <div class="hero-btns fade" style="animation-delay:.3s">
        <button class="btn btn-teal" onclick="openAuth('register')"><i class="fa fa-rocket"></i> Start Free</button>
        <button class="btn btn-ghost" onclick="scrollTo('ev-sec')"><i class="fa fa-calendar"></i> Browse Events</button>
      </div>
      <div class="hero-stats fade" style="animation-delay:.4s">
        <div class="stat-item"><div class="snum" id="heroEvCount">—</div><div class="slbl">Live Events</div></div>
        <div class="stat-item"><div class="snum">6</div><div class="slbl">Payment Methods</div></div>
        <div class="stat-item"><div class="snum">100%</div><div class="slbl">Responsive</div></div>
      </div>
    </div>
  </section>

  <!-- Events -->
  <section id="ev-sec" class="pub-section">
    <div class="sec-tag">Live Events</div>
    <div class="sec-title">Upcoming Events</div>
    <div class="toolbar">
      <div class="srch-wrap"><i class="fa fa-search"></i><input class="finput" id="pubQ" placeholder="Search events or locations…" oninput="debouncePoll()"></div>
      <div class="filter-grp">
        <button class="fbtn on" data-cat="" onclick="setCat(this,'')">All</button>
        <button class="fbtn" data-cat="Technology" onclick="setCat(this,'Technology')">Tech</button>
        <button class="fbtn" data-cat="Business" onclick="setCat(this,'Business')">Business</button>
        <button class="fbtn" data-cat="Education" onclick="setCat(this,'Education')">Education</button>
        <button class="fbtn" data-cat="Design" onclick="setCat(this,'Design')">Design</button>
      </div>
      <div class="filter-grp">
        <button class="fbtn on" data-price="" onclick="setPrice(this,'')">All Prices</button>
        <button class="fbtn" data-price="free" onclick="setPrice(this,'free')">Free</button>
        <button class="fbtn" data-price="paid" onclick="setPrice(this,'paid')">Paid</button>
      </div>
    </div>
    <div class="ev-grid" id="pubGrid"><div class="empty"><div class="empty-icon">⏳</div><p>Loading events…</p></div></div>
  </section>

  <!-- Features -->
  <section id="feat-sec" class="pub-section" style="padding-top:20px">
    <div class="sec-tag">Why EventSync</div>
    <div class="sec-title">Everything You Need</div>
    <div class="sec-sub">A complete event platform built on one PHP file.</div>
    <div class="features-grid">
      <div class="feat"><div class="feat-ico ft">⚡</div><h3>Real-Time Sync</h3><p>AJAX polling every 5 seconds. Events always stay fresh without a page reload.</p></div>
      <div class="feat"><div class="feat-ico fg">💳</div><h3>Mock Payments</h3><p>Credit/Debit Card, UPI, Net Banking, PayPal, Wallet — with transaction IDs.</p></div>
      <div class="feat"><div class="feat-ico fv">🛡️</div><h3>Secure Auth</h3><p>bcrypt password hashing, session management, and admin role separation.</p></div>
      <div class="feat"><div class="feat-ico fc">📊</div><h3>Admin Analytics</h3><p>Revenue charts, registrations, user management — a full admin portal.</p></div>
      <div class="feat"><div class="feat-ico ft">🔔</div><h3>Notifications</h3><p>Auto-alerts for event registration, payment confirmation, and updates.</p></div>
      <div class="feat"><div class="feat-ico fg">🌙</div><h3>Dark / Light Mode</h3><p>One-click theme toggle with smooth CSS variable transitions.</p></div>
    </div>
  </section>

  <!-- About -->
  <section id="about-sec" class="about-sec">
    <div class="about-inner">
      <div>
        <div class="sec-tag">About</div>
        <div class="sec-title">Built for College Demos</div>
        <p style="color:var(--text2);line-height:1.75;margin:12px 0 18px;font-size:14px">EventSync is a production-style full-stack event platform — auth, events, payments, admin portal, real-time sync — all within a single PHP file and SQL schema.</p>
        <div class="about-check"><i class="fa fa-check-circle"></i>Single PHP file + MySQL</div>
        <div class="about-check"><i class="fa fa-check-circle"></i>AJAX real-time polling (5s)</div>
        <div class="about-check"><i class="fa fa-check-circle"></i>Admin + User role portals</div>
        <div class="about-check"><i class="fa fa-check-circle"></i>6 mock payment methods</div>
        <div class="about-check"><i class="fa fa-check-circle"></i>Fully responsive design</div>
      </div>
      <div class="about-vis">🗓️
        <div class="about-pills">
          <div class="about-pill">⚡ Live Sync</div>
          <div class="about-pill">🔒 Secure</div>
          <div class="about-pill">📱 Responsive</div>
        </div>
      </div>
    </div>
  </section>

  <footer>
    <div class="footer-logo">EventSync</div>
    <p style="margin-top:6px">Real-Time Event Synchronization Engine — PHP + MySQL — XAMPP</p>
    <p style="margin-top:4px;color:var(--teal)">Admin: admin@eventsync.com &nbsp;/&nbsp; admin123</p>
  </footer>

</div><!-- /pubPage -->

<!-- ══════════════════════════════════════════════════════════
     AUTH MODAL
══════════════════════════════════════════════════════════ -->
<div class="overlay" id="authOverlay">
  <div class="modal">
    <div class="modal-hdr">
      <h2 id="authModalTitle">Sign In to EventSync</h2>
      <button class="modal-x" onclick="closeAuth()"><i class="fa fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="authAlertBox" class="alert"></div>
      <div class="mtabs" id="authTabs">
        <div class="mtab on" id="tabLogin" onclick="switchAuth('login')">Sign In</div>
        <div class="mtab" id="tabReg" onclick="switchAuth('register')">Register</div>
      </div>

      <!-- LOGIN -->
      <div id="loginForm">
        <div class="fgroup">
          <label><i class="fa fa-envelope"></i> Email Address</label>
          <div class="finput-icon">
            <i class="fa fa-envelope"></i>
            <input class="finput" id="li_email" type="email" placeholder="you@example.com" autocomplete="email">
          </div>
          <div class="field-error" id="li_email_err"></div>
        </div>
        <div class="fgroup">
          <label><i class="fa fa-lock"></i> Password</label>
          <div class="finput-icon">
            <i class="fa fa-lock"></i>
            <input class="finput" id="li_pass" type="password" placeholder="Enter your password" autocomplete="current-password">
            <button class="eye-toggle" type="button" onclick="toggleEye('li_pass','li_eye')"><i class="fa fa-eye" id="li_eye"></i></button>
          </div>
          <div class="field-error" id="li_pass_err"></div>
        </div>
        <button class="btn btn-teal btn-full" id="loginBtn" onclick="doLogin()">
          <span id="loginBtnTxt"><i class="fa fa-sign-in-alt"></i> Sign In</span>
          <div class="btn-loader" id="loginLoader"></div>
        </button>
      </div>

      <!-- REGISTER -->
      <div id="regForm" class="hidden">
        <div class="fgroup">
          <label><i class="fa fa-user"></i> Full Name</label>
          <div class="finput-icon">
            <i class="fa fa-user"></i>
            <input class="finput" id="rg_name" type="text" placeholder="Your full name" autocomplete="name">
          </div>
          <div class="field-error" id="rg_name_err"></div>
        </div>
        <div class="fgroup">
          <label><i class="fa fa-envelope"></i> Email Address</label>
          <div class="finput-icon">
            <i class="fa fa-envelope"></i>
            <input class="finput" id="rg_email" type="email" placeholder="you@example.com" autocomplete="email">
          </div>
          <div class="field-error" id="rg_email_err"></div>
        </div>
        <div class="fgroup">
          <label><i class="fa fa-lock"></i> Password</label>
          <div class="finput-icon">
            <i class="fa fa-lock"></i>
            <input class="finput" id="rg_pass" type="password" placeholder="Minimum 6 characters" autocomplete="new-password">
            <button class="eye-toggle" type="button" onclick="toggleEye('rg_pass','rg_eye')"><i class="fa fa-eye" id="rg_eye"></i></button>
          </div>
          <div class="field-error" id="rg_pass_err"></div>
          <div class="field-hint">Must be at least 6 characters</div>
        </div>
        <button class="btn btn-teal btn-full" id="regBtn" onclick="doRegister()">
          <span id="regBtnTxt"><i class="fa fa-user-plus"></i> Create Account</span>
          <div class="btn-loader" id="regLoader"></div>
        </button>
      </div>

    </div>
  </div>
</div>

<!-- PAYMENT MODAL -->
<div class="overlay" id="payOverlay">
  <div class="modal modal-lg">
    <div class="modal-hdr">
      <h2><i class="fa fa-shield-alt" style="color:var(--gold);margin-right:7px"></i>Complete Payment</h2>
      <button class="modal-x" onclick="closePayModal()"><i class="fa fa-times"></i></button>
    </div>
    <div id="payModalBody" class="modal-body">
      <div class="pay-summary">
        <div><div class="pay-ev-label">REGISTERING FOR</div><div class="pay-ev-name" id="payEvTitle">—</div></div>
        <div class="pay-amount" id="payEvAmt">₹0</div>
      </div>
      <div id="payAlertBox" class="alert"></div>
      <div class="fgroup" style="margin-bottom:6px"><label>Select Payment Method</label></div>
      <div class="pay-methods">
        <div class="pm on" data-m="credit_card" onclick="selectPM(this)"><div class="pm-ico">💳</div><div class="pm-lbl">Credit Card</div></div>
        <div class="pm" data-m="debit_card"  onclick="selectPM(this)"><div class="pm-ico">🏦</div><div class="pm-lbl">Debit Card</div></div>
        <div class="pm" data-m="upi"          onclick="selectPM(this)"><div class="pm-ico">📱</div><div class="pm-lbl">UPI</div></div>
        <div class="pm" data-m="net_banking"  onclick="selectPM(this)"><div class="pm-ico">🌐</div><div class="pm-lbl">Net Banking</div></div>
        <div class="pm" data-m="paypal"       onclick="selectPM(this)"><div class="pm-ico">🅿️</div><div class="pm-lbl">PayPal</div></div>
        <div class="pm" data-m="wallet"       onclick="selectPM(this)"><div class="pm-ico">👛</div><div class="pm-lbl">Wallet</div></div>
      </div>
      <div id="payCardFields">
        <div class="frow">
          <div class="fgroup"><label>Card Number</label><input class="finput" id="cardNum" maxlength="19" placeholder="0000 0000 0000 0000" oninput="fmtCard(this)"></div>
          <div class="fgroup"><label>Expiry (MM/YY)</label><input class="finput" maxlength="5" placeholder="MM/YY" oninput="fmtExp(this)"></div>
        </div>
        <div class="frow">
          <div class="fgroup"><label>Name on Card</label><input class="finput" placeholder="John Doe"></div>
          <div class="fgroup"><label>CVV</label><input class="finput" type="password" maxlength="4" placeholder="•••"></div>
        </div>
      </div>
      <div id="payUpiFields" class="hidden">
        <div class="fgroup"><label>UPI ID</label><input class="finput" placeholder="yourname@upi"></div>
      </div>
      <button class="btn btn-gold btn-full" onclick="doPayment()">
        <span id="payBtnTxt"><i class="fa fa-lock"></i> Pay Securely</span>
        <div class="btn-loader" id="payLoader"></div>
      </button>
      <p style="text-align:center;font-size:11px;color:var(--text3);margin-top:10px">🔒 Simulation only — no real payment processed</p>
    </div>
  </div>
</div>

<!-- EVENT MODAL (Admin) -->
<div class="overlay" id="evOverlay">
  <div class="modal modal-lg">
    <div class="modal-hdr">
      <h2 id="evModalTitle">Create Event</h2>
      <button class="modal-x" onclick="closeEvModal()"><i class="fa fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="evAlertBox" class="alert"></div>
      <input type="hidden" id="em_eid">
      <div class="fgroup"><label>Event Title *</label><input class="finput" id="em_title" placeholder="Enter event title"></div>
      <div class="fgroup"><label>Description</label><textarea class="finput" id="em_desc" placeholder="Describe the event…"></textarea></div>
      <div class="frow">
        <div class="fgroup"><label>Category</label>
          <select class="finput" id="em_cat">
            <option>Technology</option><option>Business</option><option>Education</option><option>Design</option><option>General</option>
          </select>
        </div>
        <div class="fgroup"><label>Icon Emoji</label>
          <div class="emoji-picker">
            <?php foreach(['🤖','💻','🚀','☁️','🎨','📊','🎤','🎯','🌍','⚡','🏆','🎓'] as $em): ?>
            <div class="ep-opt" data-e="<?= esc($em) ?>" onclick="pickEmoji(this)"><?= $em ?></div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="em_image" value="📅">
        </div>
      </div>
      <div class="frow">
        <div class="fgroup"><label>Date *</label><input class="finput" id="em_date" type="date"></div>
        <div class="fgroup"><label>Time *</label><input class="finput" id="em_time" type="time"></div>
      </div>
      <div class="fgroup"><label>Location</label><input class="finput" id="em_loc" placeholder="Venue, City"></div>
      <div class="frow">
        <div class="fgroup"><label>Price (₹)</label><input class="finput" id="em_price" type="number" min="0" step="1" placeholder="0 = Free"></div>
        <div class="fgroup"><label>Capacity</label><input class="finput" id="em_cap" type="number" min="1" placeholder="100"></div>
      </div>
    </div>
    <div class="modal-footer-btns">
      <button class="btn btn-ghost btn-sm" onclick="closeEvModal()">Cancel</button>
      <button class="btn btn-teal btn-sm" onclick="saveEvent()">
        <span id="saveBtnTxt"><i class="fa fa-save"></i> Save Event</span>
        <div class="btn-loader" id="saveLoader"></div>
      </button>
    </div>
  </div>
</div>

<!-- Sidebar overlay -->
<div id="sbOverlay" onclick="closeSB()"></div>

<!-- ══════════════════════════════════════════════════════════
     APP PAGE
══════════════════════════════════════════════════════════ -->
<div id="appPage" <?= $isLoggedIn ? 'style="display:block"' : '' ?>>
  <aside class="sidebar" id="sidebar">
    <div class="sb-logo"><div class="dot"></div><span>EventSync</span></div>
    <nav class="sb-nav" id="sbNav"></nav>
    <div class="sb-footer">
      <div class="user-pill">
        <div class="user-ava" id="sbAva">U</div>
        <div class="user-dets">
          <div class="user-nm" id="sbName">—</div>
          <div class="user-rl" id="sbRole">—</div>
        </div>
      </div>
      <button class="btn-out" onclick="doLogout()"><i class="fa fa-sign-out-alt"></i> Sign Out</button>
    </div>
  </aside>

  <div style="min-height:100vh;display:flex;flex-direction:column">
    <header class="topbar" id="topbar">
      <button class="hamburger" onclick="toggleSB()"><i class="fa fa-bars"></i></button>
      <div class="topbar-ttl">Event<span>Sync</span></div>
      <div style="display:flex;align-items:center;gap:6px"><div class="live-dot"></div><span class="live-lbl">Live Sync</span></div>
      <button class="icon-btn" onclick="toggleTheme()"><i class="fa fa-moon" id="themeIco2"></i></button>
      <div class="notif-wrap">
        <button class="notif-btn" onclick="toggleNotif()" id="notifBtn">
          <i class="fa fa-bell"></i>
          <span class="n-badge" id="nBadge"></span>
        </button>
        <div class="notif-dd" id="notifDD">
          <div class="n-head">Notifications <button onclick="markRead()">Mark read</button></div>
          <div class="n-list" id="nList"><div style="padding:16px;text-align:center;color:var(--text3);font-size:12px">All caught up!</div></div>
        </div>
      </div>
    </header>
    <main class="main-cnt">

      <!-- USER: Home -->
      <section class="pg" id="pg-home">
        <div class="welcome-banner">
          <div><h2>Welcome back, <span id="wbName" style="color:var(--teal)">—</span>!</h2><p>Track your events and registrations below.</p></div>
          <div class="welcome-emoji">🎉</div>
        </div>
        <div class="stats-row" id="userStats"></div>
        <div class="sec-hdr"><h2>Upcoming Events</h2><button class="btn btn-teal btn-sm" onclick="goPg('events')"><i class="fa fa-calendar"></i> Browse All</button></div>
        <div class="ev-grid" id="homeEvGrid"></div>
      </section>

      <!-- USER: Browse Events -->
      <section class="pg" id="pg-events">
        <div class="sec-hdr"><h2>All Events</h2></div>
        <div class="toolbar">
          <div class="srch-wrap"><i class="fa fa-search"></i><input class="finput" id="appQ" placeholder="Search events…" oninput="renderAppEvs()"></div>
        </div>
        <div class="ev-grid" id="appEvGrid"></div>
      </section>

      <!-- USER: My Events -->
      <section class="pg" id="pg-myevents">
        <div class="sec-hdr"><h2>My Registered Events</h2></div>
        <div class="ev-grid" id="myEvGrid"></div>
      </section>

      <!-- USER: Payments -->
      <section class="pg" id="pg-payments">
        <div class="sec-hdr"><h2>Payment History</h2></div>
        <div class="tbl-card">
          <table><thead><tr><th>Event</th><th>Amount</th><th>Method</th><th>TXN ID</th><th>Status</th><th>Date</th></tr></thead>
          <tbody id="payHistTbl"></tbody></table>
        </div>
      </section>

      <!-- ADMIN: Dashboard -->
      <section class="pg" id="pg-admin-dash">
        <div class="sec-hdr"><h2>Admin Dashboard</h2><span class="badge badge-gold"><i class="fa fa-crown"></i> Admin</span></div>
        <div class="stats-row" id="adminStats"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
          <div class="tbl-card"><div class="tbl-head">Revenue by Event <span id="revTotal" style="font-size:11px;color:var(--text3)"></span></div><div style="padding:12px 14px"><div class="chart-bars" id="revChart"></div></div></div>
          <div class="tbl-card"><div class="tbl-head">Recent Payments</div><table><thead><tr><th>User</th><th>Event</th><th>Amount</th><th>Status</th></tr></thead><tbody id="recentPayTbl"></tbody></table></div>
        </div>
        <div class="tbl-card"><div class="tbl-head">Recent Registrations</div><table><thead><tr><th>User</th><th>Event</th><th>Date</th><th>Status</th></tr></thead><tbody id="recentRegTbl"></tbody></table></div>
      </section>

      <!-- ADMIN: Events -->
      <section class="pg" id="pg-admin-events">
        <div class="sec-hdr"><h2>Manage Events</h2><button class="btn btn-teal btn-sm" onclick="openEvModal()"><i class="fa fa-plus"></i> New Event</button></div>
        <div class="admin-ev-grid" id="adminEvGrid"></div>
      </section>

      <!-- ADMIN: Users -->
      <section class="pg" id="pg-admin-users">
        <div class="sec-hdr"><h2>All Users</h2></div>
        <div class="tbl-card">
          <table><thead><tr><th>Name</th><th>Email</th><th>Registrations</th><th>Joined</th><th>Action</th></tr></thead>
          <tbody id="usersTbl"></tbody></table>
        </div>
      </section>

      <!-- ADMIN: Payments -->
      <section class="pg" id="pg-admin-payments">
        <div class="sec-hdr"><h2>All Payments</h2></div>
        <div class="tbl-card">
          <table><thead><tr><th>User</th><th>Event</th><th>Amount</th><th>Method</th><th>TXN ID</th><th>Status</th><th>Date</th></tr></thead>
          <tbody id="allPayTbl"></tbody></table>
        </div>
      </section>

    </main>
  </div>
</div>

<!-- Toast -->
<div id="toastBox" style="position:fixed;bottom:20px;right:20px;z-index:9999;pointer-events:none"></div>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════ -->
<script>
// ── STATE ────────────────────────────────────────────────
var APP = {
  loggedIn: <?= $isLoggedIn ? 'true' : 'false' ?>,
  role: '<?= esc($userRole) ?>',
  name: '<?= esc($userName) ?>',
  uid: <?= uid() ?? 0 ?>,
  allEvents: [],
  dash: { regs: [], pays: [] },
  pollTimer: null,
  notifOpen: false,
  payMethod: 'credit_card',
  payEid: null,
  payAmt: 0,
  payName: '',
  filterCat: '',
  filterPrice: '',
  pollDebounce: null
};

// ── BOOT ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  window.addEventListener('scroll', function(){
    var nb = document.getElementById('navbar');
    if(nb) nb.classList.toggle('scrolled', window.scrollY > 40);
  });
  pollEvents();
  if(APP.loggedIn){ initApp(); }
});

// ── THEME ─────────────────────────────────────────────────
function toggleTheme(){
  var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
  var ico = t === 'dark' ? 'fa fa-moon' : 'fa fa-sun';
  var i1 = document.getElementById('themeIco'); if(i1) i1.className = ico;
  var i2 = document.getElementById('themeIco2'); if(i2) i2.className = ico;
}

// ── POLL EVENTS ───────────────────────────────────────────
function debouncePoll(){
  clearTimeout(APP.pollDebounce);
  APP.pollDebounce = setTimeout(pollEvents, 300);
}
async function pollEvents(){
  var q = (document.getElementById('pubQ') || {}).value || '';
  var res = await call('poll', null, { q: q, cat: APP.filterCat, price: APP.filterPrice });
  if(!res || res.err) return;
  APP.allEvents = res.events || [];
  var c = document.getElementById('heroEvCount'); if(c) c.textContent = APP.allEvents.length;
  renderPubGrid(APP.allEvents);
  if(APP.loggedIn){ renderAppEvs(); }
  clearTimeout(APP.pollTimer);
  APP.pollTimer = setTimeout(pollEvents, 5000);
}
function setCat(el, v){
  document.querySelectorAll('[data-cat]').forEach(function(e){ e.classList.remove('on'); });
  el.classList.add('on'); APP.filterCat = v; pollEvents();
}
function setPrice(el, v){
  document.querySelectorAll('[data-price]').forEach(function(e){ e.classList.remove('on'); });
  el.classList.add('on'); APP.filterPrice = v; pollEvents();
}
function renderPubGrid(evs){
  var g = document.getElementById('pubGrid'); if(!g) return;
  if(!evs.length){ g.innerHTML = '<div class="empty"><div class="empty-icon">📭</div><p>No events match your filters</p></div>'; return; }
  g.innerHTML = evs.map(function(e){ return buildEvCard(e); }).join('');
}

// ── EVENT CARD ─────────────────────────────────────────────
function buildEvCard(e){
  var today = new Date().toISOString().slice(0,10);
  var isPast = e.date < today, isToday = e.date === today;
  var cap = parseInt(e.capacity)||100, cnt = parseInt(e.reg_count)||0;
  var pct = Math.min(100, Math.round(cnt/cap*100));
  var full = cnt >= cap;
  var reg = APP.loggedIn && APP.dash.regs.find(function(r){ return r.event_id == e.event_id; });
  var statusBadge = isPast ? '<span class="badge badge-coral">Past</span>' : isToday ? '<span class="badge badge-gold">Today</span>' : '<span class="badge badge-teal">Upcoming</span>';
  var priceStr = parseFloat(e.price) === 0 ? 'Free' : '₹' + parseFloat(e.price).toLocaleString('en-IN');

  var cd = '';
  if(!isPast && !isToday){
    var diff = Math.max(0, new Date(e.date) - new Date());
    var d = Math.floor(diff/86400000), h = Math.floor(diff%86400000/3600000), m = Math.floor(diff%3600000/60000);
    cd = '<div class="countdown"><div class="cd-box"><div class="cd-n">'+d+'</div><div class="cd-l">D</div></div><div class="cd-box"><div class="cd-n">'+h+'</div><div class="cd-l">H</div></div><div class="cd-box"><div class="cd-n">'+m+'</div><div class="cd-l">M</div></div></div>';
  }

  var btn;
  if(!APP.loggedIn){
    btn = '<button class="btn btn-teal btn-sm" onclick="openAuth(\'login\')"><i class="fa fa-ticket-alt"></i> Register</button>';
  } else if(reg){
    btn = '<span class="badge badge-teal"><i class="fa fa-check"></i> Registered</span>';
  } else if(full){
    btn = '<span class="badge badge-coral">Full</span>';
  } else if(isPast){
    btn = '<span class="badge" style="color:var(--text3);border:1px solid var(--border)">Ended</span>';
  } else {
    btn = '<button class="btn btn-teal btn-sm" onclick="regEvent('+e.event_id+')"><i class="fa fa-ticket-alt"></i> Register</button>';
  }

  return '<div class="ev-card">'+
    '<div class="ev-card-img"><span>'+xss(e.image||'📅')+'</span></div>'+
    '<div class="ev-body">'+
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px">'+
        '<div class="ev-cat">'+xss(e.category||'General')+'</div>'+statusBadge+
      '</div>'+
      '<div class="ev-title">'+xss(e.title)+'</div>'+
      cd+
      '<div class="ev-meta">'+
        '<div class="ev-meta-row"><i class="fa fa-calendar"></i>'+fmtDate(e.date)+' at '+fmtTime(e.time)+'</div>'+
        '<div class="ev-meta-row"><i class="fa fa-map-marker-alt"></i>'+xss(e.location||'TBA')+'</div>'+
        '<div class="ev-meta-row"><i class="fa fa-users"></i>'+cnt+'/'+cap+' registered</div>'+
      '</div>'+
      '<div class="ev-progress"><div class="ev-progress-fill" style="width:'+pct+'%"></div></div>'+
    '</div>'+
    '<div class="ev-footer">'+
      '<div class="ev-price">'+priceStr+'<small>'+(parseFloat(e.price)===0?'Free entry':'Per person')+'</small></div>'+
      btn+
    '</div>'+
  '</div>';
}

// ── APP INIT ──────────────────────────────────────────────
function initApp(){
  document.getElementById('pubPage').classList.add('hidden');
  document.getElementById('appPage').style.display = 'block';
  document.getElementById('wbName').textContent = APP.name;
  document.getElementById('sbName').textContent = APP.name;
  document.getElementById('sbRole').textContent = APP.role === 'admin' ? 'Administrator' : 'Member';
  document.getElementById('sbAva').textContent = (APP.name[0] || 'U').toUpperCase();
  buildSidebarNav();
  goPg(APP.role === 'admin' ? 'admin-dash' : 'home');
  loadDash();
  loadNotifs();
}
function buildSidebarNav(){
  var isA = APP.role === 'admin';
  var items = isA ? [
    { s:'admin-dash',     i:'fa-tachometer-alt', l:'Dashboard' },
    { s:'admin-events',   i:'fa-calendar-alt',   l:'Events' },
    { s:'admin-users',    i:'fa-users',           l:'Users' },
    { s:'admin-payments', i:'fa-credit-card',     l:'Payments' }
  ] : [
    { s:'home',     i:'fa-home',        l:'Home' },
    { s:'events',   i:'fa-calendar',    l:'Browse Events' },
    { s:'myevents', i:'fa-ticket-alt',  l:'My Events' },
    { s:'payments', i:'fa-receipt',     l:'Payments' }
  ];
  document.getElementById('sbNav').innerHTML =
    '<div class="sb-group"><div class="sb-group-lbl">'+(isA?'Admin Panel':'Navigation')+'</div>'+
    items.map(function(it){
      return '<div class="sb-item" id="sb-'+it.s+'" onclick="goPg(\''+it.s+'\')"><i class="fa '+it.i+'"></i>'+it.l+'</div>';
    }).join('')+'</div>';
}
function goPg(s){
  document.querySelectorAll('.pg').forEach(function(e){ e.classList.remove('on'); });
  document.querySelectorAll('.sb-item').forEach(function(e){ e.classList.remove('on'); });
  var pg = document.getElementById('pg-'+s); if(pg) pg.classList.add('on');
  var sb = document.getElementById('sb-'+s); if(sb) sb.classList.add('on');
  if(s==='admin-dash')     loadAdminStats();
  if(s==='admin-events')   loadAdminEvs();
  if(s==='admin-users')    loadAdminUsers();
  if(s==='admin-payments') loadAdminPays();
  if(s==='myevents')       renderMyEvs();
  if(s==='payments')       renderPayHist();
  if(window.innerWidth <= 900) closeSB();
}

// ── DASHBOARD DATA ────────────────────────────────────────
async function loadDash(){
  var res = await call('dash');
  if(!res || res.err){ return; }
  APP.dash = { regs: res.regs || [], pays: res.pays || [] };
  renderUserStats();
  renderAppEvs();
  renderMyEvs();
  renderPayHist();
}
function renderUserStats(){
  var regs = APP.dash.regs, pays = APP.dash.pays;
  var today = new Date().toISOString().slice(0,10);
  var upcoming = regs.filter(function(r){ return r.date >= today; }).length;
  var spent = pays.reduce(function(s,p){ return s + parseFloat(p.amount||0); }, 0);
  document.getElementById('userStats').innerHTML =
    stCard('ft','📅', regs.length, 'Registered') +
    stCard('fg','🚀', upcoming, 'Upcoming') +
    stCard('fv','💰', '₹'+spent.toLocaleString('en-IN',{maximumFractionDigits:0}), 'Total Spent');
}
function stCard(cls, ico, val, lbl){
  return '<div class="st-card"><div class="st-ico '+cls+'">'+ico+'</div><div><div class="st-num">'+val+'</div><div class="st-lbl">'+lbl+'</div></div></div>';
}
function renderAppEvs(){
  var q = ((document.getElementById('appQ')||{}).value||'').toLowerCase();
  var evs = APP.allEvents.filter(function(e){
    return !q || e.title.toLowerCase().includes(q) || (e.location||'').toLowerCase().includes(q);
  });
  var g1 = document.getElementById('homeEvGrid');
  if(g1) g1.innerHTML = evs.slice(0,6).map(function(e){ return buildEvCard(e); }).join('') || emptyState();
  var g2 = document.getElementById('appEvGrid');
  if(g2) g2.innerHTML = evs.map(function(e){ return buildEvCard(e); }).join('') || emptyState();
}
function renderMyEvs(){
  var g = document.getElementById('myEvGrid'); if(!g) return;
  g.innerHTML = APP.dash.regs.length
    ? APP.dash.regs.map(function(e){ return buildEvCard(e); }).join('')
    : emptyState('No registered events yet');
}
function renderPayHist(){
  var tb = document.getElementById('payHistTbl'); if(!tb) return;
  tb.innerHTML = APP.dash.pays.length
    ? APP.dash.pays.map(function(p){
        return '<tr>'+
          '<td>'+xss(p.title)+'</td>'+
          '<td style="color:var(--gold);font-weight:700">₹'+parseFloat(p.amount).toLocaleString('en-IN')+'</td>'+
          '<td>'+xss((p.payment_method||'').replace(/_/g,' '))+'</td>'+
          '<td style="font-family:monospace;font-size:11px">'+xss(p.transaction_id)+'</td>'+
          '<td><span class="badge badge-teal">'+xss(p.payment_status)+'</span></td>'+
          '<td>'+fmtDate((p.created_at||'').slice(0,10))+'</td>'+
        '</tr>';
      }).join('')
    : '<tr><td colspan="6" style="text-align:center;color:var(--text3);padding:20px">No payments yet</td></tr>';
}

// ── ADMIN DATA ────────────────────────────────────────────
async function loadAdminStats(){
  var r = await call('admin_stats'); if(!r||r.err) return;
  document.getElementById('adminStats').innerHTML =
    stCard('ft','👥',r.users,'Users') +
    stCard('fg','📅',r.evts,'Events') +
    stCard('fv','🎟️',r.regs,'Registrations') +
    stCard('fc','💰','₹'+parseFloat(r.rev||0).toLocaleString('en-IN',{maximumFractionDigits:0}),'Revenue');

  document.getElementById('revTotal').textContent = 'Total: ₹'+parseFloat(r.rev||0).toLocaleString('en-IN');

  // Revenue chart
  var byEv = {};
  (r.recentPay||[]).forEach(function(p){ byEv[p.title] = (byEv[p.title]||0) + parseFloat(p.amount||0); });
  var keys = Object.keys(byEv).slice(0,6);
  var maxV = keys.length ? Math.max.apply(null, keys.map(function(k){ return byEv[k]; })) : 1;
  document.getElementById('revChart').innerHTML = keys.map(function(k){
    return '<div class="bar-col">'+
      '<div class="bar-val">₹'+Math.round(byEv[k]).toLocaleString('en-IN')+'</div>'+
      '<div class="bar-fill" style="height:'+Math.round(byEv[k]/maxV*88)+'px"></div>'+
      '<div class="bar-lbl">'+k.slice(0,12)+'</div>'+
    '</div>';
  }).join('') || '<p style="color:var(--text3);font-size:12px;padding:10px">No payment data yet</p>';

  document.getElementById('recentPayTbl').innerHTML = (r.recentPay||[]).slice(0,8).map(function(p){
    return '<tr><td>'+xss(p.fullname)+'</td><td>'+xss((p.title||'').slice(0,18))+'</td><td style="color:var(--gold);font-weight:700">₹'+parseFloat(p.amount).toLocaleString('en-IN')+'</td><td><span class="badge badge-teal">'+xss(p.payment_status)+'</span></td></tr>';
  }).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--text3);padding:16px">No payments yet</td></tr>';

  document.getElementById('recentRegTbl').innerHTML = (r.allRegs||[]).map(function(reg){
    return '<tr><td>'+xss(reg.fullname)+'</td><td>'+xss(reg.title)+'</td><td>'+fmtDate((reg.created_at||'').slice(0,10))+'</td><td><span class="badge badge-teal">'+xss(reg.status)+'</span></td></tr>';
  }).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--text3);padding:16px">No registrations yet</td></tr>';
}
async function loadAdminEvs(){
  var r = await call('poll', null, {q:'',cat:'',price:''}); if(!r||r.err) return;
  var g = document.getElementById('adminEvGrid');
  if(!r.events.length){ g.innerHTML = '<p style="color:var(--text3)">No events created yet.</p>'; return; }
  g.innerHTML = r.events.map(function(e){
    return '<div class="ev-card">'+
      '<div class="ev-card-img"><span>'+xss(e.image||'📅')+'</span></div>'+
      '<div class="ev-body">'+
        '<div class="ev-cat">'+xss(e.category)+'</div>'+
        '<div class="ev-title">'+xss(e.title)+'</div>'+
        '<div class="ev-meta">'+
          '<div class="ev-meta-row"><i class="fa fa-calendar"></i>'+fmtDate(e.date)+'</div>'+
          '<div class="ev-meta-row"><i class="fa fa-map-marker-alt"></i>'+xss(e.location||'TBA')+'</div>'+
          '<div class="ev-meta-row"><i class="fa fa-users"></i>'+e.reg_count+'/'+e.capacity+'</div>'+
          '<div class="ev-meta-row"><i class="fa fa-tag"></i>₹'+parseFloat(e.price).toLocaleString('en-IN')+'</div>'+
        '</div>'+
      '</div>'+
      '<div class="ev-footer" style="gap:8px">'+
        '<button class="btn btn-ghost btn-sm" onclick="openEvModal('+e.event_id+')"><i class="fa fa-edit"></i> Edit</button>'+
        '<button class="btn btn-danger btn-xs" onclick="delEvent('+e.event_id+',\''+xss(e.title).replace(/'/g,"\\'")+'\')" title="Delete"><i class="fa fa-trash"></i></button>'+
      '</div>'+
    '</div>';
  }).join('');
}
async function loadAdminUsers(){
  var r = await call('admin_stats'); if(!r||r.err) return;
  document.getElementById('usersTbl').innerHTML = (r.usersList||[]).length
    ? (r.usersList||[]).map(function(u){
        return '<tr>'+
          '<td><strong>'+xss(u.fullname)+'</strong></td>'+
          '<td>'+xss(u.email)+'</td>'+
          '<td><span class="badge badge-teal">'+u.reg_count+'</span></td>'+
          '<td>'+fmtDate((u.created_at||'').slice(0,10))+'</td>'+
          '<td><button class="btn btn-danger btn-xs" onclick="delUser('+u.id+',\''+xss(u.fullname).replace(/'/g,"\\'")+'\')"><i class="fa fa-trash"></i></button></td>'+
        '</tr>';
      }).join('')
    : '<tr><td colspan="5" style="text-align:center;color:var(--text3);padding:20px">No users registered yet</td></tr>';
}
async function loadAdminPays(){
  var r = await call('admin_stats'); if(!r||r.err) return;
  document.getElementById('allPayTbl').innerHTML = (r.recentPay||[]).length
    ? (r.recentPay||[]).map(function(p){
        return '<tr>'+
          '<td>'+xss(p.fullname)+'</td>'+
          '<td>'+xss(p.title)+'</td>'+
          '<td style="color:var(--gold);font-weight:700">₹'+parseFloat(p.amount).toLocaleString('en-IN')+'</td>'+
          '<td>'+xss((p.payment_method||'').replace(/_/g,' '))+'</td>'+
          '<td style="font-family:monospace;font-size:11px">'+xss(p.transaction_id)+'</td>'+
          '<td><span class="badge badge-teal">'+xss(p.payment_status)+'</span></td>'+
          '<td>'+fmtDate((p.created_at||'').slice(0,10))+'</td>'+
        '</tr>';
      }).join('')
    : '<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:20px">No payments yet</td></tr>';
}

// ── AUTH ──────────────────────────────────────────────────
function openAuth(tab){
  document.getElementById('authOverlay').classList.add('open');
  switchAuth(tab);
  clearAlert('authAlertBox');
  clearFieldErrors();
}
function closeAuth(){ document.getElementById('authOverlay').classList.remove('open'); }
function switchAuth(t){
  document.getElementById('tabLogin').classList.toggle('on', t==='login');
  document.getElementById('tabReg').classList.toggle('on', t==='register');
  document.getElementById('loginForm').classList.toggle('hidden', t!=='login');
  document.getElementById('regForm').classList.toggle('hidden', t!=='register');
  document.getElementById('authModalTitle').textContent = t==='login' ? 'Sign In to EventSync' : 'Create Your Account';
  clearAlert('authAlertBox');
  clearFieldErrors();
}
function clearFieldErrors(){
  document.querySelectorAll('.field-error').forEach(function(el){ el.classList.remove('show'); el.textContent=''; });
  document.querySelectorAll('.finput').forEach(function(el){ el.classList.remove('error'); });
}
function fieldErr(id, msg){
  var errEl = document.getElementById(id+'_err');
  var inpEl = document.getElementById(id);
  if(errEl){ errEl.textContent = msg; errEl.classList.add('show'); }
  if(inpEl){ inpEl.classList.add('error'); inpEl.focus(); }
}

async function doLogin(){
  clearFieldErrors(); clearAlert('authAlertBox');
  var email = document.getElementById('li_email').value.trim();
  var pass  = document.getElementById('li_pass').value;
  var ok = true;
  if(!email){ fieldErr('li_email','Email address is required.'); ok=false; }
  else if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ fieldErr('li_email','Please enter a valid email.'); ok=false; }
  if(!pass){ fieldErr('li_pass','Password is required.'); ok=false; }
  if(!ok) return;
  setBtnLoading('loginBtn','loginBtnTxt','loginLoader',true);
  var fd = new FormData();
  fd.append('email', email);
  fd.append('password', pass);
  var r = await call('login', fd);
  setBtnLoading('loginBtn','loginBtnTxt','loginLoader',false);
  if(!r){ showAlert('authAlertBox','err','Connection error. Please try again.'); return; }
  if(r.err){ showAlert('authAlertBox','err',r.err); return; }
  APP.loggedIn = true; APP.role = r.role; APP.name = r.name; APP.uid = r.uid;
  closeAuth();
  initApp();
  toast('Welcome back, '+r.name+'! 👋','ok');
}

async function doRegister(){
  clearFieldErrors(); clearAlert('authAlertBox');
  var name  = document.getElementById('rg_name').value.trim();
  var email = document.getElementById('rg_email').value.trim();
  var pass  = document.getElementById('rg_pass').value;
  var ok = true;
  if(!name){ fieldErr('rg_name','Full name is required.'); ok=false; }
  else if(name.length < 2){ fieldErr('rg_name','Name must be at least 2 characters.'); ok=false; }
  if(!email){ fieldErr('rg_email','Email address is required.'); ok=false; }
  else if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ fieldErr('rg_email','Please enter a valid email.'); ok=false; }
  if(!pass){ fieldErr('rg_pass','Password is required.'); ok=false; }
  else if(pass.length < 6){ fieldErr('rg_pass','Password must be at least 6 characters.'); ok=false; }
  if(!ok) return;
  setBtnLoading('regBtn','regBtnTxt','regLoader',true);
  var fd = new FormData();
  fd.append('fullname', name);
  fd.append('email', email);
  fd.append('password', pass);
  var r = await call('register', fd);
  setBtnLoading('regBtn','regBtnTxt','regLoader',false);
  if(!r){ showAlert('authAlertBox','err','Connection error. Please try again.'); return; }
  if(r.err){ showAlert('authAlertBox','err',r.err); return; }
  showAlert('authAlertBox','ok',r.ok);
  document.getElementById('rg_name').value = '';
  document.getElementById('rg_email').value = '';
  document.getElementById('rg_pass').value = '';
  setTimeout(function(){ switchAuth('login'); }, 1800);
}

async function doLogout(){
  await call('logout');
  APP.loggedIn = false; APP.role = ''; APP.name = '';
  clearTimeout(APP.pollTimer);
  document.getElementById('appPage').style.display = 'none';
  document.getElementById('pubPage').classList.remove('hidden');
  APP.dash = { regs:[], pays:[] };
  pollEvents();
  toast('Signed out successfully.','ok');
}

// ── EVENT REGISTRATION ────────────────────────────────────
async function regEvent(eid){
  var fd = new FormData(); fd.append('event_id', eid);
  var r = await call('reg_event', fd);
  if(!r){ toast('Connection error.','err'); return; }
  if(r.err){ toast(r.err,'err'); return; }
  APP.payEid = eid; APP.payAmt = parseFloat(r.price||0); APP.payName = r.title;
  if(APP.payAmt > 0){
    openPayModal();
  } else {
    toast('Registered successfully! 🎉','ok');
    await loadDash();
    renderAppEvs();
  }
}

// ── PAYMENT ───────────────────────────────────────────────
function openPayModal(){
  document.getElementById('payEvTitle').textContent = APP.payName;
  document.getElementById('payEvAmt').textContent = '₹'+APP.payAmt.toLocaleString('en-IN');
  clearAlert('payAlertBox');
  document.getElementById('payOverlay').classList.add('open');
}
function closePayModal(){
  document.getElementById('payOverlay').classList.remove('open');
  // Reset body in case success screen was shown
  setTimeout(function(){
    var pb = document.getElementById('payModalBody');
    if(pb && !pb.querySelector('.pay-summary')){
      location.reload(); // simplest way to reset after success
    }
  }, 200);
}
function selectPM(el){
  document.querySelectorAll('.pm').forEach(function(e){ e.classList.remove('on'); });
  el.classList.add('on');
  APP.payMethod = el.getAttribute('data-m');
  var isCard = APP.payMethod === 'credit_card' || APP.payMethod === 'debit_card';
  document.getElementById('payCardFields').classList.toggle('hidden', !isCard);
  document.getElementById('payUpiFields').classList.toggle('hidden', APP.payMethod !== 'upi');
}
function fmtCard(el){ el.value = el.value.replace(/\D/g,'').replace(/(\d{4})(?=\d)/g,'$1 ').slice(0,19); }
function fmtExp(el){ var v=el.value.replace(/\D/g,''); if(v.length>2) v=v.slice(0,2)+'/'+v.slice(2,4); el.value=v; }

async function doPayment(){
  clearAlert('payAlertBox');
  setBtnLoading('payBtn','payBtnTxt','payLoader',true);
  var fd = new FormData();
  fd.append('event_id', APP.payEid);
  fd.append('method', APP.payMethod);
  fd.append('amount', APP.payAmt);
  var r = await call('payment', fd);
  setBtnLoading('payBtn','payBtnTxt','payLoader',false);
  if(!r){ showAlert('payAlertBox','err','Connection error. Try again.'); return; }
  if(r.err){ showAlert('payAlertBox','err',r.err); return; }
  document.getElementById('payModalBody').innerHTML =
    '<div class="success-wrap">'+
      '<div class="success-ico">✅</div>'+
      '<h2 style="font-size:20px;margin-bottom:8px">Payment Successful!</h2>'+
      '<p style="color:var(--text2);font-size:13px">Your registration is confirmed.</p>'+
      '<div class="txn-code">'+xss(r.txn)+'</div>'+
      '<button class="btn btn-teal" style="margin-top:12px" onclick="closePayModal()">Done</button>'+
    '</div>';
  await loadDash();
  loadNotifs();
}

// ── ADMIN EVENTS ──────────────────────────────────────────
var editEid = null;
function openEvModal(eid){
  editEid = eid || null;
  clearAlert('evAlertBox');
  document.getElementById('evModalTitle').textContent = eid ? 'Edit Event' : 'Create Event';
  document.getElementById('em_eid').value = eid || '';
  if(eid){
    var e = APP.allEvents.find(function(x){ return x.event_id == eid; });
    if(e){
      document.getElementById('em_title').value = e.title||'';
      document.getElementById('em_desc').value  = e.description||'';
      document.getElementById('em_cat').value   = e.category||'Technology';
      document.getElementById('em_date').value  = e.date||'';
      document.getElementById('em_time').value  = (e.time||'').slice(0,5);
      document.getElementById('em_loc').value   = e.location||'';
      document.getElementById('em_price').value = e.price||'0';
      document.getElementById('em_cap').value   = e.capacity||'100';
      document.getElementById('em_image').value = e.image||'📅';
      document.querySelectorAll('.ep-opt').forEach(function(el){ el.classList.toggle('on', el.getAttribute('data-e')===e.image); });
    }
  } else {
    ['em_title','em_desc','em_loc'].forEach(function(id){ document.getElementById(id).value=''; });
    document.getElementById('em_date').value  = new Date().toISOString().slice(0,10);
    document.getElementById('em_time').value  = '09:00';
    document.getElementById('em_price').value = '0';
    document.getElementById('em_cap').value   = '100';
    document.getElementById('em_image').value = '📅';
    document.querySelectorAll('.ep-opt').forEach(function(el){ el.classList.remove('on'); });
  }
  document.getElementById('evOverlay').classList.add('open');
}
function closeEvModal(){ document.getElementById('evOverlay').classList.remove('open'); }
function pickEmoji(el){
  document.querySelectorAll('.ep-opt').forEach(function(e){ e.classList.remove('on'); });
  el.classList.add('on');
  document.getElementById('em_image').value = el.getAttribute('data-e');
}
async function saveEvent(){
  clearAlert('evAlertBox');
  var title = document.getElementById('em_title').value.trim();
  if(!title){ showAlert('evAlertBox','err','Event title is required.'); return; }
  setBtnLoading('saveBtn','saveBtnTxt','saveLoader',true);
  var fd = new FormData();
  var eid = document.getElementById('em_eid').value;
  if(eid) fd.append('event_id', eid);
  fd.append('title',       document.getElementById('em_title').value.trim());
  fd.append('description', document.getElementById('em_desc').value.trim());
  fd.append('category',    document.getElementById('em_cat').value);
  fd.append('date',        document.getElementById('em_date').value);
  fd.append('time',        document.getElementById('em_time').value);
  fd.append('location',    document.getElementById('em_loc').value.trim());
  fd.append('price',       document.getElementById('em_price').value||'0');
  fd.append('capacity',    document.getElementById('em_cap').value||'100');
  fd.append('image',       document.getElementById('em_image').value||'📅');
  var r = await call(eid ? 'update_event' : 'create_event', fd);
  setBtnLoading('saveBtn','saveBtnTxt','saveLoader',false);
  if(!r){ showAlert('evAlertBox','err','Connection error. Try again.'); return; }
  if(r.err){ showAlert('evAlertBox','err',r.err); return; }
  toast(r.ok,'ok');
  closeEvModal();
  // Refresh events list
  var ev = await call('poll',null,{q:'',cat:'',price:''});
  if(ev && !ev.err) APP.allEvents = ev.events || [];
  loadAdminEvs();
}
async function delEvent(eid, title){
  if(!confirm('Delete "'+title+'"? This cannot be undone.')){ return; }
  var fd = new FormData(); fd.append('event_id', eid);
  var r = await call('delete_event', fd);
  toast(r && r.ok ? r.ok : (r&&r.err?r.err:'Error'), r&&r.err?'err':'ok');
  if(r && r.ok){ loadAdminEvs(); var ev=await call('poll',null,{q:'',cat:'',price:''}); if(ev&&!ev.err) APP.allEvents=ev.events||[]; }
}
async function delUser(tid, name){
  if(!confirm('Remove user "'+name+'"? This will delete all their data.')){ return; }
  var fd = new FormData(); fd.append('target_id', tid);
  var r = await call('delete_user', fd);
  toast(r&&r.ok?r.ok:(r&&r.err?r.err:'Error'), r&&r.err?'err':'ok');
  if(r && r.ok) loadAdminUsers();
}

// ── NOTIFICATIONS ─────────────────────────────────────────
async function loadNotifs(){
  if(!APP.loggedIn) return;
  var r = await call('notifs');
  if(!r||r.err) return;
  var badge = document.getElementById('nBadge');
  if(r.unread > 0){ badge.textContent = r.unread; badge.style.display = 'flex'; }
  else { badge.style.display = 'none'; }
  var list = document.getElementById('nList');
  if(!r.notifs.length){ list.innerHTML='<div style="padding:16px;text-align:center;color:var(--text3);font-size:12px">All caught up!</div>'; return; }
  list.innerHTML = r.notifs.map(function(n){
    return '<div class="n-item '+(n.status==='unread'?'unread':'')+'">'+
      '<div><div>'+xss(n.message)+'</div><div class="n-time">'+timeAgo(n.created_at)+'</div></div>'+
    '</div>';
  }).join('');
}
async function markRead(){
  await call('mark_read');
  loadNotifs();
}
function toggleNotif(){
  APP.notifOpen = !APP.notifOpen;
  document.getElementById('notifDD').classList.toggle('open', APP.notifOpen);
  if(APP.notifOpen) loadNotifs();
}
document.addEventListener('click', function(e){
  var nb = document.getElementById('notifBtn'), dd = document.getElementById('notifDD');
  if(APP.notifOpen && nb && !nb.contains(e.target) && dd && !dd.contains(e.target)){
    APP.notifOpen = false; dd.classList.remove('open');
  }
});

// ── SIDEBAR ───────────────────────────────────────────────
function toggleSB(){
  var sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
  var open = !sb.classList.contains('open');
  sb.classList.toggle('open', open); ov.style.display = open ? 'block' : 'none';
}
function closeSB(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').style.display = 'none';
}

// ── UI HELPERS ────────────────────────────────────────────
function toggleEye(inputId, iconId){
  var inp = document.getElementById(inputId), ico = document.getElementById(iconId);
  if(inp.type==='password'){ inp.type='text'; if(ico) ico.className='fa fa-eye-slash'; }
  else { inp.type='password'; if(ico) ico.className='fa fa-eye'; }
}
function setBtnLoading(btnId, txtId, ldrId, loading){
  var btn = document.getElementById(btnId), txt = document.getElementById(txtId), ldr = document.getElementById(ldrId);
  if(!btn) return;
  btn.disabled = loading;
  if(txt) txt.style.display = loading ? 'none' : '';
  if(ldr) ldr.style.display = loading ? 'inline-block' : 'none';
}
function showAlert(id, type, msg){
  var el = document.getElementById(id); if(!el) return;
  el.className = 'alert '+(type==='err'?'alert-err':'alert-ok')+' show';
  el.innerHTML = '<i class="fa '+(type==='err'?'fa-exclamation-circle':'fa-check-circle')+'"></i>'+msg;
}
function clearAlert(id){ var el=document.getElementById(id); if(el){ el.className='alert'; el.innerHTML=''; } }
function toast(msg, type){
  var tb = document.getElementById('toastBox');
  var div = document.createElement('div');
  div.style.cssText='background:'+(type==='ok'?'#065F46':'#7F1D1D')+';color:#fff;padding:11px 18px;border-radius:9px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px;box-shadow:0 8px 20px rgba(0,0,0,.3);margin-top:8px;pointer-events:auto;animation:fadeUp .3s ease';
  div.innerHTML='<i class="fa '+(type==='ok'?'fa-check-circle':'fa-times-circle')+'"></i>'+xss(msg);
  tb.appendChild(div);
  setTimeout(function(){ if(div.parentNode) div.parentNode.removeChild(div); }, 4000);
}
function emptyState(msg){ return '<div class="empty"><div class="empty-icon">📭</div><p>'+(msg||'No events found')+'</p></div>'; }
function scrollTo(id){ var el=document.getElementById(id); if(el) el.scrollIntoView({behavior:'smooth'}); }

// ── DATA UTILITIES ────────────────────────────────────────
async function call(action, fd, params){
  var url = '?api='+action;
  if(params){ Object.keys(params).forEach(function(k){ url += '&'+k+'='+encodeURIComponent(params[k]); }); }
  try{
    var opts = fd ? { method:'POST', body:fd } : {};
    var res = await fetch(url, opts);
    if(!res.ok) return { err:'Server error ('+res.status+')' };
    return await res.json();
  } catch(e){ return { err:'Network error. Check your connection.' }; }
}
function xss(s){ var d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function fmtDate(d){
  if(!d||d==='—') return '—';
  try{ return new Date(d+'T00:00:00').toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'}); }
  catch(e){ return d; }
}
function fmtTime(t){
  if(!t) return '—';
  var parts = t.split(':'), h=parseInt(parts[0]), m=parts[1]||'00';
  return (h%12||12)+':'+m+' '+(h>=12?'PM':'AM');
}
function timeAgo(dt){
  try{
    var diff = Math.floor((new Date()-new Date(dt))/1000);
    if(diff<60) return 'just now';
    if(diff<3600) return Math.floor(diff/60)+'m ago';
    if(diff<86400) return Math.floor(diff/3600)+'h ago';
    return Math.floor(diff/86400)+'d ago';
  }catch(e){ return ''; }
}
</script>
</body>
</html>
