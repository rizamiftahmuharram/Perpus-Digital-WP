# 📚 Plugin Perpus Digital Berbasis WordPress

Sebuah plugin WordPress yang ringan dan aman untuk mengelola perpustakaan digital. Plugin ini menyediakan antarmuka admin yang modern untuk mengelola katalog ebook, melacak stok, dan mengelola data peminjaman pengguna.

## ✨ Fitur Utama

- 📊 **Dashboard Statistik**: Ringkasan visual total ebook, peminjaman aktif, stok habis, dan peminjaman kedaluwarsa.
- 📖 **Manajemen Ebook (CRUD)**: Tambah, edit, dan hapus ebook dengan mudah.
- 📦 **Manajemen Stok Otomatis**: Stok tersedia berkurang saat dipinjam dan dapat di-reset secara massal.
- 📋 **Pelacakan Peminjaman**: Lihat status peminjaman (Aktif/Expired), perpanjang masa pinjam, atau cabut akses secara manual.
- 🖼️ **Integrasi WordPress Media Library**: Pilih cover gambar dan file PDF langsung dari media library WordPress.
- 🔒 **Keamanan Terjamin**: 
  - Pencegahan akses langsung (`ABSPATH` check).
  - Verifikasi Nonce untuk semua aksi form dan URL.
  - Pemeriksaan kapabilitas pengguna (`manage_options`).
  - Sanitasi dan escaping data yang ketat (`sanitize_text_field`, `esc_html`, `esc_url`, dll).
- 🎨 **UI Modern**: Tampilan admin yang bersih dan responsif dengan CSS kustom.

## ⚙️ Persyaratan Sistem

- **WordPress**: 5.8 atau lebih tinggi
- **PHP**: 7.4 atau lebih tinggi (direkomendasikan PHP 8.0+)
- **Database**: MySQL 5.7+ atau MariaDB 10.3+

## 📦 Instalasi

1. Download file ZIP plugin ini atau clone repositori ini.
2. Ekstrak folder plugin ke direktori `/wp-content/plugins/` di instalasi WordPress Anda.
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/username-anda/nama-repo.git digital-library
3. Masuk ke dashboard WordPress Anda, lalu navigasi ke Plugins > Installed Plugins.
4. Cari "Digital Library Admin" dan klik Activate.
5. Menu "Perpustakaan" akan muncul di sidebar admin WordPress Anda.

## 🗄️ Catatan Penting: Struktur Database

Plugin ini mengasumsikan keberadaan dua tabel kustom di database WordPress Anda. Jika tabel ini belum dibuat, Anda perlu membuatnya terlebih dahulu melalui phpMyAdmin atau WP-CLI. Berikut adalah skema dasarnya:
```bash
CREATE TABLE IF NOT EXISTS wp_diglib_ebooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255),
    description TEXT,
    cover_url VARCHAR(255),
    file_url VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    total_copies INT DEFAULT 25,
    available_copies INT DEFAULT 25,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wp_diglib_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ebook_id INT NOT NULL,
    borrower_email VARCHAR(255) NOT NULL,
    borrowed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    status ENUM('active', 'expired') DEFAULT 'active',
    FOREIGN KEY (ebook_id) REFERENCES wp_diglib_ebooks(id) ON DELETE CASCADE
);
```

(Catatan: Ganti prefix wp_ dengan prefix database WordPress Anda jika berbeda).

💡 Tips: Anda dapat menambahkan logika pembuatan tabel ini secara otomatis menggunakan hook register_activation_hook di file utama plugin Anda agar lebih praktis.

## 🛡️ Praktik Keamanan yang Diterapkan

Kode plugin ini telah diaudit dan dibersihkan sesuai dengan WordPress Coding Standards:
1. Direct Access Prevention: defined( 'ABSPATH' ) || exit; di baris pertama.
2. Capability Checks: current_user_can( 'manage_options' ) di setiap endpoint admin.
3. Nonce Verification: check_admin_referer() dan wp_verify_nonce() untuk semua aksi POST/GET.
4. Data Sanitization: Input dibersihkan menggunakan sanitize_text_field(), sanitize_textarea_field(), dan esc_url_raw().
5. Data Escaping: Output diamankan dengan esc_html(), esc_attr(), dan esc_url() untuk mencegah XSS.
6. Prepared Statements: Semua query database menggunakan $wpdb->prepare() untuk mencegah SQL Injection.

## 🤝 Kontribusi

Jika Anda menemukan bug atau memiliki ide fitur baru:
1. Fork repositori ini.
2. Buat branch fitur baru (git checkout -b feature/AmazingFeature).
3. Commit perubahan Anda (git commit -m 'Add some AmazingFeature').
4. Push ke branch (git push origin feature/AmazingFeature).
5. Buka Pull Request.
