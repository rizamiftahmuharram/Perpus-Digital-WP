// GitHub-safe WordPress guard. Credentials are stored in WordPress options,
// not in this source file.
if (!defined('ABSPATH')) {
    exit;
}

// =============================================
// DIGLIB — FULL BUILD + WAITLIST + PERPANJANG + LOGIN
// =============================================
if (!function_exists('diglib_expire_loans')) {
function diglib_expire_loans() {
global $wpdb;
$lt = $wpdb->prefix . 'diglib_loans';
$et = $wpdb->prefix . 'diglib_ebooks';
$expired = $wpdb->get_results("SELECT id, ebook_id FROM $lt WHERE status = 'active' AND expires_at <= NOW()");
if (!empty($expired)) {
foreach ($expired as $loan) {
$wpdb->update($lt, ['status' => 'expired'], ['id' => $loan->id]);
$wpdb->query($wpdb->prepare("UPDATE $et SET available_copies = available_copies + 1 WHERE id = %d AND available_copies < total_copies", $loan->ebook_id));
if (function_exists('diglib_waitlist_notify')) diglib_waitlist_notify($loan->ebook_id);
if (function_exists('diglib_maybe_send_review_email')) diglib_maybe_send_review_email($loan->id);
}
}
$pending = $wpdb->get_results("SELECT id, ebook_id FROM $lt WHERE status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
if (!empty($pending)) {
foreach ($pending as $loan) {
$wpdb->update($lt, ['status' => 'expired'], ['id' => $loan->id]);
$wpdb->query($wpdb->prepare("UPDATE $et SET available_copies = available_copies + 1 WHERE id = %d AND available_copies < total_copies", $loan->ebook_id));
if (function_exists('diglib_waitlist_notify')) diglib_waitlist_notify($loan->ebook_id);
}
}
}
}
// =============================================
// DATABASE UPGRADE
// =============================================
function diglib_upgrade_db() {
global $wpdb;
$table = $wpdb->prefix . 'diglib_loans';
$col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'last_page'");
if (empty($col)) $wpdb->query("ALTER TABLE $table ADD last_page INT DEFAULT 0 NOT NULL AFTER access_token");
$col2 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'verification_code'");
if (empty($col2)) {
$col_old = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'verification_token'");
if (!empty($col_old)) $wpdb->query("ALTER TABLE $table DROP COLUMN verification_token");
$wpdb->query("ALTER TABLE $table ADD verification_code VARCHAR(4) NULL AFTER last_page");
}
$col3 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'status'");
if (!empty($col3) && strpos($col3[0]->Type, 'enum') !== false) $wpdb->query("ALTER TABLE $table MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
$col4 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'created_at'");
if (empty($col4)) $wpdb->query("ALTER TABLE $table ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER status");
$col5 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'rating_sent'");
if (empty($col5)) $wpdb->query("ALTER TABLE $table ADD rating_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_code");
$col6 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'review_token'");
if (empty($col6)) $wpdb->query("ALTER TABLE $table ADD review_token VARCHAR(64) NULL AFTER rating_sent");
// BARU: flag perpanjangan (maks 1x)
$col7 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'extended'");
if (empty($col7)) $wpdb->query("ALTER TABLE $table ADD extended TINYINT(1) NOT NULL DEFAULT 0 AFTER rating_sent");
$review_table = $wpdb->prefix . 'diglib_reviews';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $review_table)) != $review_table) {
$wpdb->query("CREATE TABLE IF NOT EXISTS $review_table (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
loan_id BIGINT UNSIGNED NOT NULL,
ebook_id BIGINT UNSIGNED NOT NULL,
borrower_email VARCHAR(255) NOT NULL,
rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
review TEXT NULL,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id), UNIQUE KEY loan_id (loan_id), KEY ebook_id (ebook_id)
) " . $wpdb->get_charset_collate() . ";");
}
$archive_table = $wpdb->prefix . 'diglib_loans_archive';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $archive_table)) != $archive_table) {
$wpdb->query("CREATE TABLE IF NOT EXISTS $archive_table LIKE $table");
}
// BARU: tabel waiting list
$wl_table = $wpdb->prefix . 'diglib_waitlist';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wl_table)) != $wl_table) {
$wpdb->query("CREATE TABLE IF NOT EXISTS $wl_table (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
ebook_id BIGINT UNSIGNED NOT NULL,
email VARCHAR(255) NOT NULL,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
UNIQUE KEY ebook_email (ebook_id, email),
KEY email (email)
) " . $wpdb->get_charset_collate() . ";");
}
$don_table = $wpdb->prefix . 'diglib_donations';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $don_table)) != $don_table) {
$wpdb->query("CREATE TABLE IF NOT EXISTS $don_table (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
merchant_order_id VARCHAR(64) NOT NULL,
reference VARCHAR(64) NULL,
donor_name VARCHAR(150) NULL,
donor_email VARCHAR(255) NULL,
amount INT UNSIGNED NOT NULL,
payment_method VARCHAR(50) NULL,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
paid_at DATETIME NULL,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
raw_response LONGTEXT NULL,
PRIMARY KEY  (id),
UNIQUE KEY merchant_order_id (merchant_order_id),
KEY reference (reference)
) " . $wpdb->get_charset_collate() . ";");
}
}
add_action('init', 'diglib_upgrade_db');
// =============================================
// CRON CLEANUP / ARSIP > 6 BULAN
// =============================================
function diglib_cleanup_expired_loans() {
global $wpdb;
$lt = $wpdb->prefix . 'diglib_loans';
$at = $wpdb->prefix . 'diglib_loans_archive';
$where = "status = 'expired' AND expires_at <= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $at)) == $at) {
$wpdb->query("INSERT IGNORE INTO $at SELECT * FROM $lt WHERE $where");
}
$wpdb->query("DELETE FROM $lt WHERE $where");
}
function diglib_schedule_cleanup() {
if (!wp_next_scheduled('diglib_daily_cleanup')) wp_schedule_event(time(), 'daily', 'diglib_daily_cleanup');
}
add_action('init', 'diglib_schedule_cleanup');
add_action('diglib_daily_cleanup', 'diglib_cleanup_expired_loans');
// =============================================
// AJAX BOOKMARK
// =============================================
add_action('wp_ajax_nopriv_diglib_save_bookmark', 'diglib_save_bookmark');
add_action('wp_ajax_diglib_save_bookmark', 'diglib_save_bookmark');
function diglib_save_bookmark() {
global $wpdb;
$token = sanitize_text_field($_POST['token'] ?? '');
$page  = intval($_POST['page'] ?? 0);
if (!$token || $page < 1) wp_die('Data tidak valid');
$wpdb->update($wpdb->prefix . 'diglib_loans', ['last_page' => $page], ['access_token' => $token, 'status' => 'active']);
wp_die('OK');
}
// =============================================
// HONEYPOT
// =============================================
if (!function_exists('diglib_honeypot_field')) {
function diglib_honeypot_field() {
echo '<div style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden;" aria-hidden="true"><label>Kosongkan: <input type="text" name="diglib_hp" value="" tabindex="-1" autocomplete="off"></label></div>';
}
}
if (!function_exists('diglib_is_spam')) {
function diglib_is_spam() { return !empty($_POST['diglib_hp']); }
}
// =============================================
// HELPERS REVIEW
// =============================================
if (!function_exists('diglib_generate_review_token')) { function diglib_generate_review_token() { return bin2hex(random_bytes(16)); } }
if (!function_exists('diglib_render_stars')) {
function diglib_render_stars($rating) {
$rating = round(floatval($rating)); $out = '';
for ($i = 1; $i <= 5; $i++) $out .= '<span class="dl-star' . ($i <= $rating ? '' : ' off') . '">★</span>';
return $out;
}
}
if (!function_exists('diglib_get_review_url')) {
function diglib_get_review_url($loan_id, $token) { return home_url('/?diglib_review=1&loan=' . intval($loan_id) . '&rt=' . urlencode($token)); }
}
if (!function_exists('diglib_ensure_review_token')) {
function diglib_ensure_review_token($loan_id) {
global $wpdb; $lt = $wpdb->prefix . 'diglib_loans';
$token = $wpdb->get_var($wpdb->prepare("SELECT review_token FROM $lt WHERE id=%d", $loan_id));
if (empty($token)) { $token = diglib_generate_review_token(); $wpdb->update($lt, ['review_token' => $token], ['id' => $loan_id]); }
return $token;
}
}
if (!function_exists('diglib_get_review_url_by_loan')) {
function diglib_get_review_url_by_loan($loan_id) { $t = diglib_ensure_review_token($loan_id); return $t ? diglib_get_review_url($loan_id, $t) : ''; }
}
// =============================================
// BARU: HELPERS WAITLIST & PERPANJANGAN
// =============================================
if (!function_exists('diglib_waitlist_count')) {
function diglib_waitlist_count($ebook_id) {
global $wpdb;
return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}diglib_waitlist WHERE ebook_id=%d", $ebook_id));
}
}
if (!function_exists('diglib_send_waitlist_email')) {
function diglib_send_waitlist_email($email, $book_title, $book_url) {
$subject = $book_title . ' sudah tersedia kembali!';
$message  = "<html><body>";
$message .= "<p>Halo,</p>";
$message .= "<p>Kabar baik! Ebook <strong>{$book_title}</strong> yang ada di waiting list-mu sekarang tersedia kembali.</p>";
$message .= "<p>Segera pinjam sebelum diambil pembaca lain:</p>";
$message .= "<p style='text-align:center;margin:28px 0;'><a href='" . esc_url($book_url) . "' style='display:inline-block;background:#00278a;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:600;'>Pinjam Sekarang</a></p>";
$message .= "<p>Jika kamu tidak lagi tertarik, cukup abaikan email ini.</p>";
$message .= "<p>Salam,<br>Perpus Digital Astronomi</p></body></html>";
wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}
}
if (!function_exists('diglib_waitlist_notify')) {
// Dipanggil setiap kali 1 eksemplar kembali tersedia.
// Memberi tahu 1 orang terlama di waiting list, lalu mengeluarkannya dari daftar.
function diglib_waitlist_notify($ebook_id) {
global $wpdb;
$et = $wpdb->prefix . 'diglib_ebooks';
$wl = $wpdb->prefix . 'diglib_waitlist';
$avail = (int)$wpdb->get_var($wpdb->prepare("SELECT available_copies FROM $et WHERE id=%d", $ebook_id));
if ($avail <= 0) return;
$next = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wl WHERE ebook_id=%d ORDER BY created_at ASC, id ASC LIMIT 1", $ebook_id));
if (!$next) return;
$book = $wpdb->get_row($wpdb->prepare("SELECT title FROM $et WHERE id=%d", $ebook_id));
$wpdb->delete($wl, ['id' => $next->id]);
if ($book) diglib_send_waitlist_email($next->email, $book->title, home_url('/borrow/?book=' . $ebook_id));
}
}
if (!function_exists('diglib_loan_can_extend')) {
// Boleh perpanjang HANYA jika: belum pernah diperpanjang DAN waiting list kosong.
function diglib_loan_can_extend($loan) {
if (!$loan || $loan->status !== 'active') return false;
if ((int)$loan->extended === 1) return false;
return diglib_waitlist_count($loan->ebook_id) === 0;
}
}
// =============================================
// EMAIL
// =============================================
function diglib_send_verification_email($email, $code, $book_title) {
$subject = $code . ' adalah Kode Verifikasi Pinjaman ' . $book_title;
$message  = "<html><body>";
$message .= "<p>Halo,</p>";
$message .= "<p>Kamu baru saja mengajukan peminjaman ebook <strong>{$book_title}</strong> melalui Perpus Digital Astronomi.</p>";
$message .= "<p>Gunakan kode verifikasi 4-digit berikut untuk mengaktifkan pinjamanmu:</p>";
$message .= "<h2 style='text-align:center; font-size:32px; letter-spacing:8px; margin:20px 0; color:#00278a;'><strong>{$code}</strong></h2>";
$message .= "<p>Kode ini berlaku 24 jam. Jika tidak digunakan, pinjaman akan otomatis dibatalkan.</p>";
$message .= "<p>Kamu tidak merasa meminjam? Abaikan saja email ini dan jangan berikan kode di atas kepada siapapun.</p>";
$message .= "<p>Salam,<br>Perpus Digital Astronomi</p></body></html>";
wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}
function diglib_send_login_email($email, $code) {
$subject = $code . ' adalah Kode Masuk Perpus Digital Astronomi';
$message  = "<html><body>";
$message .= "<p>Halo,</p>";
$message .= "<p>Kamu meminta kode masuk ke Perpus Digital Astronomi. Gunakan kode 4-digit berikut:</p>";
$message .= "<h2 style='text-align:center; font-size:32px; letter-spacing:8px; margin:20px 0; color:#00278a;'><strong>{$code}</strong></h2>";
$message .= "<p>Setelah masuk, kamu bisa meminjam buku tanpa verifikasi ulang dan melihat daftar pinjamanmu. Sesi masuk berlaku 30 hari selama kamu tidak keluar.</p>";
$message .= "<p>Jika ini bukan kamu, abaikan email ini dan jangan berikan kodenya kepada siapapun.</p>";
$message .= "<p>Salam,<br>Perpus Digital Astronomi</p></body></html>";
wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}
function diglib_send_rack_access_email($email, $code) { diglib_send_login_email($email, $code); }
function diglib_send_review_email($email, $book_title, $review_url) {
$subject = 'Terima kasih sudah membaca ' . $book_title;
$message  = "<html><body>";
$message .= "<p>Halo,</p>";
$message .= "<p>Masa pinjaman ebook <strong>{$book_title}</strong> telah selesai. Terima kasih sudah membaca di Perpus Digital Astronomi.</p>";
$message .= "<p>Jika berkenan, berikan rating dan ulasan singkatmu untuk membantu pembaca lain.</p>";
$message .= "<p style='text-align:center;margin:28px 0;'><a href='" . esc_url($review_url) . "' style='display:inline-block;background:#00278a;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:600;'>Beri Rating & Ulasan</a></p>";
$message .= "<p>Atau salin tautan ini: " . esc_html($review_url) . "</p>";
$message .= "<p>Salam,<br>Perpus Digital Astronomi</p></body></html>";
wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}
function diglib_maybe_send_review_email($loan_id) {
global $wpdb;
$lt = $wpdb->prefix . 'diglib_loans'; $et = $wpdb->prefix . 'diglib_ebooks'; $rt = $wpdb->prefix . 'diglib_reviews';
$loan = $wpdb->get_row($wpdb->prepare("SELECT l.id, l.borrower_email, l.rating_sent, e.title FROM $lt l JOIN $et e ON l.ebook_id=e.id WHERE l.id=%d AND l.status='expired'", $loan_id));
if (!$loan || !empty($loan->rating_sent)) return;
if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $rt WHERE loan_id=%d", $loan_id))) { $wpdb->update($lt, ['rating_sent' => 1], ['id' => $loan_id]); return; }
$token = diglib_ensure_review_token($loan_id);
diglib_send_review_email($loan->borrower_email, $loan->title, diglib_get_review_url($loan_id, $token));
$wpdb->update($lt, ['rating_sent' => 1], ['id' => $loan_id]);
}
// =============================================
// HALAMAN REVIEW STANDALONE
// =============================================
function diglib_handle_review_page() {
if (is_admin() || !isset($_GET['diglib_review'])) return;
global $wpdb;
$loan_id = intval($_GET['loan'] ?? 0);
$rt = sanitize_text_field($_GET['rt'] ?? '');
if (!$loan_id || !$rt) wp_die('Tautan ulasan tidak valid.');
$lt = $wpdb->prefix . 'diglib_loans'; $et = $wpdb->prefix . 'diglib_ebooks'; $rv = $wpdb->prefix . 'diglib_reviews';
$loan = $wpdb->get_row($wpdb->prepare("SELECT l.*, e.title FROM $lt l JOIN $et e ON l.ebook_id=e.id WHERE l.id=%d AND l.review_token=%s", $loan_id, $rt));
if (!$loan) wp_die('Tautan ulasan tidak valid atau sudah kedaluwarsa.');
$existing = $wpdb->get_row($wpdb->prepare("SELECT rating, review FROM $rv WHERE loan_id=%d", $loan_id));
if ($loan->status !== 'expired' && !$existing) wp_die('Ulasan dapat diisi setelah masa pinjaman berakhir atau buku dikembalikan.');
$alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['diglib_review_submit'])) {
if (diglib_is_spam()) { $alert = '<div class="rv-alert err">Permintaan dianggap spam.</div>'; }
else {
$rating = intval($_POST['rating'] ?? 0);
$review_text = sanitize_textarea_field($_POST['review_text'] ?? '');
if ($rating < 1 || $rating > 5) { $alert = '<div class="rv-alert err">Silakan pilih rating bintang 1 sampai 5.</div>'; }
else {
$wpdb->query($wpdb->prepare("INSERT INTO $rv (loan_id, ebook_id, borrower_email, rating, review, created_at) VALUES (%d,%d,%s,%d,%s,NOW()) ON DUPLICATE KEY UPDATE rating=VALUES(rating), review=VALUES(review)", $loan_id, $loan->ebook_id, $loan->borrower_email, $rating, $review_text));
$wpdb->update($lt, ['rating_sent' => 1], ['id' => $loan_id]);
$existing = $wpdb->get_row($wpdb->prepare("SELECT rating, review FROM $rv WHERE loan_id=%d", $loan_id));
$alert = '<div class="rv-alert ok">Terima kasih! Ulasanmu sudah tersimpan.</div>';
}
}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ulasan Buku — Perpus Digital Astronomi</title>
<style>
body{margin:0;background:#f4f6fb;color:#1a2340;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;line-height:1.6;}
.rv-wrap{max-width:600px;margin:48px auto;padding:28px;background:#fff;border:1px solid #e4e8f1;border-radius:8px;box-shadow:0 2px 10px rgba(16,24,40,.06);}
.rv-kicker{font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:#00278a;font-weight:700;margin-bottom:8px;}
.rv-title{font-size:1.4rem;font-weight:800;margin:0 0 6px;color:#101828;}
.rv-book{border:1px solid #e4e8f1;background:#f7f9fe;padding:12px 14px;margin:16px 0;border-radius:6px;}
.rv-label{display:block;font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#5b6478;margin:14px 0 8px;}
.rv-stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:6px;}
.rv-stars input{display:none;}
.rv-stars label{font-size:30px;color:#d5dae6;cursor:pointer;line-height:1;}
.rv-stars input:checked ~ label,.rv-stars label:hover,.rv-stars label:hover ~ label{color:#00278a;}
textarea{width:100%;min-height:130px;resize:vertical;background:#fff;border:1px solid #e4e8f1;border-radius:6px;padding:12px;color:#1a2340;font-family:inherit;font-size:.95rem;outline:none;box-sizing:border-box;}
textarea:focus{border-color:#00278a;box-shadow:0 0 0 3px rgba(0,39,138,.08);}
.rv-btn{margin-top:18px;width:100%;border:none;cursor:pointer;padding:13px;background:#00278a;color:#fff;font-family:inherit;font-weight:700;font-size:.95rem;border-radius:6px;}
.rv-btn:hover{background:#001c66;}
.rv-alert{padding:10px 14px;margin-bottom:14px;font-size:.9rem;border-radius:6px;border:1px solid;}
.rv-alert.ok{background:#e9f7ef;border-color:#bfe6d0;color:#1e8e5a;}
.rv-alert.err{background:#fdecea;border-color:#f5c6c0;color:#c0392b;}
.rv-note{margin-top:12px;color:#5b6478;font-size:.8rem;}
</style>
</head>
<body>
<div class="rv-wrap">
<div class="rv-kicker">Kartu Ulasan Pembaca</div>
<h1 class="rv-title">Bagaimana bukunya?</h1>
<div class="rv-book"><strong><?= esc_html($loan->title) ?></strong><br><small style="color:#5b6478;">Dipinjam oleh <?= esc_html($loan->borrower_email) ?></small></div>
<?= $alert ?>
<form method="post">
<?php diglib_honeypot_field(); ?>
<label class="rv-label">Rating</label>
<div class="rv-stars">
<input type="radio" id="rv5" name="rating" value="5" <?= ($existing && $existing->rating == 5) ? 'checked' : '' ?> required><label for="rv5">★</label>
<input type="radio" id="rv4" name="rating" value="4" <?= ($existing && $existing->rating == 4) ? 'checked' : '' ?>><label for="rv4">★</label>
<input type="radio" id="rv3" name="rating" value="3" <?= ($existing && $existing->rating == 3) ? 'checked' : '' ?>><label for="rv3">★</label>
<input type="radio" id="rv2" name="rating" value="2" <?= ($existing && $existing->rating == 2) ? 'checked' : '' ?>><label for="rv2">★</label>
<input type="radio" id="rv1" name="rating" value="1" <?= ($existing && $existing->rating == 1) ? 'checked' : '' ?>><label for="rv1">★</label>
</div>
<label class="rv-label">Ulasan (opsional)</label>
<textarea name="review_text" placeholder="Tulis kesanmu tentang buku ini..."><?= esc_textarea($existing->review ?? '') ?></textarea>
<button class="rv-btn" type="submit" name="diglib_review_submit" value="1">Kirim Ulasan</button>
<p class="rv-note">Mengirim ulang akan memperbarui ulasan lamamu.</p>
</form>
</div>
</body>
</html>
<?php exit;
}
add_action('init', 'diglib_handle_review_page');
// =============================================
// SESI / LOGIN PERSISTEN (30 HARI)
// =============================================
if (!function_exists('diglib_set_auth_session')) {
function diglib_set_auth_session($email) {
$token = bin2hex(random_bytes(32));
$salt = wp_salt('auth');
$hash = hash('sha256', $email . $token . $salt);
set_transient('diglib_session_' . $hash, $email, 30 * DAY_IN_SECONDS);
setcookie('diglib_auth', $hash, ['expires' => time() + (30 * DAY_IN_SECONDS), 'path' => '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
}
}
if (!function_exists('diglib_is_authenticated')) {
function diglib_is_authenticated() {
if (!isset($_COOKIE['diglib_auth'])) return false;
$hash = sanitize_text_field($_COOKIE['diglib_auth']);
$email = get_transient('diglib_session_' . $hash);
return $email ? $email : false;
}
}
if (!function_exists('diglib_logout')) {
function diglib_logout() {
if (isset($_COOKIE['diglib_auth'])) {
$hash = sanitize_text_field($_COOKIE['diglib_auth']);
delete_transient('diglib_session_' . $hash);
setcookie('diglib_auth', '', ['expires' => time() - 3600, 'path' => '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
}
}
}
add_action('init', function() {
if (isset($_GET['diglib_logout']) && $_GET['diglib_logout'] === '1') { diglib_logout(); wp_redirect(home_url('/peminjaman-saya/')); exit; }
});
// =============================================
// STREAMING READER
// =============================================
add_action('init', function() {
if (isset($_GET['diglib_stream'])) {
$token = sanitize_text_field($_GET['diglib_stream']);
global $wpdb;
$loan = $wpdb->get_row($wpdb->prepare("SELECT e.file_url FROM {$wpdb->prefix}diglib_loans l JOIN {$wpdb->prefix}diglib_ebooks e ON l.ebook_id=e.id WHERE l.access_token=%s AND l.status='active' AND l.expires_at>NOW()", $token));
if ($loan && !empty($loan->file_url)) {
$upload_dir = wp_upload_dir();
$local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $loan->file_url);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="ebook.pdf"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (file_exists($local_path)) { readfile($local_path); exit; }
$response = wp_remote_get($loan->file_url);
if (!is_wp_error($response)) { echo wp_remote_retrieve_body($response); exit; }
}
wp_die('Akses ditolak.');
}
});
remove_action('wp_body_open', function() { if (!is_admin()) echo do_shortcode('[diglib_header]'); });
remove_action('wp_footer',    function() { if (!is_admin()) echo do_shortcode('[diglib_footer]'); }, 5);
// =============================================
// MENU ADMIN: SETTINGS DUITKU + ULASAN
// =============================================
add_action('admin_menu', 'diglib_register_admin_pages', 9999);
function diglib_register_admin_pages() {
$parent_slug = null;
if (!empty($GLOBALS['menu'])) {
foreach ($GLOBALS['menu'] as $item) {
if (is_array($item) && isset($item[0], $item[2]) && trim($item[0]) === 'Perpustakaan') {
$parent_slug = $item[2];
break;
}
}
}
if (!$parent_slug) {
$parent_slug = 'diglib-settings';
add_menu_page('Perpustakaan', 'Perpustakaan', 'manage_options', $parent_slug, null, 'dashicons-book', 30);
remove_submenu_page($parent_slug, $parent_slug);
}
add_submenu_page($parent_slug, 'Pengaturan Duitku', 'Pengaturan Duitku', 'manage_options', 'diglib-duitku', 'diglib_settings_page');
add_submenu_page($parent_slug, 'Ulasan Pembaca', 'Ulasan', 'manage_options', 'diglib-reviews', 'diglib_reviews_page');
}
function diglib_settings_page() {
if (!current_user_can('manage_options')) return;
$saved = false;
if (isset($_POST['diglib_save_settings']) && check_admin_referer('diglib_save_settings_nonce')) {
update_option('diglib_duitku_api_key', sanitize_text_field($_POST['duitku_api_key'] ?? ''));
update_option('diglib_duitku_merchant_code', sanitize_text_field($_POST['duitku_merchant_code'] ?? ''));
update_option('diglib_duitku_env', in_array($_POST['duitku_env'] ?? '', ['sandbox','production']) ? $_POST['duitku_env'] : 'sandbox');
update_option('diglib_duitku_callback_key', sanitize_text_field($_POST['duitku_callback_key'] ?? ''));
update_option('diglib_duitku_active', isset($_POST['duitku_active']) ? 1 : 0);
$saved = true;
}
$api_key = get_option('diglib_duitku_api_key', '');
$merchant_code = get_option('diglib_duitku_merchant_code', '');
$env = get_option('diglib_duitku_env', 'sandbox');
$callback_key = get_option('diglib_duitku_callback_key', '');
$active = (int)get_option('diglib_duitku_active', 0);
global $wpdb;
$dt = $wpdb->prefix . 'diglib_donations';
$total_donations = (int)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM $dt WHERE status='success'");
$total_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE status='success'");
$pending_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE status='pending'");
$callback_url = home_url('/?diglib_duitku_callback=1');
if (empty($callback_key)) {
$callback_key = wp_generate_password(32, false);
update_option('diglib_duitku_callback_key', $callback_key);
}
?>
<div class="wrap">
<h1>Pengaturan Perpustakaan Digital</h1>
<?php if ($saved): ?>
<div class="notice notice-success"><p>Pengaturan berhasil disimpan.</p></div>
<?php endif; ?>
<h2>📊 Statistik Donasi</h2>
<table class="widefat" style="max-width:500px;">
<tr><td><strong>Total donasi terkumpul</strong></td><td>Rp <?= number_format($total_donations, 0, ',', '.') ?></td></tr>
<tr><td>Jumlah transaksi sukses</td><td><?= $total_count ?></td></tr>
<tr><td>Transaksi menunggu</td><td><?= $pending_count ?></td></tr>
</table>
<h2 style="margin-top:32px;">💳 Konfigurasi Duitku Payment Gateway</h2>
<p>Daftar di <a href="https://duitku.com" target="_blank">duitku.com</a> untuk mendapatkan API Key dan Merchant Code.</p>
<form method="post">
<?php wp_nonce_field('diglib_save_settings_nonce'); ?>
<table class="form-table">
<tr>
<th>Aktifkan Donasi</th>
<td>
<label>
<input type="checkbox" name="duitku_active" value="1" <?= $active ? 'checked' : '' ?>>
Tampilkan menu "Donasi" di header website
</label>
</td>
</tr>
<tr>
<th>Environment</th>
<td>
<select name="duitku_env">
<option value="sandbox" <?= selected($env, 'sandbox') ?>>Sandbox (uji coba)</option>
<option value="production" <?= selected($env, 'production') ?>>Production (live)</option>
</select>
</td>
</tr>
<tr>
<th>Merchant Code</th>
<td>
<input type="text" name="duitku_merchant_code" value="<?= esc_attr($merchant_code) ?>" class="regular-text" placeholder="MC12345">
<p class="description">Kode merchant dari Duitku dashboard.</p>
</td>
</tr>
<tr>
<th>API Key</th>
<td>
<input type="password" name="duitku_api_key" value="<?= esc_attr($api_key) ?>" class="regular-text" placeholder="xxxxxxxxxxxxxxxx">
<p class="description">API Key dari Duitku dashboard.</p>
</td>
</tr>
<tr>
<th>Callback URL</th>
<td>
<code style="display:block;padding:8px;background:#f1f1f1;"><?= esc_html($callback_url) ?></code>
<p class="description">Salin URL di atas ke kolom "Callback URL" di dashboard Duitku. Ini sudah final dan tidak perlu diubah.</p>
<input type="hidden" name="duitku_callback_key" value="<?= esc_attr($callback_key) ?>">
</td>
</tr>
<tr>
<th>Return URL</th>
<td>
<code style="display:block;padding:8px;background:#f1f1f1;"><?= esc_html(home_url('/?diglib_duitku_return=1')) ?></code>
<p class="description">Halaman tujuan setelah pengguna selesai membayar (opsional).</p>
</td>
</tr>
</table>
<p><button type="submit" name="diglib_save_settings" class="button button-primary">Simpan Pengaturan</button></p>
</form>
<h2 style="margin-top:32px;">📜 Riwayat Donasi Terbaru</h2>
<?php
$recent = $wpdb->get_results("SELECT * FROM $dt ORDER BY created_at DESC LIMIT 20");
if (empty($recent)) {
echo '<p>Belum ada transaksi.</p>';
} else {
echo '<table class="widefat striped"><thead><tr>
<th>ID Order</th><th>Nama</th><th>Email</th><th>Nominal</th><th>Metode</th><th>Status</th><th>Waktu</th>
</tr></thead><tbody>';
foreach ($recent as $d) {
$status_class = $d->status === 'success' ? 'color:#1e8e5a;' : ($d->status === 'failed' ? 'color:#c0392b;' : 'color:#9a6700;');
echo '<tr>
<td><code>' . esc_html($d->merchant_order_id) . '</code></td>
<td>' . esc_html($d->donor_name ?: '-') . '</td>
<td>' . esc_html($d->donor_email ?: '-') . '</td>
<td>Rp ' . number_format($d->amount, 0, ',', '.') . '</td>
<td>' . esc_html($d->payment_method ?: '-') . '</td>
<td><span style="' . $status_class . 'font-weight:600;">' . esc_html(ucfirst($d->status)) . '</span></td>
<td>' . esc_html(date_i18n('d M Y H:i', strtotime($d->created_at))) . '</td>
</tr>';
}
echo '</tbody></table>';
}
?>
</div>
<?php
}
function diglib_reviews_page() {
if (!current_user_can('manage_options')) return;
global $wpdb;
$rv = $wpdb->prefix . 'diglib_reviews';
$et = $wpdb->prefix . 'diglib_ebooks';
$per_page = 20;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;
$total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $rv");
$total_pages = max(1, (int)ceil($total / $per_page));
$avg_rating = floatval($wpdb->get_var("SELECT AVG(rating) FROM $rv"));
$with_text = (int)$wpdb->get_var("SELECT COUNT(*) FROM $rv WHERE review IS NOT NULL AND review != ''");
$reviews = $wpdb->get_results($wpdb->prepare(
"SELECT r.*, e.title AS book_title FROM $rv r LEFT JOIN $et e ON r.ebook_id = e.id ORDER BY r.created_at DESC LIMIT %d OFFSET %d",
$per_page, $offset
));
?>
<div class="wrap">
<h1>Ulasan Pembaca</h1>
<p>Semua ulasan yang masuk dari pembaca — termasuk yang dikirim sebelum halaman ini dibuat.</p>
<div style="display:flex;gap:12px;margin:16px 0 20px;flex-wrap:wrap;">
<div style="background:#fff;border:1px solid #e4e8f1;border-radius:8px;padding:12px 20px;">
<strong style="font-size:1.2rem;"><?= $total ?></strong>
<span style="color:#5b6478;"> total ulasan</span>
</div>
<div style="background:#fff;border:1px solid #e4e8f1;border-radius:8px;padding:12px 20px;">
<strong style="font-size:1.2rem;color:#00278a;"><?= $total ? number_format($avg_rating, 1) : '-' ?> ★</strong>
<span style="color:#5b6478;"> rating rata-rata</span>
</div>
<div style="background:#fff;border:1px solid #e4e8f1;border-radius:8px;padding:12px 20px;">
<strong style="font-size:1.2rem;"><?= $with_text ?></strong>
<span style="color:#5b6478;"> dengan komentar</span>
</div>
</div>
<?php if (empty($reviews)): ?>
<p>Belum ada ulasan masuk.</p>
<?php else: ?>
<table class="widefat striped">
<thead>
<tr>
<th style="width:24%;">Buku</th>
<th style="width:10%;">Rating</th>
<th>Ulasan</th>
<th style="width:20%;">Email</th>
<th style="width:12%;">Tanggal</th>
</tr>
</thead>
<tbody>
<?php foreach ($reviews as $r): ?>
<tr>
<td><strong><?= esc_html($r->book_title ?: '(buku tidak ditemukan)') ?></strong></td>
<td style="color:#00278a;letter-spacing:2px;"><?php for ($i = 1; $i <= 5; $i++) { echo $i <= (int)$r->rating ? '★' : '☆'; } ?></td>
<td><?= ($r->review !== null && $r->review !== '') ? esc_html($r->review) : '<em style="color:#5b6478;">(tanpa komentar)</em>' ?></td>
<td><code><?= esc_html($r->borrower_email) ?></code></td>
<td><?= esc_html(date_i18n('d M Y H:i', strtotime($r->created_at))) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($total_pages > 1): ?>
<div style="margin-top:16px;">
<?php for ($p = 1; $p <= $total_pages; $p++): ?>
<?php if ($p === $current_page): ?>
<span style="display:inline-block;padding:4px 10px;background:#00278a;color:#fff;border-radius:4px;"><?= $p ?></span>
<?php else: ?>
<a href="<?= esc_url(add_query_arg('paged', $p)) ?>" style="display:inline-block;padding:4px 10px;background:#fff;border:1px solid #e4e8f1;border-radius:4px;text-decoration:none;"><?= $p ?></a>
<?php endif; ?>
<?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php
}
// =============================================
// DONASI: HELPERS & API DUITKU
// =============================================
function diglib_donation_enabled() {
return (int)get_option('diglib_duitku_active', 0) === 1
&& !empty(get_option('diglib_duitku_api_key'))
&& !empty(get_option('diglib_duitku_merchant_code'));
}
function diglib_duitku_create_invoice($amount, $donor_name, $donor_email, $payment_method = '') {
$env = get_option('diglib_duitku_env', 'sandbox');
$api_key = get_option('diglib_duitku_api_key');
$merchant_code = get_option('diglib_duitku_merchant_code');
if (empty($api_key) || empty($merchant_code)) {
return ['success' => false, 'message' => 'Konfigurasi Duitku belum lengkap.'];
}
$endpoint = $env === 'production'
? 'https://passport.duitku.com/webapi/api/merchant/v2/inquiry'
: 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry';
$merchant_order_id = 'DON' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12)) . time();
if (strlen($merchant_order_id) > 50) {
$merchant_order_id = substr($merchant_order_id, 0, 50);
}
$string_to_sign = $merchant_code . $merchant_order_id . $amount;
$signature = hash_hmac('sha256', $string_to_sign, $api_key);
$return_url = home_url('/?diglib_duitku_return=1&order=' . $merchant_order_id);
$callback_url = home_url('/?diglib_duitku_callback=1');
if (empty($payment_method)) {
return ['success' => false, 'message' => 'Metode pembayaran belum dipilih.'];
}
$va_name = !empty($donor_name) ? $donor_name : 'Donatur Perpus';
if (strlen($va_name) > 20) $va_name = substr($va_name, 0, 20);
$email_safe = substr($donor_email, 0, 50);
$first_name = !empty($donor_name) ? explode(' ', $donor_name)[0] : 'Donatur';
$last_name = !empty($donor_name) && strpos($donor_name, ' ') !== false
? trim(substr($donor_name, strpos($donor_name, ' ') + 1))
: '';
if (strlen($first_name) > 50) $first_name = substr($first_name, 0, 50);
if (strlen($last_name) > 50) $last_name = substr($last_name, 0, 50);
$address = [
'firstName'   => $first_name,
'lastName'    => $last_name,
'address'     => 'Indonesia',
'city'        => 'Jakarta',
'postalCode'  => '11530',
'phone'       => '',
'countryCode' => 'ID'
];
$customer_detail = [
'firstName'       => $first_name,
'lastName'        => $last_name,
'email'           => $email_safe,
'phoneNumber'     => '',
'billingAddress'  => $address,
'shippingAddress' => $address
];
$item_details = [[
'name'     => 'Donasi Perpus Digital Astronomi',
'price'    => (int)$amount,
'quantity' => 1
]];
$body = [
'merchantCode'     => $merchant_code,
'paymentAmount'    => (int)$amount,
'paymentMethod'    => $payment_method,
'merchantOrderId'  => $merchant_order_id,
'productDetails'   => 'Donasi Perpus Digital Astronomi',
'additionalParam'  => '',
'merchantUserInfo' => $donor_name ?: 'Donatur',
'customerVaName'   => $va_name,
'email'            => $email_safe,
'phoneNumber'      => '',
'itemDetails'      => $item_details,
'customerDetail'   => $customer_detail,
'callbackUrl'      => $callback_url,
'returnUrl'        => $return_url,
'signature'        => $signature,
'expiryPeriod'     => 1440
];
$response = wp_remote_post($endpoint, [
'headers' => ['Content-Type' => 'application/json'],
'body'    => wp_json_encode($body),
'timeout' => 30,
]);
if (is_wp_error($response)) {
return ['success' => false, 'message' => 'Gagal menghubungi Duitku: ' . $response->get_error_message()];
}
$code = wp_remote_retrieve_response_code($response);
$data = json_decode(wp_remote_retrieve_body($response), true);
global $wpdb;
$dt = $wpdb->prefix . 'diglib_donations';
$wpdb->insert($dt, [
'merchant_order_id' => $merchant_order_id,
'reference'         => $data['reference'] ?? '',
'donor_name'        => $donor_name,
'donor_email'       => $donor_email,
'amount'            => (int)$amount,
'payment_method'    => $payment_method,
'status'            => 'pending',
'raw_response'      => wp_json_encode($data),
]);
if ($code === 200 && isset($data['statusCode']) && $data['statusCode'] === '00') {
return [
'success'     => true,
'payment_url' => $data['paymentUrl'] ?? '',
'reference'   => $data['reference'] ?? '',
'order_id'    => $merchant_order_id,
];
}
$err_msg = $data['statusMessage'] ?? ('Gagal membuat invoice. HTTP ' . $code);
return ['success' => false, 'message' => $err_msg, 'raw' => $data, 'body_sent' => $body];
}
add_action('wp_ajax_diglib_create_donation', 'diglib_create_donation_handler');
add_action('wp_ajax_nopriv_diglib_create_donation', 'diglib_create_donation_handler');
function diglib_create_donation_handler() {
check_ajax_referer('diglib_donate_nonce', 'nonce');
if (!diglib_donation_enabled()) {
wp_send_json_error(['message' => 'Fitur donasi belum diaktifkan oleh admin.']);
}
$amount         = intval($_POST['amount'] ?? 0);
$donor_name     = sanitize_text_field($_POST['donor_name'] ?? '');
$donor_email    = sanitize_email($_POST['donor_email'] ?? '');
$payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
if ($amount < 10000) {
wp_send_json_error(['message' => 'Nominal donasi minimal Rp10.000.']);
}
if ($amount > 100000000) {
wp_send_json_error(['message' => 'Nominal donasi terlalu besar.']);
}
if (!is_email($donor_email)) {
wp_send_json_error(['message' => 'Email tidak valid.']);
}
if (empty($payment_method)) {
wp_send_json_error(['message' => 'Pilih metode pembayaran terlebih dahulu.']);
}
$result = diglib_duitku_create_invoice($amount, $donor_name, $donor_email, $payment_method);
if (!$result['success']) {
wp_send_json_error(['message' => $result['message']]);
}
wp_send_json_success(['payment_url' => $result['payment_url']]);
}
add_action('init', 'diglib_handle_duitku_callback');
function diglib_handle_duitku_callback() {
if (!isset($_GET['diglib_duitku_callback'])) return;
$api_key = get_option('diglib_duitku_api_key');
$merchant_code     = sanitize_text_field($_POST['merchantCode'] ?? '');
$amount            = intval($_POST['amount'] ?? 0);
$merchant_order_id = sanitize_text_field($_POST['merchantOrderId'] ?? '');
$result_code       = sanitize_text_field($_POST['resultCode'] ?? '');
$signature         = sanitize_text_field($_POST['signature'] ?? '');
if (empty($merchant_code) || empty($amount) || empty($merchant_order_id) || empty($signature)) {
status_header(400);
echo 'Bad Parameter';
exit;
}
$string_to_sign = $merchant_code . $amount . $merchant_order_id;
$expected_signature = hash_hmac('sha256', $string_to_sign, $api_key);
if (!hash_equals($expected_signature, $signature)) {
status_header(401);
echo 'Bad Signature';
exit;
}
global $wpdb;
$dt = $wpdb->prefix . 'diglib_donations';
$new_status = 'pending';
if ($result_code === '00') $new_status = 'success';
elseif ($result_code === '02') $new_status = 'failed';
$wpdb->update($dt, [
'status'  => $new_status,
'paid_at' => ($new_status === 'success') ? current_time('mysql') : null,
], ['merchant_order_id' => $merchant_order_id]);
if ($new_status === 'success') {
$row = $wpdb->get_row($wpdb->prepare(
"SELECT donor_email, donor_name, amount FROM $dt WHERE merchant_order_id=%s",
$merchant_order_id
));
if ($row && is_email($row->donor_email)) {
diglib_send_donation_thanks_email($row->donor_email, $row->donor_name, $row->amount);
}
}
status_header(200);
echo 'OK';
exit;
}
add_action('init', 'diglib_handle_duitku_return');
function diglib_handle_duitku_return() {
if (!isset($_GET['diglib_duitku_return'])) return;
$order_id = sanitize_text_field($_GET['order'] ?? '');
global $wpdb;
$dt = $wpdb->prefix . 'diglib_donations';
$donation = $order_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $dt WHERE merchant_order_id=%s", $order_id)) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Status Donasi — Perpus Digital Astronomi</title>
<style>
body{margin:0;background:#f4f6fb;color:#101828;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.6;}
.wrap{max-width:560px;margin:48px auto;padding:28px;background:#fff;border:1px solid #e4e8f1;border-radius:8px;box-shadow:0 2px 10px rgba(16,24,40,.06);text-align:center;}
h1{font-size:1.5rem;margin-bottom:10px;}
p{color:#3d4759;}
.status{display:inline-block;padding:6px 14px;border-radius:20px;font-weight:700;font-size:.85rem;margin:14px 0;}
.status.pending{background:#fff8e6;color:#9a6700;border:1px solid #f0dcae;}
.status.success{background:#e9f7ef;color:#1e8e5a;border:1px solid #bfe6d0;}
.status.failed{background:#fdecea;color:#c0392b;border:1px solid #f5c6c0;}
a.btn{display:inline-block;background:#00278a;color:#fff;text-decoration:none;padding:11px 22px;border-radius:6px;font-weight:600;margin-top:12px;}
</style>
</head>
<body>
<div class="wrap">
<h1>Status Donasi</h1>
<?php if ($donation): ?>
<?php $s = $donation->status; ?>
<div class="status <?= esc_attr($s) ?>"><?= esc_html(ucfirst($s)) ?></div>
<p><strong>Rp <?= number_format($donation->amount, 0, ',', '.') ?></strong></p>
<p>ID: <code><?= esc_html($donation->merchant_order_id) ?></code></p>
<?php if ($s === 'pending'): ?>
<p>Terima kasih! Pembayaran masih menunggu konfirmasi. Kami akan memberi tahu kamu via email begitu pembayaran berhasil.</p>
<?php elseif ($s === 'success'): ?>
<p>Terima kasih banyak! Donasimu sudah kami terima. Semoga Allah membalas dengan yang lebih baik.</p>
<?php else: ?>
<p>Pembayaran tidak berhasil. Kamu bisa mencoba lagi dengan nominal atau metode yang berbeda.</p>
<?php endif; ?>
<?php else: ?>
<p>Transaksi tidak ditemukan.</p>
<?php endif; ?>
<a href="<?= esc_url(home_url('/')) ?>" class="btn">Kembali ke Beranda</a>
</div>
</body>
</html>
<?php exit;
}
function diglib_send_donation_thanks_email($email, $name, $amount) {
$subject = 'Terima kasih atas donasimu — Perpus Digital Astronomi';
$greeting = $name ? "Halo {$name}," : "Halo,";
$message  = "<html><body>";
$message .= "<p>{$greeting}</p>";
$message .= "<p>Terima kasih banyak atas donasi sebesar <strong>Rp " . number_format($amount, 0, ',', '.') . "</strong> kepada Perpus Digital Astronomi.</p>";
$message .= "<p>Dukunganmu membantu kami terus menyediakan ebook astronomi berkualitas, menjaga server tetap aktif, dan menjangkau lebih banyak pembaca di seluruh Indonesia.</p>";
$message .= "<p>Semoga kebaikanmu dibalas berlipat ganda. 🌌</p>";
$message .= "<p>Salam hangat,<br>Tim Perpus Digital Astronomi</p>";
$message .= "</body></html>";
wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}
// =============================================
// CSS & JS
// =============================================
add_action('wp_head', function() { ?>
<style>
:root{
--white:#ffffff;
--blue:#00278a;
--blue-dark:#001c66;
--tint:#eaf0fb;
--tint2:#f7f9fe;
--ink:#101828;
--ink2:#3d4759;
--mut:#5b6478;
--line:#e4e8f1;
--green:#1e8e5a;
--red:#c0392b;
--amber:#9a6700;
--r:8px; --r-sm:6px;
--shadow:0 2px 10px rgba(16,24,40,.06);
--shadow-lg:0 10px 28px rgba(16,24,40,.10);
--nh:58px;
--mp:16px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
.diglib-site-header,.diglib-site-footer,header.site-header,footer.site-footer,#masthead,#colophon{display:none!important;}
::selection{background:var(--blue);color:#fff;}
html,body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;background:var(--white)!important;color:var(--ink)!important;font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased;overflow-x:hidden;}
a{color:var(--blue);}
a:focus-visible,button:focus-visible,input:focus-visible,summary:focus-visible{outline:2px solid var(--blue);outline-offset:2px;}
.dl-root{display:flex;flex-direction:column;min-height:100vh;position:relative;}
.dl-ico{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;}
.dl-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:var(--r-sm);border:1px solid transparent;font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none!important;transition:background .15s,border-color .15s,color .15s;}
.dl-btn-solid{background:var(--blue);color:#fff!important;}
.dl-btn-solid:hover{background:var(--blue-dark);}
.dl-btn-outline{background:var(--white);color:var(--blue)!important;border-color:var(--blue);}
.dl-btn-outline:hover{background:var(--tint);}
.dl-btn-sm{padding:8px 16px;font-size:.82rem;}
.dl-btn-block{width:100%;}
.dl-header{position:sticky;top:0;z-index:500;background:var(--white);border-bottom:1px solid var(--line);}
.dl-header-top{max-width:1200px;margin:0 auto;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.dl-brand{display:flex;align-items:center;gap:10px;text-decoration:none!important;}
.dl-brand-img{width:36px;height:36px;border-radius:8px;overflow:hidden;border:1px solid var(--line);flex-shrink:0;}
.dl-brand-img img{width:100%;height:100%;object-fit:cover;display:block;}
.dl-brand-name{font-size:1.02rem;font-weight:800;color:var(--ink);line-height:1.2;}
.dl-brand-name small{display:block;font-size:.66rem;font-weight:500;color:var(--mut);letter-spacing:.04em;}
.dl-header-actions{display:flex;gap:8px;align-items:center;min-width:0;}
.dl-user-chip{font-size:.76rem;color:var(--mut);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dl-logout-link{font-size:.76rem;font-weight:700;color:var(--red)!important;text-decoration:none!important;border:1px solid currentColor;border-radius:var(--r-sm);padding:7px 12px;white-space:nowrap;}
.dl-header-nav{border-top:1px solid var(--line);}
.dl-header-nav-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:4px;overflow-x:auto;}
.dl-nav-link{position:relative;padding:13px 14px;font-size:.88rem;font-weight:600;color:var(--ink2);text-decoration:none!important;white-space:nowrap;background:none;border:none;cursor:pointer;font-family:inherit;}
.dl-nav-link:hover{color:var(--blue);}
.dl-nav-link.on{color:var(--blue);}
.dl-nav-link.on::after{content:'';position:absolute;left:14px;right:14px;bottom:0;height:3px;background:var(--blue);border-radius:2px 2px 0 0;}
.dl-modal{display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;padding:20px;}
.dl-modal.open{display:flex;}
.dl-modal-backdrop{position:absolute;inset:0;background:rgba(16,24,40,.5);}
.dl-modal-box{position:relative;background:var(--white);border-radius:var(--r);max-width:560px;width:100%;max-height:85vh;overflow:auto;box-shadow:var(--shadow-lg);}
.dl-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;background:var(--blue);color:#fff;border-radius:var(--r) var(--r) 0 0;}
.dl-modal-head h3{font-size:1.05rem;font-weight:700;}
.dl-modal-x{background:none;border:none;color:#fff;font-size:1.4rem;line-height:1;cursor:pointer;padding:2px 6px;}
.dl-modal-body{padding:22px;}
.dl-howto-list{list-style:none;counter-reset:howto;display:flex;flex-direction:column;gap:14px;}
.dl-howto-list li{counter-increment:howto;display:flex;gap:12px;font-size:.92rem;color:var(--ink2);}
.dl-howto-list li::before{content:counter(howto);flex-shrink:0;width:26px;height:26px;border-radius:50%;background:var(--tint);color:var(--blue);font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);}
.dl-howto-note{margin-top:18px;padding:12px 14px;background:var(--tint2);border:1px solid var(--line);border-radius:var(--r-sm);font-size:.82rem;color:var(--mut);}
.dl-donate-intro{font-size:.95rem;color:var(--ink2);line-height:1.65;margin-bottom:20px;}
.dl-donate-intro .emoji{font-size:1.1rem;}
.dl-donate-label{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mut);margin-bottom:10px;display:block;}
.dl-donate-amounts{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;}
.dl-donate-amount{padding:12px;border:1.5px solid var(--line);border-radius:var(--r-sm);background:var(--white);font-family:inherit;font-size:.9rem;font-weight:600;color:var(--ink);cursor:pointer;transition:all .15s;text-align:center;}
.dl-donate-amount:hover{border-color:var(--blue);color:var(--blue);}
.dl-donate-amount.selected{background:var(--blue);border-color:var(--blue);color:#fff;}
.dl-donate-custom{margin-bottom:16px;}
.dl-donate-custom-row{display:flex;border:1.5px solid var(--line);border-radius:var(--r-sm);overflow:hidden;}
.dl-donate-custom-row:focus-within{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,39,138,.08);}
.dl-donate-custom-prefix{padding:10px 14px;background:var(--tint2);color:var(--ink2);font-weight:700;font-size:.88rem;border-right:1px solid var(--line);}
.dl-donate-custom-input{flex:1;border:none;outline:none;padding:10px 14px;font-family:inherit;font-size:.95rem;color:var(--ink);background:var(--white);}
.dl-donate-summary{padding:12px 14px;background:var(--tint2);border-radius:var(--r-sm);margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;font-size:.9rem;}
.dl-donate-summary .amount{font-size:1.05rem;font-weight:800;color:var(--blue);}
.dl-donate-form{display:flex;flex-direction:column;gap:10px;margin-bottom:16px;}
.dl-donate-form input{padding:10px 14px;border:1px solid var(--line);border-radius:var(--r-sm);font-family:inherit;font-size:.9rem;color:var(--ink);outline:none;}
.dl-donate-form input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,39,138,.08);}
.dl-donate-foot{font-size:.74rem;color:var(--mut);text-align:center;margin-top:6px;line-height:1.5;}
.dl-donate-err{padding:10px 14px;background:#fdecea;border:1px solid #f5c6c0;color:var(--red);border-radius:var(--r-sm);font-size:.85rem;margin-bottom:14px;display:none;}
.dl-donate-err.show{display:block;}
.dl-donate-loading{display:none;align-items:center;gap:8px;font-size:.88rem;color:var(--mut);}
.dl-donate-loading.show{display:inline-flex;}
.dl-spinner{width:16px;height:16px;border:2px solid var(--line);border-top-color:var(--blue);border-radius:50%;animation:dl-spin 0.8s linear infinite;}
@keyframes dl-spin{to{transform:rotate(360deg);}}
.dl-hero{background:var(--tint);border-bottom:1px solid var(--line);}
.dl-hero-inner{max-width:1200px;margin:0 auto;padding:44px 24px;display:grid;grid-template-columns:1.15fr .85fr;gap:40px;align-items:center;}
.dl-hero-title{font-size:clamp(1.7rem,3.4vw,2.5rem);font-weight:800;color:var(--ink);line-height:1.2;letter-spacing:-.01em;margin-bottom:18px;}
.dl-hero-title .acc{color:var(--blue);}
.dl-hero-search{display:flex;gap:8px;max-width:520px;margin-bottom:16px;}
.dl-hero-search input{flex:1;border:1px solid var(--line);border-radius:var(--r-sm);padding:11px 14px;font-family:inherit;font-size:.95rem;color:var(--ink);background:var(--white);outline:none;}
.dl-hero-search input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,39,138,.08);}
.dl-hero-search input::placeholder{color:var(--mut);}
.dl-hero-ctas{display:flex;gap:10px;flex-wrap:wrap;}
.dl-hero-books{position:relative;height:250px;display:flex;align-items:center;justify-content:center;}
.dl-hb{position:absolute;width:140px;aspect-ratio:2/3;border-radius:6px;overflow:hidden;background:#fff;border:1px solid var(--line);box-shadow:var(--shadow-lg);animation:dl-bob 5s ease-in-out infinite;}
.dl-hb img{width:100%;height:100%;object-fit:cover;}
.dl-hb-1{transform:translateX(-118px) rotate(-8deg);z-index:1;}
.dl-hb-2{transform:translateY(-14px);z-index:2;animation-delay:.6s;}
.dl-hb-3{transform:translateX(118px) rotate(8deg);z-index:1;animation-delay:1.2s;}
@keyframes dl-bob{0%,100%{margin-top:0;}50%{margin-top:-12px;}}
.dl-main{flex:1;width:100%;max-width:1200px;margin:0 auto;padding:32px 24px 56px;}
.dl-topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap;}
.dl-page-title{font-size:1.5rem;font-weight:800;color:var(--ink);}
.dl-page-sub{font-size:.88rem;color:var(--mut);margin-top:2px;}
.dl-search-bar{display:flex;gap:8px;}
.dl-search-bar input{border:1px solid var(--line);border-radius:var(--r-sm);padding:9px 14px;font-family:inherit;font-size:.88rem;color:var(--ink);width:240px;outline:none;background:#fff;}
.dl-search-bar input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,39,138,.08);}
.dl-cat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:8px;}
.dl-cat-card{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:20px 14px;text-align:center;text-decoration:none!important;display:flex;flex-direction:column;align-items:center;gap:8px;transition:border-color .15s,transform .15s,box-shadow .15s;}
.dl-cat-card:hover{border-color:var(--blue);transform:translateY(-3px);box-shadow:var(--shadow);}
.dl-cat-icon{font-size:2.1rem;line-height:1;}
.dl-cat-title{font-size:.95rem;font-weight:700;color:var(--ink);}
.dl-cat-count{font-size:.76rem;color:var(--mut);font-weight:500;}
.dl-sec{margin-bottom:40px;}
.dl-sec-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:16px;}
.dl-sec-head h3{font-size:1.15rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:10px;}
.dl-sec-head h3::before{content:'';width:4px;height:18px;background:var(--blue);border-radius:2px;}
.dl-sec-head a{font-size:.82rem;font-weight:600;color:var(--blue);text-decoration:none!important;}
.dl-sec-head a:hover{text-decoration:underline!important;}
.dl-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.dl-chip{font-size:.8rem;font-weight:600;padding:7px 14px;border-radius:var(--r-sm);border:1px solid var(--line);background:var(--white);color:var(--ink2)!important;text-decoration:none!important;transition:all .15s;}
.dl-chip:hover{border-color:var(--blue);color:var(--blue)!important;}
.dl-chip.on{background:var(--blue);border-color:var(--blue);color:#fff!important;}
.dl-hscroll{display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory;}
.dl-hscroll::-webkit-scrollbar{height:4px;}
.dl-hscroll::-webkit-scrollbar-thumb{background:var(--line);border-radius:2px;}
.dl-hscroll>*{scroll-snap-align:start;flex-shrink:0;}
.dl-book-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:16px;}
.dl-book-card{background:var(--white);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;text-decoration:none!important;display:flex;flex-direction:column;transition:border-color .15s,transform .15s,box-shadow .15s;}
.dl-book-card:hover{border-color:var(--blue);transform:translateY(-3px);box-shadow:var(--shadow);}
.dl-book-card.full{opacity:.55;}
.dl-book-cover{position:relative;aspect-ratio:2/3;background:var(--tint2);border-bottom:1px solid var(--line);overflow:hidden;}
.dl-book-cover img{width:100%;height:100%;object-fit:cover;display:block;}
.dl-book-cover-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:var(--mut);}
.dl-book-body{padding:12px 14px;flex:1;display:flex;flex-direction:column;gap:3px;}
.dl-book-body .ttl{font-size:.9rem;font-weight:600;color:var(--ink);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.dl-book-body .auth{font-size:.76rem;color:var(--mut);}
.dl-badge{position:absolute;top:8px;left:8px;font-size:.6rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 8px;border-radius:4px;border:1px solid;}
.dl-badge.av{background:#e9f7ef;color:var(--green);border-color:#bfe6d0;}
.dl-badge.fu{background:#fdecea;color:var(--red);border-color:#f5c6c0;}
.dl-feat-card{width:180px;background:var(--white);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;text-decoration:none!important;display:flex;flex-direction:column;transition:border-color .15s,transform .15s,box-shadow .15s;}
.dl-feat-card:hover{border-color:var(--blue);transform:translateY(-3px);box-shadow:var(--shadow);}
.dl-feat-cover{aspect-ratio:2/3;border-bottom:1px solid var(--line);overflow:hidden;position:relative;background:var(--tint2);}
.dl-feat-cover img{width:100%;height:100%;object-fit:cover;}
.dl-feat-cover-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:var(--mut);}
.dl-feat-body{padding:12px 14px;}
.dl-feat-body .cat{font-size:.62rem;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:4px;}
.dl-feat-body .ttl{font-size:.9rem;font-weight:600;color:var(--ink);line-height:1.35;}
.dl-feat-body .auth{font-size:.76rem;color:var(--mut);margin-top:3px;}
.dl-mission-inner{display:grid;grid-template-columns:1fr 2fr;gap:36px;background:var(--tint2);border:1px solid var(--line);border-left:4px solid var(--blue);border-radius:var(--r);padding:30px 34px;}
.dl-mission-lead{font-size:1.12rem;font-weight:700;color:var(--ink);line-height:1.55;}
.dl-mission-body{display:flex;flex-direction:column;gap:12px;}
.dl-mission-body p{font-size:.9rem;color:var(--ink2);}
.dl-back{display:inline-flex;align-items:center;gap:6px;color:var(--mut)!important;text-decoration:none!important;font-size:.84rem;font-weight:600;margin-bottom:24px;}
.dl-back:hover{color:var(--blue)!important;}
.dl-back svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;}
.dl-detail-hero{display:grid;grid-template-columns:240px 1fr;gap:44px;align-items:start;margin-bottom:36px;}
.dl-detail-cover-wrap{position:sticky;top:120px;}
.dl-detail-cover{aspect-ratio:2/3;border-radius:var(--r);overflow:hidden;background:var(--tint2);border:1px solid var(--line);box-shadow:var(--shadow-lg);}
.dl-detail-cover img{width:100%;height:100%;object-fit:cover;}
.dl-detail-cover-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2.6rem;color:var(--mut);}
.dl-detail-meta{display:flex;flex-direction:column;gap:18px;min-width:0;}
.dl-detail-tags{display:flex;flex-wrap:wrap;gap:8px;}
.dl-tag{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:5px 10px;border-radius:4px;background:var(--tint);color:var(--blue);border:1px solid var(--line);}
.dl-detail-title{font-size:2rem;font-weight:800;color:var(--ink);line-height:1.15;}
.dl-detail-author{font-size:.95rem;color:var(--mut);margin-top:6px;}
.dl-detail-desc{font-size:.94rem;color:var(--ink2);line-height:1.75;padding-bottom:18px;border-bottom:1px solid var(--line);}
.dl-stock{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;}
.dl-stock .lbl{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut);display:block;}
.dl-stock .val{font-size:1.2rem;font-weight:800;color:var(--ink);}
.dl-stock-track{flex:1;height:6px;background:var(--tint);border-radius:3px;overflow:hidden;min-width:120px;}
.dl-stock-fill{height:100%;background:var(--blue);border-radius:3px;}
.dl-stock .dl-badge{position:static;}
.dl-wl-badge{font-size:.68rem;font-weight:700;color:var(--amber);border:1px solid #f0dcae;background:#fff8e6;border-radius:4px;padding:3px 8px;}
.dl-borrow-form-box{background:var(--tint2);border:1px solid var(--line);border-radius:var(--r);padding:22px;display:flex;flex-direction:column;gap:16px;}
.dl-login-note{font-size:.8rem;color:var(--green);background:#e9f7ef;border:1px solid #bfe6d0;border-radius:var(--r-sm);padding:8px 12px;}
.dl-form-group{margin-bottom:0;}
.dl-label{display:block;font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mut);margin-bottom:6px;}
.dl-input{width:100%;padding:11px 14px;background:var(--white);border:1px solid var(--line);border-radius:var(--r-sm);font-family:inherit;font-size:.95rem;color:var(--ink);outline:none;transition:border-color .15s,box-shadow .15s;}
.dl-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,39,138,.08);}
.dl-input::placeholder{color:var(--mut);}
.dl-alert{padding:13px 16px;border-radius:var(--r-sm);font-size:.9rem;line-height:1.6;border:1px solid;background:var(--white);margin-bottom:16px;display:flex;flex-direction:column;gap:8px;}
.dl-alert.ok{background:#e9f7ef;border-color:#bfe6d0;color:var(--green);}
.dl-alert.err{background:#fdecea;border-color:#f5c6c0;color:var(--red);}
.dl-alert.wrn{background:#fff8e6;border-color:#f0dcae;color:var(--amber);}
.dl-alert-cta{display:inline-flex;align-self:flex-start;padding:8px 14px;background:var(--blue);color:#fff!important;border-radius:var(--r-sm);font-weight:600;font-size:.82rem;text-decoration:none!important;}
.dl-alert-cta:hover{background:var(--blue-dark);}
/* Waiting list box */
.dl-waitlist-box{background:var(--white);border:1px dashed var(--line);border-radius:var(--r-sm);padding:18px;text-align:center;}
.dl-waitlist-box p{font-size:.88rem;color:var(--ink2);margin-bottom:12px;}
.dl-waitlist-box .dl-input{margin-bottom:10px;text-align:center;}
/* Tombol perpanjang */
.dl-extend-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;background:transparent;color:var(--blue)!important;border:1px solid var(--blue);border-radius:var(--r-sm);font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .15s;}
.dl-extend-btn:hover{background:var(--tint);}
.dl-extend-note{font-size:.7rem;color:var(--mut);white-space:nowrap;}
.dl-share-wrap{padding-top:16px;border-top:1px solid var(--line);}
.dl-share-title{font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mut);margin-right:10px;}
.dl-share-links{display:inline-flex;flex-wrap:wrap;align-items:center;gap:14px;}
.dl-share-links a,.dl-share-links button{background:none;border:none;padding:0;font-family:inherit;font-size:.84rem;font-weight:600;color:var(--blue)!important;text-decoration:none!important;cursor:pointer;}
.dl-share-links a:hover,.dl-share-links button:hover{text-decoration:underline!important;}
.dl-review-wrap{padding-top:16px;border-top:1px solid var(--line);}
.dl-review-avg{display:flex;align-items:center;gap:10px;margin:10px 0 6px;font-size:.88rem;color:var(--ink2);}
.dl-star{color:var(--blue);font-size:1.05rem;}
.dl-star.off{color:#cdd4e2;}
.dl-review-item{padding:14px 0;border-top:1px solid var(--line);}
.dl-review-item .rv-text{font-size:.92rem;color:var(--ink2);margin-top:6px;}
.dl-review-item .rv-meta{font-size:.72rem;color:var(--mut);margin-top:6px;}
.dl-review-empty{color:var(--mut);font-size:.88rem;margin-top:8px;}
.dl-check-card{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:26px;margin-bottom:24px;max-width:560px;box-shadow:var(--shadow);}
.dl-check-card h3{font-size:1.2rem;font-weight:800;color:var(--ink);margin-bottom:6px;}
.dl-check-card p{font-size:.9rem;color:var(--mut);margin-bottom:18px;}
.dl-loans-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
.dl-loan-card{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:18px;display:flex;gap:14px;box-shadow:var(--shadow);animation:fadein .3s ease both;}
.dl-loan-thumb{width:64px;height:92px;border-radius:6px;overflow:hidden;flex-shrink:0;background:var(--tint2);border:1px solid var(--line);}
.dl-loan-thumb img{width:100%;height:100%;object-fit:cover;}
.dl-loan-thumb-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:var(--mut);}
.dl-loan-info{flex:1;min-width:0;}
.dl-loan-cat{font-size:.62rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--blue);display:block;margin-bottom:4px;}
.dl-loan-title{font-size:.95rem;font-weight:700;color:var(--ink);line-height:1.3;margin-bottom:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.dl-loan-author{font-size:.8rem;color:var(--mut);margin-bottom:10px;display:block;}
.dl-expiry{font-size:.76rem;font-weight:700;color:var(--green);}
.dl-expiry.urgent{color:var(--red);}
.dl-loan-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-end;justify-content:center;flex-shrink:0;}
.dl-loan-actions form{margin:0;}
.dl-read-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:var(--blue);color:#fff!important;border-radius:var(--r-sm);font-size:.8rem;font-weight:700;text-decoration:none!important;white-space:nowrap;}
.dl-read-btn:hover{background:var(--blue-dark);}
.dl-return-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;background:transparent;color:var(--mut)!important;border:1px solid var(--line);border-radius:var(--r-sm);font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;white-space:nowrap;}
.dl-return-btn:hover{border-color:var(--red);color:var(--red)!important;}
.dl-read-btn svg,.dl-return-btn svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2;}
.dl-faq-list{display:flex;flex-direction:column;gap:8px;}
.dl-faq-item{background:var(--white);border:1px solid var(--line);border-radius:var(--r-sm);overflow:hidden;}
.dl-faq-q{list-style:none;padding:15px 18px;font-size:.92rem;font-weight:600;color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.dl-faq-q::-webkit-details-marker{display:none;}
.dl-faq-q::after{content:'+';font-size:1.2rem;font-weight:700;color:var(--blue);flex-shrink:0;}
details[open] .dl-faq-q::after{content:'–';}
details[open] .dl-faq-q{color:var(--blue);}
.dl-faq-a{padding:0 18px 16px;font-size:.88rem;color:var(--ink2);line-height:1.75;}
.dl-empty{text-align:center;padding:60px 20px;color:var(--mut);grid-column:1/-1;}
.dl-empty .ico{font-size:2.4rem;margin-bottom:12px;color:var(--blue);}
.dl-empty h3{font-size:1.2rem;font-weight:800;color:var(--ink);margin-bottom:6px;}
.dl-empty p{font-size:.9rem;}
.dl-pagination{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:26px;}
.dl-pagination a,.dl-pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border:1px solid var(--line);border-radius:var(--r-sm);background:var(--white);color:var(--ink2);text-decoration:none;font-size:.85rem;font-weight:600;}
.dl-pagination a:hover{border-color:var(--blue);color:var(--blue);}
.dl-pagination span.current{background:var(--blue);border-color:var(--blue);color:#fff;}
.dl-mobile-header{padding:16px 16px 8px;display:none;}
.dl-mobile-header h1{font-size:1.3rem;font-weight:800;color:var(--ink);}
.dl-mobile-header p{font-size:.85rem;color:var(--mut);margin-top:2px;}
.dl-mobile-search{display:none;gap:8px;margin:0 16px 16px;}
.dl-mobile-search input{flex:1;border:1px solid var(--line);border-radius:var(--r-sm);padding:10px 14px;font-family:inherit;font-size:.9rem;color:var(--ink);outline:none;background:#fff;}
.dl-mobile-authbar{display:none;padding:0 16px 12px;text-align:right;}
.dl-mobile-authbar a{font-size:.76rem;font-weight:700;color:var(--red)!important;text-decoration:none!important;border:1px solid currentColor;border-radius:var(--r-sm);padding:6px 10px;}
.dl-reader-root{background:var(--white);min-height:100vh;position:relative;z-index:9999;}
.dl-reader-bar{background:var(--white);height:54px;display:flex;align-items:center;gap:14px;padding:0 20px;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:100;}
.dl-reader-back{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--r-sm);background:var(--tint);color:var(--blue)!important;text-decoration:none!important;flex-shrink:0;}
.dl-reader-back:hover{background:var(--line);}
.dl-reader-back svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;}
.dl-reader-bar h2{font-size:.95rem;font-weight:700;color:var(--ink);flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
.dl-reader-frame{position:relative;z-index:1;}
.dl-reader-frame iframe{display:block;width:100%;height:calc(100vh - 54px);border:none;}
.dl-reader-watermark{position:fixed;right:14px;bottom:14px;z-index:10000;pointer-events:none;color:var(--blue);background:rgba(255,255,255,.85);border:1px solid var(--line);padding:6px 10px;border-radius:4px;font-size:.68rem;text-align:right;}
.dl-reader-watermark-center{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-24deg);z-index:9999;pointer-events:none;font-size:2rem;font-weight:800;color:rgba(0,39,138,.05);white-space:nowrap;letter-spacing:.08em;text-transform:uppercase;}
.dl-footer{background:var(--white);border-top:1px solid var(--line);padding:44px 0 0;margin-top:auto;}
.dl-footer-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:44px;}
.dl-footer-brand-img{width:36px;height:36px;border-radius:8px;overflow:hidden;border:1px solid var(--line);margin-bottom:12px;}
.dl-footer-brand-img img{width:100%;height:100%;object-fit:cover;display:block;}
.dl-footer-brand-name{font-size:1rem;font-weight:800;color:var(--ink);margin-bottom:8px;}
.dl-footer-brand-desc{font-size:.84rem;color:var(--mut);line-height:1.7;max-width:32ch;}
.dl-footer-col h4{font-size:.66rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--blue);margin-bottom:14px;}
.dl-footer-links{display:flex;flex-direction:column;gap:9px;}
.dl-footer-links a{font-size:.86rem;color:var(--ink2)!important;text-decoration:none!important;}
.dl-footer-links a:hover{color:var(--blue)!important;text-decoration:underline!important;}
.dl-footer-bottom{max-width:1200px;margin:36px auto 0;padding:16px 24px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.dl-footer-copy{font-size:.76rem;color:var(--mut);}
@keyframes fadein{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}
.diglib-bnav{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--white);border-top:1px solid var(--line);justify-content:space-around;padding:8px 0 env(safe-area-inset-bottom,10px);z-index:200;}
.diglib-ni{display:flex;flex-direction:column;align-items:center;gap:3px;font-size:.58rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mut)!important;padding:6px 18px;text-decoration:none!important;}
.diglib-ni.on{color:var(--blue)!important;}
.diglib-ni svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;}
@media(max-width:900px){
.dl-header-top{padding:10px var(--mp);gap:10px;}
.dl-header-top .dl-btn{font-size:.72rem;padding:7px 12px;white-space:nowrap;flex-shrink:0;}
.dl-user-chip{display:none;}
.dl-brand-name{font-size:.92rem;}
.dl-header-nav-inner{padding:0 var(--mp);}
.dl-mission,.dl-footer{display:none!important;}
.dl-mobile-header{display:block!important;}
.dl-mobile-authbar{display:block!important;}
.dl-mobile-search{display:flex!important;}
.diglib-bnav{display:flex!important;}
.dl-main{padding:0 0 84px;max-width:100%;}
.dl-sec{padding:0 var(--mp);margin-bottom:28px;}
.dl-sec-head{margin-bottom:14px;}
.dl-hscroll,.dl-cat-grid,.dl-books-new{
display:flex!important;overflow-x:auto!important;scroll-snap-type:x mandatory!important;-webkit-overflow-scrolling:touch;
margin-left:calc(var(--mp) * -1);margin-right:calc(var(--mp) * -1);padding:2px var(--mp) 14px;scroll-padding-left:var(--mp);scrollbar-width:thin;
}
.dl-hscroll>*,.dl-cat-grid>*,.dl-books-new>*{scroll-snap-align:start;flex-shrink:0;}
.dl-hscroll::after,.dl-cat-grid::after,.dl-books-new::after{content:'';flex:0 0 1px;}
.dl-hscroll::-webkit-scrollbar,.dl-cat-grid::-webkit-scrollbar,.dl-books-new::-webkit-scrollbar{height:4px;}
.dl-hscroll::-webkit-scrollbar-thumb,.dl-cat-grid::-webkit-scrollbar-thumb,.dl-books-new::-webkit-scrollbar-thumb{background:var(--line);border-radius:2px;}
.dl-feat-card{width:150px;}
.dl-cat-grid{gap:10px!important;}
.dl-cat-grid .dl-cat-card{flex:0 0 140px!important;padding:16px 10px;}
.dl-books-new{gap:12px!important;}
.dl-books-new .dl-book-card{flex:0 0 150px!important;}
.dl-book-grid{grid-template-columns:repeat(2,1fr);gap:12px;padding:0;}
.dl-hero-inner{grid-template-columns:1fr;gap:28px;padding:32px var(--mp);}
.dl-hero-books{height:220px;transform:scale(.85);}
.dl-detail-hero{grid-template-columns:1fr;gap:12px;}
.dl-detail-cover-wrap{position:static;}
.dl-detail-cover{width:150px;margin:20px auto 8px;}
.dl-detail-meta{padding:12px var(--mp) 0;}
.dl-detail-title{font-size:1.5rem;text-align:center;}
.dl-detail-author{text-align:center;}
.dl-detail-tags{justify-content:center;}
.dl-loans-grid{grid-template-columns:1fr;padding:0 var(--mp);}
.dl-loan-actions{flex-direction:row;align-items:center;flex-wrap:wrap;justify-content:flex-end;}
.dl-check-card{margin:0 var(--mp) 20px;padding:20px;}
.dl-alerts-wrap{padding:0 var(--mp);}
.dl-topbar{padding:16px var(--mp) 0;}
.dl-reader-watermark-center{font-size:1.1rem;}
}
@media(min-width:901px){
.dl-mobile-header,.dl-mobile-search,.dl-mobile-authbar{display:none!important;}
}
.df-ui-btn-download,.df-ui-download,.df-ui-share{display:none!important;}
</style>
<script>
(function(){
var DL_CONFIG = {
ajaxUrl: <?= json_encode(admin_url('admin-ajax.php')) ?>,
donateNonce: <?= json_encode(wp_create_nonce('diglib_donate_nonce')) ?>,
};
window.DL_CONFIG = DL_CONFIG;
document.addEventListener('DOMContentLoaded',function(){
document.querySelectorAll('.diglib-ni').forEach(function(btn){
var h=btn.getAttribute('data-href')||'';
btn.classList.toggle('on',window.location.pathname.indexOf(h)!==-1&&h!=='');
});
function openModal(id){
var m=document.getElementById(id);
if(m){m.classList.add('open');document.body.style.overflow='hidden';}
}
function closeAllModals(){
document.querySelectorAll('.dl-modal.open').forEach(function(m){m.classList.remove('open');});
document.body.style.overflow='';
}
document.addEventListener('click',function(e){
if(e.target.closest('.dl-open-howto')){e.preventDefault();openModal('dl-howto-modal');}
if(e.target.closest('.dl-open-donate')){e.preventDefault();openModal('dl-donate-modal');}
if(e.target.closest('[data-dl-close]')){closeAllModals();}
});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAllModals();});
var amountInputs = document.querySelectorAll('.dl-donate-amount');
var customInput = document.getElementById('dl-donate-custom-amount');
var summaryAmount = document.getElementById('dl-donate-summary-amount');
function formatRp(n){ return 'Rp' + Number(n||0).toLocaleString('id-ID'); }
function updateSummary(){
var amt = parseInt(customInput.value, 10) || 0;
if (summaryAmount) summaryAmount.textContent = formatRp(amt);
}
amountInputs.forEach(function(btn){
btn.addEventListener('click', function(){
amountInputs.forEach(function(b){b.classList.remove('selected');});
btn.classList.add('selected');
var val = btn.getAttribute('data-amount');
if (customInput) { customInput.value = val; updateSummary(); }
});
});
if (customInput) {
customInput.addEventListener('input', function(){
var val = parseInt(customInput.value.replace(/\D/g,''), 10) || 0;
customInput.value = val || '';
amountInputs.forEach(function(b){
b.classList.toggle('selected', b.getAttribute('data-amount') == val);
});
updateSummary();
});
if (!customInput.value) {
customInput.value = '25000';
amountInputs.forEach(function(b){
if (b.getAttribute('data-amount') === '25000') b.classList.add('selected');
});
updateSummary();
}
}
var form = document.getElementById('dl-donate-form');
if (form) {
form.addEventListener('submit', function(e){
e.preventDefault();
var amount = parseInt(document.getElementById('dl-donate-custom-amount').value, 10) || 0;
var name = document.getElementById('dl-donate-name').value.trim();
var email = document.getElementById('dl-donate-email').value.trim();
var paymentMethod = document.getElementById('dl-donate-payment').value;
var err = document.getElementById('dl-donate-err');
var loading = document.getElementById('dl-donate-loading');
var btn = document.getElementById('dl-donate-submit');
err.classList.remove('show');
if (amount < 10000) { err.textContent = 'Nominal donasi minimal Rp10.000.'; err.classList.add('show'); return; }
if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { err.textContent = 'Email tidak valid.'; err.classList.add('show'); return; }
if (!paymentMethod) { err.textContent = 'Pilih metode pembayaran.'; err.classList.add('show'); return; }
btn.disabled = true;
loading.classList.add('show');
var fd = new FormData();
fd.append('action', 'diglib_create_donation');
fd.append('nonce', DL_CONFIG.donateNonce);
fd.append('amount', amount);
fd.append('donor_name', name);
fd.append('donor_email', email);
fd.append('payment_method', paymentMethod);
fetch(DL_CONFIG.ajaxUrl, {method:'POST', body: fd, credentials:'same-origin'})
.then(function(r){ return r.json(); })
.then(function(res){
btn.disabled = false;
loading.classList.remove('show');
if (res.success && res.data && res.data.payment_url) {
window.location.href = res.data.payment_url;
} else {
err.textContent = (res.data && res.data.message) || 'Terjadi kesalahan. Silakan coba lagi.';
err.classList.add('show');
}
})
.catch(function(){
btn.disabled = false;
loading.classList.remove('show');
err.textContent = 'Koneksi gagal. Periksa jaringanmu dan coba lagi.';
err.classList.add('show');
});
});
}
document.addEventListener('contextmenu',function(e){if(e.target.closest('.dl-reader-frame'))e.preventDefault();});
document.addEventListener('dragstart',function(e){if(e.target.tagName==='IMG'||e.target.closest('.dl-reader-frame'))e.preventDefault();});
});
})();
</script>
<?php });
/* ── FOOTER MOBILE NAV ── */
add_action('wp_footer',function(){
if(is_admin())return;
$cur=$_SERVER['REQUEST_URI'];
$home=home_url('/');$rak=home_url('/rak-ebook/');$pinj=home_url('/peminjaman-saya/');
$ih=strpos($cur,'/rak-ebook/')===false&&strpos($cur,'/peminjaman-saya/')===false&&strpos($cur,'/borrow/')===false&&strpos($cur,'/baca-ebook/')===false;
$is=strpos($cur,'/rak-ebook/')!==false;
$il=strpos($cur,'/peminjaman-saya/')!==false;
echo '
<nav class="diglib-bnav">
<a href="'.esc_url($home).'" class="diglib-ni'.($ih?' on':'').'" data-href="/">
<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
Beranda
</a>
<a href="'.esc_url($rak).'" class="diglib-ni'.($is?' on':'').'" data-href="/rak-ebook/">
<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
Rak Buku
</a>
<a href="'.esc_url($pinj).'" class="diglib-ni'.($il?' on':'').'" data-href="/peminjaman-saya/">
<svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
Pinjaman
</a>
</nav>';
});
/* ── HELPERS ── */
if(!function_exists('diglib_cat_icon')){
function diglib_cat_icon($cat){
$m=['Astronomi'=>'✨','Kosmologi'=>'⭐','Majalah Astronomi'=>'📔','Lubang Hitam'=>'⚫','Astrofisika'=>'📐','Perbintangan'=>'🌟','Alien'=>'👽','Astrofotografi'=>'📷','Eksoplanet'=>'🪐','Multiverse'=>'🌌','Astrobiologi'=>'🧬'];
return $m[$cat]??'🌟';
}
}
function diglib_render_navbar($active='home'){
$home=home_url('/');$rak=home_url('/rak-ebook/');$pinj=home_url('/peminjaman-saya/');
$donation_active = diglib_donation_enabled();
$auth = diglib_is_authenticated();
ob_start();?>
<header class="dl-header">
<div class="dl-header-top">
<a href="<?=esc_url($home)?>" class="dl-brand">
<div class="dl-brand-img"><img src="https://belajarastro.com/wp-content/uploads/2023/10/foto-profil-ba.jpg" alt="Belajar Astronomi"></div>
<div class="dl-brand-name">Perpus Digital Astronomi<small>Koleksi Ebook Ilmiah</small></div>
</a>
<div class="dl-header-actions">
<?php if($auth): ?>
<span class="dl-user-chip" title="<?=esc_attr($auth)?>"><?=esc_html($auth)?></span>
<a href="<?=esc_url($pinj)?>" class="dl-btn dl-btn-outline dl-btn-sm">Pinjaman Saya</a>
<a href="?diglib_logout=1" class="dl-logout-link">Keluar</a>
<?php else: ?>
<a href="<?=esc_url($pinj)?>" class="dl-btn dl-btn-outline dl-btn-sm">Pinjaman Saya</a>
<a href="<?=esc_url($pinj)?>" class="dl-btn dl-btn-solid dl-btn-sm">Masuk</a>
<?php endif; ?>
</div>
</div>
<nav class="dl-header-nav">
<div class="dl-header-nav-inner">
<a href="<?=esc_url($home)?>" class="dl-nav-link <?=$active==='home'?'on':''?>" data-href="/"><span class="dl-nav-dot"></span>Beranda</a>
<a href="<?=esc_url($rak)?>" class="dl-nav-link <?=$active==='shelf'?'on':''?>" data-href="/rak-ebook/"><span class="dl-nav-dot"></span>Rak Buku</a>
<a href="#" class="dl-nav-link dl-open-howto"><span class="dl-nav-dot"></span>Cara Pinjam</a>
<?php if ($donation_active): ?>
<a href="#" class="dl-nav-link dl-open-donate" style="color:var(--blue);">❤ Donasi</a>
<?php endif; ?>
</div>
</nav>
</header>
<div class="dl-modal" id="dl-howto-modal" role="dialog" aria-modal="true" aria-label="Cara meminjam ebook">
<div class="dl-modal-backdrop" data-dl-close></div>
<div class="dl-modal-box">
<div class="dl-modal-head">
<h3>Cara Meminjam Ebook</h3>
<button type="button" class="dl-modal-x" data-dl-close aria-label="Tutup">&times;</button>
</div>
<div class="dl-modal-body">
<ol class="dl-howto-list">
<li>Buka halaman Rak Buku dan pilih ebook yang ingin kamu pinjam.</li>
<li>Jika kamu sudah masuk (login) dengan email, kamu bisa langsung klik "Pinjam Ebook". Jika belum, masukkan email dan verifikasi kode 4 digit yang dikirim ke emailmu dulu.</li>
<li>Pinjaman ebook akan aktif selama 3 hari dan bisa diperpanjang untuk 3 hari ke depan lagi sebanyak 1 kali selama tidak ada waiting list pada ebook yang kamu pinjam. Jika ada waiting list, kamu tidak bisa memperpanjang pinjaman agar ebook bisa dipinjamkan ke waiting list dulu.</li>
<li>Buka halaman Pinjaman Saya untuk membaca, melanjutkan, memperpanjang, atau mengembalikan ebook.</li>
<li>Jika ebook penuh, kamu bisa bergabung ke waiting list dulu, sistem kami akan mengirimkan email notifikasi ke kamu saat ebook tersedia untuk kamu pinjam.</li>
</ol>
<div class="dl-howto-note">
Catatan: maksimal 3 pinjaman aktif per email. Login berlaku 30 hari selama kamu tidak keluar. Buku dipinjam untuk dibaca, bukan untuk diunduh.
</div>
</div>
</div>
</div>
<?php if ($donation_active): ?>
<div class="dl-modal" id="dl-donate-modal" role="dialog" aria-modal="true" aria-label="Donasi">
<div class="dl-modal-backdrop" data-dl-close></div>
<div class="dl-modal-box">
<div class="dl-modal-head">
<h3>❤ Dukung Perpustakaan Ini</h3>
<button type="button" class="dl-modal-x" data-dl-close aria-label="Tutup">&times;</button>
</div>
<div class="dl-modal-body">
<p class="dl-donate-intro">Merasa Perpus Digital Astronomi bermanfaat untukmu? <span class="emoji">✨</span><br><br>
Bantu kami untuk terus aktif meningkatkan literasi astronomi di Indonesia dengan terus menyediakan dan memperbarui koleksi ebook berkualitas dan berlisensi resmi dengan berdonasi. Donasi terkumpul akan digunakan untuk membayar sewa server, domain, dan menyediakan lebih banyak ebook.</p>
<div class="dl-donate-err" id="dl-donate-err"></div>
<form id="dl-donate-form" novalidate>
<label class="dl-donate-label">Pilih nominal donasi</label>
<div class="dl-donate-amounts">
<button type="button" class="dl-donate-amount" data-amount="10000">Rp10.000</button>
<button type="button" class="dl-donate-amount" data-amount="25000">Rp25.000</button>
<button type="button" class="dl-donate-amount" data-amount="50000">Rp50.000</button>
<button type="button" class="dl-donate-amount" data-amount="100000">Rp100.000</button>
</div>
<div class="dl-donate-custom">
<label class="dl-donate-label">Atau masukkan nominal sendiri</label>
<div class="dl-donate-custom-row">
<span class="dl-donate-custom-prefix">Rp</span>
<input type="text" id="dl-donate-custom-amount" class="dl-donate-custom-input" inputmode="numeric" placeholder="Contoh: 75000" autocomplete="off">
</div>
</div>
<div class="dl-donate-summary">
<span>Donasi yang akan kamu kirim</span>
<span class="amount" id="dl-donate-summary-amount">Rp0</span>
</div>
<label class="dl-donate-label">Metode pembayaran</label>
<select id="dl-donate-payment" class="dl-input" style="padding:11px 14px;">
<optgroup label="Virtual Account">
<option value="BC">BCA Virtual Account</option>
<option value="M2">Mandiri Virtual Account</option>
<option value="I1">BNI Virtual Account</option>
<option value="BR">BRIVA (BRI)</option>
<option value="BV">BSI Virtual Account</option>
<option value="B1">CIMB Niaga VA</option>
<option value="BT">Permata Bank VA</option>
<option value="VA">Maybank VA</option>
</optgroup>
<optgroup label="E-Wallet">
<option value="DA">DANA</option>
<option value="OV">OVO</option>
<option value="SA">ShopeePay</option>
<option value="LF">LinkAja</option>
</optgroup>
<optgroup label="QRIS">
<option value="SP">QRIS</option>
</optgroup>
</select>
<p style="font-size:.72rem;color:var(--mut);margin:6px 0 0;">Metode di atas adalah yang umum tersedia di Duitku. Jika gagal, coba metode lain.</p>
<div style="margin-top:16px;">
<label class="dl-donate-label">Data donatur (opsional)</label>
<div class="dl-donate-form">
<input type="text" id="dl-donate-name" placeholder="Nama (kosongkan jika ingin anonim)">
<input type="email" id="dl-donate-email" placeholder="Email (untuk bukti terima kasih)" required>
</div>
</div>
<button type="submit" id="dl-donate-submit" class="dl-btn dl-btn-solid dl-btn-block" style="padding:13px 20px;font-size:.95rem;margin-top:16px;">Lanjut ke Pembayaran</button>
<span class="dl-donate-loading" id="dl-donate-loading"><span class="dl-spinner"></span> Membuat invoice pembayaran...</span>
<p class="dl-donate-foot">Pembayaran diproses aman oleh <strong>Duitku</strong>. Kamu akan diarahkan ke halaman pembayaran setelah klik tombol di atas.</p>
</form>
</div>
</div>
</div>
<?php endif; ?>
<?php return ob_get_clean();
}
function diglib_render_footer(){
ob_start();?>
<footer class="dl-footer">
<div class="dl-footer-inner">
<div>
<div class="dl-footer-brand-img"><img src="https://belajarastro.com/wp-content/uploads/2023/10/foto-profil-ba.jpg" alt="Perpus Astronomi Digital"></div>
<div class="dl-footer-brand-name">Perpus Astronomi Digital</div>
<p class="dl-footer-brand-desc">Perpustakaan digital gratis untuk membaca dan meminjam ebook astronomi pilihan — dari kosmologi hingga astrofisika.</p>
</div>
<div class="dl-footer-col">
<h4>Navigasi</h4>
<div class="dl-footer-links">
<a href="https://belajarastro.com/baca/rak-ebook/">Rak Buku</a>
<a href="https://belajarastro.com/baca/peminjaman-saya/">Pinjaman Saya</a>
</div>
</div>
<div class="dl-footer-col">
<h4>Temukan Kami</h4>
<div class="dl-footer-links">
<a href="https://belajarastro.com" target="_blank" rel="noopener">BelajarAstro.com</a>
<a href="https://instagram.com/belajarastro.id" target="_blank" rel="noopener">Instagram</a>
<a href="https://x.com/belajarastro_id" target="_blank" rel="noopener">X (Twitter)</a>
</div>
</div>
</div>
<div class="dl-footer-bottom">
<span class="dl-footer-copy">© 2026 PT Belajar Astronomi Indonesia. Semua hak dilindungi.</span>
</div>
</footer>
<?php return ob_get_clean();
}
/* ── [diglib_home] ── */
add_shortcode('diglib_home',function(){
global $wpdb;
if(function_exists('diglib_expire_loans'))diglib_expire_loans();
$tbl   = $wpdb->prefix.'diglib_ebooks';
$feat  = $wpdb->get_results("SELECT * FROM $tbl ORDER BY RAND() LIMIT 8");
$news  = $wpdb->get_results("SELECT * FROM $tbl ORDER BY RAND() LIMIT 12");
$latest_books = $wpdb->get_results("SELECT * FROM $tbl ORDER BY id DESC LIMIT 6");
$cats  = $wpdb->get_results("SELECT category,COUNT(*) AS cnt FROM $tbl WHERE category!='' GROUP BY category ORDER BY cnt DESC");
$total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tbl");
$avail = (int)$wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE available_copies>0");
$actv  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}diglib_loans WHERE status='active' AND expires_at>NOW()");
$hero_pool = [
'https://infoastronomystore.com/wp-content/uploads/2025/04/cover-digital.png',
'https://belajarastro.com/baca/wp-content/uploads/2026/05/719JU3Z5fkL._AC_UF10001000_QL80_.jpg',
'https://covers.z-lib.sk/covers300/collections/userbooks/b2099e6136904693d1527bd68031528112cd58080f847355d44a53381c5c6cd0.jpg',
'https://belajarastro.com/baca/wp-content/uploads/2026/05/dasardasar2.jpg',
'https://belajarastro.com/baca/wp-content/uploads/2026/05/lightbox.jpg',
'https://belajarastro.com/baca/wp-content/uploads/2026/04/sistem-koordinat-benda-langit.png',
'https://belajarastro.com/baca/wp-content/uploads/2026/03/EBOOK-Fisika-Wormhole.png',
'https://infoastronomystore.com/wp-content/uploads/2026/04/Cover-Majalah-Astronomi-Edisi-12.png',
];
shuffle($hero_pool);
$hero_covers = array_slice($hero_pool, 0, 3);
ob_start();?>
<div class="dl-root">
<?=diglib_render_navbar('home')?>
<section class="dl-hero">
<div class="dl-hero-inner">
<div>
<h1 class="dl-hero-title">Pinjam, Baca, dan Mulai <span class="acc">Jelajahi Semesta</span></h1>
<form action="<?=esc_url(home_url('/rak-ebook/'))?>" method="get">
<div class="dl-hero-search">
<input type="text" name="q" placeholder="Cari judul ebook, penulis, atau topik...">
<button type="submit" class="dl-btn dl-btn-solid">Cari</button>
</div>
</form>
<div class="dl-hero-ctas">
<a href="<?=esc_url(home_url('/rak-ebook/'))?>" class="dl-btn dl-btn-solid">Jelajahi Katalog</a>
<a href="<?=esc_url(home_url('/peminjaman-saya/'))?>" class="dl-btn dl-btn-outline">Pinjaman Saya</a>
</div>
</div>
<div class="dl-hero-books" aria-hidden="true">
<div class="dl-hb dl-hb-1"><img src="<?=esc_url($hero_covers[0])?>" alt=""></div>
<div class="dl-hb dl-hb-2"><img src="<?=esc_url($hero_covers[1])?>" alt=""></div>
<div class="dl-hb dl-hb-3"><img src="<?=esc_url($hero_covers[2])?>" alt=""></div>
</div>
</div>
</section>
<div class="dl-main">
<div class="dl-page-wrap">
<?php if(!empty($feat)):?>
<div class="dl-sec">
<div class="dl-sec-head"><h3>Banyak Dibaca Pekan Ini</h3><a href="<?=esc_url(home_url('/rak-ebook/'))?>">Lihat semua →</a></div>
<div class="dl-hscroll">
<?php foreach($feat as $b):$av=(int)$b->available_copies;$url=esc_url(home_url('/borrow/?book='.$b->id));?>
<a href="<?=$url?>" class="dl-feat-card <?=$av<=0?'full':''?>">
<div class="dl-feat-cover">
<?php if($b->cover_url):?><img src="<?=esc_url($b->cover_url)?>" alt="" loading="lazy"><?php else:?><div class="dl-feat-cover-ph">📖</div><?php endif;?>
<span class="dl-badge <?=$av>0?'av':'fu'?>"><?=$av>0?'Tersedia':'Penuh'?></span>
</div>
<div class="dl-feat-body">
<?php if($b->category):?><span class="cat"><?=esc_html($b->category)?></span><?php endif;?>
<div class="ttl"><?=esc_html($b->title)?></div>
<div class="auth"><?=esc_html($b->author)?></div>
</div>
</a>
<?php endforeach;?>
</div>
</div>
<?php endif;?>
<?php if(!empty($cats)):?>
<div class="dl-sec">
<div class="dl-sec-head"><h3>Jelajahi Kategori</h3></div>
<div class="dl-cat-grid">
<a href="<?=esc_url(home_url('/rak-ebook/'))?>" class="dl-cat-card">
<div class="dl-cat-icon">📚</div>
<div class="dl-cat-title">Semua</div>
<div class="dl-cat-count"><?=$total?> Ebook</div>
</a>
<?php foreach($cats as $c):?>
<a href="<?=esc_url(home_url('/rak-ebook/?cat='.urlencode($c->category)))?>" class="dl-cat-card">
<div class="dl-cat-icon"><?=diglib_cat_icon($c->category)?></div>
<div class="dl-cat-title"><?=esc_html($c->category)?></div>
<div class="dl-cat-count"><?=$c->cnt?> Ebook</div>
</a>
<?php endforeach;?>
</div>
</div>
<?php endif;?>
<?php if(!empty($latest_books)):?>
<div class="dl-sec">
<div class="dl-sec-head"><h3>Buku-buku Baru Masuk</h3><a href="<?=esc_url(home_url('/rak-ebook/'))?>">Lihat semua →</a></div>
<div class="dl-book-grid dl-books-new">
<?php foreach($latest_books as $b):$av=(int)$b->available_copies;$url=esc_url(home_url('/borrow/?book='.$b->id));?>
<a href="<?=$url?>" class="dl-book-card <?=$av<=0?'full':''?>">
<div class="dl-book-cover">
<?php if($b->cover_url):?><img src="<?=esc_url($b->cover_url)?>" alt="" loading="lazy"><?php else:?><div class="dl-book-cover-ph">📖</div><?php endif;?>
<span class="dl-badge <?=$av>0?'av':'fu'?>"><?=$av>0?'Tersedia':'Penuh'?></span>
</div>
<div class="dl-book-body"><div class="ttl"><?=esc_html($b->title)?></div><div class="auth"><?=esc_html($b->author)?></div></div>
</a>
<?php endforeach;?>
</div>
</div>
<?php endif;?>
<?php if(!empty($news)):?>
<div class="dl-sec">
<div class="dl-sec-head"><h3>Pilihan Bacaan Terbaik</h3><a href="<?=esc_url(home_url('/rak-ebook/'))?>">Lihat semua →</a></div>
<div class="dl-book-grid">
<?php foreach($news as $b):$av=(int)$b->available_copies;$url=esc_url(home_url('/borrow/?book='.$b->id));?>
<a href="<?=$url?>" class="dl-book-card <?=$av<=0?'full':''?>">
<div class="dl-book-cover">
<?php if($b->cover_url):?><img src="<?=esc_url($b->cover_url)?>" alt="" loading="lazy"><?php else:?><div class="dl-book-cover-ph">📖</div><?php endif;?>
<span class="dl-badge <?=$av>0?'av':'fu'?>"><?=$av>0?'Tersedia':'Penuh'?></span>
</div>
<div class="dl-book-body"><div class="ttl"><?=esc_html($b->title)?></div><div class="auth"><?=esc_html($b->author)?></div></div>
</a>
<?php endforeach;?>
</div>
</div>
<?php endif;?>
<div class="dl-sec dl-mission">
<div class="dl-sec-head"><h3>Misi Kami</h3></div>
<div class="dl-mission-inner">
<div class="dl-mission-lead">Tujuan utama Perpus Digital Astronomi adalah meningkatkan taraf pendidikan masyarakat, khususnya dalam ilmu pengetahuan alam semesta.</div>
<div class="dl-mission-body">
<p>Kami percaya kunci tujuan ini adalah akses semudah mungkin terhadap buku-buku berkualitas, karena buku selalu menjadi sumber pengetahuan paling berharga sepanjang sejarah.</p>
<p>Misi kami adalah memberikan akses gratis terhadap literatur kepada sebanyak mungkin orang. Buku adalah warisan ilmiah dan budaya umat manusia, dan kami berupaya melestarikannya.</p>
<p>Bagi banyak orang, pendidikan berkualitas mustahil tanpa akses mudah ke pengetahuan. Karena itu prioritas kami adalah literatur bernilai budaya, ilmiah, dan pendidikan yang tinggi.</p>
</div>
</div>
</div>
<div class="dl-sec">
<div class="dl-sec-head"><h3>FAQ</h3></div>
<div class="dl-faq-list">
<?php $faqs=[
['Bagaimana cara pinjam buku?','Pilih buku yang mau dipinjam. Jika sudah masuk (login), pinjaman langsung aktif. Jika belum, masukkan email dan verifikasi kode 4 digit yang dikirim ke emailmu.'],
['Bagaimana cara masuk (login)?','Buka halaman Pinjaman Saya, masukkan email, lalu masukkan kode 4 digit yang kami kirim. Login berlaku 30 hari selama kamu tidak keluar.'],
['Berapa lama waktu pinjam?','Tiga hari. Bisa diperpanjang 1 kali (+3 hari) selama tidak ada waiting list untuk buku tersebut.'],
['Eksemplar habis, bagaimana?','Gabung waiting list. Kami akan mengirim email begitu buku tersedia kembali.'],
['Bolehkah saya unduh bukunya?','Tidak. Di sini buku dipinjam, bukan diunduh. Mengunduh secara ilegal sama dengan membajak.'],
['Berapa batas maksimal peminjaman?','Tiga buku aktif per alamat email.'],
];
foreach($faqs as $faq):?>
<details class="dl-faq-item">
<summary class="dl-faq-q"><?=esc_html($faq[0])?></summary>
<div class="dl-faq-a"><?=esc_html($faq[1])?></div>
</details>
<?php endforeach;?>
</div>
</div>
<div style="text-align:center;padding:24px 20px 8px;font-size:0.62rem;letter-spacing:3px;font-weight:700;color:rgba(16,24,40,.3);text-transform:uppercase">Papua bukan tanah kosong</div>
</div>
</div>
<?=diglib_render_footer()?>
</div>
<?php return ob_get_clean();
});
/* ── [diglib_shelf] ── */
add_shortcode('diglib_shelf',function(){
global $wpdb;
if(function_exists('diglib_expire_loans'))diglib_expire_loans();
$tbl    = $wpdb->prefix.'diglib_ebooks';
$search = sanitize_text_field($_GET['q']??'');
$cat    = sanitize_text_field($_GET['cat']??'');
$cats   = $wpdb->get_col("SELECT DISTINCT category FROM $tbl WHERE category!='' ORDER BY category ASC");
$parts  = [];
if($search)$parts[]=$wpdb->prepare("(title LIKE %s OR author LIKE %s)","%$search%","%$search%");
if($cat)$parts[]=$wpdb->prepare("category=%s",$cat);
$where  = $parts?'WHERE '.implode(' AND ',$parts):'';
$ebooks = $wpdb->get_results("SELECT * FROM $tbl $where ORDER BY title ASC");
ob_start();?>
<div class="dl-root">
<?=diglib_render_navbar('shelf')?>
<div class="dl-main"><div class="dl-page-wrap">
<div class="dl-topbar">
<div>
<h2 class="dl-page-title">Rak Buku</h2>
<p class="dl-page-sub"><?=count($ebooks)?> ebook ditemukan<?=$cat?' · '.esc_html($cat):''?></p>
</div>
<form method="get" class="dl-search-bar">
<input type="text" name="q" placeholder="Cari ebook..." value="<?=esc_attr($search)?>">
<button type="submit" class="dl-btn dl-btn-solid dl-btn-sm">Cari</button>
</form>
</div>
<div class="dl-mobile-header"><h1>Rak Buku</h1></div>
<form method="get" class="dl-mobile-search">
<input type="text" name="q" placeholder="Cari ebook..." value="<?=esc_attr($search)?>">
<button type="submit" class="dl-btn dl-btn-solid dl-btn-sm">Cari</button>
</form>
<div class="dl-sec">
<div class="dl-chips">
<a href="<?=esc_url(add_query_arg(['q'=>$search,'cat'=>'']))?>" class="dl-chip <?=!$cat?'on':''?>">Semua</a>
<?php foreach($cats as $c):?>
<a href="<?=esc_url(add_query_arg(['q'=>$search,'cat'=>$c]))?>" class="dl-chip <?=$cat===$c?'on':''?>"><?=diglib_cat_icon($c)?> <?=esc_html($c)?></a>
<?php endforeach;?>
</div>
</div>
<div class="dl-sec">
<div class="dl-book-grid">
<?php if(empty($ebooks)):?>
<div class="dl-empty"><div class="ico">🔭</div><h3>Tidak ditemukan</h3><p>Coba kata kunci lain.</p></div>
<?php else:foreach($ebooks as $b):$av=(int)$b->available_copies;$url=esc_url(home_url('/borrow/?book='.$b->id));?>
<a href="<?=$url?>" class="dl-book-card <?=$av<=0?'full':''?>">
<div class="dl-book-cover">
<?php if($b->cover_url):?><img src="<?=esc_url($b->cover_url)?>" alt="" loading="lazy"><?php else:?><div class="dl-book-cover-ph">📖</div><?php endif;?>
<span class="dl-badge <?=$av>0?'av':'fu'?>"><?=$av>0?'Tersedia':'Penuh'?></span>
</div>
<div class="dl-book-body"><div class="ttl"><?=esc_html($b->title)?></div><div class="auth"><?=esc_html($b->author)?></div></div>
</a>
<?php endforeach;endif;?>
</div>
</div>
</div></div>
<?=diglib_render_footer()?>
</div>
<?php return ob_get_clean();
});
/* ── [diglib_borrow] ── */
add_shortcode('diglib_borrow',function(){
global $wpdb;
if(function_exists('diglib_expire_loans'))diglib_expire_loans();
$book_id = isset($_GET['book'])?(int)$_GET['book']:0;
$book    = $book_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}diglib_ebooks WHERE id=%d",$book_id)):null;
if(!$book)return '<div class="dl-root">'.diglib_render_navbar('shelf').'<div class="dl-main"><div class="dl-page-wrap"><div class="dl-empty"><div class="ico">📭</div><h3>Ebook tidak ditemukan</h3><a href="'.esc_url(home_url('/rak-ebook/')).'" class="dl-btn dl-btn-solid" style="margin-top:16px;">Ke Rak Buku</a></div></div></div>'.diglib_render_footer().'</div>';
$alert='';
$show_verification_form = false;
$pending_email = '';
$auth_email = diglib_is_authenticated();
$wl_count = diglib_waitlist_count($book_id);
$spam_post = ($_SERVER['REQUEST_METHOD'] === 'POST' && diglib_is_spam());
if ($spam_post) $alert = '<div class="dl-alert err">Permintaan ditolak karena terdeteksi spam.</div>';
// POST: gabung waiting list
if(!$spam_post && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['diglib_waitlist_nonce'])){
if(wp_verify_nonce($_POST['diglib_waitlist_nonce'], 'diglib_waitlist_action')){
$wl_email = $auth_email ? $auth_email : sanitize_email($_POST['waitlist_email'] ?? '');
if(!is_email($wl_email)){
$alert = '<div class="dl-alert err">Email tidak valid.</div>';
}elseif((int)$book->available_copies > 0){
$alert = '<div class="dl-alert wrn">Buku ini sebenarnya sudah tersedia — kamu bisa langsung meminjamnya.</div>';
}else{
$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}diglib_waitlist (ebook_id, email) VALUES (%d, %s)", $book_id, $wl_email));
$wl_count = diglib_waitlist_count($book_id);
$alert = '<div class="dl-alert ok">Kamu masuk waiting list. Kami akan mengirim email begitu buku ini tersedia kembali.</div>';
}
}
}
// POST: verifikasi kode pinjaman (hanya untuk user belum login)
if(!$spam_post && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['diglib_verify_code_nonce'])){
if(wp_verify_nonce($_POST['diglib_verify_code_nonce'], 'diglib_verify_code_action')){
$email_v = sanitize_email($_POST['verify_email'] ?? '');
$code    = sanitize_text_field($_POST['verification_code'] ?? '');
$lt      = $wpdb->prefix.'diglib_loans';
if(get_transient('diglib_blocked_ve_' . $email_v)){
$alert = '<div class="dl-alert err">Akses verifikasi untuk email ini diblokir selama 5 jam karena terlalu banyak percobaan salah.</div>';
$show_verification_form = true; $pending_email = $email_v;
} elseif(is_email($email_v) && strlen($code) === 4){
$loan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE borrower_email=%s AND verification_code=%s AND status='pending'", $email_v, $code));
if($loan){
delete_transient('diglib_attempts_ve_' . $email_v);
delete_transient('diglib_blocked_ve_' . $email_v);
$wpdb->update($lt, ['status'=>'active'], ['id'=>$loan->id]);
diglib_set_auth_session($email_v);
$auth_email = $email_v;
$alert='<div class="dl-alert ok">Kode berhasil diverifikasi! <a href="'.esc_url(home_url('/baca-ebook/?token='.$loan->access_token)).'" class="dl-alert-cta">Mulai Membaca</a></div>';
} else {
$attempts = get_transient('diglib_attempts_ve_' . $email_v) ?: 0;
$attempts++;
set_transient('diglib_attempts_ve_' . $email_v, $attempts, 5 * HOUR_IN_SECONDS);
if($attempts >= 3){
set_transient('diglib_blocked_ve_' . $email_v, 1, 5 * HOUR_IN_SECONDS);
delete_transient('diglib_attempts_ve_' . $email_v);
$alert = '<div class="dl-alert err">Terlalu banyak percobaan. Email ini diblokir selama 5 jam.</div>';
} else {
$alert = '<div class="dl-alert err">Kode verifikasi salah. Sisa percobaan: ' . (3 - $attempts) . ' kali.</div>';
}
$show_verification_form = true; $pending_email = $email_v;
}
}
}
}
// POST: pinjam buku
if(!$spam_post && $_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['diglib_borrow_nonce'])){
if(!wp_verify_nonce($_POST['diglib_borrow_nonce'],'diglib_borrow_action')){
$alert='<div class="dl-alert err">Verifikasi keamanan gagal.</div>';
}else{
// User login: pakai email sesi (tanpa input email & tanpa kode).
$email = $auth_email ? $auth_email : sanitize_email($_POST['email']??'');
$lt=$wpdb->prefix.'diglib_loans';$et=$wpdb->prefix.'diglib_ebooks';
if(!is_email($email)){
$alert='<div class="dl-alert err">Email tidak valid.</div>';
}elseif(get_transient('diglib_blocked_ve_' . $email)){
$alert = '<div class="dl-alert err">Email ini sedang diblokir karena terlalu banyak percobaan verifikasi. Silakan coba lagi nanti.</div>';
}else{
$ex = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE ebook_id=%d AND borrower_email=%s AND status IN ('active','pending')", $book_id, $email));
if($ex){
if($ex->status == 'active'){
$alert='<div class="dl-alert wrn">Kamu sudah meminjam ebook ini. <a href="'.esc_url(home_url('/baca-ebook/?token='.$ex->access_token)).'" class="dl-alert-cta">Lanjut Membaca</a></div>';
} else {
$show_verification_form = true; $pending_email = $email;
$alert='<div class="dl-alert wrn">Cek email kamu ya! Kamu sudah mengajukan peminjaman untuk buku ini. Masukkan kode verifikasi 4 digit yang telah dikirim.</div>';
}
}elseif((int)$book->available_copies<=0){
$alert='<div class="dl-alert err">Peminjaman penuh. Semua eksemplar sedang dipinjam. Gabung waiting list di bawah ya.</div>';
}else{
$cnt = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lt WHERE borrower_email=%s AND status='active' AND expires_at>NOW()", $email));
if($cnt>=3){
$alert='<div class="dl-alert err">Batas maksimal 3 ebook aktif tercapai. Kembalikan ebook lain dulu.</div>';
}else{
$token = bin2hex(random_bytes(32));
$exp_at = date('Y-m-d H:i:s', strtotime('+3 days'));
if($auth_email){
// LOGIN: pinjaman LANGSUNG AKTIF tanpa kode verifikasi
$wpdb->insert($lt, ['ebook_id'=>$book_id,'borrower_email'=>$email,'expires_at'=>$exp_at,'access_token'=>$token,'verification_code'=>null,'last_page'=>0,'status'=>'active','rating_sent'=>0,'extended'=>0,'review_token'=>diglib_generate_review_token()]);
$wpdb->query($wpdb->prepare("UPDATE $et SET available_copies=available_copies-1 WHERE id=%d AND available_copies>0", $book_id));
$alert='<div class="dl-alert ok">Pinjaman aktif! <a href="'.esc_url(home_url('/baca-ebook/?token='.$token)).'" class="dl-alert-cta">Mulai Membaca</a></div>';
}else{
// BELUM LOGIN: pending + kirim kode verifikasi
$code  = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$wpdb->insert($lt, ['ebook_id'=>$book_id,'borrower_email'=>$email,'expires_at'=>$exp_at,'access_token'=>$token,'verification_code'=>$code,'last_page'=>0,'status'=>'pending','rating_sent'=>0,'extended'=>0,'review_token'=>diglib_generate_review_token()]);
$wpdb->query($wpdb->prepare("UPDATE $et SET available_copies=available_copies-1 WHERE id=%d AND available_copies>0", $book_id));
diglib_send_verification_email($email, $code, $book->title);
$show_verification_form = true; $pending_email = $email;
$alert='<div class="dl-alert ok">Cek email kamu ya! 4 digit kode verifikasi telah dikirim. Salin dan masukkan kode tersebut di bawah ini untuk mulai membaca.</div>';
}
}
}
}
}
}
$review_table = $wpdb->prefix . 'diglib_reviews';
$review_stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM $review_table WHERE ebook_id=%d", $book_id));
$review_list = $wpdb->get_results($wpdb->prepare("SELECT rating, review, created_at FROM $review_table WHERE ebook_id=%d ORDER BY created_at DESC LIMIT 5", $book_id));
$av=(int)$book->available_copies;
$pct=$book->total_copies>0?round((($book->total_copies-$av)/$book->total_copies)*100):0;
$share_url = esc_url(home_url('/borrow/?book='.$book->id));
$share_text = urlencode('Baca ebook ' . $book->title . ' gratis di Perpus Digital Astronomi: ');
ob_start();?>
<div class="dl-root">
<?=diglib_render_navbar('shelf')?>
<div class="dl-main"><div class="dl-page-wrap">
<a href="<?=esc_url(home_url('/rak-ebook/'))?>" class="dl-back">
<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Kembali ke Rak Buku
</a>
<div class="dl-detail-hero">
<div class="dl-detail-cover-wrap">
<div class="dl-detail-cover">
<?php if($book->cover_url):?><img src="<?=esc_url($book->cover_url)?>" alt=""><?php else:?><div class="dl-detail-cover-ph">📖</div><?php endif;?>
</div>
</div>
<div class="dl-detail-meta">
<?php if($book->category):?>
<div class="dl-detail-tags"><span class="dl-tag"><?=diglib_cat_icon($book->category)?> <?=esc_html($book->category)?></span></div>
<?php endif;?>
<div>
<h1 class="dl-detail-title"><?=esc_html($book->title)?></h1>
<p class="dl-detail-author">oleh <?=esc_html($book->author)?></p>
</div>
<?php if($book->description):?>
<p class="dl-detail-desc"><?=esc_html($book->description)?></p>
<?php endif;?>
<div class="dl-stock">
<div>
<span class="lbl">Ketersediaan</span>
<span class="val"><?=$av?> / <?=(int)$book->total_copies?> eksemplar</span>
</div>
<div class="dl-stock-track"><div class="dl-stock-fill" style="width:<?=100-$pct?>%"></div></div>
<span class="dl-badge <?=$av>0?'av':'fu'?>"><?=$av>0?'Tersedia':'Penuh'?></span>
<?php if($wl_count>0): ?><span class="dl-wl-badge">Waiting list: <?=$wl_count?></span><?php endif; ?>
</div>
<div class="dl-borrow-form-box">
<?=$alert?>
<?php if($auth_email): ?><div class="dl-login-note">✓ Kamu masuk sebagai <?=esc_html($auth_email)?> — pinjaman langsung aktif tanpa kode verifikasi.</div><?php endif; ?>
<?php if($show_verification_form): ?>
<form method="post">
<?php wp_nonce_field('diglib_verify_code_action','diglib_verify_code_nonce');?>
<?php diglib_honeypot_field(); ?>
<input type="hidden" name="verify_email" value="<?=esc_attr($pending_email)?>">
<div class="dl-form-group">
<label class="dl-label">Kode verifikasi 4 digit</label>
<input type="text" name="verification_code" class="dl-input" placeholder="0000" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required style="text-align:center;font-size:1.4rem;letter-spacing:10px;">
</div>
<button type="submit" class="dl-btn dl-btn-solid dl-btn-block" style="margin-top:14px;">Verifikasi Kode</button>
</form>
<?php elseif($av>0 && strpos($alert,'diverifikasi')===false && strpos($alert,'Cek email')===false && strpos($alert,'sudah meminjam')===false && strpos($alert,'Pinjaman aktif')===false): ?>
<form method="post">
<?php wp_nonce_field('diglib_borrow_action','diglib_borrow_nonce');?>
<?php diglib_honeypot_field(); ?>
<input type="hidden" name="book_id" value="<?=$book_id?>">
<?php if(!$auth_email): ?>
<div class="dl-form-group">
<label class="dl-label">Mau baca? Masukkan email untuk meminjam</label>
<input type="email" name="email" class="dl-input" placeholder="nama@email.com" required autocomplete="email">
</div>
<?php endif; ?>
<button type="submit" class="dl-btn dl-btn-solid dl-btn-block" style="margin-top:14px;">Pinjam Ebook</button>
</form>
<?php elseif($av<=0 && !$show_verification_form): ?>
<div class="dl-waitlist-box">
<p><strong>Sedang dipinjam semua.</strong><br>Ada <?=$wl_count?> orang di waiting list. Gabung, dan kami akan email kamu begitu buku tersedia.</p>
<form method="post">
<?php wp_nonce_field('diglib_waitlist_action','diglib_waitlist_nonce');?>
<?php diglib_honeypot_field(); ?>
<?php if(!$auth_email): ?>
<input type="email" name="waitlist_email" class="dl-input" placeholder="nama@email.com" required autocomplete="email">
<?php endif; ?>
<button type="submit" class="dl-btn dl-btn-solid dl-btn-block">Gabung Waiting List</button>
</form>
</div>
<?php endif;?>
</div>
<div class="dl-share-wrap">
<span class="dl-share-title">Bagikan:</span>
<div class="dl-share-links">
<a href="https://api.whatsapp.com/send?text=<?=$share_text . $share_url?>" target="_blank" rel="noopener">WhatsApp</a>
<a href="https://t.me/share/url?url=<?=$share_url?>&text=<?=$share_text?>" target="_blank" rel="noopener">Telegram</a>
<a href="https://x.com/intent/tweet?url=<?=$share_url?>&text=<?=$share_text?>" target="_blank" rel="noopener">X</a>
<a href="https://www.facebook.com/sharer/sharer.php?u=<?=$share_url?>" target="_blank" rel="noopener">Facebook</a>
<button type="button" onclick="navigator.clipboard.writeText('<?= esc_js($share_url) ?>'); alert('Link berhasil disalin!');">Salin tautan</button>
</div>
</div>
<div class="dl-review-wrap">
<span class="dl-share-title">Ulasan pembaca</span>
<?php if($review_stats && (int)$review_stats->cnt > 0): ?>
<div class="dl-review-avg">
<?= diglib_render_stars($review_stats->avg_rating) ?>
<span><?= number_format(floatval($review_stats->avg_rating), 1) ?> dari <?= (int)$review_stats->cnt ?> ulasan</span>
</div>
<?php else: ?>
<div class="dl-review-empty">Belum ada ulasan — jadilah yang pertama setelah selesai membaca.</div>
<?php endif; ?>
<?php if(!empty($review_list)): foreach($review_list as $r): ?>
<div class="dl-review-item">
<?= diglib_render_stars($r->rating) ?>
<?php if(!empty($r->review)): ?><div class="rv-text"><?= esc_html($r->review) ?></div><?php endif; ?>
<div class="rv-meta"><?= esc_html(date_i18n('d M Y', strtotime($r->created_at))) ?></div>
</div>
<?php endforeach; endif; ?>
</div>
</div>
</div>
</div></div>
<?=diglib_render_footer()?>
</div>
<?php return ob_get_clean();
});
/* ── [diglib_read] ── */
add_shortcode('diglib_read',function(){
global $wpdb;
if(function_exists('diglib_expire_loans'))diglib_expire_loans();
$token = sanitize_text_field($_GET['token'] ?? '');
if(!$token) return '<div class="dl-root">'.diglib_render_navbar().'<div class="dl-main"><div class="dl-page-wrap"><div class="dl-empty"><div class="ico">🔒</div><h3>Akses Ditolak</h3></div></div></div></div>';
$loan = $wpdb->get_row($wpdb->prepare("SELECT l.*, e.title, e.file_url, e.author FROM {$wpdb->prefix}diglib_loans l JOIN {$wpdb->prefix}diglib_ebooks e ON l.ebook_id = e.id WHERE l.access_token = %s AND l.status = 'active' AND l.expires_at > NOW()", $token));
if(!$loan) return '<div class="dl-root">'.diglib_render_navbar().'<div class="dl-main"><div class="dl-page-wrap"><div class="dl-empty"><div class="ico">🔒</div><h3>Akses Berakhir</h3><p>Pinjam ulang ebook ini.</p><a href="'.esc_url(home_url('/rak-ebook/')).'" class="dl-btn dl-btn-solid" style="margin-top:16px;">Ke Rak Buku</a></div></div></div></div>';
$last_page = (int)($loan->last_page ?? 0);
$proxy_url = site_url('?diglib_stream=' . $token);
$start_page_attr = ($last_page > 0 && isset($_GET['resume'])) ? ' page="' . $last_page . '"' : '';
$flipbook = shortcode_exists('dflip')
? do_shortcode('[dflip source="' . esc_url($proxy_url) . '" height="calc(100vh - 54px)" bgcolor="#ffffff" allowDownload="false"' . $start_page_attr . '][/dflip]')
: '<iframe src="' . esc_url($proxy_url) . '#toolbar=0&navpanes=0" style="display:block;width:100%;height:calc(100vh - 54px);border:none;" allowfullscreen></iframe>';
ob_start(); ?>
<div class="dl-reader-root">
<div class="dl-reader-bar">
<a href="<?= esc_url(home_url('/peminjaman-saya/')) ?>" class="dl-reader-back">
<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
</a>
<h2><?= esc_html($loan->title) ?></h2>
<?php if ($last_page > 0 && empty($_GET['resume'])): ?>
<div style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
<span style="font-size:.8rem;color:var(--mut);">Terakhir hlm. <?= $last_page ?></span>
<a href="?token=<?= urlencode($token) ?>&resume=1" class="dl-btn dl-btn-solid dl-btn-sm">Lanjutkan</a>
</div>
<?php endif; ?>
</div>
<div class="dl-reader-watermark-center" aria-hidden="true"><?= esc_html($loan->borrower_email ?? '') ?></div>
<div class="dl-reader-watermark" aria-hidden="true">Dipinjam oleh: <?= esc_html($loan->borrower_email ?? '') ?><br><?= esc_html(current_time('d M Y H:i')) ?></div>
<div class="dl-reader-frame" id="dl-reader-frame"><?= $flipbook ?></div>
</div>
<?php if (shortcode_exists('dflip')): ?>
<script>
(function() {
var token = <?= json_encode($token) ?>;
var lastSaved = 0;
function savePage(page) {
if (page < 1 || page === lastSaved) return;
lastSaved = page;
var xhr = new XMLHttpRequest();
xhr.open('POST', '<?= admin_url('admin-ajax.php') ?>');
xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
xhr.send('action=diglib_save_bookmark&token=' + encodeURIComponent(token) + '&page=' + page);
}
document.addEventListener('DOMContentLoaded', function() {
setTimeout(function() {
var books = document.querySelectorAll('.df-book');
if (books.length > 0) {
if (typeof jQuery !== 'undefined') {
jQuery(books[0]).on('flip', function(e, data) { savePage(data.page || data.currentPage || 0); });
}
setInterval(function() {
var p = document.querySelector('.df-page-input');
if (p && p.value) savePage(parseInt(p.value));
}, 3000);
}
window.addEventListener('beforeunload', function() {
var p = document.querySelector('.df-page-input');
if (p && p.value) savePage(parseInt(p.value));
});
}, 2000);
});
})();
</script>
<?php endif;
return ob_get_clean();
});
/* ── [diglib_my_loans] ── */
add_shortcode('diglib_my_loans',function(){
global $wpdb;
if(function_exists('diglib_expire_loans'))diglib_expire_loans();
$loans = []; $pending_loans = []; $alert = '';
$show_rack_verification = false; $rack_email = '';
$auth_email = diglib_is_authenticated();
$current_lpage = 1; $total_lpages = 1; $per_page = 30;
if($_SERVER['REQUEST_METHOD']==='POST' && diglib_is_spam()){
$alert = '<div class="dl-alert err">Permintaan ditolak karena terdeteksi spam.</div>';
}
elseif($_SERVER['REQUEST_METHOD']==='POST'){
// PERPANJANG PINJAMAN (+3 hari, maks 1x, hanya jika waiting list kosong)
if(isset($_POST['diglib_extend_nonce']) && wp_verify_nonce($_POST['diglib_extend_nonce'], 'diglib_extend_action')){
$xid = (int)$_POST['extend_loan_id'];
$xemail = sanitize_email($_POST['email'] ?? '');
$xloan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}diglib_loans WHERE id=%d AND borrower_email=%s AND status='active' AND expires_at>NOW()", $xid, $xemail));
if($xloan && diglib_loan_can_extend($xloan)){
$new_exp = date('Y-m-d H:i:s', strtotime($xloan->expires_at . ' +3 days'));
$wpdb->update($wpdb->prefix.'diglib_loans', ['expires_at'=>$new_exp, 'extended'=>1], ['id'=>$xid]);
$alert = '<div class="dl-alert ok">Pinjaman diperpanjang 3 hari. Jatuh tempo baru: ' . esc_html(date_i18n('d M Y', strtotime($new_exp))) . '.</div>';
} else {
$alert = '<div class="dl-alert err">Perpanjangan tidak tersedia (sudah diperpanjang sekali, atau ada waiting list untuk buku ini).</div>';
}
}
elseif(isset($_POST['diglib_return_nonce']) && wp_verify_nonce($_POST['diglib_return_nonce'], 'diglib_return_action')){
$rid = (int)$_POST['return_loan_id'];
$email = sanitize_email($_POST['email']??'');
$ltr = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}diglib_loans WHERE id=%d AND borrower_email=%s AND status='active'", $rid, $email));
if($ltr){
$wpdb->update($wpdb->prefix.'diglib_loans', ['status'=>'expired'], ['id'=>$rid]);
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}diglib_ebooks SET available_copies=available_copies+1 WHERE id=%d AND available_copies<total_copies", $ltr->ebook_id));
diglib_waitlist_notify($ltr->ebook_id);
diglib_maybe_send_review_email($rid);
$review_url = diglib_get_review_url_by_loan($rid);
$alert = '<div class="dl-alert ok">Ebook berhasil dikembalikan. Terima kasih!';
if ($review_url) $alert .= ' <a href="' . esc_url($review_url) . '" class="dl-alert-cta" style="margin-top:6px;">Beri Ulasan</a>';
$alert .= '</div>';
}
}
elseif(isset($_POST['diglib_rack_access_nonce']) && wp_verify_nonce($_POST['diglib_rack_access_nonce'], 'diglib_rack_access_action')){
$rack_email = sanitize_email($_POST['rack_email'] ?? '');
$code = sanitize_text_field($_POST['rack_verification_code'] ?? '');
if(get_transient('diglib_blocked_rack_' . $rack_email)){
$alert = '<div class="dl-alert err">Terlalu banyak percobaan. Akses diblokir selama 5 jam.</div>';
$show_rack_verification = true;
} elseif(strlen($code) === 4){
$stored_code = get_transient('diglib_rack_access_code_' . $rack_email);
if($stored_code && $code === $stored_code){
delete_transient('diglib_rack_access_code_' . $rack_email);
delete_transient('diglib_attempts_rack_' . $rack_email);
diglib_set_auth_session($rack_email);
$auth_email = $rack_email;
$show_rack_verification = false;
$alert = '<div class="dl-alert ok">Kode benar. Kamu berhasil masuk — sesi berlaku 30 hari.</div>';
} else {
$attempts = get_transient('diglib_attempts_rack_' . $rack_email) ?: 0;
$attempts++;
set_transient('diglib_attempts_rack_' . $rack_email, $attempts, 5 * HOUR_IN_SECONDS);
if($attempts >= 3){
set_transient('diglib_blocked_rack_' . $rack_email, 1, 5 * HOUR_IN_SECONDS);
$alert = '<div class="dl-alert err">Terlalu banyak percobaan. Akses diblokir selama 5 jam.</div>';
} else {
$alert = '<div class="dl-alert err">Kode salah. Sisa percobaan: ' . (3 - $attempts) . ' kali.</div>';
}
$show_rack_verification = true;
}
}
}
elseif(isset($_POST['diglib_verify_code_nonce']) && wp_verify_nonce($_POST['diglib_verify_code_nonce'], 'diglib_verify_code_action')){
$email_v = sanitize_email($_POST['verify_email'] ?? '');
$code    = sanitize_text_field($_POST['verification_code'] ?? '');
if(is_email($email_v)){
if(get_transient('diglib_blocked_ve_' . $email_v)){
$alert = '<div class="dl-alert err">Email ini sedang diblokir selama 5 jam karena terlalu banyak percobaan.</div>';
} elseif(strlen($code) === 4){
$lt = $wpdb->prefix.'diglib_loans';
$loan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE borrower_email=%s AND verification_code=%s AND status='pending'", $email_v, $code));
if($loan){
delete_transient('diglib_attempts_ve_' . $email_v);
delete_transient('diglib_blocked_ve_' . $email_v);
$wpdb->update($lt, ['status'=>'active'], ['id'=>$loan->id]);
diglib_set_auth_session($email_v);
$auth_email = $email_v;
$alert='<div class="dl-alert ok">Kode berhasil diverifikasi! Pinjaman sekarang aktif.</div>';
} else {
$attempts = get_transient('diglib_attempts_ve_' . $email_v) ?: 0;
$attempts++;
set_transient('diglib_attempts_ve_' . $email_v, $attempts, 5 * HOUR_IN_SECONDS);
if($attempts >= 3){
set_transient('diglib_blocked_ve_' . $email_v, 1, 5 * HOUR_IN_SECONDS);
delete_transient('diglib_attempts_ve_' . $email_v);
$alert = '<div class="dl-alert err">Terlalu banyak percobaan. Email ini diblokir selama 5 jam.</div>';
} else {
$alert = '<div class="dl-alert err">Kode verifikasi salah. Sisa percobaan: ' . (3 - $attempts) . ' kali.</div>';
}
}
}
}
}
elseif(isset($_POST['diglib_check_nonce']) && wp_verify_nonce($_POST['diglib_check_nonce'], 'diglib_check_action')){
$input_email = sanitize_email($_POST['email'] ?? '');
if(!is_email($input_email)){
$alert = '<div class="dl-alert err">Email tidak valid.</div>';
} elseif(get_transient('diglib_blocked_rack_' . $input_email)){
$alert = '<div class="dl-alert err">Email ini sedang diblokir karena terlalu banyak percobaan. Silakan coba lagi nanti.</div>';
} else {
$code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
set_transient('diglib_rack_access_code_' . $input_email, $code, 24 * HOUR_IN_SECONDS);
diglib_send_login_email($input_email, $code);
$rack_email = $input_email;
$show_rack_verification = true;
$alert = '<div class="dl-alert ok">Kode masuk telah dikirim ke email kamu. Masukkan kode 4 digit di bawah.</div>';
}
}
}
$email_to_query = $auth_email ?: (is_email($rack_email) ? $rack_email : '');
if(is_email($email_to_query)){
$total_active = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}diglib_loans WHERE borrower_email=%s AND status='active' AND expires_at>NOW()", $email_to_query));
$total_lpages = max(1, (int)ceil($total_active / $per_page));
$current_lpage = min(max(1, intval($_GET['lpage'] ?? 1)), $total_lpages);
$offset = ($current_lpage - 1) * $per_page;
$loans = $wpdb->get_results($wpdb->prepare("SELECT l.*,e.title,e.author,e.cover_url,e.category FROM {$wpdb->prefix}diglib_loans l JOIN {$wpdb->prefix}diglib_ebooks e ON l.ebook_id=e.id WHERE l.borrower_email=%s AND l.status='active' AND l.expires_at>NOW() ORDER BY l.expires_at ASC LIMIT %d OFFSET %d", $email_to_query, $per_page, $offset));
$pending_loans = $wpdb->get_results($wpdb->prepare("SELECT l.*, e.title FROM {$wpdb->prefix}diglib_loans l JOIN {$wpdb->prefix}diglib_ebooks e ON l.ebook_id = e.id WHERE l.borrower_email=%s AND l.status='pending'", $email_to_query));
}
ob_start();?>
<div class="dl-root">
<?=diglib_render_navbar('loans')?>
<div class="dl-main"><div class="dl-page-wrap">
<div class="dl-topbar">
<div>
<h2 class="dl-page-title">Pinjaman Saya</h2>
<p class="dl-page-sub"><?= $auth_email ? 'Rak buku milik ' . esc_html($auth_email) : 'Masuk untuk melihat dan mengelola pinjamanmu.' ?></p>
</div>
<?php if($auth_email): ?>
<div><a href="?diglib_logout=1" class="dl-btn dl-btn-outline dl-btn-sm" style="color:var(--red)!important;border-color:var(--red);">Keluar</a></div>
<?php endif; ?>
</div>
<?php if($auth_email): ?>
<div class="dl-mobile-authbar"><a href="?diglib_logout=1">Keluar</a></div>
<?php endif; ?>
<div style="padding-bottom:40px;">
<?php if(!$show_rack_verification && !$auth_email): ?>
<div class="dl-check-card">
<h3>Masuk dengan Email</h3>
<p>Masukkan email kamu, kami kirim kode masuk 4 digit. Setelah masuk kamu bisa meminjam tanpa verifikasi ulang dan melihat daftar pinjaman. Sesi berlaku 30 hari selama tidak keluar.</p>
<form method="post">
<?php wp_nonce_field('diglib_check_action','diglib_check_nonce');?>
<?php diglib_honeypot_field(); ?>
<div class="dl-form-group">
<label class="dl-label">Alamat email</label>
<input type="email" name="email" class="dl-input" placeholder="nama@email.com" value="<?=esc_attr($rack_email)?>" required autocomplete="email">
</div>
<button type="submit" class="dl-btn dl-btn-solid dl-btn-block" style="margin-top:16px;">Kirim Kode Masuk</button>
</form>
</div>
<?php endif; ?>
<?php if($show_rack_verification): ?>
<div class="dl-check-card">
<h3>Masukkan Kode Masuk</h3>
<p>Kode 4 digit telah dikirim ke <strong><?= esc_html($rack_email) ?></strong>.</p>
<form method="post">
<?php wp_nonce_field('diglib_rack_access_action','diglib_rack_access_nonce');?>
<?php diglib_honeypot_field(); ?>
<input type="hidden" name="rack_email" value="<?= esc_attr($rack_email) ?>">
<div class="dl-form-group">
<label class="dl-label">Kode masuk</label>
<input type="text" name="rack_verification_code" class="dl-input" placeholder="0000" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required style="text-align:center;font-size:1.4rem;letter-spacing:10px;">
</div>
<button type="submit" class="dl-btn dl-btn-solid dl-btn-block" style="margin-top:16px;">Masuk</button>
</form>
<p style="margin-top:12px;font-size:.84rem;color:var(--mut);">Tidak menerima kode? <a href="?resend=1">Kirim ulang</a> atau cek folder spam.</p>
</div>
<?php endif; ?>
<div class="dl-alerts-wrap">
<?=$alert?>
<?php if(!empty($pending_loans)): ?>
<div class="dl-alert wrn">
Kamu memiliki <strong><?= count($pending_loans) ?></strong> pinjaman yang belum diverifikasi. Masukkan kode dari email:
<?php foreach($pending_loans as $p): ?>
<form method="post" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
<?php wp_nonce_field('diglib_verify_code_action','diglib_verify_code_nonce');?>
<?php diglib_honeypot_field(); ?>
<input type="hidden" name="verify_email" value="<?= esc_attr($email_to_query) ?>">
<span style="font-size:.9rem;flex:1;min-width:160px;"><?= esc_html($p->title) ?></span>
<input type="text" name="verification_code" class="dl-input" placeholder="0000" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required style="width:80px;text-align:center;padding:8px 2px;">
<button type="submit" class="dl-read-btn">Verifikasi</button>
</form>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php if(!empty($loans)):?>
<div class="dl-loans-grid">
<?php foreach($loans as $i=>$loan):
$sisa=strtotime($loan->expires_at)-time();
$is_u=$sisa<86400;
$sl=floor($sisa/86400)>0?floor($sisa/86400).' hari':floor($sisa/3600).' jam';
$ru=esc_url(home_url('/baca-ebook/?token='.$loan->access_token));
$lp = (int)($loan->last_page ?? 0);
$can_ext = diglib_loan_can_extend($loan);
$wl_n = diglib_waitlist_count($loan->ebook_id);
?>
<div class="dl-loan-card" style="animation-delay:<?=$i*.08?>s">
<div class="dl-loan-thumb">
<?php if($loan->cover_url):?><img src="<?=esc_url($loan->cover_url)?>" alt=""><?php else:?><div class="dl-loan-thumb-ph">📖</div><?php endif;?>
</div>
<div class="dl-loan-info">
<?php if($loan->category):?><span class="dl-loan-cat"><?=esc_html($loan->category)?></span><?php endif;?>
<div class="dl-loan-title"><?=esc_html($loan->title)?></div>
<span class="dl-loan-author"><?=esc_html($loan->author)?></span>
<span class="dl-expiry <?=$is_u?'urgent':''?>"><?=$sl?> tersisa</span>
<?php if ($lp > 0): ?>
<span style="font-size:.72rem;color:var(--mut);margin-top:6px;display:inline-block;">— terakhir hlm. <?= $lp ?></span>
<?php endif; ?>
</div>
<div class="dl-loan-actions">
<a href="<?=$ru?>" class="dl-read-btn">
<svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
<?= $lp > 0 ? 'Lanjutkan' : 'Baca' ?>
</a>
<?php if($can_ext): ?>
<form method="post">
<?php wp_nonce_field('diglib_extend_action','diglib_extend_nonce');?>
<?php diglib_honeypot_field(); ?>
<input type="hidden" name="email" value="<?=esc_attr($email_to_query)?>">
<input type="hidden" name="extend_loan_id" value="<?=$loan->id?>">
<button type="submit" class="dl-extend-btn">Perpanjang +3 Hari</button>
</form>
<?php elseif((int)$loan->extended === 1): ?>
<span class="dl-extend-note">Sudah diperpanjang 1x</span>
<?php elseif($wl_n > 0): ?>
<span class="dl-extend-note">Ada waiting list — tidak bisa diperpanjang</span>
<?php endif; ?>
<form method="post">
<?php wp_nonce_field('diglib_return_action','diglib_return_nonce');?>
<?php diglib_honeypot_field(); ?>
<input type="hidden" name="email" value="<?=esc_attr($email_to_query)?>">
<input type="hidden" name="return_loan_id" value="<?=$loan->id?>">
<button type="submit" class="dl-return-btn" onclick="return confirm('Kembalikan ebook ini?');">
<svg viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
Kembalikan
</button>
</form>
</div>
</div>
<?php endforeach;?>
</div>
<?php if($total_lpages > 1): ?>
<div class="dl-pagination">
<?php if($current_lpage > 1): ?><a href="<?= esc_url(add_query_arg('lpage', $current_lpage - 1)) ?>">←</a><?php endif; ?>
<?php for($p = 1; $p <= $total_lpages; $p++): ?>
<?php if($p == $current_lpage): ?><span class="current"><?= $p ?></span><?php else: ?><a href="<?= esc_url(add_query_arg('lpage', $p)) ?>"><?= $p ?></a><?php endif; ?>
<?php endfor; ?>
<?php if($current_lpage < $total_lpages): ?><a href="<?= esc_url(add_query_arg('lpage', $current_lpage + 1)) ?>">→</a><?php endif; ?>
</div>
<?php endif; ?>
<?php elseif($auth_email && empty($loans) && empty($pending_loans)): ?>
<div class="dl-empty">
<div class="ico">🔭</div>
<h3>Rakmu kosong</h3>
<p>Saat ini kamu tidak sedang meminjam ebook apapun.</p>
</div>
<?php endif;?>
</div>
</div></div>
<?=diglib_render_footer()?>
</div>
<?php return ob_get_clean();
});
