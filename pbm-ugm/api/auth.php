<?php
// api/auth.php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$email || !$password) response(false, 'Email dan password wajib diisi', null, 400);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, $user['password'])) {
        response(false, 'Email atau password salah', null, 401);
    }

    $token = generateToken([
        'id' => $user['id'],
        'email' => $user['email'],
        'nama' => $user['nama_lengkap'],
        'role' => $user['role'],
        'exp' => time() + 86400 // 24 jam
    ]);

    response(true, 'Login berhasil', [
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'nama' => $user['nama_lengkap'],
            'role' => $user['role']
        ]
    ]);
}

if ($method === 'POST' && $action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $nama = trim($data['nama_lengkap'] ?? '');

    if (!$email || !$password || !$nama) response(false, 'Semua field wajib diisi', null, 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) response(false, 'Format email tidak valid', null, 400);
    if (strlen($password) < 8) response(false, 'Password minimal 8 karakter', null, 400);

    $db = getDB();
    $check = $db->prepare('SELECT id FROM users WHERE email = ?');
    $check->bind_param('s', $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) response(false, 'Email sudah terdaftar', null, 409);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (email, password, nama_lengkap) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $email, $hash, $nama);

    if ($stmt->execute()) {
        $userId = $db->insert_id;
        // Buat entry pendaftaran kosong
        $init = $db->prepare('INSERT INTO pendaftaran (user_id, nama_lengkap) VALUES (?, ?)');
        $init->bind_param('is', $userId, $nama);
        $init->execute();
        
        response(true, 'Registrasi berhasil! Silakan login.');
    } else {
        response(false, 'Registrasi gagal', null, 500);
    }
}

response(false, 'Endpoint tidak ditemukan', null, 404);
