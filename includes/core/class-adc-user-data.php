<?php
/**
 * Hashed user-data value object (docs/ARCHITECTURE.md §2.1).
 *
 * All values are already normalised + SHA-256 hex (lowercase), including
 * external_id. This object never holds raw PII.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable set of hashed matching fields.
 */
final readonly class ADC_User_Data
{
    /**
     * @param string|null $em          Hashed e-mail.
     * @param string|null $ph          Hashed phone.
     * @param string|null $fn          Hashed first name.
     * @param string|null $ln          Hashed last name.
     * @param string|null $ct          Hashed city.
     * @param string|null $st          Hashed state.
     * @param string|null $zp          Hashed zip.
     * @param string|null $country     Hashed country.
     * @param string|null $external_id Hashed external id.
     */
    public function __construct(
        public ?string $em = null,
        public ?string $ph = null,
        public ?string $fn = null,
        public ?string $ln = null,
        public ?string $ct = null,
        public ?string $st = null,
        public ?string $zp = null,
        public ?string $country = null,
        public ?string $external_id = null,
    ) {
    }

    /**
     * Only non-null fields, keyed by platform-neutral names.
     *
     * @return array<string, string>
     */
    public function to_array(): array
    {
        $out = array();
        foreach (get_object_vars($this) as $key => $value) {
            if (null !== $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Whether no field is set.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return array() === $this->to_array();
    }

    /**
     * Merge with another set; $other wins where it has a value.
     *
     * @param self|null $other Other (winning) values.
     * @return self
     */
    public function merge(?self $other): self
    {
        if (null === $other) {
            return $this;
        }

        return new self(
            $other->em ?? $this->em,
            $other->ph ?? $this->ph,
            $other->fn ?? $this->fn,
            $other->ln ?? $this->ln,
            $other->ct ?? $this->ct,
            $other->st ?? $this->st,
            $other->zp ?? $this->zp,
            $other->country ?? $this->country,
            $other->external_id ?? $this->external_id,
        );
    }

    /**
     * Rebuild from an array; values that are not 64-hex are dropped.
     *
     * @param array $a Raw array (e.g. decoded JSON payload).
     * @return self
     */
    public static function from_array(array $a): self
    {
        $valid = static fn (mixed $v): ?string => (
            is_string($v) && 1 === preg_match('/^[0-9a-f]{64}$/', $v)
        ) ? $v : null;

        return new self(
            $valid($a['em'] ?? null),
            $valid($a['ph'] ?? null),
            $valid($a['fn'] ?? null),
            $valid($a['ln'] ?? null),
            $valid($a['ct'] ?? null),
            $valid($a['st'] ?? null),
            $valid($a['zp'] ?? null),
            $valid($a['country'] ?? null),
            $valid($a['external_id'] ?? null),
        );
    }
}
