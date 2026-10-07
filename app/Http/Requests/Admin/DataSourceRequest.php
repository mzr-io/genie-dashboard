<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Connector\Application\ValidateDataSourceInput;
use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The fields of a Data Source to register or edit (Story 2.3): `name`, `base_url`, `headers` (a list of `{name, value}`),
 * the optional limits `timeout_seconds`, `max_response_bytes` and `max_pages`, `live_capable` and `auth_type`; an edit
 * adds the `revision` it was decided against. Every rule is the server's: nothing but the name is trimmed or repaired,
 * and the refusal is a 422 with a field error and the `reason` the page maps to its message. The `admin` middleware has
 * already authorised the request (`data_sources.manage`).
 */
class DataSourceRequest extends FormRequest
{
    protected ?DataSourceInput $parsed = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ($this->isMethod('PUT') ? ['revision' => ['required', 'integer', 'min:1']] : []) + ['confirm_password' => ['nullable', 'string', 'max:255']];
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
                $this->parsed = app(ValidateDataSourceInput::class)->validate($this->all());
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

    /** @var array<string, string> */
    protected array $reasons = [];

    public function revision(): int
    {
        return (int) $this->input('revision');
    }

    /** The Admin's password for a change of credentials; an empty string when none was given. */
    public function confirmation(): string
    {
        $value = $this->input('confirm_password');

        return is_string($value) ? $value : '';
    }

    public function dataSourceInput(): DataSourceInput
    {
        return $this->parsed ?? throw new \LogicException('The data source was not validated.');
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors, extra: $this->reasons === [] ? [] : ['reasons' => $this->reasons]));
    }
}
