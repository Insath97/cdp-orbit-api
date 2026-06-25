<?php

use App\Services\SmsService;
use App\Models\Lead;
use App\Models\Status;
use App\Models\LeadStage;
use App\Models\User;
use Illuminate\Support\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('sends birthday wishes to leads celebrating their birthday today', function () {
    // 1. Mock SmsService
    $smsMock = $this->mock(SmsService::class);
    $smsMock->shouldReceive('resolveTemplateContent')
        ->andReturnUsing(function ($template, $lead) {
            return "Dear " . $lead->name . ", Wishing you a very Happy Birthday! May this year bring you joy and success.";
        });
    $smsMock->shouldReceive('sendSms')
        ->once()
        ->with('0771234567', \Mockery::on(function ($message) {
            return str_contains($message, 'Dear Test Lead') 
                && str_contains($message, 'CDP Empire (Pvt) Ltd.')
                && str_contains($message, '+94 114 007 007');
        }))
        ->andReturn(true);

    // 2. Setup database entities
    $user = User::factory()->create([
        'username' => 'testuser',
    ]);
    
    $leadStage = LeadStage::create([
        'name' => 'Test Stage',
    ]);
    
    $status = Status::create([
        'lead_stage_id' => $leadStage->id,
        'name' => 'Test Status',
        'color_code' => '#ffffff',
    ]);

    // 3. Create a lead celebrating their birthday today
    $birthdayLead = Lead::create([
        'name' => 'Test Lead',
        'phone_primary' => '0771234567',
        'birthday' => Carbon::today()->subYears(25), // birthday is today
        'status_id' => $status->id,
        'created_by' => $user->id,
    ]);

    // 4. Create another lead whose birthday is NOT today
    $otherLead = Lead::create([
        'name' => 'Other Lead',
        'phone_primary' => '0750552243',
        'birthday' => Carbon::today()->addDays(5),
        'status_id' => $status->id,
        'created_by' => $user->id,
    ]);

    // 5. Run Artisan command
    $this->artisan('app:send-birthday-wishes')
        ->expectsOutput('Scanning for leads celebrating their birthday today...')
        ->expectsOutput('Found 1 lead(s) celebrating today.')
        ->expectsOutput('Birthday wish successfully sent to lead ID ' . $birthdayLead->id . ' (Test Lead).')
        ->assertExitCode(0);
});
