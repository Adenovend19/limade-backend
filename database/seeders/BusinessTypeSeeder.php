<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'pos',
                'name' => 'Point of Sale (POS)',
                'description' => 'Sistem kasir, manajemen stok, dan transaksi penjualan ritel.',
                'is_active' => true,
            ],
            [
                'code' => 'billing',
                'name' => 'Billing & Invoicing',
                'description' => 'Sistem penagihan otomatis, invoice, dan pencatatan piutang pelanggan.',
                'is_active' => true,
            ],
            [
                'code' => 'quotation',
                'name' => 'Quotation System',
                'description' => 'Sistem pembuatan surat penawaran harga, estimasi biaya, dan approval tender.',
                'is_active' => true,
            ],
        ];

        foreach ($types as $type) {
            BusinessType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
