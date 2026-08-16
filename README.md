# BUMDesma Backend API

REST API berbasis **PHP native** dan **MySQL** yang menjadi backend dari aplikasi **BUMDesma Android** — Sistem Informasi Pencatatan Kredit untuk program Simpan Pinjam Perempuan (SPP) pada BUMDesma Mandiri Sejahtera LKD Randudongkal. API ini menangani seluruh proses bisnis mulai dari autentikasi, pengajuan proposal pinjaman, verifikasi anggota, pencatatan angsuran, hingga laporan keuangan.

---

## Tentang Penelitian

Repository ini merupakan bagian dari implementasi skripsi berjudul:

> **Sistem Informasi Pencatatan Kredit pada BUMDesma Randudongkal Berbasis Android**

API ini dikonsumsi oleh aplikasi client [bumdesma-android](https://github.com/ilhanhanz-ux/bumdesma-android) melalui Retrofit, dengan setiap entitas ditangani oleh satu skrip PHP yang menerima beberapa metode HTTP (GET/POST/PUT) sekaligus, bukan endpoint bergaya path REST.

---

## Fitur / Endpoint

**Autentikasi & Akun**
- `login.php` — Login anggota & admin (mengembalikan status `is_ketua`)
- `register.php` — Registrasi akun anggota baru
- `lupa_password.php` / `ubah_password.php` — Reset & ubah kata sandi
- `profil.php` / `profil_admin.php` — Data profil anggota & admin

**Proposal & Kredit**
- `proposal.php` — Pengajuan & verifikasi proposal pinjaman (Setujui/Tolak/Minta Revisi), termasuk perhitungan limit dinamis per kelompok
- `kredit.php` — Ringkasan kredit aktif anggota
- `alokasi_pinjaman.php` — Alokasi pinjaman ke masing-masing anggota kelompok
- `ajukan.php` — Pengajuan terkait proposal

**Angsuran & Setoran**
- `angsuran.php` — Pencatatan dan riwayat angsuran
- `angsuran_porsi.php` — Pembagian & setoran angsuran per porsi anggota, dengan alur verifikasi bukti transfer
- `setoran_angsuran.php` — Pencatatan setoran angsuran oleh admin
- `setoran_kolektif.php` — Pencatatan setoran kolektif per kelompok
- `riwayat.php` / `riwayat_kredit_anggota.php` — Riwayat pembayaran & kredit anggota

**Anggota & Kelompok**
- `data_anggota.php` — Data anggota
- `verifikasi_anggota.php` — Verifikasi pendaftaran anggota baru
- `kelompok.php` / `kelompok_saya.php` — Data kelompok SPP

**Lainnya**
- `laporan.php` — Laporan keuangan
- `pengumuman.php` — Pengumuman untuk anggota
- `rekening.php` — Data rekening setoran
- `riwayat_aktifitas.php` / `riwayat_aktifitas_anggota.php` — Log aktivitas
- `notification.php` — Notifikasi
- `cron_reminder_angsuran.php` — Pengingat jatuh tempo angsuran terjadwal (cron job)
- `backfill_kredit.php` — Skrip utilitas backfill data kredit

---

## Teknologi yang Digunakan

- **PHP native** (satu skrip per entitas, menangani beberapa metode HTTP)
- **MySQL** sebagai basis data
- Autentikasi berbasis token (Bearer token)

---

## Instalasi & Menjalankan

Clone repository:
```
git clone https://github.com/ilhanhanz-ux/bumdesma-backend-api.git
```

Masuk ke folder project, lalu letakkan pada direktori server lokal (mis. `htdocs` untuk XAMPP):
```
cd bumdesma-backend-api
```

Buat database MySQL (`db_bumdesma`) dan import struktur tabel yang dibutuhkan (anggota, pengajuan_proposal, kredit_aktif, angsuran, angsuran_porsi, rekening_setoran, dsb).

Sesuaikan kredensial koneksi database (host, username, password, nama database) pada file konfigurasi koneksi sebelum menjalankan server.

Jalankan server lokal (contoh menggunakan XAMPP/Apache), lalu pastikan base URL API dapat diakses oleh aplikasi Android (mis. melalui tunnel seperti ngrok untuk testing).

---

## Author

**Muhamad Ilhan Maulana Aziz**
Informatika – Universitas AMIKOM Yogyakarta

GitHub: [@ilhanhanz-ux](https://github.com/ilhanhanz-ux)
