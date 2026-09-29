<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that breaks a business rule. The message is Arabic and safe to show to the employee.
 */
class BusinessRuleException extends RuntimeException
{
}
