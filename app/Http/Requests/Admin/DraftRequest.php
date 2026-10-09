<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Blocks\Contracts\Drafts;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The fields of a Draft to start or save (Story 3.3). The lenient Draft schema accepts a `name` and a Block Type (`type`) and, on a save, a
 * `category_id`, the wizard `step` and a `config` (Story 3.4); further fields are ignored, and a secret-valued one is refused before this runs (`RejectsSecretValues`). Starting needs the `type`
 * and may name an existing Block (`block_id`) that has no Draft; saving needs the `expected_revision` and the `lock_epoch` it was decided
 * against. The Block Type is checked against the registry by the application. The `admin` middleware has authorised the request (`blocks.edit`).
 */
final class DraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            return [
                'name' => ['required', 'string', 'max:'.Drafts::NAME_MAX],
                'type' => ['nullable', 'string', 'max:64'],
                'expected_revision' => ['required', 'integer', 'min:1'],
                'lock_epoch' => ['required', 'integer', 'min:1'],
                'category_id' => ['nullable', 'uuid'],
                'step' => ['nullable', 'integer', 'between:1,5'],
                'config' => ['nullable', 'array', 'max:20'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:'.Drafts::NAME_MAX],
            'type' => ['required', 'string', 'max:64'],
            'block_id' => ['nullable', 'string', 'max:36'],
        ];
    }

    public function name(): string
    {
        return (string) $this->input('name');
    }

    public function type(): ?string
    {
        $type = $this->input('type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    public function blockId(): ?string
    {
        $id = $this->input('block_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The Configure Block fields (Story 3.4); the application checks them against the Block Type's registry limits. */
    /** @return array<mixed>|null */
    public function config(): ?array
    {
        $config = $this->input('config');

        return is_array($config) ? $config : null;
    }

    public function step(): ?int
    {
        $step = $this->input('step');

        return $step === null || $step === '' ? null : (int) $step;
    }

    public function categoryId(): ?string
    {
        $id = $this->input('category_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function expectedRevision(): int
    {
        return (int) $this->input('expected_revision');
    }

    public function lockEpoch(): int
    {
        return (int) $this->input('lock_epoch');
    }

    /** A 422 in the Admin API's own envelope, with the field errors the page shows. */
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
