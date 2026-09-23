<?php

declare(strict_types=1);

namespace App;

/**
 * LeadFields — routing for leads.contact_name vs leads.target_persona.
 *
 * Item 9 rule: leads.contact_name must hold an actual person's name, or be
 * NULL. Free-form role/audience descriptions entered by the user
 * ("CTO at SaaS companies", "marketing managers") belong in
 * leads.target_persona ONLY — never in contact_name.
 *
 * When no real name is available, contact_name is left NULL: we do NOT fall
 * back to persona text.
 */
final class LeadFields
{
    /**
     * Whole-word keywords that mark a string as a role/audience description
     * (persona) rather than a person's name.
     */
    private const PERSONA_KEYWORDS = [
        'ceo', 'cto', 'cfo', 'coo', 'cmo', 'cio',
        'vp', 'svp', 'evp',
        'director', 'manager', 'head', 'chief', 'lead', 'owner', 'founder',
        'executive', 'officer', 'president', 'administrator', 'specialist',
        'consultant', 'representative', 'rep', 'buyer', 'prospect',
        'marketing', 'sales', 'engineering', 'engineer', 'developer',
        'companies', 'company', 'business', 'businesses',
        'team', 'teams', 'department',
        'saas', 'b2b', 'startup', 'startups', 'enterprise',
        'agency', 'agencies', 'audience', 'persona', 'personas',
    ];

    /**
     * Route free text into the two lead columns.
     *
     * @param string|null $nameInput    Candidate person name (e.g. CSV "Contact" column, contact form).
     * @param string|null $personaInput Explicit persona/audience description.
     *
     * @return array{0:?string,1:?string} [contact_name, target_persona]
     */
    public static function mapContactAndPersona(?string $nameInput, ?string $personaInput = null): array
    {
        $name = trim((string) $nameInput);
        $persona = trim((string) $personaInput);

        $contactName = self::isPersonName($name) ? $name : null;

        if ($persona !== '') {
            // An explicit persona always wins for target_persona.
            $targetPersona = $persona;
        } elseif ($name !== '' && !self::isPersonName($name)) {
            // Rescue: persona text was handed to us as a "name" — route it to
            // target_persona instead of dropping it.
            $targetPersona = $name;
        } else {
            $targetPersona = null;
        }

        return [$contactName, $targetPersona];
    }

    /**
     * Conservative person-name test.
     *
     * Only returns true for 1–4 whitespace-separated tokens where every token
     * starts with a capital letter and contains only letters, apostrophes,
     * hyphens, or dots — and the string contains no persona role/audience
     * keywords. Anything else (emails, URLs, digits, role titles) is NOT a
     * name, so callers leave contact_name NULL.
     */
    public static function isPersonName(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        // Emails, URLs, and anything with digits are never person names.
        if (strpos($value, '@') !== false || stripos($value, '://') !== false) {
            return false;
        }
        if (preg_match('/\d/', $value) === 1) {
            return false;
        }
        // Persona role/audience keyword anywhere (whole word) => not a name.
        $keywords = implode('|', array_map(
            static fn (string $kw): string => preg_quote($kw, '/'),
            self::PERSONA_KEYWORDS
        ));
        if (preg_match('/\b(?:' . $keywords . ')\b/i', $value) === 1) {
            return false;
        }
        $tokens = preg_split('/\s+/', $value);
        if ($tokens === false) {
            return false;
        }
        $count = count($tokens);
        if ($count < 1 || $count > 4) {
            return false;
        }
        foreach ($tokens as $token) {
            // Capitalized token: letters, apostrophes, hyphens, dots only.
            if (preg_match("/^[A-ZÀ-Þ][a-zà-þA-ZÀ-Þ'\\-.]*$/u", $token) !== 1) {
                return false;
            }
        }
        return true;
    }
}
