<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * The body of a Fetch as user (Story 2.13): `membership` (the member whose data is fetched) and `values`, the parameter values that
 * are not user-bound. The gate (`data_sources.manage`) has run; `authorize()` adds `data.preview_as_user`: without it the request is a
 * 403 `access.not_authorized` and a security event is recorded, before any field is read. A value for a user-bound name is refused
 * later by the renderer (422 `values.{name}`): the browser never supplies a bound value.
 */
final class FetchAsUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspaceId = $this->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! $user instanceof User || ! is_string($workspaceId)) {
            return false;
        }

        $workspaceId = strtolower($workspaceId);

        if (in_array(Permission::DataPreviewAsUser, app(MembershipPermissions::class)->forUser($user->id, $workspaceId), true)) {
            return true;
        }

        $membershipId = null;

        foreach (app(MembershipLookup::class)->forUser($user->id) as $membership) {
            if ($membership->workspaceId === $workspaceId) {
                $membershipId = $membership->membershipId;
            }
        }

        app(Audit::class)->recordSecurityEvent(
            AuditAction::AccessAdminDenied,
            [
                'user_id' => $user->id,
                'membership_id' => $membershipId,
                'area' => 'admin',
                'route' => $this->route()?->getName() ?? 'unnamed',
                'permission' => Permission::DataPreviewAsUser->value,
                'reason' => 'permission',
            ],
            $workspaceId,
            subject: $membershipId === null ? null : 'membership:'.$membershipId,
            actor: $membershipId,
        );

        return false;
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::NotAuthorized->value, 403, 'Forbidden'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'membership' => ['required', 'string', 'uuid'],
            'values' => ['nullable', 'array', 'max:'.TestEndpointRequest::MAX_VALUES],
            'values.*' => ['nullable', 'string', 'max:'.TestEndpointRequest::VALUE_MAX],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->values()) as $key) {
                if (strlen((string) $key) > TestEndpointRequest::KEY_MAX) {
                    $validator->errors()->add('values', 'The values are not valid.');

                    return;
                }
            }
        }];
    }

    public function membership(): string
    {
        return Str::lower((string) $this->input('membership'));
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        $values = $this->input('values');

        return is_array($values) ? $values : [];
    }
}
