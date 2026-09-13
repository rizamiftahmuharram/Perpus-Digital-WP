<?php
/**
 * Plugin Name: Digital Library Admin
 * Description: Sistem manajemen perpustakaan digital untuk WordPress.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: diglib
 */

// Mencegah akses langsung ke file ini
defined( 'ABSPATH' ) || exit;

// =============================================
// REGISTER MENU DI WORDPRESS ADMIN
// =============================================
add_action( 'init', function() {
    date_default_timezone_set( 'Asia/Jakarta' ); // UTC+7
});

add_action( 'admin_menu', function() {
    add_menu_page(
        __( 'Perpustakaan Digital', 'diglib' ),
        __( 'Perpustakaan', 'diglib' ),
        'manage_options',
        'diglib-admin',
        'diglib_admin_dashboard',
        'dashicons-book-alt',
        30
    );
    add_submenu_page(
        'diglib-admin',
        __( 'Daftar Ebook', 'diglib' ),
        __( 'Daftar Ebook', 'diglib' ),
        'manage_options',
        'diglib-admin',
        'diglib_admin_dashboard'
    );
    add_submenu_page(
        'diglib-admin',
        __( 'Tambah Ebook', 'diglib' ),
        __( 'Tambah Ebook', 'diglib' ),
        'manage_options',
        'diglib-add-ebook',
        'diglib_admin_add_ebook'
    );
    add_submenu_page(
        'diglib-admin',
        __( 'Data Peminjaman', 'diglib' ),
        __( 'Data Peminjaman', 'diglib' ),
        'manage_options',
        'diglib-loans',
        'diglib_admin_loans'
    );
});

// =============================================
// CSS & JS UNTUK ADMIN PANEL
// =============================================
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'diglib' ) === false ) {
        return;
    }
    ?>
    <style>
        .diglib-wrap { max-width: 1100px; }
        .diglib-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 20px 0; }
        .diglib-stat-card { background: #fff; border-radius: 10px; padding: 20px 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-left: 4px solid #4f46e5; }
        .diglib-stat-card.green  { border-color: #10b981; }
        .diglib-stat-card.orange { border-color: #f59e0b; }
        .diglib-stat-card.red    { border-color: #ef4444; }
        .diglib-stat-card h2 { margin: 0; font-size: 2rem; color: #1a1a2e; }
        .diglib-stat-card p  { margin: 4px 0 0; color: #666; font-size: .875rem; }
        .diglib-table-wrap { background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-top: 20px; }
        .diglib-table { width: 100%; border-collapse: collapse; }
        .diglib-table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-size: .8rem; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .diglib-table td { padding: 14px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; font-size: .9rem; }
        .diglib-table tr:last-child td { border-bottom: none; }
        .diglib-table tr:hover td { background: #fafafa; }
        .diglib-cover-thumb { width: 44px; height: 60px; object-fit: cover; border-radius: 4px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .diglib-cover-placeholder-sm { width: 44px; height: 60px; background: #e5e7eb; border-radius: 4px; display:flex; align-items:center; justify-content:center; font-size: 20px; }
        .diglib-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .75rem; font-weight: 600; }
        .diglib-badge-green  { background: #d1fae5; color: #065f46; }
        .diglib-badge-red    { background: #fee2e2; color: #991b1b; }
        .diglib-badge-gray   { background: #f3f4f6; color: #6b7280; }
        .diglib-badge-blue   { background: #dbeafe; color: #1e40af; }
        .diglib-badge-yellow { background: #fef3c7; color: #92400e; }
        .diglib-bar-bg { background: #e5e7eb; border-radius: 99px; height: 8px; width: 120px; overflow: hidden; }
        .diglib-bar-fill { height: 8px; border-radius: 99px; background: #4f46e5; transition: width .3s; }
        .diglib-form-card { background: #fff; border-radius: 10px; padding: 28px 32px; box-shadow: 0 1px 4px rgba(0,0,0,.08); max-width: 700px; }
        .diglib-form-row { margin-bottom: 20px; }
        .diglib-form-row label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .9rem; color: #374151; }
        .diglib-form-row input[type=text],
        .diglib-form-row input[type=number],
        .diglib-form-row input[type=url],
        .diglib-form-row select,
        .diglib-form-row textarea { width: 100%; padding: 9px 13px; border: 1.5px solid #e5e7eb; border-radius: 8px; font-size: .95rem; color: #1a1a2e; transition: border-color .2s; }
        .diglib-form-row input:focus,
        .diglib-form-row select:focus,
        .diglib-form-row textarea:focus { outline: none; border-color: #4f46e5; }
        .diglib-form-row textarea { min-height: 100px; resize: vertical; }
        .diglib-form-row .hint { font-size: .8rem; color: #9ca3af; margin-top: 4px; }
        .diglib-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; }
        .diglib-btn-primary { background: #4f46e5; color: #fff; border: none; padding: 10px 22px; border-radius: 8px; cursor: pointer; font-size: .95rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .diglib-btn-primary:hover { background: #4338ca; color: #fff; }
        .diglib-btn-danger  { background: #ef4444; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: .8rem; text-decoration: none; display: inline-block; }
        .diglib-btn-danger:hover { background: #dc2626; color: #fff; }
        .diglib-btn-success { background: #10b981; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: .8rem; text-decoration: none; display: inline-block; }
        .diglib-btn-success:hover { background: #059669; color: #fff; }
        .diglib-btn-secondary { background: #f3f4f6; color: #374151; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: .8rem; text-decoration: none; display: inline-block; }
        .diglib-btn-secondary:hover { background: #e5e7eb; color: #374151; }
        .diglib-notice { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .diglib-notice-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .diglib-notice-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .diglib-search-bar { display: flex; gap: 10px; margin-bottom: 16px; align-items: center; }
        .diglib-search-bar input { padding: 8px 14px; border: 1.5px solid #e5e7eb; border-radius: 8px; font-size: .9rem; min-width: 260px; }
        .diglib-search-bar input:focus { outline: none; border-color: #4f46e5; }
        .diglib-section-header { display: flex; justify-content: space-between; align-items: center; margin: 24px 0 8px; }
        .diglib-section-header h2 { margin: 0; font-size: 1.2rem; }
        .diglib-media-row { display: flex; gap: 10px; align-items: center; }
        .diglib-media-row input[type=text] { flex: 1; }
        .diglib-preview-cover { width: 80px; height: 108px; object-fit: cover; border-radius: 6px; margin-top: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.12); display: none; }
        .diglib-inline-form { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 6px; }
        .diglib-inline-form input[type="date"] { padding: 4px 8px; border: 1px solid #e5e7eb; border-radius: 4px; font-size: .8rem; }
        .diglib-inline-form button { padding: 5px 12px; font-size: .75rem; border-radius: 4px; }
    </style>
    <script>
        function diglib_open_media(field_id, preview_id, type) {
            var frame = wp.media({
                title: type === 'image' ? 'Pilih Cover' : 'Pilih File PDF',
                button: { text: 'Gunakan File Ini' },
                library: { type: type === 'image' ? 'image' : 'application/pdf' },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                document.getElementById(field_id).value = attachment.url;
                if (type === 'image' && preview_id) {
                    var img = document.getElementById(preview_id);
                    img.src = attachment.url;
                    img.style.display = 'block';
                }
            });
            frame.open();
        }
    </script>
    <?php
});

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'diglib' ) !== false ) {
        wp_enqueue_media();
    }
});

// =============================================
// HALAMAN 1: DASHBOARD / DAFTAR EBOOK
// =============================================
function diglib_admin_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Anda tidak memiliki izin untuk mengakses halaman ini.', 'diglib' ) );
    }

    global $wpdb;
    if ( function_exists( 'diglib_expire_loans' ) ) {
        diglib_expire_loans();
    }
    
    $ebooks_tbl = $wpdb->prefix . 'diglib_ebooks';
    $loans_tbl  = $wpdb->prefix . 'diglib_loans';

    // Handle aksi hapus
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) ) {
        $id = (int) $_GET['id'];
        check_admin_referer( 'diglib_delete_' . $id );
        $wpdb->delete( $loans_tbl,  [ 'ebook_id' => $id ] );
        $wpdb->delete( $ebooks_tbl, [ 'id' => $id ] );
        echo '<div class="diglib-notice diglib-notice-success">✅ Ebook berhasil dihapus.</div>';
    }

    // Handle reset stok
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'reset_stock' && isset( $_GET['id'] ) ) {
        $id = (int) $_GET['id'];
        check_admin_referer( 'diglib_reset_' . $id );
        $book = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ebooks_tbl WHERE id = %d", $id ) );
        if ( $book ) {
            $wpdb->update( $ebooks_tbl, [ 'available_copies' => $book->total_copies ], [ 'id' => $id ] );
            $wpdb->update( $loans_tbl, [ 'status' => 'expired' ], [ 'ebook_id' => $id, 'status' => 'active' ] );
            echo '<div class="diglib-notice diglib-notice-success">✅ Stok berhasil direset. Semua peminjaman aktif diakhiri.</div>';
        }
    }

    // Statistik
    $total_books   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ebooks_tbl" );
    $total_loans   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $loans_tbl WHERE status='active' AND expires_at > NOW()" );
    $total_expired = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $loans_tbl WHERE status='expired' OR expires_at <= NOW()" );
    $total_full    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ebooks_tbl WHERE available_copies = 0" );

    // Search
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $where  = $search ? $wpdb->prepare( "WHERE title LIKE %s OR author LIKE %s", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' ) : '';
    $ebooks = $wpdb->get_results( "SELECT * FROM $ebooks_tbl $where ORDER BY id DESC" );
    ?>
    <div class="wrap diglib-wrap">
        <h1>📚 <?php esc_html_e( 'Perpustakaan Digital - Daftar Ebook', 'diglib' ); ?></h1>

        <div class="diglib-stats">
            <div class="diglib-stat-card">
                <h2><?php echo esc_html( $total_books ); ?></h2>
                <p><?php esc_html_e( 'Total Judul Ebook', 'diglib' ); ?></p>
            </div>
            <div class="diglib-stat-card green">
                <h2><?php echo esc_html( $total_loans ); ?></h2>
                <p><?php esc_html_e( 'Sedang Dipinjam', 'diglib' ); ?></p>
            </div>
            <div class="diglib-stat-card orange">
                <h2><?php echo esc_html( $total_full ); ?></h2>
                <p><?php esc_html_e( 'Judul Penuh / Tidak Tersedia', 'diglib' ); ?></p>
            </div>
            <div class="diglib-stat-card red">
                <h2><?php echo esc_html( $total_expired ); ?></h2>
                <p><?php esc_html_e( 'Total Peminjaman Expired', 'diglib' ); ?></p>
            </div>
        </div>

        <div class="diglib-section-header">
            <h2><?php esc_html_e( 'Daftar Ebook', 'diglib' ); ?></h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=diglib-add-ebook' ) ); ?>" class="diglib-btn-primary">+ <?php esc_html_e( 'Tambah Ebook Baru', 'diglib' ); ?></a>
        </div>

        <div class="diglib-search-bar">
            <form method="get" style="display:flex;gap:10px;align-items:center;">
                <input type="hidden" name="page" value="diglib-admin">
                <input type="text" name="s" placeholder="<?php esc_attr_e( 'Cari judul atau penulis...', 'diglib' ); ?>" value="<?php echo esc_attr( $search ); ?>">
                <button type="submit" class="diglib-btn-secondary"><?php esc_html_e( 'Cari', 'diglib' ); ?></button>
                <?php if ( $search ): ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=diglib-admin' ) ); ?>" class="diglib-btn-secondary">× <?php esc_html_e( 'Reset', 'diglib' ); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="diglib-table-wrap">
            <table class="diglib-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Cover', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Judul & Penulis', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Kategori', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Stok Tersedia', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Aktif Dipinjam', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Aksi', 'diglib' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $ebooks ) ): ?>
                    <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:40px;">
                        <?php if ( $search ): ?>
                            <?php esc_html_e( 'Tidak ada ebook yang cocok.', 'diglib' ); ?>
                        <?php else: ?>
                            <?php esc_html_e( 'Belum ada ebook.', 'diglib' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=diglib-add-ebook' ) ); ?>"><?php esc_html_e( 'Tambah sekarang', 'diglib' ); ?></a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: foreach ( $ebooks as $b ):
                    $active_loans = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM $loans_tbl WHERE ebook_id = %d AND status='active' AND expires_at > NOW()", $b->id
                    ) );
                    $pct = $b->total_copies > 0 ? round( ( ( $b->total_copies - $b->available_copies ) / $b->total_copies ) * 100 ) : 0;
                    $available = (int) $b->available_copies;
                    $badge_class = $available === 0 ? 'diglib-badge-red' : ( $available < 5 ? 'diglib-badge-yellow' : 'diglib-badge-green' );
                ?>
                <tr>
                    <td>
                        <?php if ( $b->cover_url ): ?>
                            <img src="<?php echo esc_url( $b->cover_url ); ?>" class="diglib-cover-thumb" alt="<?php echo esc_attr( $b->title ); ?>">
                        <?php else: ?>
                            <div class="diglib-cover-placeholder-sm">📖</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html( $b->title ); ?></strong><br>
                        <small style="color:#6b7280;"><?php echo esc_html( $b->author ); ?></small>
                    </td>
                    <td>
                        <?php if ( $b->category ): ?>
                            <span class="diglib-badge diglib-badge-blue"><?php echo esc_html( $b->category ); ?></span>
                        <?php else: ?>
                            <span style="color:#9ca3af;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="diglib-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $available ); ?> / <?php echo esc_html( (int) $b->total_copies ); ?></span>
                        <div class="diglib-bar-bg" style="margin-top:6px;">
                            <div class="diglib-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
                        </div>
                        <small style="color:#9ca3af;"><?php echo esc_html( $pct ); ?>% terpinjam</small>
                    </td>
                    <td>
                        <?php if ( $active_loans > 0 ): ?>
                            <span class="diglib-badge diglib-badge-blue"><?php echo esc_html( $active_loans ); ?> <?php esc_html_e( 'peminjam', 'diglib' ); ?></span>
                        <?php else: ?>
                            <span style="color:#9ca3af;">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=diglib-add-ebook&edit=' . $b->id ) ); ?>" class="diglib-btn-secondary">✏️ <?php esc_html_e( 'Edit', 'diglib' ); ?></a>
                        &nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=diglib-admin&action=reset_stock&id=' . $b->id ), 'diglib_reset_' . $b->id ) ); ?>"
                           class="diglib-btn-secondary"
                           onclick="return confirm('<?php esc_attr_e( 'Reset stok? Semua peminjaman aktif untuk buku ini akan diakhiri.', 'diglib' ); ?>')">🔄 <?php esc_html_e( 'Reset', 'diglib' ); ?></a>
                        &nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=diglib-admin&action=delete&id=' . $b->id ), 'diglib_delete_' . $b->id ) ); ?>"
                           class="diglib-btn-danger"
                           onclick="return confirm('<?php esc_attr_e( 'Hapus ebook ini beserta semua data peminjaman?', 'diglib' ); ?>')">🗑️ <?php esc_html_e( 'Hapus', 'diglib' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// =============================================
// HALAMAN 2: TAMBAH / EDIT EBOOK
// =============================================
function diglib_admin_add_ebook() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Anda tidak memiliki izin untuk mengakses halaman ini.', 'diglib' ) );
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'diglib_ebooks';
    $notice  = '';
    $edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
    $book    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) ) : null;

    // Ambil semua kategori yang ada untuk datalist
    $categories = $wpdb->get_col( "SELECT DISTINCT category FROM $table WHERE category != '' ORDER BY category ASC" );

    // Proses simpan
    if ( isset( $_POST['diglib_form_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['diglib_form_nonce'] ), 'diglib_save_ebook' ) ) {
        $title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $author      = sanitize_text_field( wp_unslash( $_POST['author'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $cover_url   = esc_url_raw( wp_unslash( $_POST['cover_url'] ?? '' ) );
        $file_url    = esc_url_raw( wp_unslash( $_POST['file_url'] ?? '' ) );
        $category    = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
        $total       = max( 1, (int) ( $_POST['total_copies'] ?? 25 ) );

        if ( ! $title || ! $file_url ) {
            $notice = '<div class="diglib-notice diglib-notice-error">❌ ' . __( 'Judul dan file PDF wajib diisi.', 'diglib' ) . '</div>';
        } else {
            if ( $edit_id && $book ) {
                // Hitung selisih perubahan total_copies
                $diff          = $total - (int) $book->total_copies;
                $new_available = max( 0, (int) $book->available_copies + $diff );
                $wpdb->update(
                    $table,
                    [
                        'title'            => $title,
                        'author'           => $author,
                        'description'      => $description,
                        'cover_url'        => $cover_url,
                        'file_url'         => $file_url,
                        'category'         => $category,
                        'total_copies'     => $total,
                        'available_copies' => $new_available,
                    ],
                    [ 'id' => $edit_id ]
                );
                $notice = '<div class="diglib-notice diglib-notice-success">✅ ' . __( 'Ebook berhasil diperbarui.', 'diglib' ) . '</div>';
                $book   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) );
            } else {
                $wpdb->insert(
                    $table,
                    [
                        'title'            => $title,
                        'author'           => $author,
                        'description'      => $description,
                        'cover_url'        => $cover_url,
                        'file_url'         => $file_url,
                        'category'         => $category,
                        'total_copies'     => $total,
                        'available_copies' => $total,
                    ]
                );
                $notice = '<div class="diglib-notice diglib-notice-success">✅ ' . __( 'Ebook berhasil ditambahkan!', 'diglib' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=diglib-admin' ) ) . '">' . __( 'Lihat daftar', 'diglib' ) . '</a> ' . __( 'atau tambah lagi di bawah.', 'diglib' ) . '</div>';
                $book   = null; // reset form
            }
        }
    } elseif ( isset( $_POST['diglib_form_nonce'] ) ) {
        $notice = '<div class="diglib-notice diglib-notice-error">❌ ' . __( 'Kegagalan verifikasi keamanan. Silakan coba lagi.', 'diglib' ) . '</div>';
    }
    ?>
    <div class="wrap diglib-wrap">
        <h1><?php echo $edit_id ? esc_html__( '✏️ Edit Ebook', 'diglib' ) : esc_html__( '➕ Tambah Ebook Baru', 'diglib' ); ?></h1>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=diglib-admin' ) ); ?>">← <?php esc_html_e( 'Kembali ke Daftar Ebook', 'diglib' ); ?></a></p>
        <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <div class="diglib-form-card">
            <form method="post">
                <?php wp_nonce_field( 'diglib_save_ebook', 'diglib_form_nonce' ); ?>

                <div class="diglib-form-grid">
                    <div class="diglib-form-row">
                        <label><?php esc_html_e( 'Judul Ebook', 'diglib' ); ?> <span style="color:red">*</span></label>
                        <input type="text" name="title" value="<?php echo esc_attr( $book->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Masukkan judul ebook', 'diglib' ); ?>" required>
                    </div>
                    <div class="diglib-form-row">
                        <label><?php esc_html_e( 'Penulis / Author', 'diglib' ); ?></label>
                        <input type="text" name="author" value="<?php echo esc_attr( $book->author ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Nama penulis', 'diglib' ); ?>">
                    </div>
                </div>

                <div class="diglib-form-row">
                    <label><?php esc_html_e( 'Deskripsi', 'diglib' ); ?></label>
                    <textarea name="description" placeholder="<?php esc_attr_e( 'Deskripsi singkat tentang ebook ini...', 'diglib' ); ?>"><?php echo esc_textarea( $book->description ?? '' ); ?></textarea>
                </div>

                <div class="diglib-form-grid">
                    <div class="diglib-form-row">
                        <label><?php esc_html_e( 'Kategori', 'diglib' ); ?></label>
                        <input type="text" name="category" value="<?php echo esc_attr( $book->category ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'Misal: Fiksi, Sains, Sejarah...', 'diglib' ); ?>"
                               list="diglib-categories">
                        <datalist id="diglib-categories">
                            <?php foreach ( $categories as $cat ): ?>
                                <option value="<?php echo esc_attr( $cat ); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="hint"><?php esc_html_e( 'Ketik atau pilih kategori yang sudah ada.', 'diglib' ); ?></p>
                    </div>
                    <div class="diglib-form-row">
                        <label><?php esc_html_e( 'Jumlah Eksemplar', 'diglib' ); ?></label>
                        <input type="number" name="total_copies" value="<?php echo esc_attr( $book->total_copies ?? 25 ); ?>" min="1" max="1000">
                        <p class="hint">
                            <?php esc_html_e( 'Default: 25.', 'diglib' ); ?>
                            <?php if ( $edit_id ) esc_html_e( 'Mengubah jumlah akan menyesuaikan stok tersedia secara otomatis.', 'diglib' ); ?>
                        </p>
                    </div>
                </div>

                <div class="diglib-form-row">
                    <label><?php esc_html_e( 'Cover Ebook (Gambar)', 'diglib' ); ?></label>
                    <div class="diglib-media-row">
                        <input type="text" name="cover_url" id="diglib_cover_url"
                               value="<?php echo esc_attr( $book->cover_url ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'URL gambar cover...', 'diglib' ); ?>">
                        <button type="button" class="diglib-btn-secondary"
                                onclick="diglib_open_media('diglib_cover_url','diglib_cover_preview','image')">
                            📁 <?php esc_html_e( 'Pilih dari Media', 'diglib' ); ?>
                        </button>
                    </div>
                    <img id="diglib_cover_preview"
                         src="<?php echo esc_url( $book->cover_url ?? '' ); ?>"
                         class="diglib-preview-cover"
                         style="<?php echo ! empty( $book->cover_url ) ? 'display:block;' : ''; ?>">
                </div>

                <div class="diglib-form-row">
                    <label><?php esc_html_e( 'File PDF Ebook', 'diglib' ); ?> <span style="color:red">*</span></label>
                    <div class="diglib-media-row">
                        <input type="text" name="file_url" id="diglib_file_url"
                               value="<?php echo esc_attr( $book->file_url ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'URL file PDF...', 'diglib' ); ?>" required>
                        <button type="button" class="diglib-btn-secondary"
                                onclick="diglib_open_media('diglib_file_url',null,'pdf')">
                            📁 <?php esc_html_e( 'Pilih dari Media', 'diglib' ); ?>
                        </button>
                    </div>
                    <p class="hint"><?php esc_html_e( 'Upload PDF melalui tombol di atas, atau tempel URL langsung. File akan ditampilkan via iframe saat dibaca.', 'diglib' ); ?></p>
                    <?php if ( ! empty( $book->file_url ) ): ?>
                        <p class="hint"><?php esc_html_e( 'File saat ini:', 'diglib' ); ?> <a href="<?php echo esc_url( $book->file_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lihat PDF', 'diglib' ); ?></a></p>
                    <?php endif; ?>
                </div>

                <div style="margin-top:8px;">
                    <button type="submit" class="diglib-btn-primary">
                        <?php echo $edit_id ? esc_html__( '💾 Simpan Perubahan', 'diglib' ) : esc_html__( '✅ Tambah Ebook', 'diglib' ); ?>
                    </button>
                    <?php if ( $edit_id ): ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=diglib-add-ebook' ) ); ?>" class="diglib-btn-secondary" style="margin-left:10px;">+ <?php esc_html_e( 'Tambah Ebook Baru', 'diglib' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php
}

// =============================================
// HALAMAN 3: DATA PEMINJAMAN
// =============================================
function diglib_admin_loans() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Anda tidak memiliki izin untuk mengakses halaman ini.', 'diglib' ) );
    }

    global $wpdb;
    
    if ( function_exists( 'diglib_expire_loans' ) ) {
        diglib_expire_loans();
    }

    $loans_tbl  = $wpdb->prefix . 'diglib_loans';
    $ebooks_tbl = $wpdb->prefix . 'diglib_ebooks';

    // Handle revoke
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'revoke' && isset( $_GET['id'] ) ) {
        $loan_id = (int) $_GET['id'];
        check_admin_referer( 'diglib_revoke_' . $loan_id );
        $loan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $loans_tbl WHERE id = %d", $loan_id ) );
        if ( $loan && $loan->status === 'active' ) {
            $wpdb->update( $loans_tbl, [ 'status' => 'expired' ], [ 'id' => $loan->id ] );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $ebooks_tbl SET available_copies = available_copies + 1 WHERE id = %d AND available_copies < total_copies",
                $loan->ebook_id
            ) );
            echo '<div class="diglib-notice diglib-notice-success">✅ ' . __( 'Peminjaman berhasil dicabut.', 'diglib' ) . '</div>';
        }
    }

    // Handle ubah tanggal (perpanjangan waktu)
    if ( isset( $_POST['action'] ) && $_POST['action'] === 'extend_date' && isset( $_POST['loan_id'] ) ) {
        $loan_id = (int) $_POST['loan_id'];
        check_admin_referer( 'diglib_extend_' . $loan_id );
        $new_date = sanitize_text_field( wp_unslash( $_POST['new_expire_date'] ?? '' ) );
        
        if ( $new_date ) {
            $new_datetime = $new_date . ' 23:59:59';
            $loan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $loans_tbl WHERE id = %d", $loan_id ) );
            if ( $loan && $loan->status === 'active' ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE $loans_tbl SET expires_at = %s WHERE id = %d",
                    $new_datetime,
                    $loan_id
                ) );
                $formatted_date = date_i18n( 'd M Y', strtotime( $new_date ) );
                echo '<div class="diglib-notice diglib-notice-success">✅ ' . sprintf( __( 'Masa peminjaman berhasil diperbarui hingga %s.', 'diglib' ), $formatted_date ) . '</div>';
            }
        }
    }

    // Filter
    $filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'active';
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    
    // Menggunakan if/elseif untuk kompatibilitas PHP 7.4+
    if ( $filter === 'expired' ) {
        $status_clause = "AND (l.status='expired' OR l.expires_at <= NOW())";
    } elseif ( $filter === 'active' ) {
        $status_clause = "AND l.status='active' AND l.expires_at > NOW()";
    } else {
        $status_clause = ''; // 'all' atau default
    }
    
    $search_clause = $search ? $wpdb->prepare( "AND (e.title LIKE %s OR l.borrower_email LIKE %s)", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' ) : '';

    $loans = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT l.*, e.title, e.cover_url 
             FROM $loans_tbl l
             JOIN $ebooks_tbl e ON l.ebook_id = e.id
             WHERE 1=1 $status_clause $search_clause
             ORDER BY l.borrowed_at DESC
             LIMIT 200"
        )
    );

    $count_active  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $loans_tbl WHERE status='active' AND expires_at > NOW()" );
    $count_expired = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $loans_tbl WHERE status='expired' OR expires_at <= NOW()" );
    $count_all     = $count_active + $count_expired;
    ?>
    <div class="wrap diglib-wrap">
        <h1>📋 <?php esc_html_e( 'Data Peminjaman', 'diglib' ); ?></h1>

        <div style="display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;align-items:center;">
            <?php
            $tabs = [
                'active'  => sprintf( __( 'Aktif (%d)', 'diglib' ), $count_active ),
                'expired' => sprintf( __( 'Expired (%d)', 'diglib' ), $count_expired ),
                'all'     => sprintf( __( 'Semua (%d)', 'diglib' ), $count_all ),
            ];
            foreach ( $tabs as $key => $label ):
                $active_tab = $filter === $key ? 'background:#4f46e5;color:#fff;' : 'background:#f3f4f6;color:#374151;';
            ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=diglib-loans&filter=" . urlencode( $key ) ) ); ?>"
                   class="diglib-btn-secondary"
                   style="<?php echo esc_attr( $active_tab ); ?> padding:8px 18px;border-radius:8px;text-decoration:none;font-weight:600;">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>

            <form method="get" style="margin-left:auto;display:flex;gap:8px;">
                <input type="hidden" name="page" value="diglib-loans">
                <input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
                <input type="text" name="s" placeholder="<?php esc_attr_e( 'Cari email atau judul...', 'diglib' ); ?>" value="<?php echo esc_attr( $search ); ?>" style="padding:7px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.9rem;">
                <button type="submit" class="diglib-btn-secondary"><?php esc_html_e( 'Cari', 'diglib' ); ?></button>
                <?php if ( $search ): ?>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=diglib-loans&filter=" . urlencode( $filter ) ) ); ?>" class="diglib-btn-secondary">× <?php esc_html_e( 'Reset', 'diglib' ); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="diglib-table-wrap">
            <table class="diglib-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Ebook', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Email Peminjam', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Tanggal Pinjam', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Berakhir', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'diglib' ); ?></th>
                        <th><?php esc_html_e( 'Aksi', 'diglib' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $loans ) ): ?>
                    <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:40px;">
                        <?php esc_html_e( 'Tidak ada data peminjaman', 'diglib' ); ?>
                        <?php if ( $search ): ?>
                            <?php echo ' "' . esc_html( $search ) . '".'; ?>
                        <?php else: ?>
                            .
                        <?php endif; ?>
                    </td></tr>
                <?php else: foreach ( $loans as $loan ):
                    $is_active = $loan->status === 'active' && strtotime( $loan->expires_at ) > time();
                    $badge_class = $is_active ? 'diglib-badge-green' : 'diglib-badge-gray';
                    $badge_text  = $is_active ? __( 'Aktif', 'diglib' ) : __( 'Expired', 'diglib' );
                    $sisa_hari   = $is_active ? ceil( ( strtotime( $loan->expires_at ) - time() ) / 86400 ) : 0;
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if ( $loan->cover_url ): ?>
                                <img src="<?php echo esc_url( $loan->cover_url ); ?>" class="diglib-cover-thumb" alt="<?php echo esc_attr( $loan->title ); ?>">
                            <?php else: ?>
                                <div class="diglib-cover-placeholder-sm">📖</div>
                            <?php endif; ?>
                            <span><?php echo esc_html( $loan->title ); ?></span>
                        </div>
                    </td>
                    <td><?php echo esc_html( $loan->borrower_email ); ?></td>
                    <td><?php echo esc_html( date_i18n( 'd M Y, H:i', strtotime( $loan->borrowed_at ) ) ); ?></td>
                    <td>
                        <?php echo esc_html( date_i18n( 'd M Y, H:i', strtotime( $loan->expires_at ) ) ); ?>
                        <?php if ( $is_active && $sisa_hari > 0 ): ?>
                            <br><small style="color:#6b7280;">(<?php echo esc_html( $sisa_hari ); ?> <?php esc_html_e( 'hari lagi', 'diglib' ); ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td><span class="diglib-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span></td>
                    <td>
                        <?php if ( $is_active ): ?>
                            <form method="post" class="diglib-inline-form">
                                <?php wp_nonce_field( 'diglib_extend_' . $loan->id ); ?>
                                <input type="hidden" name="action" value="extend_date">
                                <input type="hidden" name="loan_id" value="<?php echo esc_attr( $loan->id ); ?>">
                                <input type="date" name="new_expire_date" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( $loan->expires_at ) ) ); ?>" required>
                                <button type="submit" class="diglib-btn-success" onclick="return confirm('<?php esc_attr_e( 'Ubah tanggal berakhir peminjaman?', 'diglib' ); ?>')"><?php esc_html_e( 'Ubah Tgl', 'diglib' ); ?></button>
                            </form>
                            <br>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=diglib-loans&filter=' . urlencode( $filter ) . '&action=revoke&id=' . $loan->id ), 'diglib_revoke_' . $loan->id ) ); ?>"
                               class="diglib-btn-danger"
                               onclick="return confirm('<?php esc_attr_e( 'Cabut akses peminjaman ini?', 'diglib' ); ?>')">✕ <?php esc_html_e( 'Cabut', 'diglib' ); ?></a>
                        <?php else: ?>
                            <span style="color:#9ca3af;font-size:.85rem;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
