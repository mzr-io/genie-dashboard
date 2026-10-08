<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MemberAttributesRequest;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\AttributesUnavailable;
use App\Modules\Access\Contracts\AttributeValueInvalid;
use App\Modules\Access\Contracts\AttributeValueRow;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MemberAttributes;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\SelfChangeForbidden;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A member's user attribute values (Story 2.12), behind the `admin` middleware (`users.manage`). Values are the most
 * sensitive thing here: they are returned (decrypted) only by `show` to the Admin who may set them, never cached, and
 * never part of an error message, a log line or an audit row. Nobody edits their own attributes (403, recorded as a
 * security event); a membership of another Workspace is a 404. With the `data` or the `digest` key unusable it answers 503.
 */
final class MemberAttributeController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly MemberAttributes $attributes,
        private readonly MembershipLookup $memberships,
        private readonly Audit $audit,
    ) {}

    public function show(Request $request, string $membership): JsonResponse
    {
        try {
            $rows = $this->attributes->forMember($this->workspaceId($request), $membership);
        } catch (MembershipNotFound) {
            abort(404);
        } catch (AttributesUnavailable) {
            return $this->unavailable($request);
        }

        return response()->json(['data' => array_map($this->row(...), $rows)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function update(MemberAttributesRequest $request, string $membership): JsonResponse
    {
        $editor = $this->editor($request);

        try {
            $changed = $this->attributes->set($editor, $membership, $request->values());
        } catch (MembershipNotFound) {
            abort(404);
        } catch (SelfChangeForbidden) {
            $this->recordRefusal($editor, $membership);

            return AdminApiError::json($request, ErrorCode::SelfChangeForbidden->value, 403, 'Nobody edits their own attributes.');
        } catch (AttributeValueInvalid $e) {
            $errors = [];

            foreach ($e->reasons as $keyId => $reason) {
                $errors['values.'.$keyId] = [match ($reason) {
                    'undefined_key' => 'This attribute is not defined.',
                    'empty' => 'A value is required.',
                    'too_long' => 'This value is too long.',
                    default => 'This value is not valid for the attribute.',
                }];
            }

            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: $errors, extra: ['reasons' => $e->reasons]);
        } catch (AttributesUnavailable) {
            return $this->unavailable($request);
        }

        return response()->json(['data' => ['changed' => $changed]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** @return array{key_id: string, label: string, value_type: string, value: string|null} */
    private function row(AttributeValueRow $row): array
    {
        return ['key_id' => $row->keyId, 'label' => $row->label, 'value_type' => $row->valueType, 'value' => $row->value];
    }

    private function unavailable(Request $request): JsonResponse
    {
        return AdminApiError::json($request, ErrorCode::AttributesUnavailable->value, 503, 'User attributes are not available right now.');
    }

    /** A refused self edit as a security event on its own connection (it outlives the rolled-back request); IDs only. */
    private function recordRefusal(MemberEditor $editor, string $target): void
    {
        try {
            $values = ['route' => 'api.admin.members.attributes.update', 'reason' => 'self_change', 'user_id' => $editor->userId, 'membership_id' => $editor->membershipId, 'area' => 'admin'];
            $subject = Str::isUuid($target) ? 'membership:'.strtolower($target) : null;

            $this->audit->recordSecurityEvent(AuditAction::AccessAdminDenied, $values, $editor->workspaceId, subject: $subject, actor: $editor->membershipId);
        } catch (Throwable $e) {
            Log::error('access.admin.denied_record_failed', ['exception' => $e::class]);
        }
    }

    private function editor(Request $request): MemberEditor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new MemberEditor($user->id, $membership->membershipId, $membership->workspaceId);
            }
        }

        abort(404);
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
