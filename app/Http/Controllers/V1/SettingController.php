<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSettingsRequest;
use App\Models\SystemSetting;
use App\Traits\ActivityLogTrait;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the controller middleware and permissions mapping.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Setting Index', only: ['index']),
            new Middleware('permission:Setting Update', only: ['update']),
        ];
    }

    /**
     * Fetch all system settings as a key-value pair map.
     */
    public function index()
    {
        try {
            $settings = SystemSetting::all()->pluck('value', 'key');

            return response()->json([
                'status' => 'success',
                'message' => 'Settings retrieved successfully',
                'data' => [
                    'settings' => $settings
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve settings',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Bulk save or update system settings.
     */
    public function update(SaveSettingsRequest $request)
    {
        try {
            $settingsInput = $request->input('settings', []);
            $updatedSettings = [];

            foreach ($settingsInput as $key => $value) {
                // Perform update or create dynamically
                $setting = SystemSetting::updateOrCreate(
                    ['key' => $key],
                    ['value' => is_null($value) ? '' : $value]
                );
                
                $updatedSettings[$key] = $setting->value;
            }

            // Log activity
            $this->logActivity('UPDATE_SETTINGS', 'Setting', 'System settings updated successfully.', $settingsInput);

            return response()->json([
                'status' => 'success',
                'message' => 'Settings updated successfully',
                'data' => [
                    'settings' => $updatedSettings
                ],
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update settings',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
