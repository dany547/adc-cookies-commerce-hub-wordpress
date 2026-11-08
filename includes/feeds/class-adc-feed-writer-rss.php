<?php
/** Streaming RSS 2.0 writer for Google Merchant Center. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Writes one Google RSS feed without buffering the complete catalog. */
final class ADC_Feed_Writer_Rss
{
    private mixed $writer = null;
    /** @var resource|null */
    private $stream = null;
    private string $feed_name = '';
    private string $home_url = '';
    private bool $opened = false;
    private string $profile = ADC_Feed_Profile_Google::class;
    private array $config = array();

    /** Open a feed target and write its RSS/channel header. */
    public function open(string $path, string $feed_name, bool $append = false, string $profile = ADC_Feed_Profile_Google::class, array $config = array()): bool
    {
        if (!in_array($profile, array(ADC_Feed_Profile_Google::class, ADC_Feed_Profile_Meta::class, ADC_Feed_Profile_Tiktok::class), true)) {
            return false;
        }
        $this->profile = $profile;
        $this->config = $config;
        $this->feed_name = $feed_name;
        $this->home_url = function_exists('home_url') ? (string) home_url('/') : '/';
        $this->stream = @fopen($path, $append ? 'ab' : 'wb');
        if (!is_resource($this->stream)) {
            $this->stream = null;
            return false;
        }

        if (class_exists('XMLWriter')) {
            $this->writer = new XMLWriter();
            if (!$this->writer->openMemory()) {
                fclose($this->stream);
                $this->stream = null;
                return false;
            }
            $this->writer->startDocument('1.0', 'UTF-8');
            $this->writer->startElement('rss');
            $this->writer->writeAttribute('version', '2.0');
            $this->writer->writeAttribute('xmlns:g', ADC_Feed_Profile_Google::NAMESPACE_URI);
            $this->writer->startElement('channel');
            $this->writer->writeElement('title', $this->feed_name);
            $this->writer->writeElement('link', $this->home_url);
            $this->writer->writeElement('description', $this->feed_name);
            if (!$this->flush_writer_output()) {
                return false;
            }
        } else {
            $header = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<rss version="2.0" xmlns:g="' . self::xml_attr(ADC_Feed_Profile_Google::NAMESPACE_URI) . '"><channel>'
                . '<title>' . self::xml_text($this->feed_name) . '</title>'
                . '<link>' . self::xml_text($this->home_url) . '</link>'
                . '<description>' . self::xml_text($this->feed_name) . '</description>';
            if (!$this->write($header)) {
                return false;
            }
        }

        $this->opened = true;
        return true;
    }

    /** Write one normalized product item using Google attribute names. */
    public function write_item(array $item): bool
    {
        if (!$this->opened) {
            return false;
        }

        $mapped = $this->profile::map_item($item, $this->config);
        if (class_exists('XMLWriter')) {
            $writer = $this->writer;
            $writer->startElement('item');
            foreach (array('title', 'link', 'description') as $name) {
                if (isset($mapped[$name])) {
                    $writer->writeElement($name, (string) $mapped[$name]);
                }
            }
            foreach ($mapped as $name => $value) {
                if (in_array($name, array('title', 'link', 'description', 'weight'), true)) {
                    continue;
                }
                if ('certification' === $name && is_array($value)) {
                    $writer->startElementNS('g', 'certification', null);
                    foreach ($value as $key => $part) {
                        $writer->writeElementNS('g', (string) $key, null, (string) $part);
                    }
                    $writer->endElement();
                } elseif ('shipping' === $name && is_array($value)) {
                    foreach ($value as $shipping) {
                        $writer->startElementNS('g', 'shipping', null);
                        foreach ($shipping as $key => $part) {
                            $writer->writeElementNS('g', (string) $key, null, (string) $part);
                        }
                        $writer->endElement();
                    }
                } elseif ('internal_label' === $name && is_array($value)) {
                    foreach ($value as $label) {
                        $writer->writeElementNS('g', 'internal_label', null, (string) $label);
                    }
                } elseif ('additional_image_link' === $name && is_array($value)) {
                    foreach ($value as $url) {
                        $writer->writeElementNS('g', $name, null, (string) $url);
                    }
                } elseif (is_array($value)) {
                    foreach ($value as $part) {
                        if (is_scalar($part) && '' !== (string) $part) {
                            $writer->writeElementNS('g', $name, null, (string) $part);
                        }
                    }
                } elseif (is_scalar($value) && '' !== (string) $value) {
                    $writer->writeElementNS('g', $name, null, (string) $value);
                }
            }
            $writer->endElement();
            return $this->flush_writer_output();
        }

        $xml = '<item>';
        foreach (array('title', 'link', 'description') as $name) {
            if (isset($mapped[$name])) {
                $xml .= '<' . $name . '>' . self::xml_text((string) $mapped[$name]) . '</' . $name . '>';
            }
        }
        foreach ($mapped as $name => $value) {
            if (in_array($name, array('title', 'link', 'description', 'weight'), true)) {
                continue;
            }
            if ('certification' === $name && is_array($value)) {
                $xml .= '<g:certification>';
                foreach ($value as $key => $part) {
                    $xml .= '<g:' . $key . '>' . self::xml_text((string) $part) . '</g:' . $key . '>';
                }
                $xml .= '</g:certification>';
            } elseif ('shipping' === $name && is_array($value)) {
                foreach ($value as $shipping) {
                    $xml .= '<g:shipping>';
                    foreach ($shipping as $key => $part) {
                        $xml .= '<g:' . $key . '>' . self::xml_text((string) $part) . '</g:' . $key . '>';
                    }
                    $xml .= '</g:shipping>';
                }
            } elseif ('internal_label' === $name && is_array($value)) {
                foreach ($value as $label) {
                    $xml .= '<g:internal_label>' . self::xml_text((string) $label) . '</g:internal_label>';
                }
            } elseif ('additional_image_link' === $name && is_array($value)) {
                foreach ($value as $url) {
                    $xml .= '<g:additional_image_link>' . self::xml_text((string) $url) . '</g:additional_image_link>';
                }
            } elseif (is_array($value)) {
                foreach ($value as $part) {
                    if (is_scalar($part) && '' !== (string) $part) {
                        $xml .= '<g:' . $name . '>' . self::xml_text((string) $part) . '</g:' . $name . '>';
                    }
                }
            } elseif (is_scalar($value) && '' !== (string) $value) {
                $xml .= '<g:' . $name . '>' . self::xml_text((string) $value) . '</g:' . $name . '>';
            }
        }
        return $this->write($xml . '</item>');
    }

    /** Finish and flush the XML document. */
    public function close(): bool
    {
        if (!$this->opened) {
            return false;
        }
        $ok = true;
        if (class_exists('XMLWriter')) {
            $this->writer->endElement(); // channel
            $this->writer->endElement(); // rss
            $this->writer->endDocument();
            $ok = $this->flush_writer_output();
            $this->writer = null;
        } else {
            $ok = $this->write('</channel></rss>');
        }
        if (is_resource($this->stream)) {
            $ok = fflush($this->stream) && $ok;
            fclose($this->stream);
        }
        $this->stream = null;
        $this->opened = false;
        return $ok;
    }

    private function flush_writer_output(): bool
    {
        if (!is_object($this->writer) || !method_exists($this->writer, 'outputMemory')) {
            return false;
        }
        $buffer = $this->writer->outputMemory(true);
        return is_string($buffer) && ('' === $buffer || $this->write($buffer));
    }

    private function write(string $bytes): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($this->stream, substr($bytes, $written));
            if (false === $count || 0 === $count) {
                return false;
            }
            $written += $count;
        }
        return true;
    }

    private static function xml_text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function xml_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
