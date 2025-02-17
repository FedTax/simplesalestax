<?php

/**
 * @file
 * A base exception class to handle TaxCloud exceptions
 */

namespace TaxCloud\Exceptions;

class BaseException extends \Exception
{
  // We want to require $message
  public function __construct($message, $code = 0, Exception $previous = NULL)
  {
    parent::__construct($message, $code, $previous);
  }
}
