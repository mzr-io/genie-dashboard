<?php

namespace App\Modules\Mapping\Application;

use App\Modules\Mapping\Contracts\SettingNotConfigured;
use BcMath\Number;
use Illuminate\Contracts\Config\Repository;

/**
 * The arithmetic of the Query Plan engine (row calc and aggregate calc stages): `BcMath\Number` at `tunables.numbers.decimal_scale`, never a float.
 * No stage of plan v1 uses it yet (Epic 5 does). An unset scale is not guessed.
 */
final class DecimalMath
{
    public function __construct(private readonly Repository $config) {}

    /** @param  numeric-string  $a
     *  @param  numeric-string  $b */
    public function add(string $a, string $b): string
    {
        return (string) (new Number($a))->add(new Number($b))->round($this->scale());
    }

    /** @param  numeric-string  $a
     *  @param  numeric-string  $b */
    public function sub(string $a, string $b): string
    {
        return (string) (new Number($a))->sub(new Number($b))->round($this->scale());
    }

    /** @param  numeric-string  $a
     *  @param  numeric-string  $b */
    public function mul(string $a, string $b): string
    {
        return (string) (new Number($a))->mul(new Number($b))->round($this->scale());
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     *
     * @throws \DivisionByZeroError
     */
    public function div(string $a, string $b): string
    {
        return (string) (new Number($a))->div(new Number($b), $this->scale());
    }

    private function scale(): int
    {
        $value = $this->config->get('dashflow.tunables.numbers.decimal_scale.value');

        if ((is_int($value) || (is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,2})\z/D', trim($value)) === 1)) && (int) $value >= 0) {
            return (int) $value;
        }

        throw new SettingNotConfigured('DASHFLOW_DECIMAL_SCALE');
    }
}
