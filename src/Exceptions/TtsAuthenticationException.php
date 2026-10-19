<?php

namespace Vuthaihoc\TtsClient\Exceptions;

/**
 * 401: the token was rejected (wrong client secret, server secret rotated,
 * clock drift, lifetime above the server's limit).
 */
class TtsAuthenticationException extends TtsException {}
