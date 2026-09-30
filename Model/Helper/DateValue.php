<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Validates date values passed to the tools in YYYY-MM-DD form
 */
class DateValue
{
    /** Date format accepted from the AI */
    public const FORMAT = 'Y-m-d';

    /**
     * Trim the value; an empty one becomes null, anything else must be a real date in YYYY-MM-DD form
     *
     * @param string $value
     * @param Phrase $label What the value is, e.g. 'Argument "dob"', used in the error message
     * @return string|null
     * @throws LocalizedException
     */
    public function normalize(string $value, Phrase $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('!' . self::FORMAT, $value);
        if (!$date || $date->format(self::FORMAT) !== $value) {
            throw new LocalizedException(
                __('%1 must be a date in YYYY-MM-DD form, got "%2".', $label, $value)
            );
        }

        return $value;
    }
}
