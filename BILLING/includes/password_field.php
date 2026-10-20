<?php
// ONE reusable password field for every auth screen (Admin login, Staff login, Staff sign-up,
// Reset password, and the Users-page modal). Centralising it means the eye button, its ARIA
// wiring, the right-padding for the typed text and the "passwords match" hint exist in exactly
// one place instead of being copy-pasted per page.
//
// Usage: echo passwordField(['id' => 'password', 'label' => 'Password', 'match_with' => 'confirm_password']);
// (Never write a PHP closing tag inside a comment: it ends PHP mode and leaks the rest of the file.)
//
// Options:
//   id              (required) input id + default name
//   name            form field name (defaults to id)
//   label           visible label text
//   autocomplete    'current-password' | 'new-password'
//   required        bool (default true)
//   minlength       int (default 8)
//   maxlength       int (default 72)
//   hint            small helper text under the field
//   error_key       key used for the page's per-field error container (defaults to name)
//   match_with      id of the other password field -> renders a live "match / do not match" line
//   match_label     label used in that live line (default 'Passwords')
//   described_by    extra id(s) for aria-describedby
//   error_text      server-side error to show in this field's error container (default '')
function passwordField(array $options): string
{
    $id = (string) ($options['id'] ?? '');
    if ($id === '') {
        return '';
    }
    $name = (string) ($options['name'] ?? $id);
    $label = (string) ($options['label'] ?? 'Password');
    $autocomplete = (string) ($options['autocomplete'] ?? 'current-password');
    $required = $options['required'] ?? true;
    $minlength = (int) ($options['minlength'] ?? 8);
    $maxlength = (int) ($options['maxlength'] ?? 72);
    $hint = (string) ($options['hint'] ?? '');
    $errorKey = (string) ($options['error_key'] ?? $name);
    $matchWith = (string) ($options['match_with'] ?? '');
    $matchLabel = (string) ($options['match_label'] ?? 'Passwords');
    $describedBy = trim((string) ($options['described_by'] ?? ''));
    $errorText = (string) ($options['error_text'] ?? '');

    $matchId = $id . '-match';
    $hintId = $id . '-hint';
    $described = array_filter(array_merge(
        $describedBy !== '' ? preg_split('/\s+/', $describedBy) : [],
        $hint !== '' ? [$hintId] : [],
        $matchWith !== '' ? [$matchId] : []
    ));

    $html = '<label for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
    $html .= '<div class="password-field">';
    $html .= '<input id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"'
        . ' name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
        . ' type="password"'
        . ' minlength="' . $minlength . '"'
        . ' maxlength="' . $maxlength . '"'
        . ' autocomplete="' . htmlspecialchars($autocomplete, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-password-input'
        . ($matchWith !== '' ? ' data-password-match-source="' . htmlspecialchars($matchWith, ENT_QUOTES, 'UTF-8') . '"' : '')
        . ($required ? ' required' : '')
        . ($described ? ' aria-describedby="' . htmlspecialchars(implode(' ', $described), ENT_QUOTES, 'UTF-8') . '"' : '')
        . '>';
    // A real <button type="button">, so it can never submit the form; assets/js/login-auth.js binds
    // the toggle by class, keeps focus in the input and flips aria-label/aria-pressed.
    $html .= '<button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"'
        . ' aria-controls="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path class="eye-outline" d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12Z" fill="none" stroke="currentColor" stroke-width="1.8"/>'
        . '<circle class="eye-outline" cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/>'
        . '<path class="eye-slash" d="M4 4l16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg></button>';
    $html .= '</div>';

    if ($hint !== '') {
        $html .= '<div id="' . htmlspecialchars($hintId, ENT_QUOTES, 'UTF-8') . '" class="staff-password-hint">'
            . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    if ($matchWith !== '') {
        // Live "Passwords match / do not match" line (filled in by assets/js/login-auth.js).
        $html .= '<div id="' . htmlspecialchars($matchId, ENT_QUOTES, 'UTF-8') . '" class="password-match"'
            . ' data-password-match data-match-with="' . htmlspecialchars($matchWith, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-match-label="' . htmlspecialchars($matchLabel, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-live="polite"></div>';
    }
    $html .= '<div class="staff-field-error" data-error="' . htmlspecialchars($errorKey, ENT_QUOTES, 'UTF-8') . '" aria-live="polite">'
        . htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8') . '</div>';

    return $html;
}
