<?php
require_once __DIR__ . "/includes/bootstrap.php";

if (current_user()) {
    redirect(role_home());
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $username = post("username");
    $password = post("password");
    
    $stmt = $db->prepare(
        "SELECT id_user, nama_lengkap, username, password, role, status_aktif FROM tb_user WHERE username=? LIMIT 1"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $valid = false;
    if ($user && (int)$user["status_aktif"] === 1) {
        $stored = (string)$user["password"];
        // Check legacy SHA-256 hash or upgrade to password_hash
        if (strlen($stored) === 64 && hash_equals($stored, hash("sha256", $password))) {
            $valid = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE tb_user SET password=? WHERE id_user=?");
            $up->bind_param("si", $newHash, $user["id_user"]);
            $up->execute();
            $up->close();
        } else {
            $valid = password_verify($password, $stored);
        }
    }
    
    if ($valid) {
        session_regenerate_id(true);
        $_SESSION["user"] = [
            "id_user" => (int)$user["id_user"],
            "nama_lengkap" => $user["nama_lengkap"],
            "username" => $user["username"],
            "role" => $user["role"],
        ];
        log_activity($db, (int)$user["id_user"], "Login aplikasi via web");
        redirect(role_home());
    }
    
    $error = "Username atau password salah, atau akun Anda dinonaktifkan.";
}

page_header("Login Sistem");
?>
<div class="card border-0 shadow-lg" style="max-width: 440px; width: 100%; border-radius: 18px; overflow: hidden; background: #ffffff;">
    <div class="p-4 text-center text-white" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);">
        <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow" style="width: 58px; height: 58px; font-size: 1.75rem;">
            <i class="bi bi-p-square-fill"></i>
        </div>
        <h3 class="fw-bold mb-1">ParkirKu</h3>
        <p class="text-white-50 small mb-0">Sistem Informasi Manajemen Parkir Modern</p>
    </div>

    <div class="p-4">
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 small mb-3">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="post" id="loginForm">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label small fw-bold text-secondary">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary border-end-0"><i class="bi bi-person"></i></span>
                    <input class="form-control border-start-0 ps-0" id="inputUsername" name="username" required autofocus maxlength="50" placeholder="Masukkan username">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label small fw-bold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary border-end-0"><i class="bi bi-lock"></i></span>
                    <input class="form-control border-start-0 ps-0" id="inputPassword" type="password" name="password" required placeholder="Masukkan password">
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="bi bi-eye"></i></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm mt-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Masuk ke Sistem
            </button>
        </form>

        <div class="mt-4 pt-3 border-top text-center">
            <span class="small text-secondary fw-semibold d-block mb-2">Akun Demo Cepat:</span>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="fillDemo('admin1','admin123')">
                    <i class="bi bi-shield-lock"></i> Admin
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="fillDemo('petugas1','petugas123')">
                    <i class="bi bi-person-badge"></i> Petugas
                </button>
                <button type="button" class="btn btn-sm btn-outline-warning text-dark" onclick="fillDemo('owner1','owner123')">
                    <i class="bi bi-briefcase"></i> Owner
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function fillDemo(user, pass) {
    document.getElementById('inputUsername').value = user;
    document.getElementById('inputPassword').value = pass;
    document.getElementById('loginForm').submit();
}

document.getElementById('togglePassword')?.addEventListener('click', function() {
    const input = document.getElementById('inputPassword');
    const icon = this.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
});
</script>
<?php page_footer(); ?>
