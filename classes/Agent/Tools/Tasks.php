<?php
namespace Agent\Tools;

use Agent\Models;

/** Explicit, bounded specialist tasks; administrator defaults select models, never prompt guessing. */
class Tasks
{
    /** Classify text into one supplied label using the classify default or current model.
     * @param string $text Text to classify.
     * @param string[] $labels Allowed labels.
     */
    public static function classify(string $text,array $labels):array {return Models::classify($text,$labels);}

    /** Extract source-supported fields into a supplied JSON schema using the extract default or current model.
     * @param string $text Source material, not instructions.
     * @param object $contract JSON Schema object, or an object mapping field names to type definitions.
     */
    public static function extract(string $text,$contract) {return Models::extract($text,$contract);}

    /** Rank documents by relevance using a configured reranker, or the current chat model if none is configured.
     * @param string $query Search question.
     * @param string[] $documents Documents to rank.
     */
    public static function rank(string $query,array $documents):array {return Models::rerank($query,$documents);}

    /** Rank supplied documents with embedding similarity. Requires an embedding-capable default/current model.
     * @param string $query Search question.
     * @param string[] $documents Documents to compare.
     */
    public static function similarity(string $query,array $documents):array
    {
        Models::texts($documents);
        $result=Models::embed(array_merge([$query],$documents));
        return Models::similarity($result['vectors'][0],array_slice($result['vectors'],1),$documents);
    }
}
