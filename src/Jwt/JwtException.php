<?php

declare(strict_types=1);

namespace Lift\Jwt;

use RuntimeException;

/**
 * Thrown when a JWT cannot be decoded or validated.
 *
 * Covers all JWT failure modes:
 * - Malformed token structure
 * - Invalid signature
 * - Expired token (`exp`)
 * - Not-yet-valid token (`nbf`)
 * - Issuer mismatch (`iss`)
 * - Audience mismatch (`aud`)
 */
class JwtException extends RuntimeException {}
