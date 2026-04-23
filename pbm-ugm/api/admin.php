<?php
// api/admin.php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$admin = requireAdmin();

// GET - dashboard stats
if ($method === 'GET' && $action === 'stats') {
    $db = getDB();
    $stats = [];

    $statuses = ['draft','submitted','verifikasi','diterima','ditolak'];
    foreach ($statuses as $s) {
        $r = $db->query("SELECT COUNT(*) as c FROM pendaftaran WHERE status = '$s'");
        $stats[$s] = $r->fetch_assoc()['c'];
    }
    $total = $db->query("SELECT COUNT(*) as c FROM users WHERE role = 'user'")->fetch_assoc()['c'];
    $stats['total_user'] = $total;

    // Pendaftar terbaru
    $recent = $db->query("
        SELECT p.*, u.email FROM pendaftaran p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.status != 'draft'
        ORDER BY p.submitted_at DESC LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);

    response(true, 'OK', ['stats' => $stats, 'recent' => $recent]);
}

// GET - daftar semua pendaftaran
if ($method === 'GET' && $action === 'list') {
    $db = getDB();
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $where = ['p.status != "draft"'];
    $params = [];
    $types = '';

    if ($status) {
        $where[] = 'p.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search) {
        $where[] = '(p.nama_lengkap LIKE ? OR p.no_pendaftaran LIKE ? OR u.email LIKE ?)';
        $types .= 'sss';
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $whereStr = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) as c FROM pendaftaran p JOIN users u ON p.user_id = u.id WHERE $whereStr";
    $sql = "SELECT p.id, p.no_pendaftaran, p.nama_lengkap, p.program_studi_1, p.jalur_masuk, p.status, p.submitted_at, u.email 
            FROM pendaftaran p JOIN users u ON p.user_id = u.id 
            WHERE $whereStr ORDER BY p.submitted_at DESC LIMIT $limit OFFSET $offset";

    if ($params) {
        $cStmt = $db->prepare($countSql);
        $cStmt->bind_param($types, ...$params);
        $cStmt->execute();
        $total = $cStmt->get_result()->fetch_assoc()['c'];

        $dStmt = $db->prepare($sql);
        $dStmt->bind_param($types, ...$params);
        $dStmt->execute();
        $data = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $total = $db->query($countSql)->fetch_assoc()['c'];
        $data = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    response(true, 'OK', [
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// GET - detail pendaftaran
if ($method === 'GET' && $action === 'detail') {
    $id = intval($_GET['id'] ?? 0);
    $db = getDB();
    $stmt = $db->prepare('SELECT p.*, u.email FROM pendaftaran p JOIN users u ON p.user_id = u.id WHERE p.id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    if (!$data) response(false, 'Data tidak ditemukan', null, 404);
    response(true, 'OK', $data);
}

// POST - update status
if ($method === 'POST' && $action === 'update-status') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = intval($d['id'] ?? 0);
    $status = $d['status'] ?? '';
    $catatan = $d['catatan'] ?? '';

    $allowed = ['verifikasi','diterima','ditolak','submitted'];
    if (!in_array($status, $allowed)) response(false, 'Status tidak valid', null, 400);

    $db = getDB();
    
    // Ambil user_id dan nomor pendaftaran
    $chk = $db->prepare('SELECT user_id, no_pendaftaran, nama_lengkap FROM pendaftaran WHERE id = ?');
    $chk->bind_param('i', $id);
    $chk->execute();
    $pd = $chk->get_result()->fetch_assoc();
    if (!$pd) response(false, 'Data tidak ditemukan', null, 404);

    $verifiedAt = $status === 'diterima' ? 'verified_at = NOW(),' : '';
    $stmt = $db->prepare("UPDATE pendaftaran SET status = ?, catatan_admin = ?, $verifiedAt updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $status, $catatan, $id);

    if ($stmt->execute()) {
        // Kirim notifikasi ke user
        $statusMap = [
            'verifikasi' => 'sedang diverifikasi oleh tim kami',
            'diterima' => 'DITERIMA. Selamat! Anda diterima di UGM',
            'ditolak' => 'tidak dapat kami terima pada tahap ini',
            'submitted' => 'dikembalikan untuk perbaikan'
        ];
        $pesanStatus = $statusMap[$status];
        $judul = 'Update Status Pendaftaran';
        $pesan = "Pendaftaran Anda ({$pd['no_pendaftaran']}) $pesanStatus." . ($catatan ? " Catatan: $catatan" : '');
        
        $notif = $db->prepare('INSERT INTO notifikasi (user_id, judul, pesan) VALUES (?, ?, ?)');
        $notif->bind_param('iss', $pd['user_id'], $judul, $pesan);
        $notif->execute();

        response(true, 'Status berhasil diperbarui');
    } else {
        response(false, 'Gagal memperbarui status', null, 500);
    }
}

// DELETE - hapus pendaftaran
if ($method === 'DELETE' && $action === 'delete') {
    $id = intval($_GET['id'] ?? 0);
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM pendaftaran WHERE id = ?');
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        response(true, 'Data berhasil dihapus');
    } else {
        response(false, 'Gagal menghapus data', null, 500);
    }
}

response(false, 'Endpoint tidak ditemukan', null, 404);
