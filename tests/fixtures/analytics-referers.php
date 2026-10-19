<?php
/**
 * Referer classification fixtures for ADC_Analytics_Traffic::channel_from_referer().
 *
 * Each case: referer (raw header value), site_host (home URL host), expected channel.
 * Hostile look-alikes (suffix, userinfo, punycode, homoglyph) must fall to 'referral'.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

return array(
    array('referer' => '', 'site_host' => 'example.ro', 'expected' => 'direct'),
    array('referer' => 'not a url', 'site_host' => 'example.ro', 'expected' => 'unknown'),
    array('referer' => 'http://', 'site_host' => 'example.ro', 'expected' => 'unknown'),
    array('referer' => '//google.com/search', 'site_host' => 'example.ro', 'expected' => 'unknown'),
    array('referer' => 'javascript:alert(1)', 'site_host' => 'example.ro', 'expected' => 'unknown'),
    array('referer' => 'ftp://google.com/', 'site_host' => 'example.ro', 'expected' => 'unknown'),

    array('referer' => 'https://example.ro/produs/x?utm=1', 'site_host' => 'example.ro', 'expected' => 'internal'),
    array('referer' => 'https://WWW.Example.ro/', 'site_host' => 'example.ro', 'expected' => 'internal'),
    array('referer' => 'https://example.ro/', 'site_host' => 'www.example.ro', 'expected' => 'internal'),

    array('referer' => 'https://www.google.com/search?q=secret+term', 'site_host' => 'example.ro', 'expected' => 'search'),
    array('referer' => 'HTTPS://GOOGLE.COM/', 'site_host' => 'example.ro', 'expected' => 'search'),
    array('referer' => 'https://google.com./', 'site_host' => 'example.ro', 'expected' => 'search'),
    array('referer' => 'https://google.ro/', 'site_host' => 'example.ro', 'expected' => 'search'),
    array('referer' => 'https://www.bing.com/search?q=x', 'site_host' => 'example.ro', 'expected' => 'search'),
    array('referer' => 'https://duckduckgo.com/?q=x', 'site_host' => 'example.ro', 'expected' => 'search'),

    array('referer' => 'https://www.facebook.com/share', 'site_host' => 'example.ro', 'expected' => 'social'),
    array('referer' => 'https://m.facebook.com/', 'site_host' => 'example.ro', 'expected' => 'social'),
    array('referer' => 'https://instagram.com/p/abc', 'site_host' => 'example.ro', 'expected' => 'social'),
    array('referer' => 'https://www.linkedin.com/feed/', 'site_host' => 'example.ro', 'expected' => 'social'),
    array('referer' => 'https://www.tiktok.com/@x', 'site_host' => 'example.ro', 'expected' => 'social'),

    array('referer' => 'https://google.com.evil.example/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://evilgoogle.com/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://notgoogle.com/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://google.com@evil.example/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://facebook.com.evil.example/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://xn--ggle-0nda.com/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => "https://g\u{03BF}ogle.com/", 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://example.ro.evil.example/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://sub.example.ro/', 'site_host' => 'example.ro', 'expected' => 'referral'),
    array('referer' => 'https://other.example/articol?id=7', 'site_host' => 'example.ro', 'expected' => 'referral'),
);
