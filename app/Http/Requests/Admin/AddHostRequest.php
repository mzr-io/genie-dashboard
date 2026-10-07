<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Connector\Contracts\AllowedHost;
use App\Modules\Connector\Contracts\HostProblem;
use App\Modules\Connector\Contracts\InvalidHost;
use App\Platform\Contracts\ErrorCode;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An entry to add to the host allowlist (Story 2.1): `host` (a `host` or `host:port` string), `scheme` (`http` or
 * `https`, default `https`) and the list's `revision`. The host rules are the server's alone and nothing is trimmed
 * or repaired; a refusal is a 422 with a field error and the `reason` the page maps to its message. The `admin`
 * middleware has already authorised the request (`settings.manage`).
 */
final class AddHostRequest extends FormRequest
{
    /** @var array<string, HostProblem> field => the problem found */
    private array $problems = [];

    private ?AllowedHost $parsed = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'min:0']];
    }

    /**
     * The host and scheme are parsed after the rules, because an absent or empty value is itself an error to report
     * and a rule skips those. The first problem of a field is its error.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            try {
                $this->parsed = AllowedHost::parse($this->input('host'), $this->input('scheme'));
            } catch (InvalidHost $e) {
                $this->problems[$e->problem->field()] = $e->problem;
                $validator->errors()->add($e->problem->field(), self::message($e->problem));
            }
        }];
    }

    public function revision(): int
    {
        return (int) $this->input('revision');
    }

    public function allowedHost(): AllowedHost
    {
        return $this->parsed ?? throw new \LogicException('The host was not validated.');
    }

    public static function message(HostProblem $problem): string
    {
        return match ($problem) {
            HostProblem::Empty => 'Enter a host.',
            HostProblem::Whitespace => 'The host cannot contain spaces.',
            HostProblem::ForbiddenCharacter => 'Enter a host name only: no scheme, path, query, user, wildcard or percent sign.',
            HostProblem::NonAscii => 'Use the ASCII (punycode) form of the host name.',
            HostProblem::Malformed => 'Enter a host, or a host and port such as api.example.com:8443.',
            HostProblem::InvalidLabel => 'Host names use letters, digits and inner hyphens in each part.',
            HostProblem::TooLong => 'The host name is too long.',
            HostProblem::NumericAddress => 'Write an IP address as four numbers separated by dots, or as an IPv6 address in square brackets.',
            HostProblem::BlockedAddress => 'This address is in a range that cannot be allowed.',
            HostProblem::InvalidPort => 'The port must be a number from 1 to 65535.',
            HostProblem::InvalidScheme => 'Choose http or https.',
        };
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();
        $reasons = array_map(fn (HostProblem $problem): string => $problem->value, $this->problems);

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors, extra: $reasons === [] ? [] : ['reasons' => $reasons]));
    }
}
