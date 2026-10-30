<?php

namespace App\Http\Controllers;

use App\Models\DeviceEnrollment;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class DeviceController
{
    private function permit(Request $request): void
    {
        abort_unless($request->user()->canDo('devices.manage'), 403);
    }

    private function branches(Request $request)
    {
        return $request->user()->role->company_wide
            ? \App\Models\Branch::query()
            : \App\Models\Branch::whereIn('id', $request->user()->branches()->select('branches.id'));
    }

    public function enroll(Request $request)
    {
        $this->permit($request);
        abort_unless(TenantModule::where('key', 'access')->where('enabled', true)->exists(), 403, 'Team access is disabled.');
        $data = $request->validate([
            'device_uuid' => 'required|uuid',
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $request->user()->tenant_id)->where('active', true)],
            'name' => 'required|string|max:120',
            'platform' => ['required', Rule::in(['android', 'ios', 'windows', 'macos', 'other'])],
        ]);
        $branch = $this->branches($request)->where('active', true)->findOrFail($data['branch_id']);
        $tenantId = app(TenantContext::class)->requireId();

        $result = DB::transaction(function () use ($request, $data, $branch, $tenantId) {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if (DeviceEnrollment::where('device_uuid', $data['device_uuid'])->exists()) {
                throw ValidationException::withMessages(['device_uuid' => 'This device is already enrolled. Revoke its previous record before enrolling it again.']);
            }
            $device = DeviceEnrollment::create([
                'device_uuid' => $data['device_uuid'], 'user_id' => $request->user()->id,
                'branch_id' => $branch->id, 'name' => $data['name'], 'platform' => $data['platform'],
            ]);
            $issued = $request->user()->createToken('device-sync:'.$device->device_uuid, ['device:heartbeat']);
            $device->update(['personal_access_token_id' => $issued->accessToken->id]);
            Audit::record('device.enrolled', (string) $device->id, ['device_uuid' => $device->device_uuid, 'branch_id' => $device->branch_id, 'platform' => $device->platform]);

            return ['device' => $device->load('branch', 'user'), 'token' => $issued->plainTextToken];
        }, 3);

        return response()->json([
            'data' => [
                'id' => $result['device']->id, 'device_uuid' => $result['device']->device_uuid,
                'name' => $result['device']->name, 'branch' => $result['device']->branch->name,
                'user' => $result['device']->user->name, 'token' => $result['token'],
            ],
            'message' => 'Device enrolled. Copy the sync credential now; it will not be shown again.',
        ], 201);
    }

    public function revoke(Request $request, int $id)
    {
        $this->permit($request);
        $device = DB::transaction(function () use ($request, $id) {
            Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $device = DeviceEnrollment::whereIn('branch_id', $this->branches($request)->select('id'))->lockForUpdate()->findOrFail($id);
            if ($device->revoked_at) return $device;
            if ($device->personal_access_token_id) {
                PersonalAccessToken::whereKey($device->personal_access_token_id)->where('tokenable_type', User::class)->where('tokenable_id', $device->user_id)->delete();
            }
            $device->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);
            Audit::record('device.revoked', (string) $device->id, ['device_uuid' => $device->device_uuid, 'branch_id' => $device->branch_id]);
            return $device;
        }, 3);

        return response()->json(['data' => $device->only('id', 'revoked_at'), 'message' => 'Device access revoked.']);
    }

    public function heartbeat(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('device:heartbeat') && str_starts_with($token->name, 'device-sync:'), 403);
        $deviceUuid = substr($token->name, strlen('device-sync:'));
        $device = DeviceEnrollment::where('device_uuid', $deviceUuid)
            ->where('user_id', $request->user()->id)
            ->where('personal_access_token_id', $token->id)
            ->whereNull('revoked_at')
            ->firstOrFail();
        $device->update(['last_seen_at' => now()]);

        return response()->json(['data' => ['device_uuid' => $device->device_uuid, 'active' => true, 'branch_id' => $device->branch_id, 'last_seen_at' => $device->last_seen_at->toIso8601String()]]);
    }
}
