# GDPR Compliance - ADC Marketing Plugin

## Overview
This plugin ships **technical controls that support GDPR compliance** for EU users: consent collection, granular preferences, consent-gated tracking, and data export/erasure tooling. These controls are **not a legal certification**, and they do not make a site compliant by themselves — compliance depends on the operator's privacy policy, processing activities, contracts, and local law. Below is a summary of the GDPR-relevant features and the requirements operators still have to meet.

## GDPR Compliance Features

### ✅ 1. Explicit Consent (Opt-in)
- **Checkbox is optional** - Users are not forced to consent
- **Checkbox is unchecked by default** - No pre-checked boxes (opt-in, not opt-out)
- **Clear consent language** - Users understand what they're consenting to
- **Privacy policy link** - Direct link to privacy policy in consent message

### ✅ 2. Granular Consent
- **Separate preferences** - Users can choose email and SMS separately
- **Individual control** - Users manage preferences independently
- **My Account page** - Dedicated page for managing marketing preferences

### ✅ 3. Consent Withdrawal (Right to Object)
- **Easy withdrawal** - Users can withdraw consent anytime from My Account
- **No barriers** - Withdrawal is as easy as giving consent
- **Immediate effect** - Preferences updated immediately upon save

### ✅ 3b. Cookie Banner: Refusal Is As Easy As Acceptance
- **Equal geometry** - "Acceptă" and "Refuză" share one CSS class (`.adc-consent-btn--equal`): identical size, padding, font weight and position in the row; only colours may differ
- **Default order** - Acceptă · Setări · Respinge, printed in DOM order so tab and screen-reader order match what the visitor sees (admin can pick another order)
- **Colour audit** - `ADC_Consent_Banner::prominence_audit()` compares the configured button colours against the banner background (WCAG 1.4.3 text 4.5:1, 1.4.11 non-text 3:1, plus a blend and a prominence-ratio guard) and reports when refusal is harder to see than acceptance
- **Admin notified** - The banner tab shows a "Verificare conformitate: proeminența butoanelor" panel, live while editing colours and after saving; saving a de-emphasised "Refuză" also returns a "Modificare posibil necompliantă" notice, because consent gathered through a hidden refusal is not freely given (GDPR Art. 4(11), Recital 43; EDPB Guidelines 03/2022 on dark patterns)
- **Spacing is cosmetic only** - The configurable gap after "Acceptă" is a margin; it never changes any button's size and is capped so it cannot break the row

### ✅ 4. Data Portability (Right to Data Portability)
- **Export functionality** - Admin can export user consent data in CSV format
- **Structured format** - Data exported in machine-readable format
- **Complete data** - Includes all consent-related information

### ✅ 5. Right to be Forgotten (Right to Erasure)
- **Automatic deletion** - User consent data is automatically deleted when user account is deleted
- **Privacy tools** - The marketing-consent eraser is registered with WordPress (Tools > Erase Personal Data) and removes the same user meta without deleting the account
- **Complete removal** - All marketing consent meta is removed
- **No data retention** - No unnecessary data retention after account deletion

### ✅ 4a. Data Portability via WordPress Privacy Tools
- **Exporter** - Registered with `wp_privacy_personal_data_exporters` (Tools > Export Personal Data). It returns the user's general, e-mail and SMS consent values and their dates.
- **Guest orders** - Consent stored on guest orders is exported together with the order by WooCommerce.

### ✅ 6. Audit Trail
- **Consent text version** - Stores exact text user saw when consenting
- **Consent text hash** - Stores a SHA-256 hash of that exact text on new orders (`_adc_consent_text_hash`)
- **Consent timestamp** - Records when consent was given/withdrawn
- **Consent source** - Tracks where consent was given (checkout, My Account, etc.)
- **IP address (hashed)** - New orders store only a keyed hash of the IP (HMAC-SHA256 with the WordPress auth salt). The raw IP is not stored for new orders. Orders created before 2.0 may still contain a raw IP until they are erased.

### ✅ 7. Transparency
- **Admin visibility** - Consent status visible in order details and user profiles
- **User visibility** - Users can see their consent status in My Account
- **Clear labeling** - All consent-related fields clearly labeled

### ✅ 8. Privacy Policy Requirement
- **Admin warning** - Plugin warns admin if privacy policy page is not set
- **Link integration** - Privacy policy link integrated into consent message
- **Easy setup** - Direct link to privacy policy settings

## GDPR Compliance Checklist

- [x] Consent is optional (not mandatory)
- [x] Consent is explicit (checkbox unchecked by default)
- [x] Consent is granular (email/SMS separate)
- [x] Consent can be withdrawn easily
- [x] Cookie banner: refusal as visible and as easy as acceptance (equal geometry, default order, colour audit with admin warning)
- [x] Data can be exported
- [x] Data can be deleted
- [x] Consent text version stored
- [x] Consent timestamp stored
- [x] Privacy policy link included
- [x] Admin compliance warnings

## Data Stored

### User Meta (for logged-in users)
- `_adc_marketing_consent` - General consent (yes/no)
- `_adc_marketing_consent_date` - Consent timestamp
- `_adc_marketing_consent_source` - Where consent was given
- `_adc_marketing_email_consent` - Email consent (yes/no)
- `_adc_marketing_email_consent_date` - Email consent timestamp
- `_adc_marketing_sms_consent` - SMS consent (yes/no)
- `_adc_marketing_sms_consent_date` - SMS consent timestamp

### Order Meta
- `_adc_marketing_consent` - Consent status (yes/no)
- `_adc_marketing_consent_date` - Consent timestamp
- `_adc_consent_text_version` - Exact consent text user saw
- `_adc_consent_text_hash` - SHA-256 of the consent text (new orders, 2.0+)
- `_adc_consent_ip_hash` - HMAC-SHA256 of the client IP (new orders, 2.0+)
- `_adc_consent_ip_address` - Raw IP address (legacy orders created before 2.0 only; not written anymore)

## Important Notes

1. **IP Address Storage**: The plugin stores a keyed hash of the IP address, not the raw address, for consent audit trail (orders created with 2.0+). This is generally acceptable under GDPR Article 6(1)(f) (legitimate interest) for proving consent. However, you should:
   - Inform users in your privacy policy that a pseudonymised IP value is stored
   - Consider your local data protection authority's guidance
   - Note that the hash is only as strong as the `auth` salt in `wp-config.php`. Changing the salt makes earlier hashes unverifiable.
   - Legacy raw IP values on orders created before 2.0 are not removed by the privacy eraser. Remove them manually (or with a separate script) if your policy requires it.

2. **Privacy Policy**: You MUST set a privacy policy page in WordPress Settings > Privacy for full GDPR compliance.

3. **Consent Text**: The consent message should clearly explain:
   - What data is collected
   - How it's used
   - Who it's shared with
   - How to withdraw consent

4. **Data Retention**: Consider your data retention policy. The plugin stores consent data indefinitely until user deletion. You may want to implement automatic deletion after a certain period if required by your policy.

## Known Gaps (2.0)

- **Block checkout**: the consent checkbox is rendered only by the classic checkout. The block checkout (Store API) does not show it yet, so orders placed through the block checkout store no consent value. (Still true at 2.2.0 — documented deliberately; the operational analytics commerce reporting works on block checkout and its E2E validation is separate from this legacy checkbox gap.)
- **Order erasure**: the eraser removes user-level consent meta. It does not rewrite order-level consent meta (`_adc_marketing_consent`, text, hashes) on orders of that user; those records are kept as proof of consent.

## Recommendations

1. **Review Privacy Policy**: Ensure your privacy policy clearly explains marketing consent
2. **Regular Audits**: Periodically review consent data and compliance status
3. **User Communication**: Inform users about their rights regarding marketing consent
4. **Data Minimization**: Only collect consent data necessary for marketing purposes
5. **Documentation**: Keep records of consent processes and data handling

## Legal Disclaimer

This plugin provides tools for GDPR compliance, but compliance ultimately depends on:
- Your privacy policy content
- Your data handling practices
- Your local data protection laws
- Your specific use case

Always consult with a legal professional familiar with GDPR and your local data protection laws.

