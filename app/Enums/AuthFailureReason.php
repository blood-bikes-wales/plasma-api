<?php

namespace App\Enums;

enum AuthFailureReason: string
{
    case MissingToken = 'missing_token';
    case InvalidToken = 'invalid_token';
    case EmailNotVerified = 'email_not_verified';
    case OutOfDomain = 'out_of_domain';
}
