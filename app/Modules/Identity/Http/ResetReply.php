<?php

namespace App\Modules\Identity\Http;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

/** A response built when Laravel asks for it (Fortify's controllers must return a Responsable). */
final class ResetReply implements Responsable
{
    /** @param  Closure(): Response  $make */
    public function __construct(private readonly Closure $make) {}

    public function toResponse($request): Response
    {
        return ($this->make)();
    }
}
