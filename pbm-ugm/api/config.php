<?php
// api/config.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ugm_pmb');
define('UPLOAD_DIR', '../uploads/');
define('BASE_URL', 'http://localhost/ugm-pmb');
define('JWT_SECRET', 'ugm_pmb_secret_key_2024_secure');

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database Connection
function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Koneksi database gagal: ' . $conn->connect_error]);
        exit();
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// JWT Functions
function generateToken($payload) {
    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode($payload));
    $signature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $expectedSig = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if ($sig !== $expectedSig) return false;
    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return false;
    return $data;
}

function getAuthUser() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    if (!$auth || !str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    $token = substr($auth, 7);
    $user = verifyToken($token);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token tidak valid atau kadaluarsa']);
        exit();
    }
    return $user;
}

function requireAdmin() {
    $user = getAuthUser();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit();
    }
    return $user;
}

function response($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    $res = ['success' => $success, 'message' => $message];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res);
    exit();
}

function generateNoPendaftaran() {
    return 'UGM-' . date('Y') . '-' . strtoupper(substr(uniqid(), -8));
}
