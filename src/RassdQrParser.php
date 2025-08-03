<?php
namespace A6e6s\RassdQrParser;
// declare(strict_types=1);



/**
 * Parses GS1 Digital Link QR codes for RASSD serialization using a "Process of Elimination" strategy.
 *
 * This class is specifically designed to handle complex QR codes where Application Identifiers (AIs)
 * like '10', '17', or '21' might appear inside the values of other fields. It follows a strict,
 * rule-based order of operations:
 *
 * 1.  Extracts the GTIN (AI '01'), which is always at the beginning.
 * 2.  Finds the first valid Expiration Date (AI '17') by searching for the '17' prefix and
 * validating the subsequent 6 digits as a real date.
 * 3.  Removes the GTIN and Date strings from the code.
 * 4.  Parses the remaining string, which now only contains the Batch Number (AI '10') and
 * Serial Number (AI '21'), to extract their values.
 *
 * @link https://www.gs1.org/standards/gs1-digital-link
 */
class RassdQrParser
{
    // --- Application Identifier Constants ---
    private const AI_GTIN = '01';
    private const AI_BATCH_NUMBER = '10';
    private const AI_EXPIRATION = '17';
    private const AI_SERIAL_NUMBER = '21';

    // --- Fixed Lengths for Specific AIs ---
    private const LENGTH_GTIN = 14;
    private const LENGTH_EXPIRATION = 6;

    private ?string $gtin = null;
    private ?string $batchNumber = null;
    private ?string $serialNumber = null;
    private ?\DateTimeImmutable $expirationDate = null;
    private bool $isValid = false;

    /**
     * @param string $qrCode The raw data string from the RASSD QR code.
     */
    public function __construct(string $qrCode)
    {
        $this->parse($qrCode);
    }

    /**
     * Checks if all required components (GTIN, SN, BN, XD) were successfully parsed.
     *
     * @return bool True if the QR code was valid and fully parsed, false otherwise.
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getGtin(): ?string
    {
        return $this->gtin;
    }

    public function getBatchNumber(): ?string
    {
        return $this->batchNumber;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function getExpirationDate(): ?\DateTimeImmutable
    {
        return $this->expirationDate;
    }

    /**
     * Returns the parsed data as a JSON string.
     */
    public function toJson(): string|false
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the parsed data as an associative array.
     */
    public function toArray(): array
    {
        return [
            'GTIN' => $this->gtin,
            'SN' => $this->serialNumber,
            'BN' => $this->batchNumber,
            'XD' => $this->expirationDate?->format('Y-m-d'),
        ];
    }

    /**
     * The main parsing method that orchestrates the "Process of Elimination".
     */
    private function parse(string $qrCode): void
    {
        // Rule 1: GTIN is always at the beginning.
        if (strpos($qrCode, self::AI_GTIN) !== 0) {
            return; // Invalid format if it doesn't start with '01'.
        }
        $this->gtin = substr($qrCode, 2, self::LENGTH_GTIN);
        $remainder = substr($qrCode, 2 + self::LENGTH_GTIN);

        // Rule 6: Extract the date, validating it, and remove it from the remainder.
        $dateValue = $this->findAndRemoveDate($remainder);
        if ($dateValue === null) {
            return; // A valid date could not be found.
        }
        $this->expirationDate = $this->createDateObject($dateValue);

        // The remainder now *only* contains Batch and Serial Number data.
        $this->extractBatchAndSerial($remainder);

        // The QR is considered valid only if all four components have been found.
        $this->isValid = isset($this->gtin, $this->batchNumber, $this->serialNumber, $this->expirationDate);
    }

    /**
     * Finds the first valid '17' date, removes it from the input string, and returns the date value.
     *
     * This method iterates through the string looking for the '17' prefix. For each occurrence,
     * it checks if the following 6 digits constitute a valid date. The first one that is valid
     * is considered the correct expiration date.
     *
     * @param string $remainder The string to search, passed by reference to be modified.
     * @return string|null The 'YYMMDD' date string if found, otherwise null.
     */
    private function findAndRemoveDate(string &$remainder): ?string
    {
        $searchOffset = 0;
        while (($pos = strpos($remainder, self::AI_EXPIRATION, $searchOffset)) !== false) {
            $dateString = substr($remainder, $pos + 2, self::LENGTH_EXPIRATION);

            if (strlen($dateString) === self::LENGTH_EXPIRATION && $this->isValidGs1Date($dateString)) {
                // A valid date was found. Remove the entire AI + value from the remainder.
                $remainder = substr_replace($remainder, '', $pos, 2 + self::LENGTH_EXPIRATION);
                return $dateString;
            }
            // The date was not valid, so continue searching from the next character.
            $searchOffset = $pos + 1;
        }
        return null; // No valid '17' date block found.
    }

    /**
     * Extracts the Batch and Serial numbers from the remaining string.
     *
     * This method assumes the input string *only* contains the '10' and '21' AI blocks.
     * It checks which AI comes first and splits the string accordingly.
     *
     * @param string $remainder The string containing only BN and SN data.
     */
    private function extractBatchAndSerial(string $remainder): void
    {
        $pos10 = strpos($remainder, self::AI_BATCH_NUMBER);
        $pos21 = strpos($remainder, self::AI_SERIAL_NUMBER);

        // Ensure both AIs are present before proceeding.
        if ($pos10 === false || $pos21 === false) {
            return;
        }

        if ($pos10 < $pos21) {
            // Order is BN, then SN (e.g., "10...21...")
            $this->batchNumber = substr($remainder, $pos10 + 2, $pos21 - ($pos10 + 2));
            $this->serialNumber = substr($remainder, $pos21 + 2);
        } else {
            // Order is SN, then BN (e.g., "21...10...")
            $this->serialNumber = substr($remainder, $pos21 + 2, $pos10 - ($pos21 + 2));
            $this->batchNumber = substr($remainder, $pos10 + 2);
        }
    }

    /**
     * Validates if a 6-digit string is a valid GS1 date (YYMMDD).
     * GS1 allows the day to be '00', which means the last day of the month.
     */
    private function isValidGs1Date(string $dateStr): bool
    {
        $month = (int)substr($dateStr, 2, 2);
        $day = (int)substr($dateStr, 4, 2);
        return ($month >= 1 && $month <= 12) && ($day >= 0 && $day <= 31);
    }

    /**
     * Creates a DateTimeImmutable object from a YYMMDD string.
     * Handles the GS1 rule where '00' for the day means end of the month.
     */
    private function createDateObject(string $dateStr): ?\DateTimeImmutable
    {
        $day = substr($dateStr, 4, 2);
        $dateObject = null;

        if ($day === '00') {
            // Create date with day 01 and then modify to last day of month
            $tempDateStr = substr_replace($dateStr, '01', 4, 2);
            $dateObject = \DateTimeImmutable::createFromFormat('ymd', $tempDateStr);
            if ($dateObject) {
                $dateObject = $dateObject->modify('last day of this month');
            }
        } else {
            $dateObject = \DateTimeImmutable::createFromFormat('ymd', $dateStr);
        }

        return $dateObject ? $dateObject->setTime(0, 0) : null;
    }
}
