<?php

namespace Vuthaihoc\TtsClient;

/**
 * Result of POST /api/tts/ipa: the text split into sentences, each with
 * `text`, `ipa` (espeak-ng IPA, ready for a [[ ]] block), `cambridge`
 * (Cambridge-dictionary notation, null for Vietnamese) and, depending on
 * the request, `words` (engine=dictionary) and `alternatives` (single word).
 */
final class Ipa
{
    /**
     * @param  list<array<string, mixed>>  $sentences
     */
    public function __construct(
        public readonly string $language,
        public readonly string $engine,
        public readonly ?string $dictionary,
        public readonly array $sentences,
    ) {}

    public static function fromResponse(array $json): self
    {
        return new self($json['language'], $json['engine'] ?? 'espeak', $json['dictionary'] ?? null, $json['sentences'] ?? []);
    }

    /**
     * The whole text in espeak-ng IPA, sentences joined by spaces.
     */
    public function ipa(): string
    {
        return implode(' ', array_column($this->sentences, 'ipa'));
    }

    /**
     * The whole text in Cambridge notation, e.g. "/təˈmɑː.təʊ/" (null for Vietnamese).
     */
    public function cambridge(): ?string
    {
        $parts = array_filter(array_column($this->sentences, 'cambridge'), fn ($value) => $value !== null);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * For a single word: every pronunciation the dictionary lists,
     * as ['ipa' => ..., 'cambridge' => ...] (either: /ˈaɪ.ðə/, /ˈiː.ðə/).
     *
     * @return list<array{ipa: string, cambridge: ?string}>
     */
    public function alternatives(): array
    {
        return $this->sentences[0]['alternatives'] ?? [];
    }

    /**
     * engine=dictionary: per word, its source (cmudict, britfone, espeak)
     * and every pronunciation.
     *
     * @return list<array{word: string, source: string, pronunciations: list<array{ipa: string, cambridge: ?string}>}>
     */
    public function words(): array
    {
        return array_merge(...array_map(fn ($sentence) => $sentence['words'] ?? [], $this->sentences ?: [[]]));
    }
}
