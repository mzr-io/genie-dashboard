<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Access\Contracts\AttributeKeyRow;
use App\Modules\Access\Contracts\AttributeKeys;
use App\Modules\Connector\Application\ValidateEndpointInput;
use App\Modules\Connector\Contracts\EndpointInput;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Platform\Contracts\ErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The fields of an Endpoint to register or edit (Story 2.9): `method`, `path`, `params` and `headers` (lists of
 * `{name, binding, value}`), `body_template` (JSON text, so no number is rewritten on the way), `read_only_query` and
 * `confirm_read_only`; an edit adds the `revision` it was decided against. Every rule is the server's: nothing is trimmed or
 * repaired, and the refusal is a 422 with a field error and the `reason` the page maps to its message. The `admin` middleware
 * has already authorised the request (`data_sources.manage`).
 */
final class EndpointRequest extends FormRequest
{
    private ?EndpointInput $parsed = null;

    /** @var array<string, string> */
    private array $reasons = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->isMethod('PUT') ? ['revision' => ['required', 'integer', 'min:1']] : [];
    }

    /**
     * The fields are validated after the rules so that one response carries every error.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            try {
                $this->parsed = app(ValidateEndpointInput::class)->validate($this->all(), $this->attributeKeyIds());
            } catch (InvalidDataSource $e) {
                foreach ($e->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }

                $this->reasons = $e->reasons;
            }
        }];
    }

    /**
     * The attribute key ids the session's Workspace defines (Story 2.12): a `user_attribute` binding must name one.
     *
     * @return list<string>
     */
    private function attributeKeyIds(): array
    {
        $workspaceId = $this->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! is_string($workspaceId)) {
            return [];
        }

        return array_map(fn (AttributeKeyRow $key): string => $key->keyId, app(AttributeKeys::class)->list(strtolower($workspaceId)));
    }

    public function revision(): int
    {
        return (int) $this->input('revision');
    }

    public function endpointInput(): EndpointInput
    {
        return $this->parsed ?? throw new \LogicException('The endpoint was not validated.');
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors, extra: $this->reasons === [] ? [] : ['reasons' => $this->reasons]));
    }
}
