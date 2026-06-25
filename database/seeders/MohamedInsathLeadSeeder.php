<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Status;
use App\Models\User;
use Illuminate\Database\Seeder;

class MohamedInsathLeadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $status = Status::where('name', 'New Inquiry')->first() ?: Status::first();
        $user = User::first();

        if (!$status) {
            $this->command->error('No status found. Please run LeadStageAndStatusSeeder first.');
            return;
        }

        if (!$user) {
            $this->command->error('No user found. Please run UserSeeder first.');
            return;
        }

        Lead::updateOrCreate(
            ['phone_primary' => '0750552243'],
            [
                'name' => 'mohamed insath',
                'birthday' => '1997-06-25',
                'status_id' => $status->id,
                'created_by' => $user->id,
            ]
        );

        $this->command->info('Test Lead "mohamed insath" seeded successfully.');
    }
}
