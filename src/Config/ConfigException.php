<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

/**
 * Raised when the configuration is invalid. Every error has the form
 * "<VARIABLE_NAME>:<reason_code> - <English sentence>"; the sentence never contains a configured value.
 */
final class ConfigException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors, ?\Throwable $previous = null)
    {
        parent::__construct('Invalid configuration (' . count($errors) . ' error(s)).', 0, $previous);
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Machine codes only (<VARIABLE_NAME>:<reason_code>), safe for the bootstrap log.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->errors as $e) {
            $pos = strpos($e, ' - ');
            $codes[] = $pos === false ? $e : substr($e, 0, $pos);
        }
        return $codes;
    }
}
