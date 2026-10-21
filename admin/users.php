<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

$currentUid = (int)current_user()["id_user"];

$editId = get_int("edit");
$edit = [
    "id_user" => 0,
    "nama_lengkap" => "",
    "username" => "",
    "role" => "petugas",
    "status_aktif" => 1,
];
if ($editId) {
    $s = $db->prepare("SELECT id_user, nama_lengkap, username, role, status_aktif FROM tb_user WHERE id_user=?");
    $s->bind_param("i", $editId);
    $s->execute();
    $edit = $s->get_result()->fetch_assoc() ?: $edit;
    $s->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $action = post("action");
    $id = (int)post("id_user");
    $nama = post("nama_lengkap");
    $username = strtolower(trim(post("username")));
    $role = post("role");
    $status = (int)post("status_aktif", "1");
    $password = post("password");

    if ($action === "delete") {
        if ($id === $currentUid) {
            flash("warning", "Akun yang sedang Anda gunakan tidak dapat dihapus.");
        } else {
            try {
                $sCheck = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_user=? LIMIT 1");
                $sCheck->bind_param("i", $id);
                $sCheck->execute();
                if ($sCheck->get_result()->fetch_assoc()) {
                    throw new RuntimeException("Pengguna ini telah memiliki riwayat pencatatan transaksi parkir.");
                }

                $s = $db->prepare("DELETE FROM tb_user WHERE id_user=?");
                $s->bind_param("i", $id);
                $s->execute();
                log_activity($db, $currentUid, "Hapus user ID " . $id);
                flash("success", "Pengguna berhasil dihapus.");
            } catch (Throwable $e) {
                flash("danger", "Gagal menghapus pengguna: " . $e->getMessage());
            }
        }
    } else {
        if ($nama === "" || $username === "" || !in_array($role, ["admin", "petugas", "owner"], true)) {
            flash("danger", "Lengkapi seluruh formulir pengguna dengan benar.");
        } elseif ($id > 0) {
            // Update existing user
            try {
                if ($password !== "") {
                    if (strlen($password) < 6) {
                        throw new RuntimeException("Password baru minimal 6 karakter.");
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $s = $db->prepare("UPDATE tb_user SET nama_lengkap=?, username=?, role=?, status_aktif=?, password=? WHERE id_user=?");
                    $s->bind_param("sssisi", $nama, $username, $role, $status, $hash, $id);
                } else {
                    $s = $db->prepare("UPDATE tb_user SET nama_lengkap=?, username=?, role=?, status_aktif=? WHERE id_user=?");
                    $s->bind_param("sssii", $nama, $username, $role, $status, $id);
                }
                $s->execute();
                log_activity($db, $currentUid, "Ubah data pengguna " . $username);
                flash("success", "Data pengguna {$username} berhasil diperbarui.");
            } catch (Throwable $e) {
                flash("danger", "Gagal memperbarui pengguna: " . $e->getMessage());
            }
        } else {
            // Create new user
            if (strlen($password) < 6) {
                flash("danger", "Password untuk pengguna baru minimal 6 karakter.");
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $s = $db->prepare("INSERT INTO tb_user (nama_lengkap, username, password, role, status_aktif) VALUES (?, ?, ?, ?, ?)");
                    $s->bind_param("ssssi", $nama, $username, $hash, $role, $status);
                    $s->execute();
                    log_activity($db, $currentUid, "Tambah pengguna baru " . $username . " ({$role})");
                    flash("success", "Pengguna baru {$username} berhasil ditambahkan.");
                } catch (Throwable $e) {
                    flash("danger", "Gagal menyimpan: username sudah digunakan oleh akun lain.");
                }
            }
        }
    }
    redirect("admin/users.php");
}

$res = $db->query("SELECT id_user, nama_lengkap, username, role, status_aktif FROM tb_user ORDER BY id_user ASC");

page_header("Manajemen Pengguna");
?>

<div class="row g-4">
    <!-- Form Card -->
    <div class="col-lg-4">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3"><?= $edit["id_user"] ? "Edit Pengguna" : "Tambah Pengguna Baru" ?></h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id_user" value="<?= e($edit["id_user"]) ?>">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Nama Lengkap *</label>
                    <input class="form-control" name="nama_lengkap" required maxlength="50" placeholder="Nama lengkap" value="<?= e($edit["nama_lengkap"]) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Username *</label>
                    <input class="form-control" name="username" required maxlength="50" placeholder="Username untuk login" value="<?= e($edit["username"]) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Hak Akses (Role) *</label>
                    <select class="form-select" name="role">
                        <?php foreach (["petugas" => "Petugas (Operasional)", "admin" => "Admin (Kelola Sistem)", "owner" => "Owner (Eksekutif/Laporan)"] as $rVal => $rLabel): ?>
                            <option value="<?= $rVal ?>" <?= $edit["role"] === $rVal ? "selected" : "" ?>>
                                <?= $rLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">
                        Password <?= $edit["id_user"] ? "(Kosongkan jika tidak diubah)" : "*" ?>
                    </label>
                    <input class="form-control" type="password" name="password" <?= $edit["id_user"] ? "" : "required" ?> placeholder="Minimal 6 karakter" minlength="6">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Status Akun</label>
                    <select class="form-select" name="status_aktif">
                        <option value="1" <?= (int)$edit["status_aktif"] === 1 ? "selected" : "" ?>>Aktif (Dapat Login)</option>
                        <option value="0" <?= (int)$edit["status_aktif"] === 0 ? "selected" : "" ?>>Nonaktif (Diblokir)</option>
                    </select>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-save me-1"></i> Simpan Pengguna</button>
                    <?php if ($edit["id_user"]): ?>
                        <a href="<?= e(url("admin/users.php")) ?>" class="btn btn-secondary">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-lg-8">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3">Daftar Akun Pengguna</h5>
            <div class="table-responsive">
                <table class="table pk-table">
                    <thead>
                        <tr>
                            <th>Nama Lengkap</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = $res->fetch_assoc()): ?>
                            <?php $isSelf = (int)$r["id_user"] === $currentUid; ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= e($r["nama_lengkap"]) ?>
                                    <?php if ($isSelf): ?>
                                        <span class="badge bg-light text-primary border ms-1">Anda</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace"><?= e($r["username"]) ?></td>
                                <td>
                                    <span class="badge <?= match($r["role"]) { "admin" => "bg-danger", "petugas" => "bg-primary", default => "bg-warning text-dark" } ?>">
                                        <?= ucfirst($r["role"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-pk <?= (int)$r["status_aktif"] === 1 ? 'badge-pk-success' : 'badge-pk-danger' ?>">
                                        <?= (int)$r["status_aktif"] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= $r["id_user"] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!$isSelf): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengguna <?= e($r['username']) ?>?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id_user" value="<?= $r["id_user"] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Hapus">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php page_footer(); ?>
