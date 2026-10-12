<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Blocks\Contracts\UserSampleRef;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A read of, or a preview from, a Fetch as user result (Story 3.17): the Operation and the Endpoint it fetched, as `as_user_operation`
 * (the route's `{operation}` on the structure read), `data_source_id` and `endpoint_id`. On the structure read (GET) they are required; on a
 * preview (POST) they are optional, and a preview with none is the shared one. A request that names a Fetch as user result needs `data.preview_as_user` like the fetch: without it the
 * request is a 403 and a security event is recorded. The Operation is checked by the Connector against the requester, so an id that is
 * not the caller's reads as gone, whatever it names.
 */
final class DraftUserSampleRequest extends FormRequest
{
    use ChecksPreviewAsUser;

    public function authorize(): bool
    {
        return ! $this->asksAsUser() || $this->mayPreviewAsUser();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $needed = $this->isMethod('GET') ? 'required' : 'required_with:as_user_operation';

        return [
            'as_user_operation' => ['nullable', 'string', 'uuid'],
            'data_source_id' => [$needed, 'string', 'uuid'],
            'endpoint_id' => [$needed, 'string', 'uuid'],
        ];
    }

    public function asksAsUser(): bool
    {
        return $this->isMethod('GET') || is_string($this->input('as_user_operation'));
    }

    /** The result asked for, or null for the shared preview. */
    public function ref(bool $mayPreviewAsUser): ?UserSampleRef
    {
        $operation = $this->isMethod('GET') ? $this->route('operation') : $this->input('as_user_operation');

        if (! is_string($operation)) {
            return null;
        }

        return new UserSampleRef(
            strtolower($operation),
            strtolower((string) $this->input('data_source_id')),
            strtolower((string) $this->input('endpoint_id')),
            $mayPreviewAsUser,
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
