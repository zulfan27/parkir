<?php

declare(strict_types=1);

/**
 * Escapes string for HTML output
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * Builds absolute URL based on config base_url
 */
function url(string $path = ""): string
{
    global $config;
    $base = rtrim($config["base_url"] ?? "/aplikasi-parkir", "/");
    return $base . "/" . ltrim($path, "/");
}

/**
 * Redirects to URL and terminates execution
 */
function redirect(string $path): never
{
    header("Location: " . url($path));
    exit();
}

/**
 * Returns current logged-in user array or null
 */
function current_user(): ?array
{
    return $_SESSION["user"] ?? null;
}

/**
 * Requires an authenticated user
 */
function require_login(): void
{
    if (!current_user()) {
        redirect("login.php");
    }
}

/**
 * Requires user to have one of the permitted roles
 */
function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_user()["role"], $roles, true)) {
        http_response_code(403);
        exit("403 - Anda tidak memiliki hak akses untuk halaman ini.");
    }
}

/**
 * Returns home path according to role
 */
function role_home(): string
{
    $role = current_user()["role"] ?? "";
    return match ($role) {
        "admin" => "admin/index.php",
        "petugas" => "petugas/index.php",
        "owner" => "owner/index.php",
        default => "login.php",
    };
}

/**
 * Generates or retrieves CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}

/**
 * Renders hidden CSRF token input
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verifies POST CSRF token
 */
function verify_csrf(): void
{
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $token = $_POST["csrf_token"] ?? "";
        if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
            http_response_code(419);
            exit("Token formulir tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.");
        }
    }
}

/**
 * Sets a flash message
 */
function flash(string $type, string $message): void
{
    $_SESSION["flash"] = ["type" => $type, "message" => $message];
}

/**
 * Displays flash message if present
 */
function show_flash(): void
{
    if (!empty($_SESSION["flash"])) {
        $f = $_SESSION["flash"];
        unset($_SESSION["flash"]);
        $icon = match ($f["type"]) {
            "success" => "bi-check-circle-fill",
            "danger" => "bi-exclamation-octagon-fill",
            "warning" => "bi-exclamation-triangle-fill",
            default => "bi-info-circle-fill",
        };
        echo '<div class="alert alert-' . e($f["type"]) . ' alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">';
        echo '<i class="bi ' . $icon . ' fs-5"></i>';
        echo '<div>' . e($f["message"]) . '</div>';
        echo '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

/**
 * Logs user activity
 */
function log_activity(mysqli $db, int $userId, string $activity): void
{
    $stmt = $db->prepare("INSERT INTO tb_log_aktivitas (id_user, aktivitas, waktu_aktivitas) VALUES (?, ?, NOW())");
    $stmt->bind_param("is", $userId, $activity);
    $stmt->execute();
    $stmt->close();
}

/**
 * Gets sanitized POST input
 */
function post(string $key, string $default = ""): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

/**
 * Gets validated integer from GET
 */
function get_int(string $key): int
{
    return filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?: 0;
}

/**
 * Standard pagination limit
 */
function pagination_limit(): int
{
    return 30;
}

/**
 * Validates Y-m-d date format
 */
function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat("Y-m-d", $date);
    return $d && $d->format("Y-m-d") === $date;
}

/**
 * Formats number to Rupiah string
 */
function rupiah(mixed $amount): string
{
    if ($amount === null || $amount === "") {
        return "-";
    }
    return "Rp " . number_format((float) $amount, 0, ",", ".");
}

/**
 * Formats datetime to localized string
 */
function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return "-";
    }
    $t = strtotime($datetime);
    if (!$t) {
        return $datetime;
    }
    return date("d/m/Y H:i", $t);
}

/**
 * Calculates parking duration in hours and total cost
 */
function hitung_biaya(string $waktuMasuk, float $tarifPerJam): array
{
    $masuk = strtotime($waktuMasuk);
    $sekarang = time();
    $diffSec = max(0, $sekarang - $masuk);
    $durasiJam = max(1, (int) ceil($diffSec / 3600));
    $biaya = $durasiJam * $tarifPerJam;
    
    $jam = floor($diffSec / 3600);
    $menit = floor(($diffSec % 3600) / 60);
    $teksDurasi = ($jam > 0 ? "{$jam} jam " : "") . "{$menit} menit";
    if ($diffSec < 60) {
        $teksDurasi = "Kurang dari 1 menit";
    }

    return [
        'durasi_jam' => $durasiJam,
        'biaya' => $biaya,
        'teks_durasi' => $teksDurasi,
        'detik' => $diffSec
    ];
}

/**
 * Resolves vehicle color name to hex color code for UI badges
 */
function warna_hex(?string $warna): string
{
    $w = strtolower(trim((string)$warna));
    return match ($w) {
        'hitam' => '#1e293b',
        'putih' => '#e2e8f0',
        'merah' => '#ef4444',
        'biru' => '#2563eb',
        'silver' => '#94a3b8',
        'abu-abu', 'abu' => '#64748b',
        'kuning' => '#eab308',
        'hijau' => '#10b981',
        'cokelat', 'coklat' => '#78350f',
        'oranye', 'orange' => '#f97316',
        default => '#64748b',
    };
}

/**
 * Helper to render vehicle illustration SVG
 */
function svg_vehicle(string $type, string $status = 'tersedia'): string
{
    $type = strtolower($type);
    if ($status === 'tersedia') {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>';
    }

    if ($type === 'motor') {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="16" r="3"/><circle cx="19" cy="16" r="3"/><path d="M12 17h4l3-5h-4l-3 4-2-6h-4l-2 3"/></svg>';
    }

    // Default Mobil
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>';
}

/**
 * Page Header with Modern Responsive Sidebar Layout
 */
function page_header(string $title, string $activePage = ""): void
{
    $u = current_user();
    $currentUri = $_SERVER["REQUEST_URI"] ?? "";
    
    echo '<!doctype html>';
    echo '<html lang="id">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - ParkirKu</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link href="' . e(url("assets/vendor/bootstrap/bootstrap.min.css")) . '" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">';
    $cssVersion = file_exists(__DIR__ . "/../assets/css/style.css") ? filemtime(__DIR__ . "/../assets/css/style.css") : time();
    echo '<link rel="stylesheet" href="' . e(url("assets/css/style.css?v=" . $cssVersion)) . '">';
    echo '<script src="' . e(url("assets/vendor/chartjs/chart.umd.min.js")) . '"></script>';
    echo '</head>';
    echo '<body>';

    if (!$u) {
        // Simple public layout for login
        echo '<div class="min-vh-100 d-flex align-items-center justify-content-center p-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #1e3a8a 100%);">';
        return;
    }

    // Authenticated Layout
    echo '<div class="app-wrapper">';
    echo '<div class="sidebar-overlay"></div>';

    // Sidebar
    echo '<aside class="app-sidebar">';
    echo '  <div class="sidebar-header">';
    echo '    <a href="' . e(url(role_home())) . '" class="brand-logo">';
    echo '      <div class="brand-icon"><i class="bi bi-p-square-fill"></i></div>';
    echo '      <div>';
    echo '        <div class="brand-title">ParkirKu</div>';
    echo '        <div class="brand-sub">Sistem Manajemen Parkir</div>';
    echo '      </div>';
    echo '    </a>';
    echo '  </div>';

    echo '  <div class="sidebar-menu">';
    
    // Group navigation links based on role
    if ($u["role"] === "admin") {
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "admin/index.php") ? "active" : "") . '" href="' . e(url("admin/index.php")) . '"><i class="bi bi-house-door"></i> Dashboard</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "denah.php") ? "active" : "") . '" href="' . e(url("denah.php")) . '"><i class="bi bi-map"></i> Denah Parkir</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "kendaraan.php") ? "active" : "") . '" href="' . e(url("admin/kendaraan.php")) . '"><i class="bi bi-car-front"></i> Kendaraan</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "tarif.php") ? "active" : "") . '" href="' . e(url("admin/tarif.php")) . '"><i class="bi bi-cash-coin"></i> Data Tarif</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "slot.php") || str_contains($currentUri, "area.php") ? "active" : "") . '" href="' . e(url("admin/slot.php")) . '"><i class="bi bi-bounding-box"></i> Area & Slot</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "laporan.php") ? "active" : "") . '" href="' . e(url("admin/laporan.php")) . '"><i class="bi bi-file-earmark-bar-graph"></i> Laporan</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "users.php") ? "active" : "") . '" href="' . e(url("admin/users.php")) . '"><i class="bi bi-people"></i> Pengguna</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "log.php") ? "active" : "") . '" href="' . e(url("admin/log.php")) . '"><i class="bi bi-clock-history"></i> Log Aktivitas</a>';
    } elseif ($u["role"] === "petugas") {
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "petugas/index.php") ? "active" : "") . '" href="' . e(url("petugas/index.php")) . '"><i class="bi bi-house-door"></i> Dashboard</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "denah.php") ? "active" : "") . '" href="' . e(url("denah.php")) . '"><i class="bi bi-map"></i> Denah Parkir</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "transaksi.php") ? "active" : "") . '" href="' . e(url("petugas/transaksi.php")) . '"><i class="bi bi-box-arrow-in-right"></i> Transaksi Masuk</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "kendaraan.php") ? "active" : "") . '" href="' . e(url("admin/kendaraan.php")) . '"><i class="bi bi-car-front"></i> Kendaraan</a>';
    } elseif ($u["role"] === "owner") {
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "owner/index.php") ? "active" : "") . '" href="' . e(url("owner/index.php")) . '"><i class="bi bi-house-door"></i> Dashboard</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "denah.php") ? "active" : "") . '" href="' . e(url("denah.php")) . '"><i class="bi bi-map"></i> Denah Parkir</a>';
        echo '    <a class="nav-link-custom ' . (str_contains($currentUri, "owner/rekap.php") ? "active" : "") . '" href="' . e(url("owner/rekap.php")) . '"><i class="bi bi-file-earmark-bar-graph"></i> Laporan</a>';
    }

    echo '  </div>';

    // Sidebar Footer with Logout link at bottom exactly like image
    echo '  <div class="sidebar-footer">';
    echo '    <a href="' . e(url("logout.php")) . '" class="nav-link-custom text-white-50 text-hover-white p-2">';
    echo '      <i class="bi bi-box-arrow-left"></i> Logout';
    echo '    </a>';
    echo '  </div>';
    echo '</aside>';

    // App Main Area
    echo '<div class="app-main">';
    
    // Top Navbar
    echo '  <header class="top-navbar d-flex align-items-center justify-content-between px-3 px-md-4 bg-white border-bottom shadow-sm" style="height: 68px; position: sticky; top: 0; z-index: 1020;">';
    echo '    <div class="top-nav-left d-flex align-items-center gap-2">';
    echo '      <button class="btn btn-light d-lg-none border shadow-sm me-2" id="sidebarToggle" aria-label="Toggle Navigation"><i class="bi bi-list fs-5"></i></button>';
    echo '      <h4 class="mb-0 fw-bold text-dark fs-5">' . e($title) . '</h4>';
    echo '    </div>';
    echo '    <div class="top-nav-right d-flex align-items-center gap-3">';
    
    // Notification Dropdown
    echo '      <div class="dropdown d-inline-block">';
    echo '        <button class="btn btn-light bg-white border rounded-3 p-0 position-relative d-inline-flex align-items-center justify-content-center shadow-sm top-nav-icon-btn" style="width: 40px; height: 40px; color: #475569;" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Pemberitahuan Sistem">';
    echo '          <i class="bi bi-bell-fill fs-5"></i>';
    echo '          <span class="position-absolute badge rounded-pill bg-danger badge-counter" style="top: -4px; right: -4px; font-size: 0.65rem; border: 2px solid #ffffff; padding: 2px 5px; line-height: 1;">3</span>';
    echo '        </button>';
    echo '        <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0 notification-dropdown" style="min-width: 300px; border-radius: 12px; overflow: hidden;">';
    echo '          <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light">';
    echo '            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-bell text-primary me-2"></i>Notifikasi Sistem</h6>';
    echo '            <span class="badge bg-primary">3 Baru</span>';
    echo '          </div>';
    echo '          <div class="p-2" style="max-height: 280px; overflow-y: auto;">';
    echo '            <a href="' . e(url("denah.php")) . '" class="dropdown-item p-2 rounded-2 d-flex align-items-center gap-2 mb-1">';
    echo '              <div class="stat-circle-icon stat-circle-blue flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.85rem;"><i class="bi bi-car-front-fill"></i></div>';
    echo '              <div class="overflow-hidden">';
    echo '                <div class="small fw-semibold text-dark text-truncate">Kapasitas Slot Mobil Terisi</div>';
    echo '                <div class="text-muted" style="font-size: 0.7rem;">Pantau ketersediaan slot di Denah</div>';
    echo '              </div>';
    echo '            </a>';
    echo '            <a href="' . e(url("denah.php")) . '" class="dropdown-item p-2 rounded-2 d-flex align-items-center gap-2 mb-1">';
    echo '              <div class="stat-circle-icon stat-circle-green flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.85rem;"><i class="bi bi-check-circle-fill"></i></div>';
    echo '              <div class="overflow-hidden">';
    echo '                <div class="small fw-semibold text-dark text-truncate">Sistem ParkirKu Berjalan Normal</div>';
    echo '                <div class="text-muted" style="font-size: 0.7rem;">Database & server lokal terhubung</div>';
    echo '              </div>';
    echo '            </a>';
    echo '            <a href="#" class="dropdown-item p-2 rounded-2 d-flex align-items-center gap-2">';
    echo '              <div class="stat-circle-icon stat-circle-amber flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.85rem;"><i class="bi bi-shield-check"></i></div>';
    echo '              <div class="overflow-hidden">';
    echo '                <div class="small fw-semibold text-dark text-truncate">Sesi ' . ucfirst(e($u["role"])) . ' Aktif</div>';
    echo '                <div class="text-muted" style="font-size: 0.7rem;">Login sebagai ' . e($u["nama_lengkap"]) . '</div>';
    echo '              </div>';
    echo '            </a>';
    echo '          </div>';
    echo '        </div>';
    echo '      </div>';

    // User Profile Dropdown Pill
    echo '      <div class="dropdown d-inline-block">';
    echo '        <div class="btn btn-light bg-white border rounded-pill d-inline-flex align-items-center gap-2 py-1 px-3 shadow-sm user-profile-pill" data-bs-toggle="dropdown" role="button" aria-expanded="false" style="cursor: pointer; user-select: none;">';
    echo '          <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center shadow-sm flex-shrink-0 user-avatar-circle" style="width: 34px; height: 34px; font-size: 1rem;">';
    echo '            <i class="bi bi-person-fill"></i>';
    echo '          </div>';
    echo '          <div class="d-none d-sm-block text-start user-meta" style="line-height: 1.25;">';
    echo '            <div class="fw-bold small text-dark user-name" style="font-size: 0.825rem;">' . e($u["nama_lengkap"]) . '</div>';
    echo '            <div class="text-muted user-role" style="font-size: 0.7rem; font-family: monospace;">' . e($u["username"]) . ' &bull; ' . ucfirst(e($u["role"])) . '</div>';
    echo '          </div>';
    echo '          <i class="bi bi-chevron-down text-secondary ms-1" style="font-size: 0.75rem;"></i>';
    echo '        </div>';
    echo '        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 py-2" style="min-width: 220px; border-radius: 12px;">';
    echo '          <li class="px-3 py-2 border-bottom">';
    echo '            <div class="fw-bold text-dark">' . e($u["nama_lengkap"]) . '</div>';
    echo '            <div class="small text-muted font-monospace">' . e($u["username"]) . ' &bull; ' . ucfirst(e($u["role"])) . '</div>';
    echo '          </li>';
    echo '          <li><a class="dropdown-item py-2" href="' . e(url("denah.php")) . '"><i class="bi bi-map text-primary me-2"></i>Denah Parkir</a></li>';
    echo '          <li><hr class="dropdown-divider my-1"></li>';
    echo '          <li><a class="dropdown-item py-2 text-danger fw-semibold" href="' . e(url("logout.php")) . '"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>';
    echo '        </ul>';
    echo '      </div>';
    echo '    </div>';
    echo '  </header>';

    // Main Page Container
    echo '  <main class="page-container">';
    show_flash();
}

/**
 * Page Footer
 */
function page_footer(): void
{
    $u = current_user();
    if ($u) {
        echo '  </main>';
        echo '</div>'; // End app-main
        echo '</div>'; // End app-wrapper
    } else {
        echo '</div>'; // End login min-vh wrapper
    }

    echo '<script src="' . e(url("assets/vendor/bootstrap/bootstrap.bundle.min.js")) . '"></script>';
    echo '<script src="' . e(url("assets/js/app.js")) . '"></script>';
    echo '</body></html>';
}
