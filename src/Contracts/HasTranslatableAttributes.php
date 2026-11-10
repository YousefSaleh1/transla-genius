<?php

namespace CodingPartners\TranslaGenius\Contracts;

/**
 * Documents the real precondition TranslationWriter and TranslateFields
 * depend on beyond the base Eloquent Model: getTranslation()/setTranslation()
 * from Spatie\Translatable\HasTranslations (pulled in by this package's own
 * Traits\Translatable).
 *
 * PHP intersection types only accept interfaces, not traits - `Model &
 * HasTranslations` isn't legal PHP, since HasTranslations is a trait. This
 * interface exists so the intersection can be expressed at all
 * (`Model&HasTranslatableAttributes`) for static analysis and documentation.
 *
 * You do not need to `implements` this yourself. Any model using
 * Traits\Translatable already has the two methods this interface names -
 * declaring `implements HasTranslatableAttributes` on your model is optional
 * and purely for IDE/static-analysis benefit, never required. The actual
 * runtime enforcement is a guard clause in TranslationWriter::apply(),
 * not this interface.
 */
interface HasTranslatableAttributes
{
    /**
     * Get the value of a translatable attribute for a given locale.
     *
     * @param string $key The translatable attribute name (e.g. "name").
     * @param string $locale The locale to read (e.g. "en", "ar").
     * @param bool $useFallbackLocale Whether to fall back to the app's
     *        default locale when $locale has no value for $key.
     * @return mixed The translated value, or null/empty depending on the
     *         implementation's fallback behavior.
     *
     * @see \Spatie\Translatable\HasTranslations::getTranslation()
     */
    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed;

    /**
     * Set the value of a translatable attribute for a given locale.
     *
     * @param string $key The translatable attribute name (e.g. "name").
     * @param string $locale The locale to write (e.g. "en", "ar").
     * @param mixed $value The value to store for that locale.
     * @return mixed Implementation-dependent; Spatie's HasTranslations
     *         returns the model instance for chaining.
     *
     * @see \Spatie\Translatable\HasTranslations::setTranslation()
     */
    public function setTranslation(string $key, string $locale, $value);
}
