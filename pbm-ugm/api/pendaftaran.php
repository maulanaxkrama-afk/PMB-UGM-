<?php
// api/pendaftaran.php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$user = getAuthUser();

// GET - ambil data pendaftaran user
if ($method === 'GET' && $action === 'get') {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM pendaftaran WHERE user_id = ?');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    response(true, 'OK', $data);
}

// POST/PUT - simpan/update data pendaftaran
if (($method === 'POST' || $method === 'PUT') && $action === 'save') {
    $db = getDB();
    
    // Cek apakah sudah submitted/diverifikasi
    $chk = $db->prepare('SELECT status FROM pendaftaran WHERE user_id = ?');
    $chk->bind_param('i', $user['id']);
    $chk->execute();
    $current = $chk->get_result()->fetch_assoc();
    
    if ($current && in_array($current['status'], ['verifikasi', 'diterima', 'ditolak'])) {
        response(false, 'Data tidak dapat diubah, sudah dalam proses verifikasi', null, 403);
    }

    $d = json_decode(file_get_contents('php://input'), true);

    $fields = [
        'nik', 'nama_lengkap', 'tempat_lahir', 'tanggal_lahir', 'jenis_kelamin',
        'agama', 'kewarganegaraan', 'no_hp', 'alamat', 'kelurahan', 'kecamatan',
        'kabupaten', 'provinsi', 'kode_pos', 'asal_sekolah', 'jurusan_sekolah',
        'tahun_lulus', 'nisn', 'nilai_rata_rata', 'program_studi_1', 'fakultas_1',
        'program_studi_2', 'fakultas_2', 'jalur_masuk', 'nama_ayah', 'pekerjaan_ayah',
        'penghasilan_ayah', 'nama_ibu', 'pekerjaan_ibu', 'penghasilan_ibu'
    ];

    $sets = [];
    $types = '';
    $values = [];

    foreach ($fields as $f) {
        if (isset($d[$f])) {
            $sets[] = "$f = ?";
            $types .= 's';
            $values[] = $d[$f];
        }
    }

    if (empty($sets)) response(false, 'Tidak ada data yang dikirim', null, 400);

    $types .= 'i';
    $values[] = $user['id'];
    $sql = 'UPDATE pendaftaran SET ' . implode(', ', $sets) . ' WHERE user_id = ?';
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        response(true, 'Data berhasil disimpan');
    } else {
        response(false, 'Gagal menyimpan data', null, 500);
    }
}

// POST - submit pendaftaran
if ($method === 'POST' && $action === 'submit') {
    $db = getDB();
    $chk = $db->prepare('SELECT * FROM pendaftaran WHERE user_id = ?');
    $chk->bind_param('i', $user['id']);
    $chk->execute();
    $pd = $chk->get_result()->fetch_assoc();

    if (!$pd) response(false, 'Data pendaftaran tidak ditemukan', null, 404);
    if ($pd['status'] !== 'draft') response(false, 'Pendaftaran sudah disubmit sebelumnya', null, 400);

    // Validasi kelengkapan data minimal
    $required = ['nik','nama_lengkap','tanggal_lahir','jenis_kelamin','no_hp','asal_sekolah','program_studi_1'];
    foreach ($required as $r) {
        if (empty($pd[$r])) response(false, "Field '$r' belum diisi. Lengkapi data terlebih dahulu.", null, 400);
    }

    $noReg = generateNoPendaftaran();
    $stmt = $db->prepare('UPDATE pendaftaran SET status = "submitted", no_pendaftaran = ?, submitted_at = NOW() WHERE user_id = ?');
    $stmt->bind_param('si', $noReg, $user['id']);

    if ($stmt->execute()) {
        // Kirim notifikasi
        $msg = "Pendaftaran Anda dengan nomor $noReg telah berhasil disubmit dan sedang menunggu verifikasi.";
        $notif = $db->prepare('INSERT INTO notifikasi (user_id, judul, pesan) VALUES (?, ?, ?)');
        $judul = 'Pendaftaran Berhasil Disubmit';
        $notif->bind_param('iss', $user['id'], $judul, $msg);
        $notif->execute();
        
        response(true, 'Pendaftaran berhasil disubmit!', ['no_pendaftaran' => $noReg]);
    } else {
        response(false, 'Gagal submit pendaftaran', null, 500);
    }
}

// POST - upload file
if ($method === 'POST' && $action === 'upload') {
    $type = $_POST['type'] ?? '';
    $allowed = ['foto','ijazah','rapor','ktp','kartu_keluarga'];
    
    if (!in_array($type, $allowed)) response(false, 'Tipe file tidak valid', null, 400);
    
    $db = getDB();
    $chk = $db->prepare('SELECT status FROM pendaftaran WHERE user_id = ?');
    $chk->bind_param('i', $user['id']);
    $chk->execute();
    $pd = $chk->get_result()->fetch_assoc();
    
    if ($pd && in_array($pd['status'], ['verifikasi','diterima','ditolak'])) {
        response(false, 'File tidak dapat diubah, sudah dalam proses verifikasi', null, 403);
    }

    if (!isset($_FILES['file'])) response(false, 'File tidak ditemukan', null, 400);
    
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg','jpeg','png','pdf'];
    
    if (!in_array($ext, $allowed_ext)) response(false, 'Format file harus JPG, PNG, atau PDF', null, 400);
    if ($file['size'] > 5 * 1024 * 1024) response(false, 'Ukuran file maksimal 5MB', null, 400);

    $dir = UPLOAD_DIR . $user['id'] . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $filename = $type . '_' . time() . '.' . $ext;
    $path = $dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $path)) {
        $relPath = $user['id'] . '/' . $filename;
        $stmt = $db->prepare("UPDATE pendaftaran SET $type = ? WHERE user_id = ?");
        $stmt->bind_param('si', $relPath, $user['id']);
        $stmt->execute();
        response(true, 'File berhasil diupload', ['path' => $relPath]);
    } else {
        response(false, 'Gagal mengupload file', null, 500);
    }
}

// GET notifikasi
if ($method === 'GET' && $action === 'notifikasi') {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM notifikasi WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $notifs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Mark as read
    $db->query("UPDATE notifikasi SET dibaca = 1 WHERE user_id = {$user['id']}");
    
    response(true, 'OK', $notifs);
}

response(false, 'Endpoint tidak ditemukan', null, 404);
