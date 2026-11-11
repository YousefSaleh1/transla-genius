<?php

namespace CodingPartners\TranslaGenius\DTOs;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;

/**
 * Everything a translation job is dispatched with, as one object: the
 * contract between the package's dispatch points (model create/update,
 * translagenius:backfill, the review workflow) and any job class that
 * implements Contracts\TranslationJob. New dispatch data is added here, never
 * as another constructor argument.
 *
 * Serializes its model as a model identifier (like SerializesModels), so a
 * job that keeps the payload whole still re-reads the model from the database
 * when the queue runs it.
 */
final class TranslationJobPayload
{
    use SerializesAndRestoresModelIdentifiers;

    /**
     * @param  Model&HasTranslatableAttributes  $model  The record to translate.
     * @param  array<int, string>  $fields  Translatable fields to fill.
     * @param  string|null  $sourceLocale  The locale the content was authored in,
     *                                     decided at dispatch time (null: TRANSLATION_SOURCE_LOCALE, then
     *                                     app.locale - see TranslateFields).
     * @param  array<string, array{locale: string, source_hash: string, keep: array<int, string>, target_hashes?: array<string, string>}>  $retranslate  Fields whose
     *                                                                                                                                                   source text changed and must be re-translated.
     * @param  array<string, mixed>  $options  e.g. include_rejected (backfill).
     */
    public function __construct(
        public readonly Model $model,
        public readonly array $fields,
        public readonly ?string $sourceLocale = null,
        public readonly array $retranslate = [],
        public readonly array $options = [],
    ) {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [
            'model' => $this->getSerializedPropertyValue($this->model),
            'fields' => $this->fields,
            'sourceLocale' => $this->sourceLocale,
            'retranslate' => $this->retranslate,
            'options' => $this->options,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->model = $this->getRestoredPropertyValue($data['model']);
        $this->fields = $data['fields'];
        $this->sourceLocale = $data['sourceLocale'];
        $this->retranslate = $data['retranslate'];
        $this->options = $data['options'];
    }
}
