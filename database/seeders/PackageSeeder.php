<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pos = BusinessType::where('code', 'pos')->first();
        $billing = BusinessType::where('code', 'billing')->first();
        $quotation = BusinessType::where('code', 'quotation')->first();

        $packages = [
            // POS Packages
            [
                'business_type_id' => $pos?->id,
                'name' => 'POS Bulanan',
                'code' => 'pos-monthly',
                'price' => 50000.00,
                'duration_in_days' => 30,
                'description' => 'Paket kasir POS durasi 30 hari penuh.',
                'features' => ['Fitur Kasir POS', 'Laporan Penjualan Harian', 'Manajemen Stok Dasar'],
                'is_active' => true,
            ],
            [
                'business_type_id' => $pos?->id,
                'name' => 'POS Tahunan (Hemat)',
                'code' => 'pos-yearly',
                'price' => 500000.00,
                'duration_in_days' => 365,
                'description' => 'Paket kasir POS durasi 1 tahun hemat 2 bulan.',
                'features' => ['Fitur Kasir POS Lengkap', 'Laporan Keuangan & Laba Rugi', 'Multi Kasir & Multi Cabang', 'Dukungan Prioritas'],
                'is_active' => true,
            ],

            // Billing Packages
            [
                'business_type_id' => $billing?->id,
                'name' => 'Billing Bulanan',
                'code' => 'billing-monthly',
                'price' => 75000.00,
                'duration_in_days' => 30,
                'description' => 'Paket billing & invoicing durasi 30 hari.',
                'features' => ['Kirim Invoice Digital', 'Pencatatan Piutang', 'Pengingat Pembayaran Otomatis'],
                'is_active' => true,
            ],
            [
                'business_type_id' => $billing?->id,
                'name' => 'Billing Tahunan (Hemat)',
                'code' => 'billing-yearly',
                'price' => 750000.00,
                'duration_in_days' => 365,
                'description' => 'Paket billing durasi 1 tahun dengan fitur otomatisasi.',
                'features' => ['Invoice Unlimited', 'Integrasi Email & WhatsApp', 'Rekonsiliasi Bank', 'Dukungan Prioritas'],
                'is_active' => true,
            ],

            // Quotation Packages
            [
                'business_type_id' => $quotation?->id,
                'name' => 'Quotation Bulanan',
                'code' => 'quotation-monthly',
                'price' => 40000.00,
                'duration_in_days' => 30,
                'description' => 'Paket surat penawaran harga 30 hari.',
                'features' => ['Template Penawaran Standar', 'Export PDF', 'Pelacakan Status Approval'],
                'is_active' => true,
            ],
            [
                'business_type_id' => $quotation?->id,
                'name' => 'Quotation Tahunan (Hemat)',
                'code' => 'quotation-yearly',
                'price' => 400000.00,
                'duration_in_days' => 365,
                'description' => 'Paket surat penawaran 1 tahun profesional.',
                'features' => ['Custom Template & Branding', 'Tanda Tangan Elektronik', 'Konversi Otomatis ke Invoice', 'Export PDF & Excel'],
                'is_active' => true,
            ],
        ];

        foreach ($packages as $pkg) {
            if ($pkg['business_type_id']) {
                Package::updateOrCreate(['code' => $pkg['code']], $pkg);
            }
        }
    }
}
