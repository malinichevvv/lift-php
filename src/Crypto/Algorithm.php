<?php

declare(strict_types=1);

namespace Lift\Crypto;

/**
 * Password hashing algorithms supported by {@see Hasher}.
 *
 * **Argon2id** is the recommended default — it is memory-hard and resistant
 * to both GPU brute-force and side-channel attacks (combines Argon2i and
 * Argon2d). Use Bcrypt only for compatibility with legacy systems.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
 */
enum Algorithm: string
{
    /** Argon2id — recommended for new applications (PHP 7.3+). Equals PASSWORD_ARGON2ID. */
    case Argon2id = 'argon2id';

    /** Argon2i — side-channel resistant variant (less common). Equals PASSWORD_ARGON2I. */
    case Argon2i  = 'argon2i';

    /** bcrypt — max 72-byte input, cost factor 10–12 recommended. Equals PASSWORD_BCRYPT. */
    case Bcrypt   = '2y';
}
