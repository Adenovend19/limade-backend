<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\BusinessTypeRole;
use Illuminate\Database\Seeder;

class BusinessTypeRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pos = BusinessType::where('code', 'pos')->first();
        $billing = BusinessType::where('code', 'billing')->first();
        $quotation = BusinessType::where('code', 'quotation')->first();

        // 1. Point of Sale (POS) Roles
        if ($pos) {
            $posRoles = [
                [
                    'code' => 'pos_manager',
                    'name' => 'Manajer Toko POS',
                    'description' => 'Mengelola seluruh operasional gerai, shift kasir, dan laporan penjualan ritel.',
                ],
                [
                    'code' => 'cashier',
                    'name' => 'Kasir Toko',
                    'description' => 'Melayani transaksi pembayaran belanja kasir (QRIS/Tunai) dan cetak struk belanja.',
                ],
                [
                    'code' => 'supervisor',
                    'name' => 'Supervisor Shift',
                    'description' => 'Verifikasi diskon promo khusus, pembatalan/void struk transaksi, dan tutup buku kasir.',
                ],
                [
                    'code' => 'inventory_staff',
                    'name' => 'Staf Gudang POS',
                    'description' => 'Pencatatan stok barang masuk, mutasi stok antar rak, dan verifikasi stok opname.',
                ],
            ];

            foreach ($posRoles as $role) {
                BusinessTypeRole::updateOrCreate(
                    ['business_type_id' => $pos->id, 'code' => $role['code']],
                    ['name' => $role['name'], 'description' => $role['description']]
                );
            }
        }

        // 2. Billing & Invoicing Roles
        if ($billing) {
            $billingRoles = [
                [
                    'code' => 'billing_admin',
                    'name' => 'Administrator Billing',
                    'description' => 'Mengelola konfigurasi sistem faktur, master klien langganan, dan template tagihan.',
                ],
                [
                    'code' => 'finance_officer',
                    'name' => 'Staf Finance & Penagihan',
                    'description' => 'Menerbitkan, menjadwalkan, dan mengirim faktur tagihan invoice ke klien.',
                ],
                [
                    'code' => 'accountant',
                    'name' => 'Akuntan Perusahaan',
                    'description' => 'Rekonsiliasi konfirmasi pembayaran bank, jurnal penerimaan, dan laporan piutang usaha.',
                ],
            ];

            foreach ($billingRoles as $role) {
                BusinessTypeRole::updateOrCreate(
                    ['business_type_id' => $billing->id, 'code' => $role['code']],
                    ['name' => $role['name'], 'description' => $role['description']]
                );
            }
        }

        // 3. Quotation System Roles
        if ($quotation) {
            $quotationRoles = [
                [
                    'code' => 'quotation_admin',
                    'name' => 'Administrator Quotation',
                    'description' => 'Mengelola katalog produk tender, data klien korporat, dan master syarat kontrak proyek.',
                ],
                [
                    'code' => 'sales_estimator',
                    'name' => 'Sales Estimator',
                    'description' => 'Menyusun rincian rencana anggaran biaya (RAB) dan draf surat penawaran harga.',
                ],
                [
                    'code' => 'project_approver',
                    'name' => 'Project Approver',
                    'description' => 'Memberikan persetujuan (approval) resmi penawaran harga dan otorisasi diskon tender.',
                ],
            ];

            foreach ($quotationRoles as $role) {
                BusinessTypeRole::updateOrCreate(
                    ['business_type_id' => $quotation->id, 'code' => $role['code']],
                    ['name' => $role['name'], 'description' => $role['description']]
                );
            }
        }
    }
}
