<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Support;

use Illuminate\Auth\GenericUser;
use JsonSerializable;

/**
 * Illuminate\Auth\GenericUser stores attributes in a protected property with no
 * toArray()/JsonSerializable support, so response()->json($user) silently serializes
 * to {} for it. Real apps use Eloquent models (Arrayable/JsonSerializable out of the
 * box); this test double fills that gap so /me can be asserted against.
 */
class JsonableGenericUser extends GenericUser implements JsonSerializable
{
    public function __construct(private readonly array $data)
    {
        parent::__construct($data);
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
