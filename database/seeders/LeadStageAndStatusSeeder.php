<?php

namespace Database\Seeders;

use App\Models\LeadStage;
use App\Models\Status;
use Illuminate\Database\Seeder;

class LeadStageAndStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stages = [
            [
                'name' => 'Inquiry / Lead',
                'sort_order' => 1,
                'description' => 'Initial inquiry or contact from the lead.',
                'statuses' => [
                    [
                        'name' => 'New Inquiry',
                        'sort_order' => 1,
                        'color_code' => '#3B82F6',
                        'description' => 'A newly received lead/inquiry.',
                        'is_need_sms' => false,
                    ],
                    [
                        'name' => 'Contacted',
                        'sort_order' => 2,
                        'color_code' => '#60A5FA',
                        'description' => 'Initial contact established with the lead.',
                        'is_need_sms' => false,
                    ],
                    [
                        'name' => 'Unreachable',
                        'sort_order' => 3,
                        'color_code' => '#9CA3AF',
                        'description' => 'Failed attempts to contact the lead.',
                        'is_need_sms' => false,
                    ],
                ]
            ],
            [
                'name' => 'Opportunity',
                'sort_order' => 2,
                'description' => 'Lead has qualified and shows potential business opportunity.',
                'statuses' => [
                    [
                        'name' => 'Meeting Scheduled',
                        'sort_order' => 1,
                        'color_code' => '#8B5CF6',
                        'description' => 'Discussion or demo meeting scheduled.',
                        'is_need_sms' => true,
                    ],
                    [
                        'name' => 'Proposal Sent',
                        'sort_order' => 2,
                        'color_code' => '#EC4899',
                        'description' => 'Business proposal or quotation sent.',
                        'is_need_sms' => false,
                    ],
                ]
            ],
            [
                'name' => 'Negotiation',
                'sort_order' => 3,
                'description' => 'Contract terms or pricing are being negotiated.',
                'statuses' => [
                    [
                        'name' => 'Under Discussion',
                        'sort_order' => 1,
                        'color_code' => '#F97316',
                        'description' => 'Actively negotiating proposal details.',
                        'is_need_sms' => false,
                    ],
                    [
                        'name' => 'Contract Sent',
                        'sort_order' => 2,
                        'color_code' => '#14B8A6',
                        'description' => 'Final agreement/contract sent for signature.',
                        'is_need_sms' => false,
                    ],
                ]
            ],
            [
                'name' => 'Closed',
                'sort_order' => 4,
                'description' => 'Final outcome of the lead pipeline.',
                'statuses' => [
                    [
                        'name' => 'Won / Converted',
                        'sort_order' => 1,
                        'color_code' => '#10B981',
                        'description' => 'Lead successfully won and converted to a customer.',
                        'is_need_sms' => true,
                    ],
                    [
                        'name' => 'Lost',
                        'sort_order' => 2,
                        'color_code' => '#EF4444',
                        'description' => 'Lead lost or disqualified.',
                        'is_need_sms' => false,
                    ],
                ]
            ],
        ];

        foreach ($stages as $stageData) {
            $statuses = $stageData['statuses'];
            unset($stageData['statuses']);

            $stage = LeadStage::updateOrCreate(
                ['name' => $stageData['name']],
                $stageData
            );

            foreach ($statuses as $statusData) {
                $statusData['lead_stage_id'] = $stage->id;
                Status::updateOrCreate(
                    ['name' => $statusData['name']],
                    $statusData
                );
            }
        }
    }
}
