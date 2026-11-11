<?php
/** Streaming CSV writer for product catalog feeds. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Writes UTF-8 CSV with optional spreadsheet formula-injection protection. */
final class ADC_Feed_Writer_Csv
{
    /** @var resource|null */
    private $stream = null;
    private string $profile = '';
    private array $config = array();
    private array $fields = array();
    private bool $opened = false;

    /** Open a comma-delimited file and write its field-name header. */
    public function open(string $path, string $profile, array $config = array(), bool $append = false): bool
    {
        if (!in_array($profile, array(ADC_Feed_Profile_Meta::class, ADC_Feed_Profile_Tiktok::class), true)) {
            return false;
        }
        $this->profile = $profile;
        $this->config = $config;
        $this->fields = $profile::fields();
        if (class_exists('ADC_Feed_Field_Registry')) {
            $channel = self::channel_for_profile($profile);
            $supported = ADC_Feed_Field_Registry::fields($channel);
            foreach (is_array($config['params'] ?? null) ? $config['params'] : array() as $param) {
                $field = is_array($param) ? (string) ($param['field'] ?? '') : '';
                if (is_array($param) && !empty($param['enabled']) && isset($supported[$field]) && !in_array($field, $this->fields, true)) {
                    $this->fields[] = $field;
                }
            }
        }
        $this->stream = @fopen($path, $append ? 'ab' : 'wb');
        if (!is_resource($this->stream)) {
            $this->stream = null;
            return false;
        }
        if (0 === ftell($this->stream) && false === fputs($this->stream, "\xEF\xBB\xBF")) {
            fclose($this->stream);
            $this->stream = null;
            return false;
        }
        $this->opened = $this->write_row($this->fields);
        return $this->opened;
    }

    /** Resume a guarded CSV snapshot after validating its header and every existing row. */
    public function resume_existing(string $path, string $profile, array $config = array()): bool
    {
        if (!in_array($profile, array(ADC_Feed_Profile_Meta::class, ADC_Feed_Profile_Tiktok::class), true)
            || !is_file($path) || is_link($path)
            || (array_key_exists('csv_excel_safe', $config) && !is_bool($config['csv_excel_safe']))) {
            return false;
        }

        $fields = $profile::fields();
        if (!is_array($fields) || empty($fields) || count($fields) !== count(array_unique($fields))) {
            return false;
        }
        foreach ($fields as $field) {
            if (!is_string($field) || '' === $field) {
                return false;
            }
        }
        if (array_key_exists('params', $config)) {
            if (!is_array($config['params']) || !class_exists('ADC_Feed_Field_Registry')
                || !class_exists('ADC_Feed_Params') || !empty(ADC_Feed_Params::validate(self::channel_for_profile($profile), $config['params']))) {
                return false;
            }
            $supported = ADC_Feed_Field_Registry::fields(self::channel_for_profile($profile));
            foreach ($config['params'] as $param) {
                if (!is_array($param) || !is_string($param['field'] ?? null)) {
                    return false;
                }
                $field = $param['field'];
                if (!empty($param['enabled']) && isset($supported[$field]) && !in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
        }

        $stream = @fopen($path, 'r+b');
        if (!is_resource($stream)) {
            return false;
        }
        $stat = fstat($stream);
        $guard = "<?php exit; ?>\n";
        $valid = is_array($stat) && (($stat['mode'] & 0170000) === 0100000)
            && (int) ($stat['size'] ?? 0) > strlen($guard)
            && $guard === fread($stream, strlen($guard));
        if ($valid) {
            $after_guard = ftell($stream);
            $marker = fread($stream, 3);
            if ("\xEF\xBB\xBF" !== $marker && false === fseek($stream, (int) $after_guard)) {
                $valid = false;
            }
        }
        if ($valid) {
            $header = fgetcsv($stream, null, ',', '"', '');
            $valid = is_array($header) && $header === $fields;
        }
        $has_data = false;
        while ($valid && !feof($stream)) {
            $row = fgetcsv($stream, null, ',', '"', '');
            if (false === $row) {
                break;
            }
            if (count($row) !== count($fields)) {
                $valid = false;
                break;
            }
            $has_data = true;
        }
        if (!$valid || !$has_data || 0 !== fseek($stream, 0, SEEK_END)) {
            fclose($stream);
            return false;
        }

        $this->stream = $stream;
        $this->profile = $profile;
        $this->config = $config;
        $this->fields = $fields;
        $this->opened = true;
        return true;
    }

    /** Serialize one normalized catalog item. */
    public function write_item(array $item): bool
    {
        if (!$this->opened) {
            return false;
        }
        $mapped = $this->profile::map_item($item, $this->config);
        $row = array();
        foreach ($this->fields as $field) {
            $value = $mapped[$field] ?? '';
            if (is_array($value)) {
                $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $row[] = is_scalar($value) ? (string) $value : '';
        }
        return $this->write_row($row);
    }

    public function close(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $ok = fflush($this->stream);
        fclose($this->stream);
        $this->stream = null;
        $this->opened = false;
        return $ok;
    }

    private static function channel_for_profile(string $profile): string
    {
        return match ($profile) {
            ADC_Feed_Profile_Meta::class => 'meta',
            ADC_Feed_Profile_Tiktok::class => 'tiktok',
            default => 'google',
        };
    }

    private function write_row(array $values): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $safe = !array_key_exists('csv_excel_safe', $this->config) || !empty($this->config['csv_excel_safe']);
        $values = array_map(static function (mixed $value) use ($safe): string {
            $value = is_scalar($value) ? (string) $value : '';
            if (preg_match('//u', $value) !== 1) {
                return "\xFF"; // Invalid UTF-8 is rejected below rather than emitting a malformed catalog.
            }
            if ($safe && preg_match('/^[\p{Z}\p{C}\s]*[=+@\-]/u', $value)) {
                $value = "'" . $value;
            }
            return $value;
        }, $values);
        foreach ($values as $value) {
            if ("\xFF" === $value) {
                return false;
            }
        }
        return false !== fputcsv($this->stream, $values, ',', '"', '');
    }
}
