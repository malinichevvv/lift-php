<?php

declare(strict_types=1);

namespace Lift\Jwt;

enum JwtAlgorithm: string
{
    case HS256 = 'HS256';
    case HS384 = 'HS384';
    case HS512 = 'HS512';
    case RS256 = 'RS256';
    case RS384 = 'RS384';
    case RS512 = 'RS512';

    public function isHmac(): bool
    {
        return match ($this) {
            self::HS256, self::HS384, self::HS512 => true,
            default => false,
        };
    }

    public function isRsa(): bool
    {
        return !$this->isHmac();
    }
}
